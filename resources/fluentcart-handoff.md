# FluentCart Add-On — Handoff

## The big idea

Use the **free** version of FluentCart and add only the features I need, built the way I want them, as my own add-on plugin. I'm not buying FluentCart Pro for a pile of features I'll never use.

This is the same approach I already use with FluentCommunity: about 20 custom features, all built as add-ons that plug into the hooks FluentCommunity provides, and none of them has ever broken on an update.

The goal is to replace my current setup (SureCart for checkout and AutoKit for the steps after purchase) with FluentCart free plus this add-on.

---

## Ground rules (not negotiable)

1. **Never modify FluentCart's code.** Everything goes in our own add-on plugin, using FluentCart's actions, filters, REST API and module system.
2. **Never modify `wp-config.php` or server files.** Any settings go on an admin settings page that saves to the database.
3. **Work from the real source code.** Read the FluentCart free plugin's code (it will be in the repo) before building anything. Confirm the hooks and data structures exist. Don't guess from the docs alone.
4. **Pick the simplest way that works.** If FluentCart already does something, use it instead of rebuilding it.
5. **Test and verify everything**, especially anything that touches payments, before it goes live.

---

## Reference

- Developer docs: https://dev.fluentcart.com/ (hooks: https://dev.fluentcart.com/hooks)
- The free plugin is documented as having 315+ hooks, a full REST API and a module system (`fluentcart_loaded`, `$app->addModule(...)`).
- Already free in core: subscriptions, free trials, coupons, setup fees, subscription bump offers, Stripe and PayPal.
- Pro-only (according to their listing): licensing, installments, plan upgrades with prorated charges, failed-renewal retries, upgrade paths, bundles, R2 storage, reports past 30 days.

---

## What I want built (in priority order)

### 1. Custom checkout forms (top priority; doesn't exist anywhere in FluentCart)

**The problem:** FluentCart has one checkout page. A custom field added there shows up on every checkout for every product.

**What I want:** I create as many checkout forms as I want. Each form has its own settings:

- **Product:** which product it sells.
- **Variations:** which of that product's pricing options appear on this form.
- **Fields:** which extra fields to collect, on this form only.
- **Placement:** goes on any page with a shortcode or block, and gets its own checkout link.

**Real example: my community product**

One product with four variations: Paid, Paid Annual, Paid Quarterly, Free.

- The public checkout form shows **only Paid Annual and Paid Quarterly**.
- Paid and Free still exist on the same product but stay hidden from that form.
- No need to create separate products just to control what shows.

**Requirements:**
- Answers from custom fields save to the order and are visible in the FluentCart admin.
- Those answers are available to automations (FluentCRM and so on).
- The form only allows its chosen variations, checked on the server, not just hidden on the page.

**Check in the source first:** where FluentCart builds the checkout fields and saves the order. The only documented field hook is `fluent_cart/checkout_address_fields`. Find out how to make the fields change based on which form the buyer came from, and how to limit the variations per form.

### 2. Free signup form (easy win)

A simple form (name and email, plus any fields I choose). When someone submits it, our add-on creates a **$0 order for the Free variation** in FluentCart. Whatever access FluentCart already grants for that product then happens on its own.

- No payment step, no full checkout page.
- No outside automation tool. This replaces what AutoKit does today.
- Should create or match the WordPress user and the FluentCart customer the same way a normal purchase would.

**Check in the source first:** how FluentCart creates orders and customers in code, and which events fire afterward (for example `fluent_cart/order/created` and `fluent_cart/cart_completed`), so a $0 order sets off the same steps a real purchase does.

### 3. Advanced subscriptions (details to be decided)

These are Pro-only features I may want built myself:
- Upgrades and downgrades between plans, with prorated charges
- Retrying failed renewal payments
- Installment plans
- Upgrade paths customers can use from their account page

**Still open:** which of these I actually need. Ask me before building any of them.

### 4. Licensing (details to be decided)

These are Pro-only features I may want built myself: license keys attached to products, expiry rules, activation limits per site, and renewals.

**Still open:** what I'd sell with license keys, and whether I need full "check the key from another website" support or just keys with expiry dates. Ask me before building.

---

## Where to start

1. Read the FluentCart free plugin source in the repo. Write up the hooks, data tables and order and checkout flow that matter for features 1 and 2.
2. Show me the plan for feature 1 (where each piece plugs in) before writing code.
3. Build feature 1, then feature 2, testing each one.
4. Come back to me with questions on features 3 and 4.
