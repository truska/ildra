# Amazon SES enhancements

A running list of issues and possible improvements for Amazon SES email delivery.
These are deferred items, not authorization to change delivery settings or send
emails. Add further issues and requests here as they arise.

## Keep sensitive links on our own domain

Recorded: 20 November 2026. Status: deferred for another day.

The unsubscribe link in an email sent through Amazon appeared to use an Amazon
URL instead of our website URL. This appears consistent with SES click tracking,
which rewrites links and redirects the recipient to the original destination.
The exact received link and active tracking configuration still need checking.

Concern: recipients checking a link before clicking may distrust an unfamiliar
Amazon destination, especially for unsubscribe and account access links.

Preferred outcome for review:

- Keep unsubscribe, sign-in and password-reset links as direct website URLs.
- Consider retaining click tracking for other links only where useful.
- If tracking is wanted, consider a branded tracking subdomain instead of the
  default Amazon domain.

AWS supports excluding individual HTML links from click tracking using the
`ses:no-track` attribute. It also supports custom tracking domains. Before
implementation, check the active SES configuration and our HTML generation and
sanitisation paths, then verify the actual received email destinations with a
controlled test. Previously delivered emails will remain unchanged.

References:

- [Amazon SES email sending metrics FAQs](https://docs.aws.amazon.com/ses/latest/dg/faqs-metrics.html)
- [Custom domains for open and click tracking](https://docs.aws.amazon.com/ses/latest/dg/configure-custom-open-click-domains.html)
- [Project email delivery documentation](EMAIL_DELIVERY.md)

## Further issues and requests

Add each item with the date, observed behaviour or requested outcome, and status.
