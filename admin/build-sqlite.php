#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Bytes;

require dirname(__DIR__) . '/vendor/autoload.php';

(new SingleCommandApplication())
    ->setName('build-sqlite')
    ->setVersion('0.2.0')
    ->setCode(
        function (
            SymfonyStyle $io,
            #[Option('Directory containing downloaded GeoNames source files.')]
            ?string $sourceDir = null,
            #[Option('Directory where SQLite files should be written.')]
            ?string $outputDir = null,
            #[Option('Hugging Face account or org name to use in generated dataset card examples.')]
            string $hfAccount = 'museado',
            #[Option('Rebuild SQLite databases even when the target file already exists.')]
            bool $force = false,
            #[Option('Country code to build a city database for. Repeat to generate more than one country database.')]
            array $country = ['us', 'mx', 'fr', 'es'],
        ): int {
            $filesystem = new Filesystem();
            $sourceDir ??= dirname(__DIR__) . '/var/geonames';
            $outputDir ??= dirname(__DIR__) . '/var/sqlite';

            $filesystem->mkdir($outputDir, 0700);
            $outputDir = rtrim($outputDir, '/');

            $requiredFiles = [
                $sourceDir . '/countryInfo.txt',
                $sourceDir . '/admin1CodesASCII.txt',
                $sourceDir . '/admin2Codes.txt',
                $sourceDir . '/allCountries.zip',
            ];

            foreach ($requiredFiles as $requiredFile) {
                if (!is_file($requiredFile)) {
                    throw new RuntimeException(sprintf(
                        'Missing required GeoNames source file: %s. Run admin/download-geonames.php first.',
                        $requiredFile,
                    ));
                }
            }

            $io->title('Build GeoNames SQLite databases');
            $io->text(sprintf('Source directory: %s', $sourceDir));
            $io->text(sprintf('Output directory: %s', $outputDir));

            $geoSqlite = $outputDir . '/geo.sqlite';
            if (shouldSkipDatabase($geoSqlite, 'country', $io, $force)) {
                $io->text(sprintf('Skipping %s.', basename($geoSqlite)));
            } else {
                buildGeoDatabase(
                    $geoSqlite,
                    $sourceDir . '/countryInfo.txt',
                    $sourceDir . '/admin1CodesASCII.txt',
                    $sourceDir . '/admin2Codes.txt',
                    $io,
                );
            }

            $requestedCountries = array_values(array_unique(array_map(
                static fn (string $code): string => strtoupper($code),
                $country,
            )));
            if (in_array('ALL', $requestedCountries, true)) {
                $requestedCountries = allCountryCodes($sourceDir . '/countryInfo.txt');
                $io->text(sprintf('Resolved ALL to %d country databases.', count($requestedCountries)));
            }

            foreach ($requestedCountries as $countryCode) {
                $countrySqlite = $outputDir . '/' . strtolower($countryCode) . '.sqlite';
                if (shouldSkipDatabase($countrySqlite, 'city', $io, $force)) {
                    $io->text(sprintf('Skipping %s.', basename($countrySqlite)));
                    continue;
                }

                buildCityDatabase(
                    $countrySqlite,
                    $sourceDir . '/allCountries.zip',
                    $countryCode,
                    $io,
                );
            }

            $allFiles = array_merge(
                ['geo.sqlite'],
                array_map(static fn (string $code): string => strtolower($code) . '.sqlite', $requestedCountries),
            );
            writeDatasetCard(
                $outputDir . '/README.md',
                datasetCard(
                    datasetId: sprintf('%s/geonames-data', $hfAccount),
                    prettyName: 'GeoNames Authority Data',
                    summary: 'GeoNames authority SQLite databases for countries, administrative areas, and country-specific populated places.',
                    files: $allFiles,
                    usageExample: "bin/console survos:geo\nbin/console survos:geo --country=us",
                ),
            );

            $io->success('SQLite authority databases created.');
            $io->listing(buildOutputSummary($outputDir, array_merge(['README.md'], $allFiles)));

            return Command::SUCCESS;
        }
    )
    ->run();

