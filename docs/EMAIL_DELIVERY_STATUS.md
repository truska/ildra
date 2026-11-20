# Email delivery status and Amazon SES feedback

This change is independent of account migration/onboarding. It observes all
email kinds routed through the shared sender. Existing `email_log.status`
continues to hold the submission result, preserving campaign and retry logic.
There are no migration-table, enrolment, account, basket or consent changes.
The shared testing-reset allowlist clears these new observability tables whenever
it clears email_log, so feedback cannot outlive its deliberately reset test logs.

## What administrators see

Email history distinguishes Accepted by Amazon SES / Accepted by sending system
from confirmed delivery. Its Status column also shows recipient outcomes and
mixed results when To/CC/BCC differ. The submission filter retains existing
stored values (sent is displayed as Accepted by sending system). The separate
Delivery event filter matches any recorded event of the selected kind; it is
historical, not a filter exclusively on the latest outcome. View shows send
duration, provider message ID, final SMTP acceptance and a chronological
recipient/status/diagnostic history with UK date formatting.

Supported feedback: Send, Delivery, DeliveryDelay, Bounce, Complaint, Reject,
and Rendering Failure. Generic bounces are labelled Bounced; Invalid address
requires explicit missing-mailbox evidence (SMTP 5.1.1 or SES NoEmail).
Delivery confirms recipient-server acceptance, not reading or inbox placement.
Open/click tracking is not enabled or stored. Redirected tests report the actual
envelope recipient and explicitly identify the original intended recipient.
They never imply that the original account received the message.

Existing messages remain unconfirmed unless matching feedback arrives; their
missing provider IDs/timings cannot be reconstructed. New sends record elapsed
time and the SES ID returned after SMTP DATA, with metadata fallback if receipt
storage fails. Logging failures do not trigger a second transport send. A QUIT
disconnect after DATA acceptance remains a successful submission. Losing the
connection before final DATA acceptance is labelled Submission unconfirmed;
the email could have been accepted, so review feedback before manually resending.
The underlying failed result remains unchanged for existing consumers.

## Code and additive schema

- `email_delivery_status.php`: transport receipts, batched log queries and labels.
- `email_ses_feedback.php`: SNS signature verification, event normalisation and
  idempotent per-recipient storage.
- `email_ses_webhook.php`: standalone SNS POST endpoint, deliberately outside
  interactive authentication, CSRF and onboarding redirects.
- `views/email_delivery_panel.php`: shared admin status summary/detail panel.
- `migrations/2026-11-17-email-delivery-status.sql`: two additive tables,
  `email_delivery_transport` and `email_delivery_events`. No existing columns or
  data are rewritten. Prepared outside transactions by the shared email helper.

Only signed messages from explicitly approved SNS topic ARNs are processed.
Signature versions 1 and 2 are supported (prefer version 2 on the topic).
Certificates must use the exact SNS HTTPS hostname for the topic region, with
the AWS certificate filename pattern, no credentials, ports, query or fragment,
and no redirects. TLS verification, validity dates, signature checks, bounded
payload/certificate reads and timeouts apply. Subscription confirmation is
performed only after signature/topic checks and matching Action/TopicArn/Token
on the exact SNS HTTPS endpoint. Unsubscribe confirmations are not acted on.

Events correlate by log ID plus random bounce token/site headers or SES tags,
then by the stored provider ID, and must match actual recipients. Early unmatched
events are retained for later receipt correlation. Repeated SNS/SES reports are
deduplicated; delayed/Send reports cannot downgrade delivery, and later adverse
outcomes remain visible. Events never overwrite submission status or mutate
users' consent, suppression lists or account records. Store only the normalised
status and diagnostic, not whole message bodies or the SNS envelope/certificate.

## AWS connection still required

No AWS resources, sender identity settings, routes, SMTP credentials or redirect
settings were changed during implementation. With no topic allowlist the webhook
is disabled (POST returns 404) and history displays a setup notice. Application
support alone cannot obtain recipient delivery outcomes from SMTP.

Configure separately for each environment, using the existing regional account
(`eu-north-1` for the current SES sender):

1. Create a standard SNS topic dedicated to the website/environment. Scope its
   publish permission to SES in your AWS account and the intended identity or
   configuration set, following AWS's event-destination instructions. Prefer
   SNS signature version 2. Keep existing feedback forwarding enabled while
   validating this connection.
