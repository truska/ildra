<?php
declare(strict_types=1);

/** Monthly cash reporting. All calculations use pennies; product years are ignored. */
function finance_month_valid(string $month): bool
{
    return preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month) === 1;
}

function finance_month_key(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/London'))->format('Y-m');
}

/** Largest-remainder allocation keeps mixed-basket totals exact to a penny. */
function finance_month_allocate(int $amount, array $weights): array
{
    $total = array_sum($weights);
    $result = array_fill_keys(array_keys($weights), 0);
    if ($total <= 0 || $amount <= 0) return $result;
    $remainders = [];
    foreach ($weights as $key => $weight) {
        $result[$key] = intdiv($amount * $weight, $total);
        $remainders[$key] = ($amount * $weight) % $total;
    }
    arsort($remainders, SORT_NUMERIC);
    $left = $amount - array_sum($result);
    foreach ($remainders as $key => $_) {
        if ($left-- <= 0) break;
        $result[$key]++;
    }
    return $result;
}

function finance_month_empty(string $month): array
{
    return ['month' => $month, 'membership' => 0, 'horse_logbook' => 0, 'payments' => 0, 'fees' => 0,
        'refunds' => 0, 'net' => 0, 'collected' => 0, 'reserved' => 0, 'remaining' => 0,
        'missing_fees' => 0, 'reconciliation' => false, 'transactions' => [], 'payouts' => []];
}

function finance_month_metadata(array $row): array
{
    if (is_array($row['metadata'] ?? null)) return $row['metadata'];
    $meta = json_decode((string)($row['metadata'] ?? ''), true);
    return is_array($meta) ? $meta : [];
}

