---
title: AI Assistant: permissions
category: AI Assistant
sort: 820
---
In [System > Permissions > Roles](admin:permissions_role) > a role > **Role Resources**:

- **AI Assistant (use the chat)**: may open the chat at all.
- **What the assistant may look up**, each separately:
  - **Orders, shipments, invoices, credit memos**
  - **Customers**
  - **Catalogue and stock**
  - **Sales figures**
  - **Email Campaigns results**
  - **Store settings, cron and logs**
  - **Report query (read-only SQL)**
  - **How-to help** (the admin menu, where settings are, and this wiki)
- **Wiki (read)** and **Write, edit and delete pages** (separate from the assistant).
- **System > AI Assistant Audit Log**: may read the log of what was asked.

The assistant is only offered the look-ups the person's role allows, and refuses any other. A role with the chat but no look-ups can open it and get nothing from the store.
