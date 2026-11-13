---
title: Order statuses explained
category: Orders
sort: 100
---
Every order has a **state** (what OpenMage itself tracks) and a **status** (the label shown, which can be renamed). The usual path:

| Status | What it means | What can happen next |
|---|---|---|
| **Pending** | Placed, not paid or not invoiced yet | Invoice, cancel, hold |
| **Pending Payment** | Waiting for an online payment to come back from the gateway | Usually completes by itself; if it stays for a day, the payment was probably abandoned |
| **Processing** | Invoiced, or partly shipped | Ship, refund, hold |
| **Complete** | Invoiced and shipped | Refund (credit memo) |
| **Closed** | Fully refunded | Nothing more |
| **Canceled** | Canceled before invoicing | Nothing more (reorder makes a new order) |
| **On Hold** | Held by staff; nothing can be done until released | **Unhold** |
| **Payment Review** | The payment provider flagged it | Accept or deny the payment |

## Where to see it

[Sales > Orders](admin:sales_order): the grid's **Status** column, and the top of each order's page. The **Comments History** at the bottom of an order shows every change.

> [!TIP]
> Ask the AI Assistant "what's wrong with order 100000123?" to get the order's status history and anything that looks stuck.

Statuses can be added or renamed under [System > Order Statuses](admin:sales_order_status).
