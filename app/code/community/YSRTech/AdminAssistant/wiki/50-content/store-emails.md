---
title: Store emails: senders and templates
category: Content
sort: 540
---
## Who emails come from

[System > Configuration > Store Email Addresses](admin:system_config/edit/section/trans_email): the name and address of **General Contact**, **Sales Representative**, **Customer Support** and the custom senders. Each kind of email chooses one of them.

## Which emails are sent

[System > Configuration > Sales Emails](admin:system_config/edit/section/sales_email): for **Order**, **Invoice**, **Shipment**, **Credit Memo** and their comments: **Enabled**, the **Email Sender**, the **Template**, and **Send Order Email Copy To** (a staff copy).

## Changing the wording

1. [System > Transactional Emails](admin:system_email_template) > **Add New Template**.
2. **Load default template**: choose the one to change (e.g. **New Order**), **Load Template**.
3. Edit **Template Subject** and **Template Content**; keep the `{{var …}}` placeholders. **Preview Template** to check.
4. **Save Template**.
5. Choose it in **Sales Emails** (above) for that email.

> [!NOTE]
> Newsletters and campaigns are sent by Email Campaigns, not here. See [[email-campaigns-overview]]. On this store the "you are subscribed / unsubscribed" newsletter emails are switched off ([[pardes-core]]).
