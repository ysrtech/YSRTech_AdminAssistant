---
title: Coupon codes and cart discounts
category: Promotions
sort: 400
---
Discounts applied in the cart, with or without a code, are **Shopping Cart Price Rules**.

1. [Promotions > Shopping Cart Price Rules](admin:promo_quote) > **Add New Rule**.
2. **Rule Information**:
   - **Rule Name**; **Active**.
   - **Websites**, and **Customer Groups** (tick **NOT LOGGED IN** too, or guests will not get it).
   - **Coupon**: **Specific Coupon** and a **Coupon Code** for a code; **No Coupon** for an automatic discount.
   - **Uses per Coupon** / **Uses per Customer**: limits; empty means unlimited.
   - **From Date** / **To Date**.
3. **Conditions**: when the rule applies (e.g. **Subtotal** equals or greater than 100). Empty means always.
4. **Actions**:
   - **Apply**: **Percent of product price discount**, **Fixed amount discount** (per item), **Fixed amount discount for whole cart**, or **Buy X get Y free**.
   - **Discount Amount**; **Maximum Qty Discount is Applied To**.
   - **Apply to Shipping Amount**; **Free Shipping**.
   - **Min Stock Required** (this store): above 0, items with less stock than this get no discount, and the cart's coupon is removed. See [[stock-promo]].
   - **Stop Further Rules Processing**: no other rule applies after this one.
   - The conditions at the bottom of **Actions** limit which items are discounted (e.g. a category).
5. **Labels**: the name shown in the cart totals.
6. **Save**.

## Many unique codes

Tick **Use Auto Generation** under **Coupon**, save, then the **Manage Coupon Codes** tab: **Coupon Qty**, **Code Length**, **Code Format**, a **Code Prefix**, then **Generate**. **Export** the list as CSV.

## Did it work?

Add a product to the cart on the storefront and apply the code. [Reports > Sales > Coupons](admin:report_sales/coupons) shows use (after **Refresh Statistics**, see [[reports]]).
