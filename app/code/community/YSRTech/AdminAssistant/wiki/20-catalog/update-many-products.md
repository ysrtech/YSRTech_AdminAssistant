---
title: Change many products at once
category: Catalog
sort: 250
---
## From the grid

On [Catalog > Manage Products](admin:catalog_product), filter the grid, tick the products (or **Select All**), then choose in **Actions**:

- **Change status**: enable or disable.
- **Update Attributes**: change any field (price, special price, description, inventory, websites) on all ticked products at once. Only the fields you tick **Change** for are changed.
- **Delete**.

## From a spreadsheet

[System > Import/Export > Export](admin:export) saves products as CSV. Edit it and bring it back with [Import](admin:import):

1. **Entity Type** Products, **Import Behavior** Append Complex Data (adds and updates), choose the file.
2. **Check Data** first. Fix any rows it reports.
3. **Import**.

> [!WARNING]
> **Replace Existing Complex Data** and **Delete Entities** remove data. Export a copy before any import.
