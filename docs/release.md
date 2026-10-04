# Releasing to WordPress.org

The plugin is published as **Meridian Digital Cjenik i Sidrena Cijena**. Its slug, folder, main file and text domain are all `meridian-digital-cjenik-i-sidrena-cijena`.

## What lives where

| Path | What it is |
|---|---|
| `meridian-digital-cjenik-i-sidrena-cijena/` | The plugin. `.distignore` lists what stays out of the zip. |
| `meridian-digital-cjenik-i-sidrena-cijena/languages/` | POT and Croatian PO/MO. Not in the zip (WordPress.org delivers translations as language packs); imported into translate.wordpress.org and used by the tests and the dev site. |
| `meridian-digital-cjenik-i-sidrena-cijena/readme.txt` | The directory listing (English, with the Croatian terms). Keep only the current and previous version in its changelog; the rest goes in `changelog.txt`. |
| `.wordpress-org/` | Copy of SVN `assets/`: icon, banners, screenshots (`-hr` = Croatian) and `blueprints/blueprint.json` (Live Preview). Never in the zip. |
| `release/readme-hr.po` | Croatian translation of readme.txt, for translate.wordpress.org's Stable Readme project. |
| `release/asset-text.json` | The text drawn into the icon and banners, so the wording rules can check it. Keep it in step with `release/artwork/banner.html`. |
| `release/` | Build, release check, screenshot capture and SVN deploy scripts. |

## Commands

| Command | What it does |
|---|---|
| `npm run build` | Builds `dist/meridian-digital-cjenik-i-sidrena-cijena.zip` |
| `npm run release:check` | Checks the zip against every listing rule; passing means it's ready to upload |
| `npm run release:check:static` | Only stages 1–5 (no Docker, no Playground), in seconds |
| `npm run release:check -- --keep-going` | Reports every broken rule instead of stopping at the first |
| `npm run readme:po` | Regenerates `release/readme-hr.po` after a readme edit (needs `npm run env:start`) |
| `npm run artwork` | Renders the icon and banners from `release/artwork/` (`icon.svg`, `banner.html`) into `.wordpress-org/` (needs `npx playwright install chromium`) |
| `npm run screenshots` | Recaptures all screenshots from the seeded dev site (needs `npm run env:start` and `npx playwright install chromium`) |

## The release check

It fails on the first broken rule, in this order:

1. **Artifact**: no `.sh`, `.phar`, archives, hidden files, tests or dev tooling in the zip; under 10 MB; one top-level folder and a main file named after the slug.
2. **Metadata**: readme parses; the name produces the slug and uses "WooCommerce" only as a trailing "for WooCommerce"; exactly 5 tags; short description ≤ 150 characters; `Stable tag` = `Version` = `CJENIK_VERSION` = newest changelog entry; `Requires at least` / `Requires PHP` agree between header and readme; `Tested up to` is only in the readme (WordPress.org's upload scan rejects it in the header); text domain = slug, no `Domain Path` and no `.po`/`.mo` in the zip; changelog trimmed; disclaimer present.
3. **Wording**: no compliance claims ("usklađen", "zakonski", "100%", "compliant", …) in the name, readme, translations or asset text, except the disclaimer sentence.
4. **Translation**: `readme-hr.po` was generated from this readme and is fully translated (name copied unchanged); the code PO translates every POT string and the `.mo` is compiled from it.
5. **Assets**: names, sizes and limits of icon, banners and screenshots (English and `-hr`, one screenshot per readme caption); `blueprint.json` valid, ≤ 100 KB, sets no language.
6. **Install** (a clean wp-env site on port 8890, `release/env`): installs the zip with WP-CLI next to WooCommerce, runs Plugin Check (`plugin_repo` category, which includes the readme and trademark checks; errors fail, warnings are printed), compares `readme-hr.po` with the strings translate.wordpress.org will import, checks the code for untranslated strings, then publishes once and checks that no request left the site and that the archive page, price list file and a product page carry no plugin credit.
7. **Preview**: boots the blueprint in WordPress Playground with the zip in place of the directory download and checks that the status page renders without a PHP fatal.

Before trusting a stage, the check breaks each of its rules on purpose (`release/fixtures.mjs`) and fails if a rule doesn't notice. If you add a rule, add a fixture.

Plugin Check warnings don't block the upload, but reviewers read them. Accept each one consciously or fix it.

The "no outbound requests" rule sees requests made through WordPress's HTTP API (`wp_remote_*`). Plugin Check flags direct `curl_*` and remote `file_get_contents()` calls, so keep all HTTP on the WordPress API.

## Changing the readme

1. Edit `meridian-digital-cjenik-i-sidrena-cijena/readme.txt`.
2. `npm run env:start`, then `npm run readme:po`. It keeps existing translations and lists new and dropped strings.
3. Translate the new strings in `release/readme-hr.po` (formal "Vi"; copy the plugin name unchanged; keep the HTML tags).
4. `npm run build && npm run release:check:static`.

## Changing plugin strings

1. `npm run wp -- i18n make-pot wp-content/plugins/meridian-digital-cjenik-i-sidrena-cijena wp-content/plugins/meridian-digital-cjenik-i-sidrena-cijena/languages/meridian-digital-cjenik-i-sidrena-cijena.pot --exclude=vendor,tests,bin` (run from the repo root; the path is inside the container).
2. `msgmerge --update --no-wrap meridian-digital-cjenik-i-sidrena-cijena/languages/meridian-digital-cjenik-i-sidrena-cijena-hr.po meridian-digital-cjenik-i-sidrena-cijena/languages/meridian-digital-cjenik-i-sidrena-cijena.pot`, then translate and remove any `fuzzy` flags.
3. `msgfmt -o meridian-digital-cjenik-i-sidrena-cijena/languages/meridian-digital-cjenik-i-sidrena-cijena-hr.mo meridian-digital-cjenik-i-sidrena-cijena/languages/meridian-digital-cjenik-i-sidrena-cijena-hr.po`

## Releasing a version

1. Bump the version in the plugin header, `CJENIK_VERSION`, readme `Stable tag`, and add the changelog entry (move the oldest one to `changelog.txt`). Add an Upgrade Notice if users must act.
2. `npm run readme:po`, translate, `npm run build`, `npm run release:check`.
3. Merge to `main`, then tag: `git tag 0.2.0 && git push origin 0.2.0`.
4. The **Release to WordPress.org** workflow builds, runs the release check and commits `trunk/`, `tags/0.2.0/` and `assets/`. It refuses if the tag and plugin version differ, or if the tag already exists in SVN.

**Readme or assets only** (e.g. a new `Tested up to`): merge to `main`, then run the workflow by hand with mode `listing`. It updates `readme.txt` in `trunk/` and the current tag, and `assets/`, without a new version. It publishes the version in the checked-out plugin header, and refuses if that tag isn't in SVN yet. So once `main` carries an unreleased version bump, run it from a branch cut at the released tag.

**Dry run**: run the workflow by hand with *dry run* ticked. It checks out SVN anonymously and prints what would be committed.

### Timing

- Every release waits in a **6-hour security cooldown** before it reaches sites through updates. A hotfix released the evening before the 08:00 deadline reaches auto-updating sites the next morning at the earliest. Release fixes early in the day.
- Listing changes (readme, assets) show up within minutes, and the CDN can take up to 6 hours.
- Release in batches. Frequent releases don't help search ranking (the recency decay is flat for 180 days), and rapid-fire commits count as gaming "Recently Updated".

## Manual launch checklist

These steps need a person; nothing here automates them.

1. Create a WordPress.org account with a company email (not an auto-responder), enable 2FA and whitelist `plugins@wordpress.org`. Put its username in the readme `Contributors:` line.
2. Build the zip and get `npm run release:check` green. Submit it at https://wordpress.org/plugins/developers/add/. On the confirmation page, check that the proposed slug is `meridian-digital-cjenik-i-sidrena-cijena`. It can be changed once there, before review.
3. Optional: email plugins@wordpress.org asking whether a plugin for a legal deadline qualifies for the FAQ's "legal issue" queue exception.
4. Answer the review email (in English). Update the files from the submission page; don't resubmit.
   Until step 6, Croatian sites see the plugin in English: it bundles no translation and doesn't call `load_plugin_textdomain()`, as the review asks.
5. On approval: generate the SVN password (Profile → Account & Security) and add `SVN_USERNAME` and `SVN_PASSWORD` as secrets of the `wordpress-org` environment in GitHub. Run the release workflow by hand from `main` with *dry run* ticked and check what it would commit. Then push the tag (`git tag 0.1.0 && git push origin 0.1.0`), which runs the real release. Pushing a version tag always publishes, so do the dry run first.
6. The same day, on translate.wordpress.org (locale `hr`): import `meridian-digital-cjenik-i-sidrena-cijena/languages/meridian-digital-cjenik-i-sidrena-cijena-hr.po` into **Stable (latest release)** and `release/readme-hr.po` into **Stable Readme**. Then post a PTE request tagged `#hr` on https://make.wordpress.org/polyglots/ so the strings get approved. Croatian search fields follow within a day of reaching ≥ 40 % approved.
7. Enable the public Live Preview on the plugin's Advanced tab.
8. Afterwards: answer and resolve every thread in the main support forum (only resolutions from the last 2 months count), release in batches, and bump `Tested up to` at the next WordPress major release.

Review takes 1–14 days; search inclusion another 6–14 days after the first SVN commit. Realistic search presence is mid to late October 2026.
