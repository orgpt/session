=== Arabic ↔ English Translator ===
Contributors:       yourname
Tags:               translation, arabic, english, rtl, woocommerce, woodmart
Requires at least:  5.8
Tested up to:       6.5
Requires PHP:       7.4
Stable tag:         1.0.0
License:            GPLv2 or later
License URI:        https://www.gnu.org/licenses/gpl-2.0.html

Automatic Arabic ↔ English frontend translation with RTL/LTR support, Woodmart theme compatibility, and WooCommerce integration.

== Description ==

**Arabic ↔ English Translator** provides seamless bilingual translation for WordPress sites using the free Google Translate endpoint — no API key required.

**Key Features:**

* **Language switcher** — floating button (bottom corner) and `[site_language_switcher]` shortcode.
* **Persistent selection** — stored in PHP session, cookie, or both; survives pagination, AJAX navigation, and WooCommerce pages.
* **RTL / LTR support** — sets `html[lang]` and `html[dir]` automatically; integrates with WordPress locale filters so Woodmart loads the correct RTL stylesheet.
* **Woodmart theme compatible** — forces `is_rtl()`, overrides locale, handles Woodmart's RTL CSS enqueue.
* **WooCommerce compatible** — translates cart, checkout, product pages; re-translates AJAX-loaded fragments.
* **localStorage caching** — translated strings cached client-side for fast page changes.
* **MutationObserver** — automatically translates dynamically injected content.
* **No page reload flicker** — language switches in-place via JS.
* **SEO-safe** — does not modify URLs or server-rendered HTML; translation is applied client-side.
* **Admin excluded** — dashboard is never translated.
* **Clean OOP architecture** — separate classes for plugin core, session manager, translator, switcher UI, Woodmart compat, and settings.

**Settings page** (Settings → AET Translator):

* Default language
* Show / hide floating switcher
* Storage mode (session / cookie / both)
* Exclude CSS selectors from translation

== Installation ==

1. Upload the `arabic-english-translator` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Configure options at **Settings → AET Translator**.
4. Place `[site_language_switcher]` anywhere in your content, or use the automatic floating button.

== Frequently Asked Questions ==

= Does this require a Google Translate API key? =
No. The plugin uses the free, unauthenticated Google Translate endpoint. For high-traffic sites consider adding a server-side cache layer.

= Will it work with Woodmart RTL? =
Yes. The plugin hooks into `locale`, `determine_locale`, and `language_attributes` filters, and mutates the global `$wp_locale` object so Woodmart's PHP conditionals see the correct text direction.

= Does it translate the admin dashboard? =
No. All hooks are restricted to the frontend.

= Is it WooCommerce compatible? =
Yes. Cart fragments, checkout fields, product pages, and AJAX-loaded content are all handled.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
