---
title: Writing wiki pages
category: Start here
sort: 30
---
## Good pages

- One task or one topic per page, titled the way people ask: "Refund an order", not "Credit memo functionality".
- Steps as a numbered list, with the exact names of menus, buttons and fields in **bold**.
- Say what to check afterwards, and what can go wrong.
- Keep store-specific rules on the page they belong to (for example, a refund approval rule on the refund page).

## Formatting

| Write | To get |
|---|---|
| `## Heading` | A section heading. Pages with three or more get an "On this page" list |
| `**bold**`, `*italic*`, `` `code` `` | Emphasis, and field or button names |
| `1. Step` / `- point` | Numbered steps / bullets (indent two spaces to nest) |
| `[[credit-memos]]` or `[[credit-memos|Refunds]]` | A link to a wiki page by its address |
| `[Credit Memos](admin:sales_creditmemo)` | A link into this admin, by route |
| `[Site](https://example.com)` | An outside link |
| `> [!TIP]`, `> [!NOTE]`, `> [!WARNING]` | A coloured box (the first line of a quote) |
| a header row, then `|---|---|` | A table |
| `![picture](/media/wysiwyg/picture.png)` | An image from the media folder |

### Admin links

The route is the part of the admin address after `/admin/`, without the secret key: `sales_order` is Sales > Orders, `catalog_product` is Manage Products, `system_config/edit/section/carriers` is the Shipping Methods settings. The wiki adds the admin's secret key itself, so the link works for everyone who may open that page.

### Page addresses

The address is made from the title when the page is first saved (`Refund an order` becomes `refund-an-order`). Changing it later breaks `[[links]]` to the old address, so leave it unless needed.

## History

Each save keeps a copy. Use the short "What changed" note when saving; it shows in the history. Two people editing at once cannot overwrite each other: the second save is refused with a message, and their text is kept in the editor.
