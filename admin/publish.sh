#!/usr/bin/env bash
set -euo pipefail

# Downloads GeoNames source files, rebuilds the SQLite authority databases (including the
# locale-specific alt_name tables added for survos/mono#26), and publishes the result to the
# museado/geonames-data Hugging Face dataset.
#
# Usage:
#   ./publish.sh                  # every country GeoNames covers (default)
#   ./publish.sh us,hu,ch         # just these countries
#   SKIP_UPLOAD=1 ./publish.sh hu # download + build only, skip the hf upload step
#
# Requires:
#   - `composer install` already run in the bundle root (../) so admin.php can autoload
#   - the `hf` CLI (`pip install -U huggingface_hub[cli]`), logged in with write access to
#     the target dataset repo (`hf auth login`) unless SKIP_UPLOAD=1

ADMIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ADMIN_DIR"

COUNTRIES="${1:-ALL}"
HF_REPO="${HF_REPO:-museado/geonames-data}"

if [ ! -f ../vendor/autoload.php ]; then
    echo "Missing dependencies — run 'composer install' in $ADMIN_DIR/.. first." >&2
    exit 1
fi

if [ "${SKIP_UPLOAD:-0}" != "1" ]; then
    if ! command -v hf >/dev/null 2>&1; then
        echo "hf CLI not found. Install: pip install -U huggingface_hub[cli]" >&2
        exit 1
    fi
    if ! hf auth whoami >/dev/null 2>&1; then
        echo "Not logged in to Hugging Face. Run 'hf auth login' first (needs write access to $HF_REPO)." >&2
        exit 1
    fi
fi

# "us,hu,ch" -> --country=us --country=hu --country=ch (the console options don't split
# comma-separated values themselves, each --country= occurrence is one array entry)
COUNTRY_FLAGS=()
IFS=',' read -ra CODES <<< "$COUNTRIES"
for code in "${CODES[@]}"; do
    COUNTRY_FLAGS+=("--country=${code}")
done

echo "==> Downloading GeoNames source files (${COUNTRIES})"
php admin.php download "${COUNTRY_FLAGS[@]}"

echo "==> Building SQLite authority databases (${COUNTRIES})"
php admin.php build --force "${COUNTRY_FLAGS[@]}"

# Read GEONAMES_OUTPUT_DIR the same way admin.php does (.env, then .env.local override)
set -a
# shellcheck disable=SC1091
source .env
if [ -f .env.local ]; then
    # shellcheck disable=SC1091
    source .env.local
fi
set +a

if [ "${SKIP_UPLOAD:-0}" = "1" ]; then
    echo "==> SKIP_UPLOAD=1, not publishing to Hugging Face. Built files are in $GEONAMES_OUTPUT_DIR"
else
    echo "==> Publishing $GEONAMES_OUTPUT_DIR to https://huggingface.co/datasets/${HF_REPO}"
    hf upload "$HF_REPO" "$GEONAMES_OUTPUT_DIR" --repo-type=dataset \
        --commit-message="Rebuild GeoNames authority databases (${COUNTRIES})"
fi

echo "==> Done."
