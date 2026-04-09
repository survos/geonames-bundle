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

- `geo.sqlite`: countries and states
- `<countryCode>.sqlite`: country-specific city databases, starting with `us.sqlite`

Public bundle workflow:

1. Install `survos/geonames-bundle`
2. Run `bin/console survos:geo`
3. Fetch `geo.sqlite`
4. Optionally fetch `us.sqlite`
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

The build process should use GeoNames files directly.

Required files:

- `countryInfo.txt`
- `admin1CodesASCII.txt`
- `admin2Codes.txt`
- `allCountries.zip`

Why:

- countries and administrative areas have multiple external codes
- GeoNames ids are the stable canonical ids we want to publish
- cities need GeoNames ids anyway, so using GeoNames throughout keeps the authority consistent

## SQLite Targets

Initial SQLite outputs:

- `geo.sqlite`
  contains `country`, `admin1`, `admin2`, and alias lookups
- `us.sqlite`
  contains `city` and alias lookups for United States populated places

This keeps the first runtime story simple.

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

Initial published artifacts:

- `geo.sqlite`
- `us.sqlite`

## Next Steps

1. Build `geo.sqlite` from `countryInfo.txt`, `admin1CodesASCII.txt`, and `admin2Codes.txt`.
2. Build `us.sqlite` from `allCountries.zip` filtered to US populated places.
3. Review alias coverage for country and admin lookups.
4. Wire `survos:geo` to fetch the published databases.
