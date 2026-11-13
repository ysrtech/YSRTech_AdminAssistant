---
title: Send a campaign
category: Email Campaigns
sort: 710
---
1. [Email Campaigns > Campaigns](admin:ysrtech_campaign) > **New Campaign**.
2. Give it a name (for staff), a **subject**, and the preview text shown after the subject in the inbox.
3. Choose who gets it: all subscribers, or one or more segments, with segments to leave out ([[email-campaigns-segments]]). The page shows how many people it would reach.
4. Write the email: start from a template ([[email-campaigns-templates]]) or design it in the campaign.
5. Send yourself a test and read it on a phone as well as a computer.
6. Run the checks. A campaign is checked before it can be scheduled: subject, links, unsubscribe link, sender. Fix anything it reports.
7. **Schedule**: pick the date and time (it defaults to today) and confirm. The campaign shows **Scheduled for…** with a countdown.

At the scheduled time cron starts sending. The campaign moves to **Sending**, then **Sent**.

## After sending

- While it sends: **Pause sending**, or **Cancel campaign** (nobody else gets it; emails already sent stay sent).
- Results: the campaign's report (delivered, real opens, clicks, bounces, complaints, orders and revenue credited) and the [[email-campaigns-dashboard]].
- The AI Assistant answers "how did the last campaign do?", "who clicked it?", "did jane@example.com open it?".

> [!TIP]
> Write your store's own checklist here: who approves campaigns, what day and time they go out, which segment to leave out.
