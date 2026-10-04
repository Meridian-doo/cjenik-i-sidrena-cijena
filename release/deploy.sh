#!/usr/bin/env bash
# Publishes the built zip and .wordpress-org/ to the plugin's WordPress.org SVN.
#
#   release/deploy.sh release <version> [--dry-run]
#       trunk/ = the zip, tags/<version>/ = copy of trunk, assets/ = .wordpress-org/
#   release/deploy.sh listing <version> [--dry-run]
#       only readme.txt (trunk/ and tags/<version>/) and assets/; no new version
#
# Run `npm run build` and `npm run release:check` first. Needs svn, unzip and
# rsync, and SVN_USERNAME / SVN_PASSWORD in the environment unless --dry-run.
# --dry-run checks out anonymously and prints what would be committed.
set -euo pipefail

SLUG=meridian-digital-cjenik-i-sidrena-cijena
SVN_URL=${SVN_URL:-"https://plugins.svn.wordpress.org/$SLUG"}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
MODE=${1:-}
VERSION=${2:-}
DRY_RUN=${3:-}

die() { echo "Error: $*" >&2; exit 1; }

[[ $MODE == release || $MODE == listing ]] || die "usage: $0 release|listing <version> [--dry-run]"
[[ $VERSION =~ ^[0-9]+(\.[0-9]+)*$ ]] || die "version \"$VERSION\" must be numbers and dots only"
[[ -z $DRY_RUN || $DRY_RUN == --dry-run ]] || die "unknown option $DRY_RUN"
[[ -n $DRY_RUN || ( -n ${SVN_USERNAME:-} && -n ${SVN_PASSWORD:-} ) ]] || die "SVN_USERNAME and SVN_PASSWORD must be set"

VERSION_RE=${VERSION//./\\.}
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

unzip -q "$ROOT/dist/$SLUG.zip" -d "$WORK/build"
BUILD="$WORK/build/$SLUG"
grep -Eq "^[[:space:]*]*Version:[[:space:]]*$VERSION_RE[[:space:]]*$" "$BUILD/$SLUG.php" || die "dist/$SLUG.zip is not version $VERSION"
grep -Eq "^Stable tag:[[:space:]]*$VERSION_RE[[:space:]]*$" "$BUILD/readme.txt" || die "readme.txt Stable tag is not $VERSION"

tag_exists() { svn info --non-interactive "$SVN_URL/tags/$VERSION" > /dev/null 2>&1; }

svn checkout --quiet --non-interactive --depth immediates "$SVN_URL" "$WORK/svn"
cd "$WORK/svn"
svn update --quiet --non-interactive --set-depth infinity trunk assets

case $MODE in
	release)
		! tag_exists || die "tags/$VERSION already exists; bump the version for a code change"
		rsync -rc --delete --exclude=.svn "$BUILD/" trunk/
		;;
	listing)
		tag_exists || die "tags/$VERSION doesn't exist; release it first"
		svn update --quiet --non-interactive --set-depth infinity "tags/$VERSION"
		cp "$BUILD/readme.txt" trunk/readme.txt
		cp "$BUILD/readme.txt" "tags/$VERSION/readme.txt"
		;;
esac
rsync -rc --delete --exclude=.svn "$ROOT/.wordpress-org/" assets/

# Paths start after svn status's 8 status columns.
svn status | { grep '^?' || true; } | cut -c9- | while IFS= read -r path; do svn add --quiet --parents "$path"; done
svn status | { grep '^!' || true; } | cut -c9- | while IFS= read -r path; do svn rm --quiet "$path"; done
# Served with the right type instead of as downloads.
find assets -name '*.png' -exec svn propset --quiet svn:mime-type image/png {} +
find assets -name '*.svg' -exec svn propset --quiet svn:mime-type image/svg+xml {} +
if [[ $MODE == release ]]; then
	svn cp --quiet trunk "tags/$VERSION"
fi

echo "Changes for $SVN_URL ($MODE $VERSION):"
svn status

if [[ -n $DRY_RUN ]]; then
	echo "Dry run: nothing committed."
	exit 0
fi
message="$([[ $MODE == release ]] && echo "Release $VERSION" || echo "Update readme and assets for $VERSION")"
svn commit --non-interactive --no-auth-cache --username "$SVN_USERNAME" --password-from-stdin -m "$message" <<< "$SVN_PASSWORD"
