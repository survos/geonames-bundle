# Geonames Bundle Plan

## Goal

`survos/geonames-bundle` is the GeoNames authority-list bundle for packaged SQLite place databases.

The bundle should:

- treat source downloads and CSV generation as admin concerns
- publish prebuilt SQLite authority databases
- expose a small runtime lookup API through a service such as `GeoService`
- avoid Gedmo, ApiPlatform, and ORM-heavy loading paths
- use GeoNames identifiers as the canonical authority ids

## Public Product

Public runtime artifacts:

- `geo.sqlite`: every country, plus admin1/admin2 (state/county-level) records, plus alias lookups
- `<countryCode>.sqlite`: one per-country city database, for every country GeoNames covers — not just
  `us.sqlite`. `--country` on both admin commands (below) defaults to `ALL`; a single country is the
  narrowed case, not the initial one.

Public bundle workflow:

1. Install `survos/geonames-bundle`
2. Run `bin/console survos:geo`
3. Fetch `geo.sqlite`
4. Optionally fetch one or more `<countryCode>.sqlite` (e.g. `us`, `hu`, `ch`)
5. Query the local authority through `GeoService`

Example:

```php
$geoId = $this->geoService->find('Olso');
```

## Build Workflow

Admin tooling stays separate from the public bundle command.

Admin tools:

- `admin/download-geonames.php`
- `admin/build-sqlite.php`

Build steps:

1. Download raw or normalized source files.
2. Run `admin/build-sqlite.php`.
3. Parse GeoNames source files directly and insert them into SQLite with prepared statements.
4. Publish the SQLite files to Hugging Face.

## GeoNames Source Files

The build process uses GeoNames files directly.

Required files:

- `countryInfo.txt`, `admin1CodesASCII.txt`, `admin2Codes.txt`, `hierarchy.zip` — `geo.sqlite` inputs
- `<countryCode>.zip` (one per country, e.g. `US.zip`, `HU.zip`) — city inputs, fetched per country
  rather than the single combined `allCountries.zip`, so `download`/`build` can target a subset of
  countries without pulling (or re-parsing) the whole world.

Why:

- countries and administrative areas have multiple external codes
- GeoNames ids are the stable canonical ids we want to publish
- cities need GeoNames ids anyway, so using GeoNames throughout keeps the authority consistent

## SQLite Targets

SQLite outputs:

- `geo.sqlite`
  contains `country`, `admin1`, `admin2`, and alias lookups — every country GeoNames covers
- `<countryCode>.sqlite`, one per country
  contains `city` and alias lookups for that country's populated places. `admin/build --country=ALL`
  (the default) builds one for every country; `--country=us,hu,ch` narrows to a subset.

## Import Strategy

Do not use ORM for large imports.

Preferred path:

1. open GeoNames source files directly
2. create SQLite tables
3. use prepared statements inside transactions
4. add indexes after inserts
5. run `ANALYZE` and `VACUUM`

This avoids an intermediate CSV artifact and keeps validation plus normalization inside one Symfony console tool.

## Publishing

Each Hugging Face dataset directory should contain:

- a dataset-card `README.md` with YAML front matter
- the SQLite database file
- optional metadata files

Published artifacts: `geo.sqlite` plus one `<countryCode>.sqlite` per built country (not just `us`).

## Locale-Specific Alternate Names (survos/mono#26)

Cities carry a language-tagged `alt_name` table alongside `city`/`alias` in each `<countryCode>.sqlite`,
sourced from GeoNames' per-country `alternatenames/<CC>.zip` export (NOT the flat `alternatenames`
convenience column already on the `city` row, which has no language tagging at all).

- `admin download` also fetches `alternatenames/<CC>.zip` for every requested country, alongside
  `<CC>.zip`, into `<sourceDir>/alternatenames/<CC>.zip`.
- `admin build --force` imports it into `alt_name (geoname_id, iso_language, alternate_name,
  is_preferred, is_short, is_colloquial, is_historic)`, filtered to real ISO-639 language tags
  (GeoNames' `isolanguage` column also carries 4+ char pseudo-language codes for postal codes
  (`post`), Wikidata ids (`wkdt`), phonetic spellings (`phon`), transport/UN codes (`iata`, `icao`,
  `faac`, `unlc`, `tcid`), abbreviations (`abbr`), and historic-period tags (`fr_1793`) — filtering
  `iso_language` to length <= 3 drops all of those without an explicit denylist) and to geoname_ids
  already present in that country's `city` table.
- `GeoService::alternateNames(int $geonameId, string $countryCode): array` returns names keyed by
  ISO language code.

Not covered: `country`/`admin1`/`admin2` alternate names in `geo.sqlite` — those geoname_ids aren't
in any single country's `alternatenames/<CC>.zip` in general (admin1 name geoname_ids could show up
under their own country though, that's just not wired up yet). Fast-follow, not blocking — the
originating issue's use case (facet-vocabulary place tags) is dominated by cities.

## Actual status (last checked 2026-07-11)

What works:

- `admin download` / `admin build --force` genuinely build `geo.sqlite` and per-country `<cc>.sqlite`
  from real GeoNames source files — prepared statements, indexes after insert, no ORM, matches the
  Import Strategy above. `--country` already defaults to `ALL`.
- `GeoService`'s runtime query API (country/admin1/admin2/city lookups, multi-level
  "City, State, Country" parsing, alias resolution) is implemented and reasonably capable.

What's missing:

1. **`survos:geo` (the public fetch command) is a stub** — it does not actually download anything yet.
   No app can currently consume a published database through the bundle's own workflow.
2. **Nothing has actually been published.** `refreshMetadata()` regenerates local dataset-card
   `README.md`s only; there is no confirmed upload-to-Hugging-Face step, and no SQLite file exists
   anywhere in this repo or its build output — the pipeline has not been run end-to-end.
3. `data/normalized/{iso-3166-2,world-cities}.json` (~2.9 MB) are unused by any current code — dead
   weight from an earlier pre-SQLite approach. Delete once confirmed nothing external depends on them.
4. The bundle class is still the pre-`kit-bundle` pattern (`src/DependencyInjection/{Configuration,
   SurvosGeonamesExtension}.php` + `src/Resources/config/services.php`) — see `bu/CLAUDE.md`'s bundle
   rules; migrate onto `AbstractSurvosBundle` when next touched.
5. Not wired into any consuming app yet.

## Next Steps

1. Actually run `admin download --country=ALL` + `admin build --force --country=ALL` once, end to end,
   and confirm real output.
2. Decide and implement the actual Hugging Face upload step (or another distribution mechanism).
3. Implement `survos:geo` for real: fetch `geo.sqlite` (+ requested `<countryCode>.sqlite`) from wherever
   step 2 publishes to.
4. Delete `data/normalized/`.
5. Migrate the bundle class onto `kit-bundle`/`AbstractSurvosBundle`.
6. Review alias coverage for country and admin lookups.
