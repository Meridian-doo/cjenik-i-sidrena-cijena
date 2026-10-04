# Contributing

Thanks for helping. Bug reports, fixes and improvements are all welcome.

## Reporting a bug

Open an [issue](https://github.com/Meridian-doo/cjenik-i-sidrena-cijena/issues) with:

- the WordPress, WooCommerce, PHP and plugin versions;
- what you did, what you expected and what happened;
- the relevant rows of the published CSV or XML, the Publication Log entry, or the error from **WooCommerce → Status → Logs** (source `cjenik`), if any.

Leave out customer data and anything else you wouldn't publish. Security problems go through [SECURITY.md](SECURITY.md), not the issue tracker.

## Pull requests

1. Fork the repository and branch from `main`.
2. Set up the local site (see [Development](README.md#development)).
3. Make your change, with a test that fails without it. Tests run against real WordPress and WooCommerce; see the existing tests in `meridian-digital-cjenik-i-sidrena-cijena/tests/` for the helpers.
4. Run the checks CI runs:

   ```sh
   npm run lint:php
   npm run typecheck
   npm run test:php
   ```

5. Open the pull request against `main` and describe what changed and why.

Keep each pull request to one change. If you plan something large, open an issue first so we can agree on the approach.

## Coding standards

- PHP follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/), checked by PHPCS (`phpcs.xml.dist`). `npm run lint:php:fix` fixes most formatting.
- Classes live in `meridian-digital-cjenik-i-sidrena-cijena/src/` under the `Cjenik\` namespace, one class per file, named after the class (PSR-4).
- Declare parameter and return types. PHPStan (level 6) checks them; add a docblock where a type needs more detail (`list<int>`, `array<string, mixed>`) or where the code needs explaining.
- Escape all output, check nonces and capabilities on every admin action, and prepare every SQL query.
- Anything that depends on the current time asks the plugin's `Clock` (`cjenik()->clock()`), never `time()` or `current_time()`, so tests can fix the time.
- Prices come from `PriceResolver`, never from WooCommerce's `_price` meta.

## Translations

User-facing strings use the `meridian-digital-cjenik-i-sidrena-cijena` text domain. The Croatian translation is kept in `meridian-digital-cjenik-i-sidrena-cijena/languages/`; it isn't shipped in the zip, because WordPress.org delivers translations as language packs. If you add or change strings, you don't need to update the translation; a maintainer does that before the release (see [`docs/release.md`](docs/release.md#changing-plugin-strings)).

Once the plugin is on WordPress.org, translations are managed on [translate.wordpress.org](https://translate.wordpress.org/).

## Wording

The plugin *helps* shops publish their price list. Don't describe it, in code, readme or strings, as making a shop "compliant" or "legally aligned" (*usklađeno*, *zakonski*). The release check rejects that wording.

## License

By contributing, you agree that your contributions are licensed under the [GPL-2.0-or-later](LICENSE), the same license as the plugin.
