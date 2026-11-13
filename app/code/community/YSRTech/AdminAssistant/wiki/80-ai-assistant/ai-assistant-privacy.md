---
title: AI Assistant: privacy and settings
category: AI Assistant
sort: 810
---
[System > Configuration > YSRTech > AI Assistant](admin:system_config/edit/section/ysrtech_adminassistant)

## The switch (off by default)

While off, nothing is sent anywhere. When on, the question, the page it was asked from, and what the assistant looks up to answer (which can include customer names, emails, addresses and orders) are sent to the AI provider.

**Never sent**: payment details, password hashes and reset tokens, API keys and secret settings, admin user records.

Quebec's Law 25 applies to personal information sent outside Quebec: read the provider's data terms before switching it on.

## Connection

**Key and Model**: Email Campaigns' AI key and model (default), or a key and model of its own (stored encrypted). OpenRouter or OpenAI; the model must support tool calling. **Maximum Model Requests Per Hour** caps spending. **Test Connection** sends one word, no store data.

## Behaviour

- **Store Procedures**: house rules in plain words, which the assistant follows when explaining how to do things.
- **Maximum Look-ups Per Question**, and how long chats and the audit log are kept (90 days by default).

## Report query

Optional read-only SQL for questions no other look-up answers: [[ai-assistant-report-query]].
