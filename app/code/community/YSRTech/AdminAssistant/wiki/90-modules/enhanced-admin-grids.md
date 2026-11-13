---
title: Enhanced Admin Grids (customizable grids)
category: This store's modules
sort: 990
---
**Enhanced Admin Grids** (YSRTech's maintained version of BL CustomGrid) lets staff change the admin's grids: which columns show and in what order, extra columns from product or customer attributes, the default filters and sorting, and values edited straight in the grid. Long dropdowns get a search box.

## On a grid

Grids it manages get a row of buttons above them:

| Button | What it does |
|---|---|
| **Columns List** | Show or hide columns, change their order, header, width and alignment, and make a column **Editable** |
| **Custom Columns** | Add columns from attributes and other data (**Add Attribute Column**) |
| **Default Parameters** | Save the current page size, sorting and filters as what the grid opens with |
| **Export** | Export the grid's results |
| **Grid Infos** | Details of the grid |
| **Edit Grid** | The grid's full settings page |

Changes apply to the grid for everyone using the same **profile**.

## Profiles

A grid can have several **profiles**: saved layouts of columns and defaults (for example one for the warehouse, one for customer service). **Choose Profile:** above the grid switches between them. Profiles can be restricted and assigned to roles, so a role sees its own layout.

## Editing values in the grid

Where a column is made **Editable** (in **Columns List**), its values can be changed in the grid itself without opening each record. Who may do this is a permission (below).

## Searchable dropdowns

Filters and editors with long option lists (order status, attribute values, stores, customer groups) get a search field above the dropdown: type to narrow the options (several words keep options matching all of them). **Enter** picks the only remaining option and applies the filters; **Esc** clears the search. The product edit form gets the same search.

## Managing grids

[System > Custom Grids > List](admin:blcg_grid) lists every grid it knows (a grid is added the first time it is opened), where each can be enabled or disabled, given its own defaults and role permissions, or deleted.

> [!TIP]
> If a grid looks wrong after a module update (columns missing or in the wrong place), deleting that grid's record from the list resets it: it is set up again, plainly, the next time the grid is opened. Email Campaigns did this itself for the three pages that became card pages.

## Settings

[System > Custom Grids > Base Configuration](admin:system_config/edit/section/customgrid):

- **General**: grids to leave alone (**Global Exclusions**), **Use drag'n'drop for sorting columns**, and the searchable dropdowns (**Add A Search Field To Long Dropdowns**, from more than **10** options by default; their style; which admin forms get them; **Allow Adding Values From The Product Form**).
- **Customization Parameters - Default Values**: the **Custom Pagination Values** and **Default Pagination Value**, pinning the pager and mass-actions, hiding the original export block or filter reset button.
- **Default Parameters Behaviours** and **Profiles - Default Values**.

**Rewriters Configuration** and **Editors Configuration** are technical; leave them to the developer.

## Permissions

In [System > Permissions > Roles](admin:permissions_role), **Custom Grids** has separate rights for customizing columns, editing default parameters, exporting, the columns editor (**Choose Editable Columns**, **Edit Columns Values**), profiles (access, choose defaults, copy, edit, assign to roles, delete) and grid administration.

> [!WARNING]
> **Edit Columns Values** lets a role change data straight from grids. Give it only to roles that should edit those records.
