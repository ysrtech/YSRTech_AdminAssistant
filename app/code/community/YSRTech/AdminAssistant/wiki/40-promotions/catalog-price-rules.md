---
title: Sale prices by rule (catalog price rules)
category: Promotions
sort: 410
---
**Catalog Price Rules** lower the price shown on product and category pages, for products matching conditions: a category, an attribute, a price range.

1. [Promotions > Catalog Price Rules](admin:promo_catalog) > **Add New Rule**.
2. **Rule Name**, **Active**, **Websites**, **Customer Groups** (include **NOT LOGGED IN**), **From Date** / **To Date**, **Priority**.
3. **Conditions**: e.g. **Category is 12** or an attribute. The attribute must have **Use for Promo Rule Conditions** = Yes.
4. **Actions**: **Apply** (By Percentage of the Original Price, By Fixed Amount, To Percentage…, To Fixed Amount) and **Discount Amount**; **Stop Further Rules Processing**.
5. **Save and Apply**.

> [!WARNING]
> A rule only changes prices after it is applied. **Save and Apply**, or **Apply Rules** on the rules list. Rules with dates are re-applied each night by cron, so cron must be running ([[cron]]).

For one product's sale price, use **Special Price** instead ([[prices-and-special-prices]]).
