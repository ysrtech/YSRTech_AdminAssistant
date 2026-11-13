---
title: Email Campaigns: overview
category: Email Campaigns
sort: 700
---
**Email Campaigns** (YSRTech) sends newsletters and automatic emails to the store's newsletter subscribers and customers. There are no separate mailing lists: contacts are OpenMage's own subscribers and customers.

## The pages

| Page | What it is for |
|---|---|
| [Dashboard](admin:ysrtech_dashboard) | Sender reputation, then sent, delivered, real opens, clicks, bounce and spam rates over 7, 14 or 30 days, by mailbox provider. See [[email-campaigns-dashboard]] |
| [Subscribers](admin:ysrtech_subscriber) | Everyone on the newsletter, with each person's history |
| [Segments](admin:ysrtech_segment) | Groups of subscribers and customers by rules. See [[email-campaigns-segments]] |
| [Campaigns](admin:ysrtech_campaign) | Write, check, schedule and send. See [[email-campaigns-send-a-campaign]] |
| [Templates](admin:ysrtech_template) | Starter designs and the drag-and-drop designer. See [[email-campaigns-templates]] |
| [Automations](admin:ysrtech_automation) | Emails sent by events: order placed, shipped, abandoned cart… See [[email-campaigns-automations]] |
| [Suppressions](admin:ysrtech_suppression) | Addresses that must not be mailed, and why. See [[email-campaigns-suppressions]] |
| [Import / Migrate](admin:ysrtech_import) | Subscribers from a CSV, or a whole account from another provider. See [[email-campaigns-import]] |

Each page has its own permission under **System > Permissions > Roles > Email Campaigns**.

## How sending works

Nothing is sent while a page loads. Scheduling a campaign only marks it; OpenMage's cron then queues the recipients and sends them in batches, at most **Maximum Per Minute** across all campaigns and automations (1000 by default). **If cron stops, nothing is sent** ([[cron]]).

Settings: [System > Configuration > YSRTech > Email Campaigns](admin:system_config/edit/section/ysrtech_emailcampaigns). See [[email-campaigns-sending-setup]].
