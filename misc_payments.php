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
        created_by_user_id INT UNSIGNED DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_misc_payment_status (status), INDEX idx_misc_payment_recipient (recipient_email)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function miscPaymentComplete(?PDO $pdo, array $session, array &$alerts = []): bool {
    if (!$pdo || ($session['payment_status'] ?? '') !== 'paid') return false;
    $requestId = (int)($session['metadata']['misc_payment_request_id'] ?? 0);
    $sessionId = trim((string)($session['id'] ?? ''));
    if ($requestId <= 0 || $sessionId === '') return false;
    ensureMiscPaymentTables($pdo);
    $find = $pdo->prepare('SELECT * FROM misc_payment_requests WHERE id=:id AND stripe_session_id=:session LIMIT 1');
    $find->execute([':id'=>$requestId, ':session'=>$sessionId]); $request=$find->fetch();
    if (!$request) return false;
    if ((int)($request['finance_transaction_id'] ?? 0) > 0) return true;
    $paymentIntent = is_array($session['payment_intent'] ?? null) ? (string)($session['payment_intent']['id'] ?? '') : (string)($session['payment_intent'] ?? '');
    $financeAlerts=[];
    if (!record_finance_transaction($pdo, ['user_id'=>null, 'type'=>'payment_stripe_misc', 'amount'=>(float)$request['amount'], 'affects_credit'=>false,
        'reference'=>$sessionId, 'notes'=>(string)$request['description'], 'metadata'=>['misc_payment_request_id'=>$requestId,'recipient_email'=>$request['recipient_email'],'stripe_session_id'=>$sessionId,'stripe_payment_intent'=>$paymentIntent]], $financeAlerts)) {
        foreach($financeAlerts as $alert) $alerts[]=$alert; return false;
    }
    $transactionId=(int)$pdo->lastInsertId();
    $update=$pdo->prepare("UPDATE misc_payment_requests SET status='paid', paid_at=NOW(), stripe_payment_intent_id=:intent, finance_transaction_id=:transaction WHERE id=:id AND finance_transaction_id IS NULL");
    $update->execute([':intent'=>$paymentIntent ?: null, ':transaction'=>$transactionId, ':id'=>$requestId]);
    return true;
}