function buildGeoDatabase(
    string $databasePath,
    string $countryInfoPath,
    string $admin1Path,
    string $admin2Path,
    SymfonyStyle $io,
): void {
    @unlink($databasePath);
    $pdo = createSqliteConnection($databasePath);

    $io->section('Building geo.sqlite');
    $pdo->exec(<<<'SQL'
CREATE TABLE country (
    geoname_id INTEGER PRIMARY KEY,
    iso2 TEXT NOT NULL UNIQUE,
    iso3 TEXT NULL,
    iso_numeric TEXT NULL,
    fips TEXT NULL,
    name TEXT NOT NULL,
    capital TEXT NULL,
    area REAL NULL,
    population INTEGER NULL,
    continent TEXT NULL,
    tld TEXT NULL,
    currency_code TEXT NULL,
    currency_name TEXT NULL,
    phone TEXT NULL,
    postal_format TEXT NULL,
    postal_regex TEXT NULL,
    languages TEXT NULL,
    neighbors TEXT NULL
);
CREATE TABLE admin1 (
    geoname_id INTEGER PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    country_code TEXT NOT NULL,
    admin1_code TEXT NOT NULL,
    name TEXT NOT NULL,
    ascii_name TEXT NULL
);
CREATE TABLE admin2 (
    geoname_id INTEGER PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    country_code TEXT NOT NULL,
    admin1_code TEXT NOT NULL,
    admin2_code TEXT NOT NULL,
    name TEXT NOT NULL,
    ascii_name TEXT NULL
);
CREATE TABLE alias (
    alias TEXT NOT NULL,
    target_table TEXT NOT NULL,
    geoname_id INTEGER NOT NULL,
    PRIMARY KEY (alias, target_table, geoname_id)
);
SQL);

    $pdo->beginTransaction();

    $countryStatement = $pdo->prepare(<<<'SQL'
INSERT INTO country (
    geoname_id, iso2, iso3, iso_numeric, fips, name, capital, area, population, continent, tld,
    currency_code, currency_name, phone, postal_format, postal_regex, languages, neighbors
) VALUES (
    :geoname_id, :iso2, :iso3, :iso_numeric, :fips, :name, :capital, :area, :population, :continent, :tld,
    :currency_code, :currency_name, :phone, :postal_format, :postal_regex, :languages, :neighbors
)
SQL);
    $admin1Statement = $pdo->prepare(<<<'SQL'
INSERT INTO admin1 (geoname_id, code, country_code, admin1_code, name, ascii_name)
VALUES (:geoname_id, :code, :country_code, :admin1_code, :name, :ascii_name)
SQL);
    $admin2Statement = $pdo->prepare(<<<'SQL'
INSERT INTO admin2 (geoname_id, code, country_code, admin1_code, admin2_code, name, ascii_name)
VALUES (:geoname_id, :code, :country_code, :admin1_code, :admin2_code, :name, :ascii_name)
SQL);
    $aliasStatement = $pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO alias (alias, target_table, geoname_id)
VALUES (:alias, :target_table, :geoname_id)
SQL);

    $countryCount = 0;
    foreach (iterateTabFile($countryInfoPath, true) as $row) {
        if (count($row) < 17 || !is_numeric($row[16])) {
            continue;
        }

        [
            $iso2,
            $iso3,
            $isoNumeric,
            $fips,
            $name,
            $capital,
            $area,
            $population,
            $continent,
            $tld,
            $currencyCode,
            $currencyName,
            $phone,
            $postalFormat,
            $postalRegex,
            $languages,
            $geonameId,
            $neighbors,
        ] = array_pad($row, 18, null);

        $countryStatement->execute([
            'geoname_id' => (int) $geonameId,
            'iso2' => $iso2,
            'iso3' => normalizeNullable($iso3),
            'iso_numeric' => normalizeNullable($isoNumeric),
            'fips' => normalizeNullable($fips),
            'name' => $name,
            'capital' => normalizeNullable($capital),
            'area' => normalizeNullableNumeric($area),
            'population' => normalizeNullableInt($population),
            'continent' => normalizeNullable($continent),
            'tld' => normalizeNullable($tld),
            'currency_code' => normalizeNullable($currencyCode),
            'currency_name' => normalizeNullable($currencyName),
            'phone' => normalizeNullable($phone),
            'postal_format' => normalizeNullable($postalFormat),
            'postal_regex' => normalizeNullable($postalRegex),
            'languages' => normalizeNullable($languages),
            'neighbors' => normalizeNullable($neighbors),
        ]);
        insertAlias($aliasStatement, $iso2, 'country', (int) $geonameId);
        insertAlias($aliasStatement, $iso3, 'country', (int) $geonameId);
        ++$countryCount;
    }

    $admin1Count = 0;
    foreach (iterateTabFile($admin1Path) as $row) {
        if (count($row) < 4 || !is_numeric($row[3])) {
            continue;
        }

        [$code, $name, $asciiName, $geonameId] = $row;
        [$countryCode, $admin1Code] = explode('.', $code, 2);

        $admin1Statement->execute([
            'geoname_id' => (int) $geonameId,
            'code' => $code,
            'country_code' => $countryCode,
            'admin1_code' => $admin1Code,
            'name' => $name,
            'ascii_name' => normalizeNullable($asciiName),
        ]);
        insertAlias($aliasStatement, $code, 'admin1', (int) $geonameId);
        insertAlias($aliasStatement, strtolower($countryCode . '-' . $admin1Code), 'admin1', (int) $geonameId);
        insertAlias($aliasStatement, $admin1Code, 'admin1', (int) $geonameId);
        ++$admin1Count;
    }

    $admin2Count = 0;
    foreach (iterateTabFile($admin2Path) as $row) {
        if (count($row) < 4 || !is_numeric($row[3])) {
            continue;
        }

        [$code, $name, $asciiName, $geonameId] = $row;
        [$countryCode, $admin1Code, $admin2Code] = explode('.', $code, 3);

        $admin2Statement->execute([
            'geoname_id' => (int) $geonameId,
            'code' => $code,
            'country_code' => $countryCode,
            'admin1_code' => $admin1Code,
            'admin2_code' => $admin2Code,
            'name' => $name,
            'ascii_name' => normalizeNullable($asciiName),
        ]);
        insertAlias($aliasStatement, $code, 'admin2', (int) $geonameId);
        insertAlias($aliasStatement, strtolower($countryCode . '-' . $admin1Code . '-' . $admin2Code), 'admin2', (int) $geonameId);
        ++$admin2Count;
    }

    $pdo->commit();

    $pdo->exec(<<<'SQL'
CREATE INDEX idx_country_name ON country(name);
CREATE INDEX idx_admin1_country_code ON admin1(country_code);
CREATE INDEX idx_admin1_name ON admin1(name);
CREATE INDEX idx_admin2_country_code ON admin2(country_code);
CREATE INDEX idx_admin2_admin1_code ON admin2(country_code, admin1_code);
CREATE INDEX idx_admin2_name ON admin2(name);
CREATE INDEX idx_alias_lookup ON alias(alias, target_table);
ANALYZE;
VACUUM;
SQL);

    $io->text(sprintf('Inserted %d countries, %d admin1 rows, %d admin2 rows.', $countryCount, $admin1Count, $admin2Count));
}

