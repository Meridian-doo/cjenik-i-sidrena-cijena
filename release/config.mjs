// Names and paths shared by the build, the release check and the SVN deploy.
import { fileURLToPath } from 'node:url';
import path from 'node:path';

export const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/** WordPress.org slug = folder = main file = text domain. */
export const SLUG = 'cjenik-i-sidrena-cijena';
export const VERSION_CONSTANT = 'CJENIK_VERSION';

export const PLUGIN_DIR = path.join( ROOT, SLUG );
export const DIST_DIR = path.join( ROOT, 'dist' );
export const ZIP_PATH = path.join( DIST_DIR, `${ SLUG }.zip` );

/** Mirrors SVN `assets/` one-to-one. Never shipped in the zip. */
export const ASSETS_DIR = path.join( ROOT, '.wordpress-org' );
/** Croatian translation of readme.txt, for translate.wordpress.org's Stable Readme project. */
export const README_PO = path.join( ROOT, 'release', 'readme-hr.po' );
/** The text drawn into the icon and banners, so the wording rules can read it. */
export const ASSET_TEXT = path.join( ROOT, 'release', 'asset-text.json' );

/** The one sentence allowed to talk about compliance (Compliance Disclaimers page), in each language. */
export const DISCLAIMERS = [
	'No plugin can guarantee legal compliance; you remain responsible for your data and for checking the current rules.',
	'Nijedan dodatak ne može jamčiti usklađenost s propisima; Vi ste odgovorni za svoje podatke i za provjeru važećih pravila.',
];

/** Compliance-claim phrases (guideline 9), matched case- and diacritic-insensitively. */
export const CLAIM_DENYLIST = [
	'usklađen',
	'zakonski',
	'100%',
	'compliant',
	'guarantees compliance',
	'guaranteed compliance',
	'ensures compliance',
];
