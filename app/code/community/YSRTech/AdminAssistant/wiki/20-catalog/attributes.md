---
title: Product attributes and attribute sets
category: Catalog
sort: 260
---
**Attributes** are the fields of a product (author, size, publisher). **Attribute sets** are which fields a kind of product has.

- New field: [Catalog > Attributes > Manage Attributes](admin:catalog_product_attribute) > **Add New Attribute**. Set the **Attribute Code** (lower case, no spaces; cannot change later), **Scope**, **Catalog Input Type**, and on **Manage Label / Options** the label and choices.
  - **Use in Layered Navigation** shows it as a filter on category pages.
  - **Visible on Product View Page on Front-end** shows it in the product's details.
  - **Use for Promo Rule Conditions** lets price rules use it.
- Add it to a set: [Manage Attribute Sets](admin:catalog_product_set) > the set > drag the attribute from **Unassigned Attributes** into a group > **Save Attribute Set**.

After changing filterable attributes, reindex ([[cache-and-indexes]]).
