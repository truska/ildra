<?php
declare(strict_types=1);

function ensureMiscPaymentTables(?PDO $pdo): void {
    if (!$pdo) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS misc_payment_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, request_token CHAR(48) NOT NULL UNIQUE,
        recipient_email VARCHAR(190) NOT NULL, description VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'gbp',
        status VARCHAR(20) NOT NULL DEFAULT 'sent', stripe_session_id VARCHAR(255) DEFAULT NULL UNIQUE,
        stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, stripe_checkout_url TEXT DEFAULT NULL,
        email_sent_at DATETIME DEFAULT NULL, paid_at DATETIME DEFAULT NULL, finance_transaction_id INT UNSIGNED DEFAULT NULL,
        event_id INT UNSIGNED DEFAULT NULL, booking_item_id INT UNSIGNED DEFAULT NULL,
        created_by_user_id INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_misc_payment_status (status), INDEX idx_misc_payment_recipient (recipient_email)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (['event_id'=>'INT UNSIGNED DEFAULT NULL','booking_item_id'=>'INT UNSIGNED DEFAULT NULL'] as $column=>$definition) {
        if (!table_column_exists($pdo, 'misc_payment_requests', $column)) $pdo->exec("ALTER TABLE misc_payment_requests ADD COLUMN {$column} {$definition}");
    }
    if (!table_index_on_column_exists($pdo, 'misc_payment_requests', 'event_id')) $pdo->exec('ALTER TABLE misc_payment_requests ADD INDEX idx_misc_payment_event (event_id)');
    if (!table_index_on_column_exists($pdo, 'misc_payment_requests', 'booking_item_id')) $pdo->exec('ALTER TABLE misc_payment_requests ADD INDEX idx_misc_payment_entry (booking_item_id)');
}

function miscPaymentComplete(?PDO $pdo, array $session, array &$alerts = [], ?array $stripeConfig = null): bool {
    if (!$pdo || ($session['payment_status'] ?? '') !== 'paid') return false;
    $requestId = (int)($session['metadata']['misc_payment_request_id'] ?? 0);
    $sessionId = trim((string)($session['id'] ?? ''));
    if ($requestId <= 0 || $sessionId === '') return false;
    ensureMiscPaymentTables($pdo);
    if (!ensure_finance_tables($pdo, $alerts)) return false;
    $find = $pdo->prepare('SELECT * FROM misc_payment_requests WHERE id=:id AND stripe_session_id=:session LIMIT 1');
    $find->execute([':id'=>$requestId, ':session'=>$sessionId]); $request=$find->fetch();
    if (!$request) return false;
    if ((int)($request['finance_transaction_id'] ?? 0) > 0) return true;
    if ((int)($session['amount_total'] ?? -1) !== (int)round((float)$request['amount']*100) || strtolower((string)($session['currency'] ?? '')) !== strtolower((string)$request['currency'])) {
        $alerts[]=['type'=>'danger','message'=>'The Stripe payment amount or currency does not match this payment request.']; return false;
    }
    $paymentIntent = is_array($session['payment_intent'] ?? null) ? (string)($session['payment_intent']['id'] ?? '') : (string)($session['payment_intent'] ?? '');
    $meta=['misc_payment_request_id'=>$requestId,'recipient_email'=>$request['recipient_email'],'stripe_session_id'=>$sessionId,'stripe_payment_intent'=>$paymentIntent,'event_id'=>(int)($request['event_id']??0)?:null,'booking_item_id'=>(int)($request['booking_item_id']??0)?:null,'stripe_fee'=>null];
    if ($stripeConfig !== null && $paymentIntent !== '') {
        require_once __DIR__ . '/finance_fulfilment.php';
        try { $meta=finance_receipt_fee($stripeConfig,['amount'=>$request['amount'],'metadata'=>$meta]); }
        catch (RuntimeException $e) { /* Payment remains recorded; missing fees are reconciled in Stripe Fulfilment. */ }
    }
    try {
        // The browser return and webhook may arrive together. Lock the request
        // and commit its receipt and paid state atomically, without touching account credit.
        $pdo->beginTransaction();
        $lock=$pdo->prepare('SELECT finance_transaction_id FROM misc_payment_requests WHERE id=:id FOR UPDATE');
        $lock->execute([':id'=>$requestId]);
        if ((int)$lock->fetchColumn()>0) { $pdo->commit(); return true; }
        $insert=$pdo->prepare("INSERT INTO finance_transactions (user_id,type,direction,amount,balance_after,reference,notes,metadata) VALUES (NULL,'payment_stripe_misc','credit',:amount,NULL,:reference,:notes,:metadata)");
        $insert->execute([':amount'=>$request['amount'],':reference'=>$sessionId,':notes'=>$request['description'],':metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)]);
        $transactionId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE misc_payment_requests SET status='paid',paid_at=NOW(),stripe_payment_intent_id=:intent,finance_transaction_id=:transaction WHERE id=:id")->execute([':intent'=>$paymentIntent?:null,':transaction'=>$transactionId,':id'=>$requestId]);
        $pdo->commit(); return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $alerts[]=['type'=>'danger','message'=>'Could not record the payment request receipt.']; return false;
    }
}
