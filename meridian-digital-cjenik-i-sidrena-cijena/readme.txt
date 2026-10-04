=== Meridian Digital Cjenik i Sidrena Cijena ===
Contributors: leonardmeridian
Tags: cjenik, sidrena cijena, najniža cijena, woocommerce, croatia
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Helps Croatian WooCommerce shops publish the daily price list (cjenik) as CSV/XML and show the anchor price (sidrena cijena) and lowest 30-day price.

== Description ==

From 1 October 2026, Croatian traders who sell online publish a machine-readable price list every day by 08:00 and keep each file online for 30 days (NN 101/2026). Next to the current price they show the anchor price (sidrena cijena, the "dodatna cijena" of the decision) and, during a sale (akcija, rasprodaja, popust), the lowest price in the last 30 days (najniža cijena).

This plugin helps your WooCommerce shop publish that price list every morning as CSV or XML, and shows both prices on your product pages. It needs no account and no daily work.

= Features =

* Publishes the price list every day at a time you choose (default 05:00, Europe/Zagreb time), including weekends and holidays.
* Names each file with the outlet type, address, code, storage number, date and time.
* Keeps a public archive page, a "latest file" URL that never changes, and every file for at least 31 days.
* Records a publication log with the time, row count and SHA-256 checksum of every file.
* Keeps a price history of every product and variation, from the day you activate the plugin.
* Takes anchor prices from that history, from the product screen, or from a CSV import (including the HOK template).
* Shows the anchor price and the lowest 30-day price on product pages, shop pages, product blocks and variation selection.
* Emails you and shows an admin notice when today's file is missing, and checks that visitors can download the files.
* Offers WP-CLI commands and instructions for a system cron job, so publishing doesn't depend on site traffic.
* Works with WooCommerce order storage (HPOS) and the cart and checkout blocks.

= Why it's reliable =

* Deadlines follow Europe/Zagreb time, including summer-time changes, whatever your site timezone.
* Prices include VAT at the Croatian rate of each tax class, even when a scheduled task runs without a customer.
* Prices come from the regular price, sale price and sale dates, so a late WooCommerce sale job can't publish a stale price.
* A watchdog retries every 15 minutes until today's file exists, and a file is only visible once it is completely written.

= Important =

This plugin helps you publish your price list and show the anchor price and the lowest 30-day price. No plugin can guarantee legal compliance; you remain responsible for your data and for checking the current rules.

= Privacy and external services =

The plugin sends no data to any external service and needs no account or licence. The only HTTP requests it makes go to your own site: the self-check downloads your price-list URLs without logging in, to confirm that visitors can reach them. Alert emails go through your site's own mailer.

= Source code =

The source code, tests and build scripts are public at [github.com/Meridian-doo/cjenik-i-sidrena-cijena](https://github.com/Meridian-doo/cjenik-i-sidrena-cijena). The PHP isn't compiled or minified; `npm run build` in that repository produces this plugin's zip.

The admin screens use the Inter and IBM Plex Mono fonts, bundled in `assets/fonts` under the SIL Open Font License 1.1 and loaded from your own site.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. The setup asks for your outlet (for a webshop: "webshop", your store address and a code such as "P-01") and where the brand, barcode and unit price come from. It then publishes the first price list.
3. Link the archive page from your site footer.
4. Open WooCommerce → Price list (cjenik) → Anchor prices, and import anchor prices for products you sold before installing the plugin.
5. On a low-traffic site, ask your host for a system cron job: `0,15,30,45 * * * * cd /path/to/site && wp cjenik watchdog --quiet`

== Frequently Asked Questions ==

= How do I publish the price list before 08:00? (Kako objaviti cjenik prije 8:00?) =

You don't have to do anything each day. The plugin publishes at 05:00 by default and retries every 15 minutes until the file exists. WordPress runs scheduled tasks only when someone visits the site, so on a quiet site add the system cron job from the installation steps. You can change the time under Settings.

= What is the anchor price and which date is used? (Što je sidrena cijena i koji se datum koristi?) =

It is the regular price (never a sale price) on 10 September 2026, or on 2 May 2025 for the food and household categories you choose. Products listed later use their first price. The plugin shows it as "Cijena na 10.9.2026.: 12,00 €", and you can change the wording.

= How is the lowest price in the last 30 days calculated? (Kako se računa najniža cijena u zadnjih 30 dana?) =

During a sale, the plugin looks up the lowest price the item was offered at in the 30 days before the sale started, from its own price history. WooCommerce keeps no history, so the plugin knows prices from the day it was activated. You can import earlier prices from a CSV with `šifra` or `barkod`, `cijena` (with VAT), `od` and optionally `do`.

= I installed the plugin after 10 September 2026. Where does the anchor price come from? =

Until you enter or import the correct anchor price, the plugin uses the earliest price it knows with that date, and lists the item under Anchor prices. Import a CSV with `šifra` or `barkod`, `sidrena cijena` and `sidreni datum`, or a HOK template with a "Cijena 10.9.2026." column.

= Where is the archive? (Gdje je arhiva?) =

The plugin creates a public page listing every published file, and you can add the list to any page with the `[cjenik_arhiva]` shortcode. The newest file is always at `/cjenik/webshop.csv` on your site.

= Does it work for services? (Radi li za usluge?) =

No. The plugin lists your WooCommerce products and variations. It doesn't cover services or a price list for several shops (outlets); the free plugin sets up one outlet, your webshop. Developers can add rows and outlets with the `cjenik_row_sources` and `cjenik_outlets` filters.

= Which WP-CLI commands are there? =

The commands are `wp cjenik publish`, `watchdog`, `status`, `log`, `snapshot`, `import-anchors <file>`, `import-history <file>` and `self-check`.

= What happens to my data if I delete the plugin? =

By default the price history, anchor prices, publication log and files are kept as evidence. You can choose to delete them in the settings.

== Screenshots ==

1. The setup asks for your outlet and where product data comes from, then publishes the first price list.
2. The status page shows today's file, the next run and the public URLs.
3. The publication log lists every file with its storage number, time, row count and checksum.
4. A product page shows the anchor price and, during a sale, the lowest price in the last 30 days.
5. Anchor prices lists items without a confirmed anchor price and imports them from CSV.
6. The public archive lists every price list published in the retention period.

== Changelog ==

= 0.1.0 =
* First public release.

The full history is in changelog.txt.

== Upgrade Notice ==

= 0.1.0 =
First public release.
