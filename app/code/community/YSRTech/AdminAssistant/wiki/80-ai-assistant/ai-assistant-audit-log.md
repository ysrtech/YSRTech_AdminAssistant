---
title: AI Assistant: the audit log
category: AI Assistant
sort: 840
---
[System > AI Assistant Audit Log](admin:ysrtech_assistantaudit) lists every question: who asked it and when, every look-up the assistant made for it with its input and the number of rows returned, and the AI requests with tokens and the cost the provider reports.

- Filter by person or by words in the question.
- It is kept apart from the chats: deleting a chat does not remove its record.
- Lines older than the retention setting (90 days by default) are removed each night by cron.
