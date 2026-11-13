---
title: Cron: the jobs that run on their own
category: Settings & system
sort: 650
---
Many things happen on a schedule, run by OpenMage's **cron**. If cron stops, they quietly stop too:

- Email Campaigns: sending scheduled campaigns and automations, imports, abandoned carts, reading suppressions ([[email-campaigns-overview]]).
- Catalog price rules with dates ([[catalog-price-rules]]).
- Clearing old login codes ([[otp-login]]), cleaning unused images ([[image-cleaner]]), clearing old AI chats.
- OpenMage's own jobs: currency rates, sitemaps, log cleaning.

The POS sync runs from the server's own schedule, not OpenMage's ([[pos-sync]]).

## Is it running?

Ask the AI Assistant "is cron running?". It reads the cron history and says when it last ran and which jobs failed. If cron has not run for more than about 15 minutes, tell the developer or host.
