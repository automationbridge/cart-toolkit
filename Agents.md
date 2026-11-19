# Cart Toolkit — Architecture Reference

> Read this at the start of every conversation. Part 1 is the FluentCart source reference that the plugin is built on.
> Part 2 is the plugin itself. Paths in Part 1 are relative to `resources/fluent-cart/` (FluentCart 2.3.2, identical
> to the wordpress.org zip, checked 2026-11-18).

## Plugin Identity

| Field | Value |
|---|---|
| Name | Cart Toolkit |
| Slug | `cart-toolkit` |
| Version | 0.3.2 |
| Requires | FluentCart (free) 2.3.2+ |
| Class prefix | `CTK_` |
| GitHub | `automationbridge/cart-toolkit` (public since 0.2.0; updater `'public' => true`, no key needed) |
| Installed on | saileapps.com (testing, FluentCRM). Members site later (Groundhogg via WP Fusion). |

---

# Part 1 — FluentCart source reference

## Checkout rendering

- Checkout page setting: `checkout_page_id` (`api/StoreSettings.php:1863`, `:1887`).
- Shortcode `[fluent_cart_checkout]`: `app/Hooks/Handlers/ShortCodes/Checkout/CheckoutPageHandler.php:25-159`.
  Block `fluent-cart/checkout`: `app/Hooks/Handlers/BlockEditors/Checkout/CheckoutBlockEditor.php:83-205`.
- **`fluent_cart/checkout/render_cart`** `(null, ['surface','attributes','block'])` lets an add-on supply the cart
  both surfaces draw (`CheckoutPageHandler.php:43`, `CheckoutBlockEditor.php:104`).
- `CheckoutRenderer::renderCustomProductCart($cart, $context)` (`app/Services/Renderer/CheckoutRenderer.php:164-176`)
  draws a given cart. `fluent_cart/checkout/render_custom_product_checkout` (`:151`) is used by Pro for
  `variant_ids` attributes, so Cart Toolkit uses its own attribute names.
- **One checkout per page:** the JS UI service and `fluentcart_checkout_vars` are singletons.

## Carts and the cart hash

- Instant checkout: `?fluent-cart=instant_checkout&item_id={variation}&quantity=1` (`app/Http/Routes/WebRoutes.php:72-224`).
  Builds a cart in group `instant` (`api/Resource/FrontendResource/CartResource.php:49-156`), redirects to checkout
  with `fct_cart_hash` and keeps any other query params.
- **Any variation of any published product can be bought this way.** Hiding a variation on a page does not stop this.
- Later requests find the cart by `fct_cart_hash` (`Helper::INSTANT_CHECKOUT_URL_PARAM`). Only groups listed by
  `fluent_cart/cart/hash_resolvable_cart_groups` (default `['instant']`, `CartResource.php:197`) resolve by hash.
- The checkout JS sends `fct_cart_hash` only when the page URL has it (`AssetLoader.php:727-732`). A form page must
  therefore be on a URL carrying the hash, or every AJAX call hits the shopper's own basket.
- Custom routes: `?fluent-cart={x}` runs `do_action('fluent_cart_action_{x}', $requestData)` then dies
  (`WebRoutes.php:233-237`). Core's model for "build a cart and redirect": `CustomCheckout::handleCustomCheckoutRedirect`
  (`app/Hooks/Handlers/CustomCheckout/CustomCheckout.php:103-208`).
- `checkout_data` (JSON on `fct_carts`) takes any key. The browser cannot write to it: `save_checkout_data` only
  accepts the fixed list in `AddressHelper::quoteFormFields()`. Reserved keys: `is_locked`, `disable_coupons`,
  `__cart_notices`, `__after_draft_created_actions__` (run with `['order','cart']` when the draft order exists,
  `CheckoutProcessor.php:308-317`), `__on_success_actions__` (run with `['cart','order','transaction']` on payment,
  `StatusHelper.php:324-335`).
- Swapping items: `$cart->removeItem()` + `$cart->addByVariation()`, as the order bump does (`WebCheckoutHandler.php:1139-1143`).
- There is **no variation switcher on the checkout** and **no filter on a product's variant list**.

## Checkout field and render hooks

All receive `['cart' => $cart]` unless noted (`CheckoutRenderer.php`).

