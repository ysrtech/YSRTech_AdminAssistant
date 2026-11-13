---
title: Guest To Customer
category: This store's modules
sort: 900
---
**What it does**: attaches guest orders to the customer account with the same email on the same website. It acts when a guest places an order and when someone registers after ordering as a guest. If switched on, it can also create accounts for guests who have none and attach their orders.

Accounts it creates are made quietly: no email is sent, the password is random (the customer gets in by resetting it, or by email code with [[otp-login]]), and the store's default customer group is used. Only the order's customer, guest flag and customer group change.

## Settings

[System > Configuration > YSRTech > Guest To Customer](admin:system_config/edit/section/ysrtech_guesttocustomer) (Default or Website scope):

| Setting | Default | |
|---|---|---|
| General > **Enabled** | Yes | Master switch |
| Triggers > **Link On Order Placement** | Yes | Link a guest order to a matching account |
| Triggers > **Link On Customer Registration** | Yes | Link earlier guest orders when someone registers |
| Accounts For Guests > **Auto-Generate Account For New Emails** | No | Create an account when no account matches |
| **Create As Each Guest Order Is Placed** | Yes | Otherwise only when the button below is used |
| **Copy The Order Addresses** | Yes | Becomes the new account's default addresses |
| **Subscribe New Accounts To The Newsletter** | No | Only if customers agreed to marketing email |

## The buttons

- **Apply To All Existing Guest Orders**: links (and, if switched on, creates accounts for) all earlier guest orders, 100 at a time. Save the settings first; keep the page open while it runs; safe to stop and run again.
- **Subscribe All Customers Who Never Subscribed**: adds every customer who was never on the newsletter, 500 at a time. People who unsubscribed are skipped. No email is sent.

Log: `var/log/ysrtech_guesttocustomer.log`. Errors never stop an order or a registration.