2. Add its exact ARN to that environment's private email configuration. This
   non-secret identifier and configuration-set name are not SMTP credentials:

   ```php
   'ses_feedback' => [
       'topic_arns' => ['arn:aws:sns:eu-north-1:YOUR_ACCOUNT_ID:YOUR_TOPIC'],
   ],
   'ses' => [
       // Keep the existing private username/password and other settings.
       'configuration_set' => 'YOUR_SES_CONFIGURATION_SET',
   ],
   ```

   Place these siblings inside the correct existing `environments` entry in
   `../private/email.php`, preserving its existing configuration. Topic ARNs
   must be standard `arn:aws:sns` regional topics; FIFO and China partitions
   are not supported by this receiver.
3. Subscribe the topic using HTTPS to the public environment URL ending
   `/email_ses_webhook.php`. Use the working externally reachable hostname and
   port, a publicly trusted TLS certificate, and no login/basic-auth challenge.
   The development hostname requires `:28086`; staging/live use their normal
   HTTPS origins. Disable SNS Raw message delivery, because the signed SNS
   envelope is required. The receiver confirms a valid allowlisted subscription.
4. Create the SES configuration set and SNS event destination in the sending
   region. Enable Send, Delivery, DeliveryDelay, Bounce, Complaint, Reject and
   Rendering Failure, then put that exact configuration-set name in the private
   `ses.configuration_set` field. The sender adds the SES configuration-set and
   correlation-tag headers only on SES sends. Do not configure an absent set:
   SES can reject sends referencing a nonexistent configuration set.
5. Alternatively, identity-level SNS delivery/bounce/complaint notifications
   work through the same receiver and stored provider IDs. Enable original
   headers for early/older-message correlation; that route does not supply all
   configuration-set events. Duplicate paths are safe, but avoid needless
   duplicate publishing.
6. Once configured, explicitly authorise a controlled send, then check the
   acceptance ID and later delivery event in View. Use only authorised tests
   for bounce/complaint events (AWS's mailbox simulator is suitable). Verify
   redirect behaviour: redirected sends test the redirected address, never
   the original recipient. Do not remove the redirect just to test logging.

If SMTP credentials are the only credentials available locally, the AWS console
owner must create the topic/event destination. An AWS API/CLI credential or SDK
is not required by the application receiver. Historic sends are not automatically
resent. No test email was sent during implementation.

Before another-environment deployment read `docs/DEPLOYMENT.md`, back up the
email logs, pull committed development source with `git pull --ff-only`, apply
the additive SQL outside a transaction, and verify public PHP read access and
endpoint TLS/reachability. Configure private ARNs/sets independently on that
environment. Rollback can disable the SNS subscription/clear its allowlist and
remove the optional configuration-set header; retain logs and the additive tables.

## Validation and independent commit boundary

Run:

```text
php scripts/test_email_delivery.php
php scripts/test_email_delivery_status.php
php scripts/test_email_delivery_status.php --mysql --smtp
```

The first two are offline. The latter uses verified temporary database tables
and a loopback SMTP fixture only, including DATA rejection, missing DATA response
and post-acceptance QUIT disconnect. No real message is delivered or production account edited.
Signature fixtures use ephemeral local RSA keys/certificates and mocked
certificate fetching. Tests cover tampering, topic/URL restrictions, signature
versions, confirmation parameters, duplicate/racing reports, partial/out-of-order
outcomes, exact invalid-address evidence, redirects and escaped diagnostics.

The shared files already contained unrelated uncommitted migration/navigation
work. The new status files and a patch against their working-tree contents at
start of this task are saved separately under the private `email-status-change`
review directory. Use its manifest/patch to review this task's hunks; do not
stage whole shared files as an email-only commit while they contain other work.
No Git commit or push has been made by this task.

References (AWS documentation reviewed 17 November 2026):

- [SES SMTP responses and message IDs](https://docs.aws.amazon.com/ses/latest/dg/troubleshoot-smtp.html)
- [Configuration-set and tag headers](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-send-email.html)
- [SES SNS event shapes](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-retrieving-sns-examples.html)
- [SNS signature verification](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message-verify-message-signature.html)
- [SES SNS event destinations](https://docs.aws.amazon.com/ses/latest/dg/event-publishing-add-event-destination-sns.html)
