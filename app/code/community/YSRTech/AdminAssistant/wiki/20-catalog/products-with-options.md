---
title: Products with options (sizes, colours)
category: Catalog
sort: 210
---
A **configurable product** shows one page with a choice (size, colour); each choice is its own simple product with its own SKU and stock.

## Once: the attribute

1. [Catalog > Attributes > Manage Attributes](admin:catalog_product_attribute): the attribute (e.g. **size**) must have **Scope** = Global, **Catalog Input Type** = Dropdown, and **Use To Create Configurable Product** = Yes, with its options listed under **Manage Label / Options**.
2. Add it to the attribute set under [Manage Attribute Sets](admin:catalog_product_set) (drag it into a group, **Save Attribute Set**).

## The product

1. **Add Product**, choose the attribute set and **Configurable Product**, **Continue**.
2. Tick the attribute(s) the customer chooses from, **Continue**.
3. Fill in the general information as for any product and save.
4. **Associated Products** tab: use **Quick simple product creation** to make each option (it fills SKU and name), or tick existing simple products. Set a price difference per option if needed.
5. Save.

The options' simple products usually have **Visibility** = Not Visible Individually, and their own stock.
