---
title: Cache and indexes: when changes do not show
category: Settings & system
sort: 640
---
## Cache

OpenMage keeps ready-made copies of settings, layouts and pages. [System > Cache Management](admin:cache):

- A yellow message "One or more of the Cache Types are invalidated" means something changed: tick the invalidated types and **Refresh**, or click **Flush Magento Cache**.
- **Flush Catalog Images Cache** rebuilds resized product pictures (use after replacing images with the same name).
- **Flush Cache Storage** clears everything in the cache store, including anything else that shares it. Prefer **Flush Magento Cache**.

## Indexes

Indexes are prepared tables for prices, categories, search and URLs. [System > Index Management](admin:process/list):

- **Ready**: up to date. **Reindex Required**: tick it, choose **Reindex Data** in **Actions**, **Submit**.
- **Update on Save** keeps an index current as products are saved; **Manual Update** waits for a reindex.

A product missing from its category or search, or an old price on the storefront, is usually an index or the cache.

> [!TIP]
> Ask the AI Assistant "are any caches off or indexes not ready?".
