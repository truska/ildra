# Truska delivered-statistics investigation

Read-only investigation on 20 November 2026. No messages sent, mailbox messages
changed, email routes changed or mail-server settings altered.

## Confirmed from the installed configuration and network checks

- The configured submission service is smtp.truska.net:587 using STARTTLS.
  Its greeting identifies mail.truska.net; it does not disclose server software.
- SMTP EHLO advertises DSN and ENHANCEDSTATUSCODES, before and after verified TLS.
  This confirms support for requesting delivery-status notifications, not actual
  final delivery for any particular message.
- The configured bounce mailbox is on imap.truska.net:143. STARTTLS is advertised.
  Verified TLS succeeded using strong AES-GCM cipher selection; the default
  negotiation encountered a weak-DH error. The existing collector is configured
  without TLS, so its connection setup needs updating before relying on new
  processing. No credentials were sent in the clear by this investigation.
- A verified-TLS, read-only examination of three current INBOX message headers
  found three multipart/report messages addressed to this site's bounce alias.
  No message body, recipient list or credential was written to investigation output.
- The site records its own Message-ID, site code and bounce token in email-log
  metadata. Outgoing messages also carry X-CMS-Log-ID, X-CMS-Site and
  X-CMS-Bounce-Token headers. New SMTP sends record the final acceptance response.

## Existing application limits

The sender does not request positive DSNs. The existing process_bounces.php
collector marks every token-matched message as bounced; it does not distinguish
success, delay, relay or failure reports. Do not enable positive DSN requests
until that processing is corrected. The current receipt-ID parser is primarily
SES-shaped; mail-server queue IDs may need parsing separately from the site's
already-recorded RFC Message-ID.

The preferred route, given server access is available, is a restricted outgoing
log feed. It can report successful recipient-server handoff without depending on
every receiving system to return a final DSN. IMAP/DSN parsing remains useful as
supplementary evidence and for later failure notices.

## Request for Truska server support

We want delivery statistics in the ILDRA website for messages submitted through
smtp.truska.net. Please confirm the outgoing mail-server software and whether it
sends directly to recipient MX servers or through another relay/smarthost.

Please provide a restricted feed/export for this website's authenticated SMTP
account only, containing:

- Initial acceptance: queue ID, RFC Message-ID and timestamp, including any
  queue-ID changes caused by content filtering or reinjection.
- Per-recipient delivery attempts: recipient address, timestamp with timezone,
  queue ID, result, SMTP/enhanced status code and diagnostic response.
- Destination server/relay identity so that final recipient-server acceptance
  can be distinguished from an intermediate relay accepting the message.
- Deferred attempts and final bounces/rejections, including later failures.

Please first supply redacted examples of a successful external delivery, a delay
and a final failure, plus an explanation of available log retention. No message
bodies, unrelated customers' logs or administrator credentials are needed.

A read-only filtered export/API or a small log-forwarding service is suitable.
If a push callback is preferred, the website needs a separate receiver and
server-specific signature/secret configuration; it does not yet have a Truska
log-ingestion endpoint. Do not send Truska events to email_ses_webhook.php: that
endpoint is for signed Amazon SNS notifications only. Agree the event format,
authentication and retry rules before installing a forwarder. Server support
should not make application source changes on live.

## Application work after the samples/feed are confirmed

1. Match the website Message-ID/queue IDs and actual envelope recipients to
   email_log, including redirected tests and To/CC/BCC.
2. Ingest authenticated, per-recipient events idempotently into the existing
   reporting model; preserve submission status and do not resend messages when
   logging fails. Handle queue changes, duplicate/out-of-order reports and
   later adverse outcomes.
3. Count Delivered only when evidence confirms recipient-server acceptance.
   Show an intermediate handoff as Relayed/Unconfirmed. An absent bounce is not
   positive evidence of delivery. Delivery is not reading or inbox placement.
4. Add delivered/pending/failed counts with per-recipient details to the reporting
   screens. Backfill only where retained logs can be matched reliably.
5. Upgrade the IMAP collector's verified TLS and report-type handling before
   enabling success/delay DSNs. Preserve its existing bounce matching and make
   any controlled real email test a separately authorized operation.

Standards: [SMTP DSN extension, RFC 3461](https://www.rfc-editor.org/rfc/rfc3461.html)
and [delivery-status format, RFC 3464](https://www.rfc-editor.org/rfc/rfc3464.html).
