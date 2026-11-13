---
title: Image Cleaner (unused images)
category: This store's modules
sort: 950
---
Once a week it finds image files nothing uses and moves them to `var/imagecleaner_trash/<date>/`. After the keep period they are deleted for good.

## Settings

[System > Configuration > Advanced > Admin > Orphaned Image Clean-up](admin:system_config/edit/section/admin):

- **Enabled** (Yes); **Mode**: move to the trash, or **Report only** (lists what would move, moves nothing).
- **Schedule**: Sundays 3:30 by default.
- What it looks at: **Product images**, **Category images**, **Product image cache** (all Yes), **WYSIWYG (editor) images** (No, because newsletters may still link to them).
- **Never touch**: paths to leave alone, one per line.
- **Leave files changed in the last (hours)**: 48.
- **Stop if more than this % look unused**: 80. A safety catch: a section is skipped when it would move too much.
- **Keep moved files for (days)**: 30.

## Getting an image back

Move it from `var/imagecleaner_trash/<date>/` back to the same path under `media/` (the developer or host can do this).

Log: `var/log/imagecleaner_cron.log`. Needs cron ([[cron]]).
