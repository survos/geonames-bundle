# Geonames Bundle

`survos/geonames-bundle` is the GeoNames authority-list bundle for packaged SQLite place databases.

The runtime model is intentionally small:

- fetch a published authority database
- store it locally
- query it through a service such as `GeoService`

## Runtime Databases

Initial published databases:

- `geo.sqlite`
  contains countries and states
- `us.sqlite`
  contains United States cities

GeoNames provides the canonical ids, so the shared runtime base is `geo.sqlite`, with country-specific city databases layered on top.

## Build Workflow

Build-time tooling is separate from the public bundle command.

Admin tools:

- `admin/download-geonames.php`
- `admin/build-sqlite.php`

Current build sequence:

1. Download the GeoNames source files.
2. Run `admin/build-sqlite.php`.
3. Parse the downloaded GeoNames files directly and insert them into SQLite with prepared statements.
4. Publish the SQLite artifacts to Hugging Face.

## GeoNames Source Files

The build command currently depends on:

- `countryInfo.txt`
- `admin1CodesASCII.txt`
- `admin2Codes.txt`
- `allCountries.zip`

These files carry the GeoNames ids needed to keep countries, administrative areas, and cities on the same authority system.

## Publishing To Hugging Face

The published output should include:

- a `README.md` dataset card with YAML front matter
- `geo.sqlite`
- `us.sqlite`

The public bundle command will fetch those published SQLite files rather than rebuilding them locally.

## Installing The Bundle

```bash
composer req survos/geonames-bundle
```

## Fetching The Authority

The public Symfony command is:

```bash
bin/console survos:geo
```

Planned usage:

```bash
bin/console survos:geo
bin/console survos:geo --country=us
```

The current bundle command is still a stub; the admin commands are the active build path.

## Using The Bundle

The runtime API will stay lookup-oriented.

Example:

```php
$geoId = $this->geoService->find('Oslo');
```

We will refine the query methods and return values later. For now, the design center is local lookup against fetched authority data.

See [PLAN.md](/home/tac/g/sites/mono/bu/geonames-bundle/PLAN.md) for the CSV layout and next migration steps.
