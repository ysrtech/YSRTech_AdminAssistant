---
title: Attribute Info Pages (brand and publisher pages)
category: This store's modules
sort: 992
---
**Attribute Info Pages** (DC_Catalog) gives each value of a chosen product attribute (a brand, a publisher, a series…) its own landing page on the storefront, like `/brand/3m/`: its own text, banner, logo, meta data and design, above the products with that value and the usual filters. A listing page (e.g. `/brand/`) shows all the values: "Shop by Brand".

## Which attributes have pages

[System > Configuration > Catalog > Attribute Info Pages](admin:system_config/edit/section/dc_catalog):

- **Attribute Selection**: tick the attributes that get pages.
- **Attribute URL Labels (per Store View)**: a translated word for the address, one per line as `attribute_code=word` (e.g. `brand=marque` on the French store view). Switch the scope to the store view first. Leave empty to use the attribute code. An address with the wrong word for a store view is redirected (301) to the right one.

## Editing a page

1. [CMS > Attribute Info Pages](admin:manufacturer) lists every value of the chosen attributes, per store view. There is no **Add** button: each value already has its place in the list, and its page is created the first time it is saved.
2. Click a value to edit it:
   - **General Information**: **Page Name**, **SEF URL Identifier** (the last part of the address), **Status**, **Favorite** (shown first in highlight lists), **Logo/Picture**, **External URL** and its label, **Content**.
   - **Product Page Information**: **Banner**, and **Description in product page** (shown on the product pages of that value).
   - **Custom Design**: **Custom Theme** with dates, **Layout**, **Layout Update XML**.
   - **Meta Data**: **Page Title**, **Meta Keywords**, **Meta Description**.
   - **Store View Translations**: each store view's own content, page title, meta keywords, meta description and product page description. Tick **Use Default Value** to use the default store view's text. Badges show which store views are translated.
3. **Save Page** (or **Save And Continue Edit**).

A new value added to the attribute ([[attributes]]) appears in the list by itself.
