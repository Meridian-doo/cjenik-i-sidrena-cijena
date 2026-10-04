# Meridian Digital Cjenik i Sidrena Cijena

[![CI](https://github.com/Meridian-doo/cjenik-i-sidrena-cijena/actions/workflows/ci.yml/badge.svg)](https://github.com/Meridian-doo/cjenik-i-sidrena-cijena/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

A WooCommerce plugin that helps Croatian shops publish the daily price list (*cjenik*) as CSV or XML, and show the anchor price (*sidrena cijena*) and the lowest 30-day price next to the product price.

From 1 October 2026, Croatian traders who sell online must publish a machine-readable price list every day by 08:00 and keep each file online for 30 days (NN 101/2026). This plugin does that every morning without any daily work, keeps a public archive of every file, and adds both prices to product pages.

The WordPress.org listing, with the full feature list and FAQ, is [`meridian-digital-cjenik-i-sidrena-cijena/readme.txt`](meridian-digital-cjenik-i-sidrena-cijena/readme.txt).

> This plugin helps you publish your price list and show the anchor price. No plugin can guarantee legal compliance; you remain responsible for your data and for checking the current rules.

## Requirements

- WordPress 6.8 or later
- WooCommerce 9.1 or later
- PHP 8.1 or later

The plugin has no runtime dependencies, needs no account and sends no data to external services.

## Installation

Install **Meridian Digital Cjenik i Sidrena Cijena** from **Plugins → Add New** once it is listed on WordPress.org. To install from source, [build the zip](#building-a-release) and upload it under **Plugins → Add New → Upload Plugin**.

## Repository layout

| Path | What it is |
|---|---|
| `meridian-digital-cjenik-i-sidrena-cijena/` | The plugin. Its slug, main file and text domain are all `meridian-digital-cjenik-i-sidrena-cijena`. |
| `meridian-digital-cjenik-i-sidrena-cijena/src/` | PHP classes, autoloaded from the `Cjenik\` namespace (PSR-4). |
| `meridian-digital-cjenik-i-sidrena-cijena/tests/` | PHPUnit tests, run against real WordPress and WooCommerce. |
| `dev/` | Scripts that seed the local site with a Croatian test shop. |
| `release/` | Build, release check, screenshot and WordPress.org deploy scripts. |
| `.wordpress-org/` | Icon, banners, screenshots and the Live Preview blueprint for the WordPress.org listing. |
| `docs/` | The release process. |

### Plugin modules

| Module | Where | What it does |
|---|---|---|
| Price Resolver | `src/Prices/PriceResolver.php` | Effective price from regular/sale price and sale dates (never `_price`), with Croatian VAT even without a customer |
| Price History | `src/Prices/PriceHistory.php`, `HistoryHooks.php` | Every price an item was offered at; the lowest 30-day price |
| Anchor Registry | `src/Anchors/` | Anchor price and anchor date per item, and where each came from |
| Price List Builder | `src/PriceList/` | Rows, columns, field mapping, legal file name, CSV and XML writers |
| Publisher | `src/Publishing/Publisher.php` | `publish( Outlet, moment )`: the only way a file gets published |
| Scheduler | `src/Publishing/Scheduler.php` | Daily run at a Europe/Zagreb time, snapshot, 15-minute watchdog |
| Archive | `src/Archive/` | "Latest" URL, download URL, `[cjenik_arhiva]` shortcode, JSON index |
| Status and alerts | `src/Status.php`, `Alerts.php`, `SelfCheck.php`, `src/Admin/` | Admin pages, notices, emails, anonymous access check |
| Storefront | `src/Storefront/PriceDisplay.php` | Anchor price and lowest 30-day price next to the product price |

`src/Plugin.php` wires the modules together and registers their hooks. Everything time-dependent asks the injectable `Clock`, so tests can swap in `tests/Support/FixedClock.php`.

Developers can add rows and outlets with the `cjenik_row_sources` and `cjenik_outlets` filters, and react to publications with the `cjenik_published` and `cjenik_publication_failed` actions.

## Development

You need Docker and Node 18 or later. The local site runs WordPress 7.1.2 and WooCommerce 11.1.2 on PHP 8.3 through [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env).

```sh
npm install
npm run env:start     # http://localhost:8888, admin / password; seeds the shop on first start
npm run env:stop
```

| Command | What it does |
|---|---|
| `npm run test:php` | Runs the PHPUnit tests (installs the Composer tools and creates the test database on first run) |
| `npm run test:php -- --filter ArchiveTest` | Runs one test class |
| `npm run test:php:slow` | Runs the large-catalogue test (20,000 items, about 20 s) |
| `npm run lint:php` | Checks the WordPress Coding Standards (PHPCS) |
| `npm run lint:php:fix` | Fixes what PHPCS can fix automatically |
| `npm run typecheck` | Runs PHPStan |
| `npm run wp -- <command>` | Runs WP-CLI, e.g. `npm run wp -- cjenik publish` |
| `npm run composer -- <args>` | Runs Composer in the plugin folder |
| `npm run env:seed` | Seeds the shop (skips if already seeded); `-- --force` seeds again, `-- --bulk=5000` adds 5,000 products |
| `npm run env:reset` | Empties the database, then seeds |
| `npm run env:theme` | Dresses the shop up on the Botiga theme for screenshots (install Botiga first; see `dev/botiga-store.php`) |
| `npm run env:destroy` | Removes the containers and data |

After `npm run wp -- cjenik publish`, files land in `wp-content/uploads/cjenik/`, and the newest one is at http://localhost:8888/cjenik/webshop.csv.

### The seeded shop

`dev/seed.php` sets up a Croatian store with products that cover the edge cases:

- Europe/Zagreb timezone, EUR with a decimal comma, a Zagreb store address.
- VAT classes: 25% standard, 13% reduced and 5% super-reduced. Prices are entered without VAT and shown with VAT.
- Groceries, drinks, cosmetics and cleaning products, each with a brand and an EAN-13 barcode.
- Sales with no end date, starting at midnight (so `_price` is stale until WooCommerce's scheduled-sales job runs) and ending tonight.
- Out-of-stock and backorder items, and a variable T-shirt with one size on sale, one out of stock and one disabled.
- Tricky names (quotes, commas, semicolons, a leading `=` for CSV injection), a product with no SKU, barcode or brand, and draft and private products.
- Creation dates before and after 10 September 2026, the anchor date.

Every seeded post has the meta key `_cjenik_seed`.

### Known trap: VAT outside a customer request

WooCommerce only loads a customer on requests it treats as frontend. WP-CLI counts as frontend, but WP-Cron and REST requests don't. Without a customer, and with the seeded tax settings, `wc_get_price_including_tax()` returns the price **without VAT**.

A check from WP-CLI hides this, so tests reproduce it by running with `WC()->customer` set to `null`.

### Tests

The tests exercise the plugin through its two outside edges: publishing a price list (`tests/*PublicationTest.php`, `ArchiveTest`, `SchedulerTest`, `AlertsTest`, `FileFormatTest`) and the WooCommerce price HTML on the storefront (`StorefrontPriceHtmlTest`). The admin screens have rendering tests too.

## Building a release

```sh
npm run build           # dist/meridian-digital-cjenik-i-sidrena-cijena.zip
npm run release:check   # checks the zip against the WordPress.org listing rules
```

The full release process, including translations and the WordPress.org SVN deploy, is in [`docs/release.md`](docs/release.md).

## Contributing

Bug reports and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md). To report a security problem, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

The admin screens bundle the Inter and IBM Plex Mono fonts under the SIL Open Font License 1.1 (`meridian-digital-cjenik-i-sidrena-cijena/assets/fonts/`).
