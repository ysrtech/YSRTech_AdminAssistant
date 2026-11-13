---
title: Invoice an order
category: Orders
sort: 110
---
An invoice records that the order has been paid (or that payment is being taken). It moves the order from **Pending** to **Processing**.

1. Open the order from [Sales > Orders](admin:sales_order).
2. Click **Invoice** at the top.
3. Check the quantities in **Items to Invoice**. Lower a quantity to invoice only part of the order.
4. For a card payment taken online, choose **Capture Online** under **Amount** if the payment was only authorised. For offline methods (cheque, bank transfer, cash on delivery) choose **Capture Offline** or leave it.
5. Tick **Create Shipment** to ship in the same step, and **Email Copy of Invoice** to send it to the customer.
6. Click **Submit Invoice**.

Invoices are listed under [Sales > Invoices](admin:sales_invoice). **Print** on an invoice gives a PDF; the **Actions** menu on the orders grid prints several at once.

> [!NOTE]
> Some payment methods create the invoice by themselves when the payment succeeds. If **Invoice** is not shown, the order is already fully invoiced.