function buildCityDatabase(
    string $databasePath,
    string $allCountriesZipPath,
    string $countryCode,
    SymfonyStyle $io,
): void {
    @unlink($databasePath);
    $pdo = createSqliteConnection($databasePath);

    $io->section(sprintf('Building %s', basename($databasePath)));
    $pdo->exec(<<<'SQL'
CREATE TABLE city (
    geoname_id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    ascii_name TEXT NULL,
    alternate_names TEXT NULL,
    latitude REAL NULL,
    longitude REAL NULL,
    feature_class TEXT NULL,
    feature_code TEXT NULL,
    country_code TEXT NOT NULL,
    admin1_code TEXT NULL,
    admin2_code TEXT NULL,
    population INTEGER NULL,
    timezone TEXT NULL,
    modification_date TEXT NULL
);
CREATE TABLE alias (
    alias TEXT NOT NULL,
    geoname_id INTEGER NOT NULL,
    PRIMARY KEY (alias, geoname_id)
);
SQL);

    $pdo->beginTransaction();

    $cityStatement = $pdo->prepare(<<<'SQL'
INSERT INTO city (
    geoname_id, name, ascii_name, alternate_names, latitude, longitude, feature_class, feature_code,
    country_code, admin1_code, admin2_code, population, timezone, modification_date
) VALUES (
    :geoname_id, :name, :ascii_name, :alternate_names, :latitude, :longitude, :feature_class, :feature_code,
    :country_code, :admin1_code, :admin2_code, :population, :timezone, :modification_date
)
SQL);
    $aliasStatement = $pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO alias (alias, geoname_id)
VALUES (:alias, :geoname_id)
SQL);

    $zipMember = 'allCountries.txt';
    $zipArchive = new \ZipArchive();
    if (true !== $zipArchive->open($allCountriesZipPath)) {
        throw new RuntimeException(sprintf('Unable to open zip archive %s', $allCountriesZipPath));
    }

    $memberStat = $zipArchive->statName($zipMember);
    $zipArchive->close();
    if (false === $memberStat || !isset($memberStat['size'])) {
        throw new RuntimeException(sprintf('Unable to read member metadata for %s in %s', $zipMember, $allCountriesZipPath));
    }

    $io->title(sprintf(
        'Importing %s from %s into %s',
        $zipMember,
        basename($allCountriesZipPath),
        basename($databasePath),
    ));

    $progressBar = new ProgressBar($io, (int) $memberStat['size']);
    $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
    $progressBar->setMessage(sprintf('Filtering %s cities', $countryCode));
    $progressBar->start();

    $cityCount = 0;
    foreach (iterateAllCountriesZip($allCountriesZipPath, $zipMember) as [$row, $position]) {
        $progressBar->setProgress(min((int) $memberStat['size'], $position));

        if (count($row) < 19) {
            continue;
        }

        [
            $geonameId,
            $name,
            $asciiName,
            $alternateNames,
            $latitude,
            $longitude,
            $featureClass,
            $featureCode,
            $rowCountryCode,
            $cc2,
            $admin1Code,
            $admin2Code,
            $admin3Code,
            $admin4Code,
            $population,
            $elevation,
            $dem,
            $timezone,
            $modificationDate,
        ] = array_pad($row, 19, null);

        if ($rowCountryCode !== $countryCode || $featureClass !== 'P') {
            continue;
        }

        $cityStatement->execute([
            'geoname_id' => (int) $geonameId,
            'name' => $name,
            'ascii_name' => normalizeNullable($asciiName),
            'alternate_names' => normalizeNullable($alternateNames),
            'latitude' => normalizeNullableNumeric($latitude),
            'longitude' => normalizeNullableNumeric($longitude),
            'feature_class' => normalizeNullable($featureClass),
            'feature_code' => normalizeNullable($featureCode),
            'country_code' => $rowCountryCode,
            'admin1_code' => normalizeNullable($admin1Code),
            'admin2_code' => normalizeNullable($admin2Code),
            'population' => normalizeNullableInt($population),
            'timezone' => normalizeNullable($timezone),
            'modification_date' => normalizeNullable($modificationDate),
        ]);
        insertCityAliases($aliasStatement, (int) $geonameId, $name, $asciiName, $alternateNames);
        ++$cityCount;
    }

    $progressBar->setProgress((int) $memberStat['size']);
    $progressBar->finish();
    $io->newLine(2);

    $pdo->commit();

    $pdo->exec(<<<'SQL'
CREATE INDEX idx_city_name ON city(name);
CREATE INDEX idx_city_ascii_name ON city(ascii_name);
CREATE INDEX idx_city_admin1_code ON city(admin1_code);
CREATE INDEX idx_city_population ON city(population);
CREATE INDEX idx_city_alias ON alias(alias);
ANALYZE;
VACUUM;
SQL);

    $io->text(sprintf('Inserted %d city rows for %s.', $cityCount, $countryCode));
}

