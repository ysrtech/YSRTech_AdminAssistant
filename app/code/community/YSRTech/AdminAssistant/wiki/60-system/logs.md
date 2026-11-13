---
title: Error logs
category: Settings & system
sort: 680
---
OpenMage writes errors to files on the server:

- `var/log/exception.log`: errors with their technical details.
- `var/log/system.log`: warnings and notices.

Logging must be on: [System > Configuration > Developer > Log Settings](admin:system_config/edit/section/dev) > **Enabled** = Yes.

The AI Assistant can read the latest entries ("any errors in the logs today?"), with passwords and keys blanked out.

This store's modules write their own logs too:

| Log | Written by |
|---|---|
| `var/log/ysrtech_guesttocustomer.log` | [[guest-to-customer]] |
| `var/log/imagecleaner_cron.log` | [[image-cleaner]] |
| `var/log/pos_sync.log` | [[pos-sync]] |
