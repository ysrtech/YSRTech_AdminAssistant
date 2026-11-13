---
title: Finding your way around the admin
category: Start here
sort: 20
---
## The parts of every admin page

- **The menu** along the top: Sales, Catalog, Customers, Promotions, Newsletter, CMS, Reports, System, plus menus that modules add (Email Campaigns, for example). What you see depends on your role.
- **The header buttons**: **Wiki** and **AI Assistant**.
- **Grids**: most lists (orders, products, customers) are grids. Type in the boxes under the column headings and press **Search** to filter; **Reset Filter** clears them. Tick rows and use **Actions** above the grid for mass actions. **Export** saves the grid as CSV.

## Scopes: Default, Website, Store View

OpenMage settings can differ by website and by store view (a store view is usually a language). On **System > Configuration**, the **Current Configuration Scope** switcher at the top left chooses which one you are changing:

- **Default Config** applies everywhere unless something narrower overrides it.
- A **website** or **store view** shows a **Use Default** (or **Use Website**) tick box beside each setting. Untick it to give that scope its own value.

Products and categories work the same way: switch **Store View** at the top left of the edit page to give a store view its own name or description.

> [!WARNING]
> A value set at a narrower scope wins. If a change "does nothing", check whether a website or store view overrides it.

## Saving and cache

Most changes take effect when saved. If the storefront still shows the old version, see [[cache-and-indexes]].
