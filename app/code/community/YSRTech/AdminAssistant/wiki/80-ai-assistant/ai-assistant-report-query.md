---
title: AI Assistant: the report query
category: AI Assistant
sort: 830
---
The report query lets the assistant answer questions no other look-up covers by writing its own read-only SQL. It is off until set up.

## Setting it up

1. [System > Configuration > YSRTech > AI Assistant > Report Query](admin:system_config/edit/section/ysrtech_adminassistant): **Enable Report Query** = Yes.
2. **Tables the Report Query May Read**: **Recommended tables** (orders, customers, catalogue, campaigns), or **Choose tables** to tick others. Payment, admin user, API, settings, session and password-holding tables can never be chosen.
3. A database administrator runs the statements shown under **Database User to Create** (with a long password of their own). They create a user that can only read the chosen tables.
4. Enter that user's name and password, save, and press **Check Database User**. It confirms the user can read and change nothing, and names any chosen table it cannot read yet.

## What keeps it safe

- The user must be read-only; one that can change anything is refused, and so is the store's own database user.
- Only a single SELECT on the chosen tables; columns holding secrets are refused in any table.
- A time limit (5 seconds) and a row limit (200).
