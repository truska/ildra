<?php
declare(strict_types=1);

/**
 * Shared entry-cancellation helpers for customer and admin workflows.
 */
function fetch_entry_cancellation_record(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare("SELECT bi.*, b.booking_ref, b.user_id, b.contact_name, b.contact_email, e.title AS live_title, e.event_date, e.entry_close_at FROM booking_items bi JOIN bookings b ON b.new_id = bi.booking_id JOIN events e ON e.id = bi.event_id WHERE bi.id = :id LIMIT 1");
    $stmt->execute([':id' => $itemId]);
    $entry = $stmt->fetch();
    return is_array($entry) ? $entry : null;
}

function cancel_event_entry(PDO $pdo, array $config, array $entry, string $method, float $amount, string $reason, int $actorUserId, string $actorName, array &$alerts, string $cancelledBy = 'admin', ?int $creditUserId = null): ?array
{
    if (!is_array($entry['metadata'] ?? null)) {
        $metadata = json_decode((string)($entry['metadata'] ?? ''), true);
        $entry['metadata'] = is_array($metadata) ? $metadata : [];
    }
    $itemId = (int)($entry['id'] ?? 0);
    $entryPrice = price_to_number($entry['price'] ?? 0);
    if ($itemId <= 0 || !empty($entry['is_withdrawn'])) {
        $alerts[] = ['type' => 'warning', 'message' => 'This entry has already been cancelled.'];
        return null;
    }
    if (!in_array($method, ['credit', 'refund'], true) || $amount <= 0 || $amount > $entryPrice + 0.0001) {
        $alerts[] = ['type' => 'danger', 'message' => 'Enter an amount greater than zero and no more than ' . format_price($entryPrice) . '.'];
        return null;
    }

    $metadata = [
        'booking_item_id' => $itemId,
        'event_id' => (int)($entry['event_id'] ?? 0),
        'cancelled_by' => $cancelledBy,
        'actor_user_id' => $actorUserId ?: null,
        'actor_name' => $actorName,
    ];
    $reference = (string)($entry['booking_ref'] ?? '');
    $userId = $creditUserId ?? (int)($entry['user_id'] ?? 0);

    if ($method === 'credit') {
        $ok = record_finance_transaction($pdo, [
            'user_id' => $userId ?: null,
            'type' => 'entry_credit',
            'amount' => $amount,
            'reference' => $reference,
            'notes' => 'Entry cancellation credit' . ($reason !== '' ? ': ' . $reason : ''),
            'metadata' => $metadata,
        ], $alerts);
    } else {
        $payment = $pdo->prepare("SELECT metadata FROM finance_transactions WHERE reference = :ref AND type = 'payment_stripe' ORDER BY id DESC LIMIT 1");
        $payment->execute([':ref' => $reference]);
        $paymentMeta = json_decode((string)$payment->fetchColumn(), true);
        $paymentIntent = is_array($paymentMeta) ? (string)($paymentMeta['stripe_payment_intent'] ?? '') : '';
        $charge = is_array($paymentMeta) ? (string)($paymentMeta['stripe_charge_id'] ?? '') : '';
        if ($paymentIntent === '' && $charge === '') {
            $alerts[] = ['type' => 'danger', 'message' => 'A Stripe payment could not be found for this entry.'];
            return null;
        }
        $params = ['amount' => (int)round($amount * 100), 'metadata[booking_item_id]' => $itemId, 'metadata[event_id]' => (int)($entry['event_id'] ?? 0)];
        if ($paymentIntent !== '') {
            $params['payment_intent'] = $paymentIntent;
        } else {
            $params['charge'] = $charge;
        }
        $refund = stripe_create_refund(stripe_config($config), $params, 'ildra-entry-refund-' . $itemId);
        if (!($refund['ok'] ?? false)) {
            $alerts[] = ['type' => 'danger', 'message' => $refund['error'] ?? 'Stripe could not start the refund.'];
            return null;
        }
        $metadata['stripe_refund_id'] = (string)($refund['data']['id'] ?? '');
        $ok = record_finance_transaction($pdo, [
            'user_id' => $userId ?: null,
            'affects_credit' => false,
            'type' => 'entry_stripe_refund',
            'amount' => -$amount,
            'reference' => $reference,
            'notes' => 'Stripe entry refund' . ($reason !== '' ? ': ' . $reason : ''),
            'metadata' => $metadata,
        ], $alerts);
    }

    if (!$ok) {
        return null;
    }
    $update = $pdo->prepare('UPDATE booking_items SET is_withdrawn = 1, withdrawn_at = NOW(), withdrawn_by_user_id = :by, withdrawal_reason = :reason WHERE id = :id AND is_withdrawn = 0');
    $update->execute([':by' => $actorUserId ?: null, ':reason' => $reason !== '' ? $reason : 'Entry cancelled: ' . $method, ':id' => $itemId]);
    if ($update->rowCount() !== 1) {
        $alerts[] = ['type' => 'danger', 'message' => 'The payment was recorded but the entry could not be marked as cancelled. Please contact support.'];
        return null;
    }
    $recipient = trim((string)($entry['contact_email'] ?? ''));
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $siteSettings = getSiteSettings($pdo);
        $emailSettings = getEmailSettings($pdo);
        $email = render_entry_cancellation_email($entry, $siteSettings, $emailSettings, $method, $amount);
        $webmaster = trim((string)($siteSettings['company_webmaster_email'] ?? ''));
        $webmasterCc = filter_var($webmaster, FILTER_VALIDATE_EMAIL) ? [$webmaster] : [];
        send_logged_email($pdo, $recipient, (string)$email['subject'], (string)$email['html'], (string)$email['text'], [
            'kind' => 'entry_cancellation',
            'booking_ref' => $reference,
            'booking_item_id' => $itemId,
            'event_id' => (int)($entry['event_id'] ?? 0),
            'cancellation_method' => $method,
            'cancellation_amount' => $amount,
        ], [], $webmasterCc);
    }
    return ['method' => $method, 'amount' => $amount];
}
