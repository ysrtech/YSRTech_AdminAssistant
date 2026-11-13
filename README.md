# YSRTech_AdminAssistant

An AI chat on every page of the OpenMage (Magento 1 LTS) admin. Staff ask
about the store in plain language ("how many orders shipped yesterday", "top
20 customers this year", "what's wrong with order 100045123", "how did last
week's campaign do") and it answers from live store data. **It reads only;
it cannot change anything.**

## How it works

The header's search box is replaced by the assistant's field. Type a question
and press Enter to open the side drawer and ask it, press the ✦ icon to open
the drawer, or press Ctrl/⌘ K to jump to the field. (While the assistant is
switched off, the search box stays, and only the icon is added beside it for
those who can turn it on. Anyone who can't see the assistant keeps the
search box.) A
question goes to the AI provider along with the list of look-ups (tools) the
person asking is allowed to use. The model asks for look-ups, the store runs
them with that admin's permissions, the results go back, and the answer
streams into the drawer. Everything runs inside the store; no separate
server is involved.

- **It knows the page you're on.** On an order, customer, product or
  campaign page, "this order" or "this customer" means that one.
- **Chats are kept**, one list per admin, with a **New chat** button. They
  are deleted after the retention period in the settings (90 days by default).

## What it can look up

| Area | Tools | Permission |
|---|---|---|
| Orders | search and count, full details (items, addresses, status history, shipments with tracking, invoices, credit memos, plus automatic "what looks wrong" notes), shipments in a period, invoices or refunds in a period | Orders |
| Customers | search, details (lifetime value, orders, group, newsletter), top customers by revenue or orders, groups | Customers |
| Catalogue | product search, product details with sales, low stock, best sellers, categories | Catalogue and stock |
| Sales | revenue, net revenue, orders, AOV and more for any period, broken down by day, week, month, store, status, payment method, country and so on; one period against another | Sales figures |
| Email Campaigns (only when that module is installed) | campaign list and results with credited revenue, the 7/14/30-day email overview, segments, list growth | Email Campaigns results |
| Store | setting values (secrets excluded), cron status, recent `system.log` / `exception.log` entries, store overview | Store settings, cron and logs |
| Report query | read-only SQL over an allowed list of tables, for anything else | Report query |

## Permissions

Under **System > Permissions > Roles**, **AI Assistant (use the chat)** lets a
role open the chat at all, and each area under **What the assistant may look
up** maps to the tools above. The chat offers a person only the tools their
role allows, and refuses any other tool even if the model asks for it. The
audit log is its own permission, under System.

## Privacy, and what is never sent

The assistant is **off by default**. Turning it on sends the question, the
page it was asked from, and whatever is looked up to answer it (which can
include customer names, emails, addresses and orders) to the AI provider.
The settings page says this in full. **Quebec's Law 25 applies:** read the
provider's data terms (retention, training) before switching it on.

Email Campaigns' own AI setting promises that no customer data is sent. That
promise is unchanged; this module has its own switch.

**Never sent:** payment details, password hashes and reset tokens, API keys
and other secret settings, admin user records. Tools select columns by name
and never read the payment table beyond the method's name. A sanitizer then
removes anything whose key looks secret from every result. Encrypted and
password settings are withheld, and likely credentials are blanked out of
log lines.

**Prompt injection:** text written by customers or anyone outside staff
(order comments, notes, names, addresses, log lines) reaches the model
wrapped as `{"untrusted_text": "..."}`, and the system prompt says it is
data, never instructions. No tool can write anything, so nothing in a tool
result can lead to a change. Each tool also runs inside a read-only
database transaction, so even a bug cannot write.

## The report query

Off until set up, under **System > Configuration > YSRTech > AI Assistant >
Report Query**. It runs as a **separate database user that can only read**.
The settings page shows the `CREATE USER` / `GRANT SELECT` statements, table
by table, to run as a database administrator. Then:

- Before every query, the user's grants are read. A user that can change
  anything is refused, and so is the store's own database user.
- Only a single `SELECT` is accepted: no `WITH`, comments, variables,
  double-quoted strings, backslashes, `INTO`, locking, `SLEEP`/`BENCHMARK`
  or `LOAD_FILE`. The tables come from an allowed list: no payment,
  settings, admin, API or OAuth tables, and not `customer_entity_varchar`
  (where password hashes live). Columns such as `password_hash`,
  `rp_token`, `protect_code` and `tracking_token` are refused wherever they
  appear, and dropped from `SELECT *` results.
- Each query runs in a read-only transaction with a statement time limit
  (5 seconds by default) and a row cap (200 by default).

## Settings

**System > Configuration > YSRTech > AI Assistant**

- **Privacy and Switch**: on/off, with the statement of what is sent.
- **AI Connection**: use Email Campaigns' AI key and model (the default,
  when that module has them), or a key and model of this module's own
  (stored encrypted). OpenRouter or OpenAI; the model must support tool
  calling. There is a ceiling on requests per hour, and **Test Connection**
  sends a one-word request with no store data.
- **Behaviour**: the most look-ups one question may take, and how long
  chats and the audit log are kept.
- **Report Query**: as above, with **Check Database User**.

## Audit log

**System > AI Assistant Audit Log** lists every question with who asked it,
every look-up with its input and rows returned, and every AI request with
tokens and the cost the provider reports. It is kept apart from the chats,
so deleting a chat does not delete its record.

## Installation

Copy `app/` and `skin/` into the OpenMage root, clear the cache, and log out
and in again. Cron (daily) deletes old chats and audit lines.

## Development

### Staging

```bash
./dev/staging/setup.sh
```

This builds on Email Campaigns' staging setup, so that checkout must be a
sibling directory (or set `EMAILCAMPAIGNS_DIR`). It links both modules into a
throwaway OpenMage and seeds about 400 orders with lines, addresses,
invoices, shipments and refunds, plus two planted orders: `100045123`
(invoiced, never shipped, backordered) and `100045200` (a customer note that
tries to instruct the assistant). It then switches the assistant on and
creates the read-only report user.

The AI provider on staging is a **scripted stand-in**
(`dev/staging/standin/`, staging only, never deployed). It chooses a tool
from keywords and writes its answer from the tool's real result, so the
whole pipeline can be driven in a browser with no key and nothing sent
anywhere. To try a real model, run `./dev/staging/link.sh` without
`--standin` and enter a key in the settings.

### Tests

From the OpenMage root of the staging install:

```bash
php <module>/dev/tests/guard.php    # report-query guard: allowed and refused SQL
php <module>/dev/tests/report.php   # report query on a real read-only user: grants, cap, time limit
php <module>/dev/tests/tools.php    # every tool; no secrets in any result; ACL; tools cannot write
php <module>/dev/tests/agent.php    # the question loop with a scripted provider: streaming, history, audit, limits
```

From the module directory:

```bash
node dev/tests/e2e.js               # Playwright: real questions in the admin, a limited role, the switch off
```

None of them sends anything to an AI provider or any email.
