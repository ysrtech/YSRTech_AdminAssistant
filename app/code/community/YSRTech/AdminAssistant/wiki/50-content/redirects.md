---
title: Redirect an old address
category: Content
sort: 530
---
When a product, category or page moves, send the old address to the new one:

1. [Catalog > URL Rewrite Management](admin:urlrewrite) > **Add URL Rewrite**.
2. **Create URL Rewrite**: **Custom**.
3. **Store**, **Request Path** (the old address, without the domain, e.g. `old-page.html`), **Target Path** (the new one).
4. **Redirect**: **Permanent (301)**.
5. **Save**.

Changing a product's or category's **URL Key** offers **Create Permanent Redirect for old URL** on the edit page; leave it ticked.