/** Build reports from ledger payments, not purchases or internal checkout debits. */
function finance_month_build(array $payments, array $items, array $adjustments, array $payouts): array
{
    $months = [];
    $itemsByRef = [];
    $itemById = [];
    foreach ($items as $item) {
        $itemsByRef[(string)$item['booking_ref']][] = $item;
        $itemById[(int)$item['id']] = $item;
    }
    $paymentByRef = [];
    $seenStripePayments = [];
    $duplicatePaymentIds = [];
    foreach ($payments as $payment) {
        $meta = finance_month_metadata($payment);
        $identity = (string)($meta['stripe_payment_intent'] ?? $meta['stripe_session_id'] ?? '');
        if ($identity === '') continue;
        if (isset($seenStripePayments[$identity])) { $duplicatePaymentIds[(int)$payment['id']] = true; $duplicatePaymentIds[$seenStripePayments[$identity]] = true; }
        $seenStripePayments[$identity] = (int)$payment['id'];
    }
    foreach ($payments as $payment) {
        if (isset($payment['type']) && $payment['type'] !== 'payment_stripe') continue;
        $ref = (string)($payment['reference'] ?? '');
        $weights = ['membership' => 0, 'horse_logbook' => 0, 'other' => 0];
        foreach ($itemsByRef[$ref] ?? [] as $item) {
            // Items allocated to events belong exclusively to the Events report.
            $kind = !empty($item['event_id']) ? 'event:' . (int)$item['event_id'] : (in_array($item['booking_type'], ['membership', 'horse_logbook'], true) ? $item['booking_type'] : 'other');
            $weights[$kind] ??= 0;
            $weights[$kind] += max(0, (int)round((float)$item['price'] * 100));
        }
        if ($weights['membership'] + $weights['horse_logbook'] <= 0) continue;
        $month = finance_month_key((int)$payment['paid_timestamp']);
        $months[$month] ??= finance_month_empty($month);
        if (isset($duplicatePaymentIds[(int)$payment['id']])) $months[$month]['reconciliation'] = true;
        $meta = finance_month_metadata($payment);
        $parts = finance_month_allocate(max(0, (int)round((float)$payment['amount'] * 100)), $weights);
        $feeKnown = isset($meta['stripe_fee']) && is_numeric($meta['stripe_fee']) && (float)$meta['stripe_fee'] >= 0;
        $fees = finance_month_allocate($feeKnown ? (int)round((float)$meta['stripe_fee'] * 100) : 0, $weights);
        $cash = $parts['membership'] + $parts['horse_logbook'];
        $fee = $fees['membership'] + $fees['horse_logbook'];
        $months[$month]['membership'] += $parts['membership'];
        $months[$month]['horse_logbook'] += $parts['horse_logbook'];
        $months[$month]['payments'] += $cash;
        $months[$month]['fees'] += $fee;
        if (!$feeKnown) $months[$month]['missing_fees']++;
        $payment['allocated_amount'] = $cash;
        $payment['allocated_fee'] = $fee;
        $payment['fee_known'] = $feeKnown;
        $payment['report_kind'] = 'Payment';
        $months[$month]['transactions'][] = $payment;
        $paymentByRef[$ref][] = ['month' => $month, 'weights' => $weights];
    }
    foreach ($paymentByRef as $originalPayments) {
        if (count($originalPayments) > 1) foreach ($originalPayments as $original) $months[$original['month']]['reconciliation'] = true;
    }
    foreach ($adjustments as $adjustment) {
        $meta = finance_month_metadata($adjustment);
        $item = $itemById[(int)($meta['booking_item_id'] ?? 0)] ?? null;
        if ($item && (!empty($item['event_id']) || !in_array($item['booking_type'], ['membership', 'horse_logbook'], true))) continue;
        if (!$item && (($adjustment['type'] ?? '') !== 'refund' || !empty($meta['event_id']))) continue;
        $ref = $item ? (string)$item['booking_ref'] : (string)($adjustment['reference'] ?? '');
        $original = $paymentByRef[$ref] ?? [];
        // Ambiguous multiple-payment bookings require reconciliation, not a guessed month.
        if (count($original) !== 1) {
            foreach ($original as $candidate) $months[$candidate['month']]['reconciliation'] = true;
            continue;
        }
        if ($item) {
            $amount = abs((int)round((float)$adjustment['amount'] * 100));
        } else {
            // A booking-wide refund is apportioned; unrelated account adjustments are excluded.
            $parts = finance_month_allocate(abs((int)round((float)$adjustment['amount'] * 100)), $original[0]['weights']);
            $amount = $parts['membership'] + $parts['horse_logbook'];
        }
        $month = $original[0]['month'];
        $months[$month]['refunds'] += $amount;
        $adjustment['allocated_amount'] = -$amount;
        $adjustment['allocated_fee'] = 0;
        $adjustment['fee_known'] = true;
        $adjustment['report_kind'] = 'Refund / credit';
        $months[$month]['transactions'][] = $adjustment;
    }
    foreach ($payouts as $payout) {
        $meta = finance_month_metadata($payout);
        $month = (string)($meta['collection_month'] ?? '');
        if (($meta['collection_scope'] ?? '') !== 'memberships_logbooks' || !finance_month_valid($month)) continue;
        $months[$month] ??= finance_month_empty($month);
        $status = (string)($meta['stripe_status'] ?? 'unknown');
        $amount = abs((int)round((float)$payout['amount'] * 100));
        if ($status === 'submitting' || $status === 'unknown') $months[$month]['reserved'] += $amount;
        elseif (!in_array($status, ['failed', 'canceled'], true)) $months[$month]['collected'] += $amount;
        $payout['metadata'] = $meta;
        $months[$month]['payouts'][] = $payout;
    }
    foreach ($months as &$month) {
        $month['net'] = $month['payments'] - $month['fees'] - $month['refunds'];
        $month['remaining'] = $month['net'] - $month['collected'] - $month['reserved'];
        usort($month['transactions'], static fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']) ?: (int)$b['id'] <=> (int)$a['id']);
    }
    unset($month);
    krsort($months);
    return $months;
}

function finance_month_reports(PDO $pdo): array
{
    // UNIX_TIMESTAMP reads TIMESTAMP values in the DB session timezone correctly;
    // conversion to the UK calendar happens explicitly in PHP.
    $payments = $pdo->query("SELECT ft.*, UNIX_TIMESTAMP(ft.created_at) AS paid_timestamp,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email) AS person_name
        FROM finance_transactions ft LEFT JOIN users u ON u.id=ft.user_id
        WHERE ft.type IN ('payment_stripe','payment_stripe_misc') ORDER BY ft.created_at,ft.id")->fetchAll() ?: [];
    $items = $pdo->query('SELECT bi.*,b.booking_ref FROM booking_items bi JOIN bookings b ON b.new_id=bi.booking_id')->fetchAll() ?: [];
    $adjustments = $pdo->query("SELECT ft.*,UNIX_TIMESTAMP(ft.created_at) AS paid_timestamp,COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.email) AS person_name
        FROM finance_transactions ft LEFT JOIN users u ON u.id=ft.user_id
        WHERE ft.type IN ('entry_refund','entry_stripe_refund','entry_credit','refund')")->fetchAll() ?: [];
    $payouts = $pdo->query("SELECT *,UNIX_TIMESTAMP(created_at) AS requested_timestamp FROM finance_transactions WHERE type='stripe_payout'
        AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.collection_scope'))='memberships_logbooks' ORDER BY created_at DESC,id DESC")->fetchAll() ?: [];
    return finance_month_build($payments, $items, $adjustments, $payouts);
}

/** Persist a reservation before contacting Stripe, then update that same ledger row. */
function finance_month_collect(PDO $pdo, array $stripeConfig, string $month, int $amount, string $requestKey, string $notes, string $actor, ?callable $api = null, string $scope = 'memberships_logbooks'): array
{
    if (!in_array($scope, ['memberships_logbooks','miscellaneous','events'], true)) throw new RuntimeException('Invalid collection group.');
    $isEvent = $scope === 'events';
    if (!str_starts_with((string)($stripeConfig['secret_key'] ?? ''), 'sk_test_')) throw new RuntimeException('Collections are restricted to Stripe test mode.');
    if (!(($isEvent && ctype_digit($month) && (int)$month > 0) || (!$isEvent && finance_month_valid($month))) || !preg_match('/^[a-f0-9]{32}$/D', $requestKey)) throw new RuntimeException('Invalid collection request.');
    $api ??= static fn(string $method, string $path, array $params, string $key): array => stripe_api_request($stripeConfig, $method, $path, $params, $key === '' ? [] : ['Idempotency-Key: ' . $key]);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lockName = '';
    if ($mysql) {
        $lockName = 'ildra-fulfilment-' . sha1((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Another collection is being processed. Please try again.');
    }
    try {
        $stmt = $pdo->prepare("SELECT *,UNIX_TIMESTAMP(created_at) AS requested_timestamp FROM finance_transactions WHERE type='stripe_payout' AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.collection_request_key'))=:request_key");
        $stmt->execute([':request_key' => $requestKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $meta = finance_month_metadata($row);
            if (($meta['collection_scope'] ?? '') !== $scope || (string)($isEvent ? ($meta['event_id'] ?? '') : ($meta['collection_month'] ?? '')) !== $month) throw new RuntimeException('Collection does not match this month.');
            $amount = abs((int)round((float)$row['amount'] * 100));
            if (in_array($meta['stripe_status'] ?? '', ['failed', 'canceled'], true)) return $row;
            if (!empty($meta['stripe_payout_id'])) {
                $response = $api('GET', '/v1/payouts/' . rawurlencode((string)$meta['stripe_payout_id']), [], '');
            } else {
                // Stripe may prune idempotency keys after 24 hours. Never blindly resend an old uncertain payout.
                if (time() - (int)$row['requested_timestamp'] >= 23 * 3600) throw new RuntimeException('This collection needs reconciliation against Stripe before it can be retried. Its amount remains reserved.');
                $response = $api('POST', '/v1/payouts', $meta['stripe_params'], $meta['stripe_idempotency_key']);
            }
        } else {
            $reports = $scope === 'memberships_logbooks' ? finance_month_reports($pdo) : finance_collection_reports($pdo, $scope);
            $report = $reports[$month] ?? finance_month_empty($month);
            if ($report['missing_fees'] > 0) throw new RuntimeException('Stripe fees are missing for payments in this group. Reconcile those fees before collecting.');
            if ($report['reconciliation']) throw new RuntimeException('Receipts or refunds need reconciliation before collecting.');
            if ($report['reserved'] > 0) throw new RuntimeException('Confirm the outstanding collection in this group before making another.');
            $balance = $api('GET', '/v1/balance', [], '');
            if (empty($balance['ok'])) throw new RuntimeException($balance['error'] ?? 'Could not check the Stripe balance.');
            $available = (int)round(stripe_available_source_balance($balance['data'] ?? [], $stripeConfig['currency'] ?? 'gbp', 'card') * 100);
            if ($amount <= 0 || $amount > min($report['remaining'], $available)) throw new RuntimeException('The amount exceeds this group’s remaining funds or Stripe’s available balance.');
            $label = $isEvent ? ($report['label'] ?? ('Event ' . $month)) : ($scope === 'miscellaneous' ? 'Miscellaneous ' : 'Memberships and logbooks ') . $month;
            $params = ['amount' => $amount, 'currency' => $stripeConfig['currency'] ?? 'gbp', 'source_type' => 'card',
                'description' => substr('ILDRA ' . $label, 0, 255), 'statement_descriptor' => substr('ILDRA ' . ($isEvent ? 'EVENT ' : ($scope === 'miscellaneous' ? 'MISC ' : 'ML ')) . $month, 0, 22),
                'metadata[collection_scope]' => $scope, ($isEvent ? 'metadata[event_id]' : 'metadata[collection_month]') => $month,
                'metadata[collection_request_key]' => $requestKey, 'metadata[admin_notes]' => substr($notes, 0, 255)];
            $meta = ['collection_scope' => $scope, ($isEvent ? 'event_id' : 'collection_month') => $month, 'collection_request_key' => $requestKey,
                'stripe_status' => 'submitting', 'actor' => $actor, 'statement_descriptor' => $params['statement_descriptor'],
                'stripe_idempotency_key' => 'ildra-month-' . $requestKey, 'stripe_params' => $params, 'livemode' => false];
            $insert = $pdo->prepare("INSERT INTO finance_transactions (user_id,type,direction,amount,balance_after,reference,notes,metadata)
                VALUES (NULL,'stripe_payout','debit',:amount,NULL,:reference,:notes,:metadata)");
            $insert->execute([':amount' => number_format(-$amount / 100, 2, '.', ''), ':reference' => 'month-' . $requestKey, ':notes' => substr($notes, 0, 255), ':metadata' => json_encode($meta, JSON_THROW_ON_ERROR)]);
            $row = ['id' => (int)$pdo->lastInsertId(), 'amount' => -$amount / 100];
            $response = $api('POST', '/v1/payouts', $params, $meta['stripe_idempotency_key']);
        }
        if (!empty($response['ok']) && !empty($response['data']['id'])) {
            $payout = $response['data'];
            if (!empty($payout['livemode']) || (int)($payout['amount'] ?? 0) !== $amount || strtolower((string)($payout['currency'] ?? '')) !== ($stripeConfig['currency'] ?? 'gbp')) throw new RuntimeException('Stripe payout details do not match this collection. Reconciliation is required.');
            $meta['stripe_payout_id'] = (string)$payout['id'];
            $meta['stripe_status'] = (string)($payout['status'] ?? 'pending');
            $meta['arrival_date'] = (int)($payout['arrival_date'] ?? 0);
            unset($meta['last_error']);
            $reference = (string)$payout['id'];
        } else {
            $httpStatus = (int)($response['status'] ?? 0);
            // Only a definite rejection releases reserved money; network/5xx uncertainty stays reserved.
            if (empty($meta['stripe_payout_id'])) $meta['stripe_status'] = in_array($httpStatus, [400, 401, 402, 403, 404, 422], true) ? 'failed' : 'unknown';
            $meta['last_error'] = (string)($response['error'] ?? 'Stripe confirmation is unavailable.');
            $reference = (string)($row['reference'] ?? ('month-' . $requestKey));
        }
        $update = $pdo->prepare('UPDATE finance_transactions SET reference=:reference,metadata=:metadata WHERE id=:id');
        $update->execute([':reference' => $reference, ':metadata' => json_encode($meta, JSON_THROW_ON_ERROR), ':id' => $row['id']]);
        $row['metadata'] = $meta;
        $row['reference'] = $reference;
        return $row;
    } finally {
        if ($mysql) {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}
