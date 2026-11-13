---
title: Guest Cart Viewer (abandoned guest carts)
category: This store's modules
sort: 994
---
OpenMage's abandoned carts report lists carts left behind by guests as well as by customers, but only a customer's row could be opened. **Guest Cart Viewer** makes every row open:

1. [Reports > Shopping Cart > Abandoned Carts](admin:report_shopcart/abandoned).
2. Click a row:
   - **A customer's cart** opens the customer's page on the **Shopping Cart** tab.
   - **A guest's cart** opens **Abandoned Cart Items**: the cart's number, the guest's email if they gave one at checkout, and each item with product ID, name, SKU, quantity, price and subtotal.

If a cart has since become an order or been removed, the page says so and goes back to the report.

It uses the abandoned carts report's own permission. Nothing to set up.

> [!TIP]
> Email Campaigns can email people who leave a cart behind automatically: see [[email-campaigns-automations]] (trigger **Left a cart behind**).
