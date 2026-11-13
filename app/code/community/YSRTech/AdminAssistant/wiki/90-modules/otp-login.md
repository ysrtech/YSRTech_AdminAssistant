---
title: Email OTP Login
category: This store's modules
sort: 910
---
**What it does**: customers sign in, or create an account, with a code emailed to them instead of a password. Google Sign-In can be added. Sign-in can be required before checkout. Customers effectively have no password: the change-password options are removed.

## Settings

[System > Configuration > YSRTech > Email OTP Login](admin:system_config/edit/section/ysrtech_otplogin):

- **Enabled**: on this store it is off unless switched on here ([[pardes-core]]); check what the page shows.
- **Allow Registration via OTP**; **Create Account Form**: the store's own page (password fields removed, email confirmed by code) or a popup.
- **Require Sign-in at Checkout**: guests must sign in with a code before ordering.
- The code: **OTP Type**, **OTP Length** (6), **Expiry Time** (300 seconds), **Wrong Guesses per Code** (5).
- Limits: **Codes per Email Address** (5), **Codes per IP Address** (20) within the **Request Limit Window** (15 minutes).
- **Google Sign-In**: **Client ID**, **Client Secret** (secret), and the redirect address shown, to enter in Google Cloud.
- **Email Sender** and **Email Template**.

## When a customer cannot sign in

1. Check the address they type is the one on their account ([[find-and-edit-a-customer]]).
2. Ask them to check spam for the code, and to use the newest code (each new one replaces the last).
3. Too many requests: they must wait out the limit window.
4. If nobody gets codes, store email is not working: [[store-emails]].

For an address with no account, the reply is the same generic message and no code is sent (so nobody can test which emails have accounts).

Old codes are deleted each night by cron.
