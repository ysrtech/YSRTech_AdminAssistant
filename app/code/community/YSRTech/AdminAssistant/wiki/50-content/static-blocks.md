---
title: Static blocks
category: Content
sort: 510
---
A static block is a piece of content (a banner, a notice, a footer column) shown inside other pages.

1. [CMS > Static Blocks](admin:cms_block) > **Add New Block**: **Block Title**, **Identifier** (e.g. `free-shipping-banner`), **Store View**, **Status**, **Content**. **Save Block**.
2. Show it:
   - In a CMS page or another block: **Insert Widget** > **CMS Static Block**, or write `{{block type="cms/block" block_id="free-shipping-banner"}}`.
   - On a category page: the category's **Display Settings** > **CMS Block**.
   - On chosen pages site-wide: a widget instance ([[widgets]]).

To hide it, set **Status** = Disabled; it disappears everywhere it is used.
