---
title: Import subscribers or migrate from another provider
category: Email Campaigns
sort: 760
---
[Email Campaigns > Import / Migrate](admin:ysrtech_import):

- **A CSV of subscribers**: upload the file, match its columns, start. Role addresses (info@, sales@…) are left out unless you choose to import them.
- **A whole account** from Mailchimp, Klaviyo, Brevo, Kit, SendGrid or Mailgun: **Start Migration**, give the provider's key, and choose what to bring over. Unsubscribes and bounces come across as suppressions.

Imports run in the background with cron, a batch at a time. Without cron, **Process a Batch Now** on the import's page moves it along by hand.

> [!WARNING]
> Only import people who agreed to receive email from the store.
