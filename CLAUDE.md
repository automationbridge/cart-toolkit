# Cart Toolkit — Project Instructions

## Required Reading

At the start of every conversation, read `Agents.md` in this directory before making any changes. It holds the
FluentCart source reference (hooks, tables, checkout and order flow, all checked against the code) and the plugin's
architecture as it is built. The owner's brief is `resources/fluentcart-handoff.md`.

## Project Context

**Cart Toolkit** (`cart-toolkit`) is an add-on for the **free** FluentCart plugin. It adds only the features the owner
needs, so FluentCart Pro is never bought, and it replaces SureCart (checkout) and AutoKit (after-purchase steps).
Same approach as FC Toolkit (`~/Desktop/My Plugins/fluent-community`), which extends FluentCommunity.

Layout of this folder:

| Path | What |
|---|---|
| `cart-toolkit/` | The plugin. Main file `cart-toolkit/cart-toolkit.php`. |
| `resources/fluent-cart/` | FluentCart 2.3.2, reference only. Byte-identical to the wordpress.org release (checked 2026-11-18). |
| `resources/fluentcart-handoff.md` | The owner's brief: priorities and ground rules. |

## Ground rules (from the brief, not negotiable)

1. **Never modify FluentCart's code.** Everything goes through its actions, filters, routes and models.
2. **Never modify `wp-config.php` or server files.** Settings live on the plugin's admin page, saved to the database.
3. **Work from the real source.** Confirm a hook exists in `resources/fluent-cart/` before using it. When FluentCart
   updates, replace that folder with the new release and diff it before building on it.
4. **Use what FluentCart already does** before rebuilding it.
5. **Test everything that touches payments** before it goes live.

## Conventions

- Classes use the `CTK_` prefix and static methods. New option keys use the `ctk_` prefix. Order meta keys use `_ctk_`.
- Admin pages are server-rendered PHP (`admin/views/page-{slug}.php`), following FC Toolkit: nonce, capability check,
  sanitize, redirect. No admin AJAX without asking.
- Read FluentCart data through its models (`FluentCart\App\Models\*`), never raw SQL against `fct_*` tables.
- **Server checks are the rule; hiding is decoration.** Anything a form restricts (variations, required fields) is
  checked again in `fluent_cart/checkout/validate_data`, whatever the page showed.
- The GitHub updater is the shared drop-in from the `wp-plugin-delivery` skill. Copy it unchanged.
- Bump the version in both the header and `CTK_VERSION` on every build.

## Testing

No WordPress here. Verify with standalone PHP harnesses in the scratchpad that stub the WP functions and FluentCart
models a class touches, then call the real class. `php -l` every changed file before packaging. Payment paths are
also tested on a staging site with Stripe in test mode before release.
