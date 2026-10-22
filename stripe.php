<?php
declare(strict_types=1);

/**
 * Minimal Stripe helpers using cURL (no composer dependency).
 * Expects configuration in $config['stripe'] with keys:
 *  - publishable_key
 *  - secret_key
 *  - webhook_secret (for webhook verification)
 *  - currency (e.g. 'gbp')
 */

function stripe_config(array $config): array
{
    $stripe = $config['stripe'] ?? [];
    return [
        'publishable_key' => trim((string)($stripe['publishable_key'] ?? '')),
        'secret_key' => trim((string)($stripe['secret_key'] ?? '')),
        'webhook_secret' => trim((string)($stripe['webhook_secret'] ?? '')),
        'currency' => strtolower((string)($stripe['currency'] ?? 'gbp')),
    ];
}

/** Public webhook address for the current site's standard HTTPS hostname. */
function stripe_misc_webhook_url(string $basePath = '', ?array $server = null): string
{
    $server = $server ?? $_SERVER;
    $authority = trim((string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
    if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?$/iD', $authority)) {
        throw new InvalidArgumentException('A valid site hostname is required for the Stripe webhook URL.');
    }
    $host = strtolower((string)parse_url('https://' . $authority, PHP_URL_HOST));
    $path = trim($basePath, '/');
    return 'https://' . $host . ($path !== '' ? '/' . $path : '') . '/stripe_misc_webhook.php';
}

function stripe_is_enabled(array $stripeConfig): bool
{
    return $stripeConfig['publishable_key'] !== '' && $stripeConfig['secret_key'] !== '';
}

function stripe_api_request(array $stripeConfig, string $method, string $path, array $params = [], array $extraHeaders = []): array
{
    $url = 'https://api.stripe.com' . $path;
    $method = strtoupper($method);
    $ch = curl_init();
    $headers = array_merge([
        'Authorization: Bearer ' . $stripeConfig['secret_key'],
    ], $extraHeaders);
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($method === 'GET') {
        if (!empty($params)) {
            $options[CURLOPT_URL] .= '?' . http_build_query($params);
        }
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $options[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'error' => 'Stripe request failed: ' . $err];
    }
    $json = json_decode((string)$response, true);
    if ($status >= 200 && $status < 300 && is_array($json)) {
        return ['ok' => true, 'data' => $json];
    }
    $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : 'Stripe API error';
    return ['ok' => false, 'error' => $msg, 'status' => $status, 'body' => $json];
}

function stripe_create_checkout_session(array $stripeConfig, array $params): array
{
    return stripe_api_request($stripeConfig, 'POST', '/v1/checkout/sessions', $params);
}

/** Use the buyer's own person record, never another rider's address. */
function stripe_buyer_address_params(array $user, array $people): array
{
    $buyer = null;
    foreach (['email', 'name'] as $matchField) {
        $matches = array_values(array_filter($people, static function (array $person) use ($user, $matchField): bool {
            if (!empty($person['is_linked']) || (int)($person['owner_user_id'] ?? 0) !== (int)($user['id'] ?? 0)) return false;
            if ($matchField === 'email') {
                $email = trim((string)($user['email'] ?? ''));
                return $email !== '' && strcasecmp(trim((string)($person['email'] ?? '')), $email) === 0;
            }
            $first = trim((string)($user['first_name'] ?? ''));
            $last = trim((string)($user['last_name'] ?? ''));
            return $first !== '' && $last !== ''
                && strcasecmp(trim((string)($person['first_name'] ?? '')), $first) === 0
                && strcasecmp(trim((string)($person['last_name'] ?? '')), $last) === 0;
        }));
        if (count($matches) === 1) { $buyer = $matches[0]; break; }
        if ($matches) return []; // Ambiguous identity: let Stripe collect the address.
    }
    if (!$buyer) return [];
    $params = [];
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($buyer['address'] ?? '')) ?: []), static fn(string $line): bool => $line !== ''));
    if ($lines) {
        $params['address[line1]'] = array_shift($lines);
        if ($lines) $params['address[line2]'] = implode(', ', $lines);
    }
    $postcode = trim((string)($buyer['postcode'] ?? ''));
    if ($postcode !== '') $params['address[postal_code]'] = $postcode;
    return $params;
}