- Inside the form on both shortcode and block: `fluent_cart/before_customer_details`, `after_customer_details`,
  `before_payment_methods`, `after_payment_methods` (block: `InnerBlocks.php:578`, `:583`, `:719`, `:725`).
- Shortcode only: `checkout_form_opening` (293), `before_billing_fields` (305), `after_order_notes_field` (816).
- Notices: `fluent_cart/checkout_page_notices` `([], ['cart'])`. Summary: `fluent_cart/checkout/cart_summary_html`,
  `fluent_cart/checkout/summary_extra_lines`. Localized JS data: `fluent_cart/checkout/localize_data ($data, $cart)`.
- `fluent_cart/checkout_address_fields` is legacy and **not** in the render path.
- Custom checkout AJAX verbs: `?action=fluent_cart_checkout_routes&fc_checkout_action={verb}` with the `fluentcart`
  nonce; answer through `fluent_cart/checkout/route_response (null, ['action'=>$verb])` (`WebCheckoutHandler.php:131`).
- JS: `window.fluentCartCheckoutParams.push(() => ({...}))` adds params to every checkout call.
  `window.fluentCartCheckout.beforeCheckoutCallbacks` / `afterCheckoutCallbacks`. Dispatching
  `fluentCartNotifyCartDrawerItemChanged` makes the checkout refetch its summary.
- Submit keeps only the **first value per input name**. Multi-value fields need one name per value.
- Custom fields are **not** saved while typing (`patchCheckoutData` returns early). Read them at place-order.

## Place-order path (`api/Checkout/CheckoutApi.php::placeOrder`)

1. Rate limit 5 per 60 s per IP (`:68`).
2. `fluent_cart/checkout/validate_before_process (true, $data)` (`:173`). `WP_Error` → message, HTTP 403.
3. **`fluent_cart/checkout/validate_data ($errors, ['data','cart'])`** (`:1247`). Errors `[field][rule] => message`,
   shown in a toast. This is where Cart Toolkit enforces forms.
4. `OrderService::validateProducts()` (`:244`). Customer matched or created (`:257`). `shouldCreateUser` (`:259`).
5. `fluent_cart/checkout/before_order_processing ['cart']` (`:359`).
6. `new CheckoutProcessor(...)->createDraftOrder()` (`:363-399`). `fluent_cart/checkout/order_data` filter
   (`CheckoutProcessor.php:1267`).
7. `fluent_cart/order/after_items_calculated` (`:408`).
8. **`fluent_cart/checkout/prepare_other_data ['cart','order','prev_order','request_data','validated_data']`**
   (`:415`). Where Cart Toolkit saves answers with `$order->updateMeta()`. `request_data` is the raw POST.
9. `finalizeOrder()`: addresses, UTM, `OrderCreated` → `fluent_cart/order_created`, stock, gateway (`:640-704`).

On a payment retry the order is adjusted, not recreated, and `order_created` fires again.

## After payment

`StatusHelper::syncOrderStatuses()` (`app/Helpers/StatusHelper.php:167-445`):

- `fluent_cart/cart_completed ['cart','order']` (only when a cart row links to the order), then `__on_success_actions__`.
- `OrderPaid` → `fluent_cart/order_paid ['order','customer','transaction','source']`. Its listener creates the WP user
  (`app/Listeners/Order/OrderPaid.php:25-65`) and queues the async step.
- Async: **`fluent_cart/order_paid_done ['order','transaction','customer','subscription'?]`** (`app/Hooks/actions.php:156-200`).
  Integration feeds run here (priority 11). Fires once (atomic delete of `action_scheduler_id` meta). Can be triggered
  now with `do_action('fluent_cart/order_paid_async_private_handle', ['order_id'=>$id])`.
- `fluent_cart/order_status_changed[_to_{status}]`; digital orders auto-complete.
- `fluent_cart/subscription_activated ['subscription','order','customer','meta']`.

## Free ($0) orders

- No "free" gateway. When the total and recurring total are 0, `PaymentHelper::validateAndGetPayMethod()` forces
  `fluent_cart/default_payment_method_for_zero_payment` = `offline_payment` (`app/Services/Payments/PaymentHelper.php:157-161`),
  even when offline payment is switched off.
- `CodHandler::handleZeroTotalPayment()` marks the transaction succeeded and syncs statuses
  (`app/Modules/PaymentMethods/Cod/CodHandler.php:26-103`). No "offline order placed" email.
