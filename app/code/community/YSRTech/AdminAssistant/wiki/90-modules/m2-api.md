---
title: M2 API (connection for ShipStation and other systems)
category: This store's modules
sort: 996
---
**M2 API** lets outside systems, such as **ShipStation**, connect to the store the way they connect to Magento 2 stores. With a key they can read orders, shipments, products, categories, customers and store details, and **mark orders invoiced or shipped and add or remove tracking numbers**.

## API keys

[System > M2 API Keys](admin:m2api_apikey) lists the keys, with when each was last used.

**A new key**:

1. **Add New**; a **Name** saying what it is for (e.g. "ShipStation"); **Status** Enabled; an **Expires On** date if it should stop working (optional).
2. **Save**. The key is shown **once**, at the top of the page. Copy it into the other system now; it cannot be shown again (only its first characters are kept, as a hint).

**Stop a key**: set its **Status** to Disabled, or delete it. A system using it stops connecting at once.

> [!WARNING]
> A key gives admin-level access to orders and customers, and can mark orders shipped. Give each system its own key, so one can be stopped without the others; set an expiry where you can; disable keys nobody uses (check **Last Used**); never send a key by email or chat.

The API also accepts an admin's own user name and password in exchange for a short-lived token (24 hours by default). Every admin account is therefore an API login too: keep admin passwords strong, and remove accounts of people who have left ([[staff-users-and-permissions]]).

## Settings

[System > Configuration > Services > M2 API](admin:system_config/edit/section/ysrtech_m2api):

- **Password Token Lifetime (seconds)**: how long a token from a user name and password lasts. 86400 (24 hours).
- **Enable Debug Logging**: writes every request to `var/log/m2api.log`, `m2api_all_requests.log` (every request to the site, not only the API) and `m2api_auctane.log` (ShipStation). Off by default. Turn it on only while looking into a problem, then off again: the logs grow fast and hold order details.

## When ShipStation stops getting orders

1. Check the key in **M2 API Keys**: Enabled, not expired, **Last Used** recent.
2. Make a new key and enter it in ShipStation if in doubt; then disable the old one.
3. Turn on debug logging briefly and look at `m2api_auctane.log` ([[logs]]), or ask the developer.
