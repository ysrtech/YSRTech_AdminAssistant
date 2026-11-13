---
title: Sending setup (Mailgun and others)
category: Email Campaigns
sort: 770
---
[System > Configuration > YSRTech > Email Campaigns > Sending](admin:system_config/edit/section/ysrtech_emailcampaigns)

- **Transport Provider**: Mailgun, Resend, or the store's own mail settings.
- For Mailgun: **Provider API Key** (a domain sending key is enough), **Mailgun Region** (US or EU, where the domain was created), **Mailgun Sending Domain** (a subdomain kept apart from the one sending order emails), **Mailgun Webhook Signing Key**, and optionally a **Suppression API Key** for the hourly read of Mailgun's lists.
- **Mailgun Webhook URL** is shown on the page: add it in Mailgun under **Sending > Webhooks** for Permanent Failure, Spam Complaints, Unsubscribes, Opens and Clicks.
- **Mailgun Connection** > test: checks the saved keys, region and domain. Sends nothing. Save first.
- **Default From Name / Email**, **Maximum Per Minute** (1000), **Batch Size**, **Abandoned Cart After (hours)**, **Max Send Attempts**.

The **Sender Check** group checks the sending domain's records and the provider's tracking settings, with a fix for each problem it finds.

**Tracking**: open and click tracking, whether links go through the provider or straight to the store, the store's own unsubscribe header, crediting orders to emails (**Credit An Order For This Many Days After A Click**, 5 by default), and Google Analytics labels.