- **A $0 subscription is created active with no next billing date: it never renews or expires** (lifetime).
- No public order-creation API. Recommended programmatic path: copy `placeOrder`'s steps — `CartHelper::generateCartItemFromVariation()`,
  customer, `CheckoutProcessor->createDraftOrder()`, `AddressHelper::insertOrderAddresses()`, `OrderCreated` dispatch,
  `CheckoutApi::updateStock()`, then `App::gateway('offline_payment')->makePaymentFromPaymentInstance(new PaymentInstance($order))`.
- The admin path (`OrderResource::updatedPlaceOrder`, "mark as paid") labels the order `source = admin`. Avoid.

## Customers and WP users

- Guest checkout attaches to the first `Customer` with that email (`CheckoutApi.php:504-520`). Email ownership is never checked.
- `user_account_creation_mode` (`all` default, `user_choice`, `only_subscription`). Subscriptions always create a user.
- If the email already has a WP account, no user is created **and the customer is not linked to it** (`OrderPaid.php:33`).
- Auto-login after account creation (`auto_login_after_account_creation`, default yes).
- `fluent_cart/user_created` is defined but never fired.

## Data

| Table | Notes |
|---|---|
| `fct_orders` | `note`, `config` JSON |
| `fct_order_items` | `post_id` product, `object_id` variation |
| `fct_order_meta` | `Order::getMeta/updateMeta/deleteMeta` (`app/Models/Order.php:499-534`), arrays JSON-encoded |
| `fct_carts` | `cart_data`, `checkout_data`, `cart_group`, `stage`, `order_id` |
| `fct_customers` | `user_id`, `contact_id` (FluentCRM) |
| `fct_product_variations` | `post_id`, `variation_title`, `payment_type` onetime/subscription, `item_price` in cents, `other_info` JSON |
| `fct_product_details` | `default_variation_id`, `variation_type` |

Variations: `ProductVariation::query()->where('post_id', $id)->get()`, `->canPurchase($qty)`.

## Admin screens

- Widgets: `GET widgets?filter=X` runs `apply_filters('fluent_cart/widgets/{X}', [], $data)` (`app/Http/Controllers/WidgetsController.php:69`).
  Slots: `single_order_page` (sidebar, `$data['order']`), `single_order_page_main`, `customer`, `customer_page_main`,
  `single_subscription_page[_main]`. A widget `['title','subtitle','type'=>'html','content'=>$escapedHtml]` renders as a card.
- JS hooks: `window.fluent_cart_admin.hooks` (wp.hooks); enqueue on `fluent_cart/admin_js_loaded`.

## Integrations (access after purchase)

- Feeds per product in `fct_product_meta` (`object_type='product_integration'`), global in `fct_meta`. Run by
  `app/Listeners/IntegrationEventListener.php` on `order_paid_done`, subscription events, refunds and cancels.
  Product feeds can be limited to variations (`conditional_variation_ids`).
- Built-in providers: FluentCRM (email, name, address, lists, tags; custom fields commented out, no filter on the
  contact data), FluentCommunity (spaces, courses), FluentSupport.
- New provider: extend `BaseIntegrationManager`, register on `fluent_cart/integration/order_integrations`.
- FluentCart emails: unknown smartcodes go to `fluent_cart/smartcode_fallback`.

---

# Part 2 — Cart Toolkit

