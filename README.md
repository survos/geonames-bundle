# Geonames Bundle

`survos/geonames-bundle` is the GeoNames authority-list bundle for packaged SQLite place databases.

The runtime model is intentionally small:

- fetch a published authority database
- store it locally
- query it through a service such as `GeoService`

## Runtime Databases

Published databases:

- `geo.sqlite`
  contains `country`, `admin1`, `admin2`, and alias lookups for every country GeoNames covers
- `<countryCode>.sqlite` (e.g. `us.sqlite`, `hu.sqlite`)
  contains that country's cities (`city` table), name-lookup aliases (`alias` table), and
  locale-specific alternate names (`alt_name` table — see below)

GeoNames provides the canonical ids, so the shared runtime base is `geo.sqlite`, with country-specific city databases layered on top.

## Scope: what this bundle is not

GeoNames covers countries, administrative regions, and populated places (cities/towns) — it is not
comprehensive for individual schools, stores, churches, or other points of interest, and this bundle
doesn't try to make it one. For OSM/Nominatim-backed points of interest — a specific building, park,
or business, as opposed to the city it's in — see
[`survos/place-map-bundle`](../place-map-bundle), the companion bundle for that layer. See
[survos/mono#28](https://github.com/survos/mono/issues/28) for how that split was decided.

### Locale-specific alternate names (`alt_name`)

Each `<countryCode>.sqlite` also carries an `alt_name` table (`geoname_id`, `iso_language`,
`alternate_name`, `is_preferred`, `is_short`, `is_colloquial`, `is_historic`) sourced from GeoNames'
per-country `alternatenames/<CC>.zip` export — the real language-tagged data (German "Wien" vs.
French "Vienne" vs. English "Vienna" for the same `geonameId`), not the untagged `alternatenames`
convenience column already on `city` rows. Query it through:

```php
$names = $this->geoService->alternateNames(3054643, 'HU'); // Budapest
// ['de' => ['Budapest', 'Ofen-Pest'], 'ru' => ['Будапешт'], 'sk' => ['Budapešť'], ...]
```

Cities only for now — `country`/`admin1`/`admin2` alt names in `geo.sqlite` aren't wired up yet (see
PLAN.md). Background: [survos/mono#26](https://github.com/survos/mono/issues/26).

## Build Workflow

Build-time tooling is separate from the public bundle command, so the published bundle itself stays
light — `admin/` has its own dev dependencies (`composer install` inside `bu/geonames-bundle`) and
never ships to consumer apps.

Admin console (`admin/admin.php`, a standalone Symfony Console app):

```bash
cd admin
php admin.php download --country=hu       # fetch GeoNames source files for one country
php admin.php download --country=us,hu,ch # or several
php admin.php download --all              # every country (~a few GB, minutes at broadband speed)
php admin.php build --force --country=hu  # rebuild geo.sqlite + hu.sqlite from downloaded sources
php admin.php metadata                    # regenerate README.md/datasets.jsonl only, no rebuild
```

`download` fetches, per requested country, both `<CC>.zip` (cities) and `alternatenames/<CC>.zip`
(locale-specific alternate names — see above) alongside the shared `countryInfo.txt`,
`admin1CodesASCII.txt`, `admin2Codes.txt`, `timeZones.txt`, `hierarchy.zip`. `build --force` parses
those files directly into SQLite with prepared statements — no ORM — and refreshes the dataset-card
`README.md`/`datasets.jsonl` in the output directory.

### One-shot: download + build + publish

`admin/publish.sh` runs the full pipeline — download, build, and `hf upload` to the
`museado/geonames-data` Hugging Face dataset — for a country or every country:

```bash
cd admin
./publish.sh                  # every country GeoNames covers
./publish.sh us,hu,ch         # just these countries
SKIP_UPLOAD=1 ./publish.sh hu # download + build only, skip the Hugging Face upload
```

Requires the [`hf` CLI](https://huggingface.co/docs/huggingface_hub/guides/cli)
(`pip install -U huggingface_hub[cli]`) logged in with write access to the dataset repo
(`hf auth login`) unless `SKIP_UPLOAD=1`.

## Publishing To Hugging Face

Each Hugging Face dataset directory contains a `README.md` dataset card, `datasets.jsonl`, `geo.sqlite`,
and one `<countryCode>.sqlite` per built country. `survos:geo` (the runtime bundle command, below)
fetches those published files rather than rebuilding them locally.

## Installing The Bundle

```bash
composer req survos/geonames-bundle
```

## Fetching The Authority

The public Symfony command:

```bash
bin/console survos:geo                  # fetch geo.sqlite
bin/console survos:geo --country=us,hu  # + one or more per-country city databases
bin/console survos:geo --all            # every published per-country database (~3.4 GB)
```

fetches the published SQLite databases straight from the `museado/geonames-data` Hugging Face
dataset into `GeoService::sqliteDir()` — no auth needed, it's a public repo.

## Using The Bundle

The runtime API will stay lookup-oriented.

Example:

```php
$geoId = $this->geoService->find('Oslo');
```

We will refine the query methods and return values later. For now, the design center is local lookup against fetched authority data.

See [PLAN.md](./PLAN.md) for the CSV layout and next migration steps.
