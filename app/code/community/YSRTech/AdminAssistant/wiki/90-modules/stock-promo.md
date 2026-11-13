---
title: Stock Promo (minimum stock on discounts)
category: This store's modules
sort: 940
---
Adds **Min Stock Required** to shopping cart price rules, on the **Actions** tab between **Free Shipping** and **Stop Further Rules Processing**.

- **0** (default): off.
- **Above 0**: an item whose stock is below this number gets no discount from the rule, and the cart's coupon code is removed.

Use it to keep a promotion from emptying the last few copies of a product.

> [!NOTE]
> Removing the coupon affects the whole cart, not only the low-stock item. The largest value accepted is 32767.

See [[coupons-and-cart-rules]].