Plan: "Cart Toolkit Plan" artifact (https://claude.ai/artifact/AwY9XnmBKwAwEKBya7A2iF), page source
`resources/cart-toolkit-plan.html`. The owner's answers are in its `decisions` collection.

## File tree

```
cart-toolkit/
├── cart-toolkit.php                     Header, constants, requires, updater registration, ctk_boot (plugins_loaded 20)
├── uninstall.php                        Deletes ctk_* options and the retry cron
├── includes/
│   ├── class-ctk-log.php                One log (option ctk_log, newest 300), sources checkout/formonly/handoff/admin
│   ├── class-ctk-settings.php           ctk_settings (connector, link_existing); fluentcart_ready() (2.3.2+)
│   ├── connectors/
│   │   ├── class-ctk-connector.php      Abstract: get_fields(), send(); registry; active() ("auto" = WP Fusion first)
│   │   ├── class-ctk-connector-fluentcrm.php   fluentcrm_get_custom_contact_fields(), FluentCrmApi('contacts')->createOrUpdate
│   │   └── class-ctk-connector-wpfusion.php    wpf_get_option('crm_fields'); crm->update_contact($id, $values, false)
│   ├── class-ctk-fields.php             Field library (ctk_fields), MODES, ORDER_SOURCES, draw/sanitize/validate
│   ├── class-ctk-forms.php              Forms (ctk_forms), link, prices, price_label, is_free_price
│   ├── class-ctk-form-only.php          ctk_form_only ids; buy-link redirect (init 5); validate_data (20)
│   ├── class-ctk-checkout.php           The form engine (see below)
│   ├── class-ctk-handoff.php            order_paid_done (20) → connector; order widget; resend
│   └── updater/class-ab-github-updater.php   Shared drop-in, unchanged
├── admin/
│   ├── class-ctk-admin.php              Shell, PAGES, NOTICES, all admin-post handlers (ctk_*)
│   ├── css/ctk-admin.css                FC Toolkit's stylesheet with the prefix renamed, plus form-editor rows
│   └── views/page-{overview,forms,fields,formonly,logs,updates}.php, partials/form-editor.php
└── assets/ctk-form.js, ctk-form.css     Price picker on the front end
```

## Boot

`ctk_boot()` always starts the admin. Everything touching FluentCart (`CTK_Form_Only`, `CTK_Checkout`,
`CTK_Handoff`, `CTK_Rest`) starts only when `CTK_Settings::fluentcart_ready()`.

## Setup API (`CTK_Rest`, 0.2.0)

`cart-toolkit/v1`, every route `manage_options` (application password). Each route calls the same method as its admin
handler: `CTK_Forms::create()` / `CTK_Forms::apply()` / `CTK_Settings::apply()` / `CTK_Fields::*` / `CTK_Form_Only::save()`.
`apply()` treats a missing switch as off, so the API merges the saved form (or settings) under the request body first;
left-out keys keep their value.

| Route | Does |
|---|---|
| `GET /state` | settings, connector, CRM fields, library, forms (+ shortcode, link, page URL), products with prices (+ form_only), form-only ids |
| `POST /settings` | `connector`, `link_existing` |
| `POST /fields` | `keys[]` added from the active CRM |
| `POST`/`DELETE /fields/{key}` | `label`, `type`, `options[]` / remove |
| `POST /forms` | `name`, `product_id`, `kind` → new form (off) |
| `POST`/`DELETE /forms/{id}` | any form setting / delete |
| `POST /form-only` | `ids[]`, replaces the list |
| `GET /orders/{id}` | `_ctk_form`, `_ctk_answers`, `_ctk_handoff` of an order |
| `POST /orders/{id}/resend` | hand-off again |
| `GET /log?source=` | log entries |

Login for saileapps.com: `~/.config/site-agent/saileapps.env` (password read from Keychain `saileapps-wp-app-password`),
used with `~/.claude/skills/wp-plugin-delivery/wp-api.sh`.

## Data

| Option / meta | Shape |
|---|---|
| `ctk_settings` | `connector` (auto, fluentcrm, wpfusion, none), `link_existing` (1/0). Merged on save. |
| `ctk_fields` | CRM field key => `label`, `type` (CTK_Connector::TYPES), `options[]`, `group` |
| `ctk_forms` | id (slug, fixed) => `name`, `kind` (paid/free), `active`, `product_id`, `variation_ids[]` (ordered), `default_variation`, `page_id`, `coupons`, `button_text` (`{price}` placeholder), `fields_heading`, `style` (card/summary), `accent` (hex), `plan_heading`, `price_notes` (variation id => line under the name), `fields[]` of `key`, `mode` (ask/set/link/order), `required`, `value`, `param`, `source` |
| `ctk_form_only` | int[] variation ids |
| `ctk_log` | entries `time`, `source`, `action`, `ok`, `details`, `order_id` |
| order meta `_ctk_form` | `id`, `name` |
| order meta `_ctk_answers` | key => `label`, `value`, `mode` (ask and link values only) |
| order meta `_ctk_handoff` | `status` (sent/failed), `message`, `time`, `trigger`, `connector`, `sent` |

## The form engine (`CTK_Checkout`)

- **Cart group `ctk_form`**, registered in `fluent_cart/cart/hash_resolvable_cart_groups`. A form cart's
  `checkout_data` holds `ctk_form` (form id), `ctk_link` (link-mode values captured when the cart was made) and
  `disable_coupons`. It is **not** `is_locked`: that freezes prices (`refreshStalePrices`) and changes retry handling.
- **Page:** `[cart_toolkit_form id="…"]`. `template_redirect` (5) finds the form in the queried post's content; with no
  matching cart in `?fct_cart_hash=` it makes one (`?option=` picks the starting price) and redirects to the same page
  with the hash, keeping other query args. Sets `DONOTCACHEPAGE` and no-cache headers.
- **Form link:** `?fluent-cart=ctk_form&form=<id>[&option=]` (route `fluent_cart_action_ctk_form`) → the form's page,
  or, with no page, a fresh cart on FluentCart's checkout page.
- **Draw:** picker (2+ prices) then `(new CheckoutPageHandler())->renderCart($cart)`; assets via
  `AssetLoader::loadCheckoutAssets($cart)` on `wp_enqueue_scripts`. Fields on `fluent_cart/after_customer_details`
  through FluentCart's `FormFieldRenderer`; url/date/radio/checkboxes as `ctk_*` types on `fluent_cart/render_custom_form_field`.
  Inputs are `ctk_f[<key>]`; checkboxes `ctk_f[<key>][<i>]` (FluentCart's submit keeps the first value per name).
- **Picker:** checkout verb `ctk_switch_option` via `fluent_cart/checkout/route_response` (FluentCart's nonce). Replaces
  items of the form's product, keeps others (order bumps), then the page reloads.
- **validate_data (10):** form exists and is on; every item of the form's product is a form price (free forms: $0 only);
  at least one form price; asked fields required/typed. Errors keyed `ctk_form`, `ctk_option`, `ctk_f_<key>`.
- **prepare_other_data:** saves `_ctk_form` and `_ctk_answers`; if `link_existing`, sets the customer's `user_id` to the
  WP user with that email when it is empty.
- **Free signup** is the same engine with a $0 price: FluentCart forces offline payment for a $0 total and shows no
  payment box for a $0 one-time price, so it is a real FluentCart purchase.

## Look (0.3.0)

The page carries the design; the form is a white card inside it. Wrapper `.ctk-form.ctk-style-{card|summary}` with
`--ctk-accent` inline. FluentCart is themed through its own CSS variables (`--fct-checkout-btn-bg-color`,
`--fct-checkout-active-border-color`, `--fct-input-radius`, ...), never by restyling its markup. Card style hides
`.fct_checkout_summary`, so coupons (which live in the summary) are forced off for it, in `apply()` and in `make_cart()`.
The price picker renders on `fluent_cart/after_customer_details` (inside the card, after name and email), before the
asked fields. Form cart items get `view_url` blanked (`CTK_Checkout::unlinked()`), so the checkout's product name and
image don't link to the product page; order items only expose `view_url` for custom items, so receipts were already plain.
The button label is set twice: `fluent_cart/checkout_page_order_button_text` for the drawn button, and
`fluent_cart/checkout/localize_data` (`fluentcart_checkout_vars.submit_button.text`), because Stripe's script resets the
button to that text once its card box loads (seen live in 0.3.0).
Name and email (0.3.2): `fluent_cart/checkout_page_name_fields_schema` (scope `render`, form carts only) gives them a
`label` (FluentCart then prints a real `<label>` above the box, same class as the asked fields) and an empty placeholder.
CSS lays `#billing_personal_information_section` out as a grid: first/last name 50/50, email full width, one column at
600px and under, separate rounded boxes; FluentCart's docked in-box label (`::after` from `data-fct-label`) is hidden.

## Hand-off (`CTK_Handoff`)

`fluent_cart/order_paid_done` (20, after FluentCart's integrations at 11). Values: ask + link answers from the order,
plus the form's set values and order values computed now. Empty values are dropped. One retry 5 minutes later when the
connector reports no contact yet (`ctk_no_contact`). Resend: `admin-post.php?action=ctk_resend&order_id=` (nonce
`ctk_resend_<id>`), linked from the order widget and failed log rows.

## Testing

Harness in the scratchpad stubs WP + cart/order objects and runs the real classes (66 checks: field validation,
form price rules, form-only rules, order-bump allowance, closed form, hand-off values, settings merge, log cap, form
create/apply), plus load tests of the main file without FluentCart and of the API routes with it. Not yet run on a live site.

## Decided (2026-11-18)

- Runs on **members.automationbridge.com**. FluentCart isn't installed there yet; SureCart, SureMembers, OttoKit and
  WP Fusion 3.48.2 (+ Enhanced Ecommerce) are.
- Answers go to **Groundhogg on go.automationbridge.com**, through WP Fusion (as FC Toolkit does).
- **Every form field is a Groundhogg field** (owner's rule): a field library pulled from Groundhogg; forms pick from it.
  Each field on a form is filled by Ask, Set (fixed value) or From link. No form-only fields.
- Paid forms are embedded on any page (shortcode/block). Free signup grants access at once (no email confirmation).
- GitHub repo `automationbridge/cart-toolkit` (private) approved; create it when building starts.
- Scope: Cart Toolkit owns checkout plus the hand-off of answers. After-purchase work stays in FluentCart's
  FluentCommunity access, WP Fusion's FluentCart feeds, Groundhogg flows and FC Toolkit Access.
- Building blocks: field library, forms (paid / free kinds), **form-only prices**, Groundhogg hand-off, shell.
- **Form-only prices (owner: "It HAS to be purchased through a form").** All four Community Membership prices are
  form-only; the FluentCart product is Private (no public product page; `canPurchase()` allows `private`). A buy link
  (`?fluent-cart=instant_checkout&item_id=`) for a form-only price is redirected on `init` before priority 99 to the
  form that sells it, or shows "no longer available" if none does. `validate_data` refuses form-only items in any cart
  whose form doesn't list them. Renewals never pass through checkout. (FluentCart's `item_status = inactive` was
  considered; it can't be used for prices a paid form sells, because checkout's `canPurchase()` refuses it.)
- Field fill modes: Ask, Set (fixed), From link, **From order** (WP user ID, price name, order date).
- Community Membership prices in SureCart today: Guest $0, Legacy $99/mo, Scale $999/yr, Growth $299/3 mo.

## Facts gathered

- WP Fusion's FluentCart integration (3.47+): product-level feeds apply/remove tags on order and subscription
  events, and syncs a fixed list of order fields. It does not sync custom checkout answers.
- Groundhogg's field definitions (name, label, type, choices, group, tab) are readable on go.automationbridge.com
  (`groundhogg/list-custom-fields` ability). Still to check: whether WP Fusion's cached field list keeps type and choices.
- FluentCart's FluentCommunity access grants by email match but revokes only through a linked customer
  (`app/Modules/Integrations/FluentPlugins/FluentCommunityConnect.php:244-250`), so an unlinked returning member keeps
  access after a cancel or refund. Recommended fix: link the customer to the WP user with that email before payment.

## Waiting on

- Today's setup: OttoKit on members listens only for WS Form submissions and post status changes (no SureCart
  trigger); its steps live on OttoKit's servers. SureCart integrations / WP Fusion SureCart settings / SureMembers
  groups aren't readable over REST (abilities `suremembers/*` are broken or empty). Plan: a read-only "Current setup"
  page in 0.1.0 reads them; owner sends one overview screenshot per OttoKit automation.
- Abilities REST on members: read-only abilities need GET with `input[...]` params. Never send a dummy property to an
  ability whose schema has empty `properties` — it fatals that request in core's validator.
- Paid flow today (OttoKit on members, trigger SureCart Purchase Created): filter, find WP user, Groundhogg
  create/update contact, tags `start_Membership Activated` (→ GH flow 4), `payment_processor_SureCart` (label, no
  flow), `trigger_Sync Member ID` (no GH flow; OttoKit on go listens for tags, likely sets `member_user_id` → GH flow
  44 "Send New User Credentials"), Push Deck webhook, Slack. Plan: WP Fusion product feed applies the flow-4 tag;
  Cart Toolkit hand-off sets Membership Plan Type and Member User ID (From order); Push Deck and Slack move into GH flow 4.
- Possible triple password email (GH flow 4 email 48, GH flow 44 email 61, WordPress core). FluentCart can skip core's
  via `fluent_cart/user/after_register/skip_hooks`. Owner picks one (q12).
- Free flow not yet seen (likely the WS Form trigger on members). OttoKit MCP URL in `~/Desktop/server.txt` returned
  401 "Invalid server key" on 2026-11-18; not saved to Keychain, file left for the owner.
- Owner's answers on: linking existing logins (q5), building blocks (q9), per-form tags (q10), free-flow access (q11),
  password email (q12).
