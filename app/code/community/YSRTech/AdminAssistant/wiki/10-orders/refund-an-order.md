---
title: Refund an order (credit memo)
category: Orders
sort: 130
---
A refund in OpenMage is a **credit memo**. It records the money going back and, if you choose, puts the items back in stock.

## Which button

- **Paid online by card, refund through the gateway**: open the **invoice** (order's **Invoices** tab), then **Credit Memo** there. The refund is sent to the payment provider (**Refund**).
- **Paid offline, or refunding outside the store** (cash, cheque, e-transfer, refunded in the gateway's own site): click **Credit Memo** on the **order** page. This records the refund only (**Refund Offline**).

## Steps

1. Open the order, then **Credit Memo** (see above).
2. Under **Items to Refund**, set **Qty to Refund** for each item. Tick **Return to Stock** if the items are going back on the shelf.
3. Click **Update Qty's** after changing quantities.
4. Check the totals: **Refund Shipping**, **Adjustment Refund** (extra money back), **Adjustment Fee** (money kept, e.g. a restocking fee).
5. Add a comment if useful; tick **Email Copy** to tell the customer.
6. Click **Refund** or **Refund Offline**.

A fully refunded order becomes **Closed**. Credit memos are listed under [Sales > Credit Memos](admin:sales_creditmemo).

> [!NOTE]
> On this store, the **YSRTech CoreMageFixes** module fixes an OpenMage bug where **New Credit Memo** could fail to open for an order with free shipping or an already-refunded item. If that page ever shows an error, tell the developer. See [[core-mage-fixes]].

> [!TIP]
> Write your store's refund rules here (who may refund, limits, what to check first). The AI Assistant follows them.
