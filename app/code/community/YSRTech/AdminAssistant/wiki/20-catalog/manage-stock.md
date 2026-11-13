---
title: Manage stock
category: Catalog
sort: 220
---
## One product

[Catalog > Manage Products](admin:catalog_product) > the product > **Inventory** tab:

- **Qty**: how many are in stock.
- **Stock Availability**: In Stock / Out of Stock. Out of stock products cannot be bought.
- **Backorders**: allow orders when the quantity is 0 or below.
- **Notify for Quantity Below**: the level at which the product counts as low.
- **Manage Stock**: No means the quantity is not tracked at all.

> [!NOTE]
> On this store, saving a product with a quantity above 0 sets it **In Stock** by itself ([[update-stock-availability]]). It never sets a product out of stock.

## Many products

On the products grid, tick products, choose **Update Attributes** in **Actions**, and change the **Inventory** tab there.

## What is low

[Reports > Products > Low Stock](admin:report_product/lowstock) lists products below their notify level. The AI Assistant answers "which products are low on stock?".

## Where stock comes from here

Stock and prices are synced from the POS several times a day. See [[pos-sync]]. A quantity changed by hand can be overwritten by the next sync.

Store-wide defaults are under [System > Configuration > Catalog > Inventory](admin:system_config/edit/section/cataloginventory).
