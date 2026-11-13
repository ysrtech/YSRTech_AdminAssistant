---
title: Suppressions: who is never mailed
category: Email Campaigns
sort: 750
---
[Email Campaigns > Suppressions](admin:ysrtech_suppression) lists every address that must not be emailed, with why:

- **Hard bounce**: the address does not exist or refuses mail.
- **Spam complaint**: the person marked an email as spam.
- **Unsubscribed**.
- **Added by hand**, with a note (e.g. "asked by phone").

Bounces, complaints and unsubscribes come back from the sending provider by webhook, and the provider's own lists are read every hour as a safety net.

Add an address by hand when someone asks not to be emailed. Remove one only when you are sure: mailing an address that bounced or complained harms the store's sender reputation.

"Why isn't jane@example.com getting our emails?": the AI Assistant shows whether the address is suppressed and why.