function createSqliteConnection(string $databasePath): PDO
{
    try {
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA temp_store = MEMORY;');
        $pdo->exec('PRAGMA foreign_keys = OFF;');

        return $pdo;
    } catch (PDOException $exception) {
        throw new RuntimeException(sprintf('Unable to create SQLite database at %s', $databasePath), 0, $exception);
    }
}

/**
 * @return iterable<array<int, string>>
 */
function iterateTabFile(string $path, bool $skipComments = false): iterable
{
    $handle = fopen($path, 'rb');
    if (false === $handle) {
        throw new RuntimeException(sprintf('Unable to open %s', $path));
    }

    try {
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (!is_array($row) || $row === [null]) {
                continue;
            }

            if ($skipComments && isset($row[0]) && str_starts_with((string) $row[0], '#')) {
                continue;
            }

            yield array_map(static fn ($value): string => trim((string) $value), $row);
        }
    } finally {
        fclose($handle);
    }
}

/**
 * @return iterable<array{0: array<int, string>, 1: int}>
 */
function iterateAllCountriesZip(string $zipPath, string $memberName = 'allCountries.txt'): iterable
{
    $streamPath = sprintf('zip://%s#%s', $zipPath, $memberName);
    $handle = fopen($streamPath, 'rb');
    if (false === $handle) {
        throw new RuntimeException(sprintf('Unable to open %s', $streamPath));
    }

    try {
        while (($row = fgetcsv($handle, 0, "\t")) !== false) {
            if (!is_array($row) || $row === [null]) {
                continue;
            }

            yield [
                array_map(static fn ($value): string => trim((string) $value), $row),
                (int) ftell($handle),
            ];
        }
    } finally {
        fclose($handle);
    }
}