/** Create or refresh the Stripe Customer belonging to one site account. */
function stripe_customer_for_user(?PDO $pdo, array $stripeConfig, array $user, array $contact = []): ?string
{
    $userId = (int)($user['id'] ?? 0);
    if (!$pdo || $userId <= 0) return null;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_customers (user_id INT UNSIGNED NOT NULL PRIMARY KEY, stripe_customer_id VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $find = $pdo->prepare('SELECT stripe_customer_id FROM stripe_customers WHERE user_id = :user_id LIMIT 1');
        $find->execute([':user_id' => $userId]);
        $existing = trim((string)$find->fetchColumn());
        $name = trim((string)($contact['name'] ?? ((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''))));
        $params = ['metadata[user_id]' => (string)$userId];
        if ($name !== '') $params['name'] = $name;
        $email = trim((string)($contact['email'] ?? ($user['email'] ?? '')));
        if ($email !== '') $params['email'] = $email;
        $phone = trim((string)($contact['phone'] ?? ''));
        if ($phone !== '') $params['phone'] = $phone;
        $params += stripe_buyer_address_params($user, fetchMembersForUser($pdo, $userId));
        if ($existing !== '') {
            stripe_api_request($stripeConfig, 'POST', '/v1/customers/' . urlencode($existing), $params);
            return $existing;
        }
        $created = stripe_api_request($stripeConfig, 'POST', '/v1/customers', $params);
        $customerId = trim((string)($created['data']['id'] ?? ''));
        if (!($created['ok'] ?? false) || $customerId === '') return null;
        $save = $pdo->prepare('INSERT INTO stripe_customers (user_id, stripe_customer_id) VALUES (:user_id, :customer_id)');
        $save->execute([':user_id' => $userId, ':customer_id' => $customerId]);
        return $customerId;
    } catch (PDOException $e) {
        return null;
    }
}

function stripe_retrieve_checkout_session(array $stripeConfig, string $sessionId): array
{
    return stripe_api_request($stripeConfig, 'GET', '/v1/checkout/sessions/' . urlencode($sessionId));
}

function stripe_retrieve_payment_intent(array $stripeConfig, string $paymentIntentId, array $expand = []): array
{
    $params = [];
    if ($expand) {
        foreach ($expand as $idx => $field) {
            $params['expand[' . $idx . ']'] = $field;
        }
    }
    return stripe_api_request($stripeConfig, 'GET', '/v1/payment_intents/' . urlencode($paymentIntentId), $params);
}

function stripe_retrieve_balance(array $stripeConfig): array
{
    return stripe_api_request($stripeConfig, 'GET', '/v1/balance');
}

function stripe_create_payout(array $stripeConfig, array $params, string $idempotencyKey = ''): array
{
    $headers = $idempotencyKey !== '' ? ['Idempotency-Key: ' . $idempotencyKey] : [];
    return stripe_api_request($stripeConfig, 'POST', '/v1/payouts', $params, $headers);
}

function stripe_create_refund(array $stripeConfig, array $params, string $idempotencyKey = ''): array
{
    $headers = $idempotencyKey !== '' ? ['Idempotency-Key: ' . $idempotencyKey] : [];
    return stripe_api_request($stripeConfig, 'POST', '/v1/refunds', $params, $headers);
}

function stripe_available_balance(array $balance, string $currency = 'gbp'): float
{
    $currency = strtolower($currency);
    $available = 0;
    foreach (($balance['available'] ?? []) as $item) {
        if (strtolower((string)($item['currency'] ?? '')) === $currency) {
            $available += (int)($item['amount'] ?? 0);
        }
    }
    return max(0, $available / 100);
}

function stripe_available_source_balance(array $balance, string $currency = 'gbp', string $sourceType = 'card'): float
{
    $currency = strtolower($currency);
    foreach (($balance['available'] ?? []) as $item) {
        if (strtolower((string)($item['currency'] ?? '')) === $currency) {
            return max(0, (int)($item['source_types'][$sourceType] ?? 0) / 100);
        }
    }
    return 0.0;
}

function stripe_pending_source_balance(array $balance, string $currency = 'gbp', string $sourceType = 'card'): float
{
    $currency = strtolower($currency);
    foreach (($balance['pending'] ?? []) as $item) {
        if (strtolower((string)($item['currency'] ?? '')) === $currency) {
            return max(0, (int)($item['source_types'][$sourceType] ?? 0) / 100);
        }
    }
    return 0.0;
}

function stripe_verify_webhook_signature(array $stripeConfig, string $payload, string $sigHeader): bool
{
    $secret = $stripeConfig['webhook_secret'] ?? '';
    if ($secret === '' || $sigHeader === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $sigHeader) as $segment) {
        if (str_contains($segment, '=')) {
            [$k, $v] = explode('=', $segment, 2);
            $parts[trim($k)] = trim($v);
        }
    }
    $timestamp = $parts['t'] ?? null;
    $signature = $parts['v1'] ?? null;
    if (!$timestamp || !$signature) {
        return false;
    }
    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    if (!hash_equals($expected, $signature)) {
        return false;
    }
    // Optional: reject very old timestamps (e.g. >5 minutes)
    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }
    return true;
}
