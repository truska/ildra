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