function insertAlias(\PDOStatement $statement, ?string $alias, string $targetTable, int $geonameId): void
{
    $alias = normalizeNullable($alias);
    if (null === $alias) {
        return;
    }

    $statement->execute([
        'alias' => strtolower($alias),
        'target_table' => $targetTable,
        'geoname_id' => $geonameId,
    ]);
}

function insertCityAliases(\PDOStatement $statement, int $geonameId, string $name, ?string $asciiName, ?string $alternateNames): void
{
    $aliases = array_filter([
        $name,
        $asciiName,
        ...explode(',', (string) $alternateNames),
    ]);

    foreach ($aliases as $alias) {
        $normalized = normalizeNullable($alias);
        if (null === $normalized) {
            continue;
        }

        $statement->execute([
            'alias' => strtolower($normalized),
            'geoname_id' => $geonameId,
        ]);
    }
}

function normalizeNullable(?string $value): ?string
{
    if (null === $value) {
        return null;
    }

    $trimmed = trim($value);

    return '' === $trimmed ? null : $trimmed;
}

function normalizeNullableInt(?string $value): ?int
{
    $value = normalizeNullable($value);

    return null === $value || !is_numeric($value) ? null : (int) $value;
}

function normalizeNullableNumeric(?string $value): ?float
{
    $value = normalizeNullable($value);

    return null === $value || !is_numeric($value) ? null : (float) $value;
}

function writeDatasetCard(string $path, string $contents): void
{
    if (false === file_put_contents($path, $contents)) {
        throw new RuntimeException(sprintf('Unable to write dataset card %s', $path));
    }
}

/**
 * @param list<string> $files
 */
function datasetCard(
    string $datasetId,
    string $prettyName,
    string $summary,
    array $files,
    string $usageExample,
): string {
    $fileLines = implode(PHP_EOL, array_map(
        static fn (string $file): string => sprintf('- `%s`', $file),
        $files,
    ));

    return <<<MD
---
pretty_name: {$prettyName}
license: mit
language:
- en
tags:
- geonames
- authority
- sqlite
- geography
viewer: false
task_categories:
- text-retrieval
---

# {$prettyName}

{$summary}

## Files

{$fileLines}

## Publishing

Suggested Hugging Face dataset id:

`{$datasetId}`

## Bundle Usage

```bash
{$usageExample}
```

The SQLite file is intended to be fetched by `survos/geonames-bundle` and queried locally through the bundle's runtime lookup service.
MD;
}

/**
 * @param list<string> $files
 * @return list<string>
 */
function buildOutputSummary(string $outputDir, array $files): array
{
    $summary = [];
    foreach ($files as $file) {
        $path = $outputDir . '/' . $file;
        $size = is_file($path) ? filesize($path) : false;
        $summary[] = false === $size
            ? $path
            : sprintf('%s (%s)', $path, (string) Bytes::parse((int) $size));
    }

    return $summary;
}

function shouldSkipDatabase(string $databasePath, string $tableName, SymfonyStyle $io, bool $force): bool
{
    if (!is_file($databasePath)) {
        return false;
    }

    $rowCount = countRows($databasePath, $tableName);
    $size = (string) Bytes::parse((int) filesize($databasePath));
    $io->text(sprintf(
        'Found existing %s with %d rows in `%s` (%s).',
        basename($databasePath),
        $rowCount,
        $tableName,
        $size,
    ));

    return !$force;
}

function countRows(string $databasePath, string $tableName): int
{
    $pdo = createSqliteConnection($databasePath);
    $statement = $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $tableName));

    return false === $statement ? 0 : (int) $statement->fetchColumn();
}

/**
 * @return list<string>
 */
function allCountryCodes(string $countryInfoPath): array
{
    $codes = [];
    foreach (iterateTabFile($countryInfoPath, true) as $row) {
        if (!isset($row[0], $row[16]) || !is_numeric($row[16])) {
            continue;
        }

        $iso2 = strtoupper(trim((string) $row[0]));
        if ('' !== $iso2) {
            $codes[$iso2] = true;
        }
    }

    $countryCodes = array_keys($codes);
    sort($countryCodes);

    return $countryCodes;
}
