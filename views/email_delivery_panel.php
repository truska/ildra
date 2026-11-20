<?php
/** Shared admin presentation for submission results and recipient feedback. */
function emailDeliveryRenderSummary(array $email,array $data):void
{
    $summary=emailDeliverySummary($email,$data['events']??[]);
    $meta=json_decode((string)($email['meta_json']??''),true)?:[];
    $submission=($email['status']??'')==='sent'?'accepted':(string)($email['status']??'');
    if(!empty($meta['submission_uncertain']))$submission='uncertain';
    ?><span class="badge rounded-pill <?= h(emailDeliveryBadge($submission)) ?>"><?= h(emailDeliverySubmissionLabel($email)) ?></span><?php
    if($summary['label']!==''): ?><div class="mt-1"><span class="badge rounded-pill <?= h(emailDeliveryBadge($summary['type'])) ?>"><?= h($summary['label']) ?></span></div><?php endif;
    if($summary['detail']!==''): ?><div class="small text-muted mt-1"><?= h($summary['detail']) ?></div><?php endif;
}

function emailDeliveryRenderPanel(array $email,array $data):void
{
    $meta=json_decode((string)($email['meta_json']??''),true)?:[];$transport=$data['transport']??[];
    $messageId=$transport['provider_message_id']??$meta['provider_message_id']??null;
    $duration=$transport['duration_ms']??$meta['delivery_duration_ms']??null;
    $response=$transport['smtp_response']??$meta['smtp_response']??null;
    ?>
    <section class="card-soft p-4 mb-4" aria-labelledby="email-delivery-heading">
        <h2 class="h5" id="email-delivery-heading">Delivery status</h2>
        <?php emailDeliveryRenderSummary($email,$data); ?>
        <p class="small text-muted mt-3">Acceptance means the sending system accepted the message. Delivered means the recipient's mail server accepted it; it does not confirm inbox placement or that the email was read.</p>
        <?php if(!empty($meta['email_test_redirect']['enabled'])): ?><div class="alert alert-info">Testing redirect: status applies to <?= h((string)$email['to_email']) ?>. Original recipient: <?= h((string)($meta['email_test_redirect']['original_to']??'')) ?>. It does not confirm delivery to the original recipient.</div><?php endif; ?>
        <dl class="row small mb-3">
            <dt class="col-sm-4">Sending system</dt><dd class="col-sm-8"><?= h(($email['provider']??'')==='ses_smtp'?'Amazon SES':(string)($email['provider']??'')) ?></dd>
            <dt class="col-sm-4">Send duration</dt><dd class="col-sm-8"><?= $duration!==null?h(number_format((float)$duration/1000,2).' seconds'):'Not recorded for this email' ?></dd>
            <dt class="col-sm-4">Provider message ID</dt><dd class="col-sm-8 text-break"><?= h((string)($messageId?:'Not recorded for this email')) ?></dd>
            <?php if($response): ?><dt class="col-sm-4">SMTP acceptance</dt><dd class="col-sm-8 text-break"><?= h((string)$response) ?></dd><?php endif; ?>
        </dl>
        <?php if(!empty($data['events'])): ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Time</th><th>Recipient</th><th>Status</th><th>Detail</th></tr></thead><tbody>
            <?php foreach($data['events'] as $event): ?><tr><td class="text-nowrap"><?= h(admin_display_datetime($event['occurred_at'])) ?></td><td class="text-break"><?= h($event['recipient']) ?></td><td><span class="badge <?= h(emailDeliveryBadge($event['event_type'])) ?>"><?= h(emailDeliveryEventLabel($event['event_type'])) ?></span></td><td class="text-break"><?= h((string)$event['detail']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php else: ?><p class="small text-muted mb-0">No recipient delivery feedback has been recorded. Amazon SES feedback requires the SES/SNS connection; older messages may remain unconfirmed.</p><?php endif; ?>
    </section>
    <?php
}
