---
title: Showcase Widgets (product rows and category tiles)
category: This store's modules
sort: 920
---
Two widgets for CMS pages and blocks, such as the home page. Add them with **Insert Widget** in the editor, or as widget instances ([[widgets]]).

## Product Showcase Row

A row of products; it becomes a carousel when there are more than fit across.

- **Products come from**: a category, chosen products (by SKU), on sale, new arrivals, or recently viewed by this visitor.
- **Category** (picker or ID) or **SKUs** (one per line; missing or disabled ones are skipped).
- **Heading** (empty: the category name, "Sale", "New Arrivals"…), **How many products** (16), **Order**.
- **Hide the row below this many products** (2; 1 always shows it).
- The **"view all"** link, its target and wording (a SKU list has no default target: set one or turn it off).
- Sale / New ribbons, publisher, prices, **Image width** (250).
- **Cache for (seconds)**: empty means no caching; random order is never cached.

## Category Showcase

Category tiles: **With images** (up to 8 across) or **Text only** (4 across). Categories by IDs, or all under a parent category; **Skip categories with no products**; thumbnail size; **Cache for (seconds)** (3600 is usually safe).

Category IDs are shown on each category's edit page ([[categories]]).
