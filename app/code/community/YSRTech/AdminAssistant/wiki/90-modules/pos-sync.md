---
title: POS Sync
category: This store's modules
sort: 930
---
Stock and prices come from the shop's POS. A server script runs the sync: stock and prices four times a day (2:00, 8:00, 14:00, 20:00) and new products at 3:30 at night. It is set up on the server, not in System > Configuration.

## POS items not on the website

[Catalog > POS Sync](admin:possync) lists POS items in stock that are not on the website yet, with possible duplicates and how sure the match is.

Decide for each (tick rows, **Actions**):

- **Decide: Create it**: the night run creates it (even if older than the usual cut-off).
- **Decide: Never create**.
- **Decide: Use the POS number on the matched product**: links the POS item to the web product found as its likely duplicate (asks to confirm).
- **Clear the decision**.
- **Create now (do not wait for tonight)**: creates up to 25 products per click, **disabled**, to check and enable in [Manage Products](admin:catalog_product).

Items already on the website, excluded, or missing a name or price are skipped. **Export** gives the list as CSV.

> [!WARNING]
> A quantity or price changed by hand in the admin can be overwritten by the next sync. Change it in the POS.

Log: `var/log/pos_sync.log`.
