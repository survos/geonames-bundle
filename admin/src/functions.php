<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin;

use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\IO\JsonlWriterOptions;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Bytes;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use ZipArchive;

function requireBuildSources(string $sourceDir): void
{
    foreach ([
        $sourceDir . '/countryInfo.txt',
        $sourceDir . '/admin1CodesASCII.txt',
        $sourceDir . '/admin2Codes.txt',
    ] as $requiredFile) {
        if (!is_file($requiredFile)) {
            throw new RuntimeException(sprintf(
                'Missing required GeoNames source file: %s. Run ./admin download first.',
                $requiredFile,
            ));
        }
    }
}

function refreshMetadata(string $outputDir, string $hfAccount): void
{
    $allFiles = generatedSqliteFiles($outputDir);
    $entries = collectDatasetEntries($outputDir, $allFiles);

    writeDatasetCard(
        $outputDir . '/README.md',
        datasetCard(
            datasetId: sprintf('%s/geonames-data', $hfAccount),
            prettyName: 'GeoNames Authority Data',
            summary: 'GeoNames authority SQLite databases for countries, administrative areas, and country-specific populated places.',
            entries: $entries,
            usageExample: "./admin download\n./admin build --force\n./admin metadata",
        ),
    );
    writeDatasetsJsonl($outputDir . '/datasets.jsonl', $entries);
}

function downloadToFile(HttpClientInterface $httpClient, SymfonyStyle $io, string $url, string $targetPath, string $label): void
{
    $progress = new ProgressBar($io, 100);
    $progress->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% Mem: %memory:6s% %message%');
    $progress->setMessage($label);
    $progress->start();

    $tmpPath = $targetPath . '.part';
    $response = $httpClient->request('GET', $url, [
        'on_progress' => static function (int $downloadedSize, int $totalSize) use ($progress): void {
            if ($totalSize > 0) {
                $progress->setProgress((int) min(100, round(($downloadedSize / $totalSize) * 100)));
            }
        },
    ]);

    $handle = fopen($tmpPath, 'wb');
    if (false === $handle) {
        throw new RuntimeException(sprintf('Unable to open "%s" for writing.', $tmpPath));
    }

    try {
        foreach ($httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }

            fwrite($handle, $chunk->getContent());
        }
    } finally {
        fclose($handle);
    }

    rename($tmpPath, $targetPath);
    $progress->setProgress(100);
    $progress->finish();
    $io->newLine(2);
}

function buildGeoDatabase(string $databasePath, string $countryInfoPath, string $admin1Path, string $admin2Path, SymfonyStyle $io): void
{
    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    $pdo = createSqliteConnection($databasePath);
    $io->section('Building geo.sqlite');
    $pdo->exec(<<<'SQL'
CREATE TABLE country ( geoname_id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL UNIQUE, iso3 TEXT NULL, iso_numeric TEXT NULL, fips TEXT NULL, name TEXT NOT NULL, capital TEXT NULL, area REAL NULL, population INTEGER NULL, continent TEXT NULL, tld TEXT NULL, currency_code TEXT NULL, currency_name TEXT NULL, phone TEXT NULL, postal_format TEXT NULL, postal_regex TEXT NULL, languages TEXT NULL, neighbors TEXT NULL );
CREATE TABLE admin1 ( geoname_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, country_code TEXT NOT NULL, admin1_code TEXT NOT NULL, name TEXT NOT NULL, ascii_name TEXT NULL );
CREATE TABLE admin2 ( geoname_id INTEGER PRIMARY KEY, code TEXT NOT NULL UNIQUE, country_code TEXT NOT NULL, admin1_code TEXT NOT NULL, admin2_code TEXT NOT NULL, name TEXT NOT NULL, ascii_name TEXT NULL );
CREATE TABLE alias ( alias TEXT NOT NULL, target_table TEXT NOT NULL, geoname_id INTEGER NOT NULL, PRIMARY KEY (alias, target_table, geoname_id) );
SQL);
    $pdo->beginTransaction();
    $countryStatement = $pdo->prepare("INSERT INTO country (geoname_id, iso2, iso3, iso_numeric, fips, name, capital, area, population, continent, tld, currency_code, currency_name, phone, postal_format, postal_regex, languages, neighbors) VALUES (:geoname_id, :iso2, :iso3, :iso_numeric, :fips, :name, :capital, :area, :population, :continent, :tld, :currency_code, :currency_name, :phone, :postal_format, :postal_regex, :languages, :neighbors)");
    $admin1Statement = $pdo->prepare("INSERT INTO admin1 (geoname_id, code, country_code, admin1_code, name, ascii_name) VALUES (:geoname_id, :code, :country_code, :admin1_code, :name, :ascii_name)");
    $admin2Statement = $pdo->prepare("INSERT INTO admin2 (geoname_id, code, country_code, admin1_code, admin2_code, name, ascii_name) VALUES (:geoname_id, :code, :country_code, :admin1_code, :admin2_code, :name, :ascii_name)");
    $aliasStatement = $pdo->prepare("INSERT OR IGNORE INTO alias (alias, target_table, geoname_id) VALUES (:alias, :target_table, :geoname_id)");
    $countryCount = 0;
    foreach (iterateTabFile($countryInfoPath, true) as $row) {
        if (count($row) < 17 || !is_numeric($row[16])) continue;
        [$iso2,$iso3,$isoNumeric,$fips,$name,$capital,$area,$population,$continent,$tld,$currencyCode,$currencyName,$phone,$postalFormat,$postalRegex,$languages,$geonameId,$neighbors] = array_pad($row, 18, null);
        $countryStatement->execute(['geoname_id'=>(int)$geonameId,'iso2'=>$iso2,'iso3'=>normalizeNullable($iso3),'iso_numeric'=>normalizeNullable($isoNumeric),'fips'=>normalizeNullable($fips),'name'=>$name,'capital'=>normalizeNullable($capital),'area'=>normalizeNullableNumeric($area),'population'=>normalizeNullableInt($population),'continent'=>normalizeNullable($continent),'tld'=>normalizeNullable($tld),'currency_code'=>normalizeNullable($currencyCode),'currency_name'=>normalizeNullable($currencyName),'phone'=>normalizeNullable($phone),'postal_format'=>normalizeNullable($postalFormat),'postal_regex'=>normalizeNullable($postalRegex),'languages'=>normalizeNullable($languages),'neighbors'=>normalizeNullable($neighbors)]);
        insertAlias($aliasStatement, $iso2, 'country', (int) $geonameId);
        insertAlias($aliasStatement, $iso3, 'country', (int) $geonameId);
        ++$countryCount;
    }
    $admin1Count = 0;
    foreach (iterateTabFile($admin1Path) as $row) {
        if (count($row) < 4 || !is_numeric($row[3])) continue;
        [$code,$name,$asciiName,$geonameId] = $row;
        [$countryCode,$admin1Code] = explode('.', $code, 2);
        $admin1Statement->execute(['geoname_id'=>(int)$geonameId,'code'=>$code,'country_code'=>$countryCode,'admin1_code'=>$admin1Code,'name'=>$name,'ascii_name'=>normalizeNullable($asciiName)]);
        insertAlias($aliasStatement, $code, 'admin1', (int) $geonameId);
        insertAlias($aliasStatement, strtolower($countryCode.'-'.$admin1Code), 'admin1', (int) $geonameId);
        insertAlias($aliasStatement, $admin1Code, 'admin1', (int) $geonameId);
        ++$admin1Count;
    }
    $admin2Count = 0;
    foreach (iterateTabFile($admin2Path) as $row) {
        if (count($row) < 4 || !is_numeric($row[3])) continue;
        [$code,$name,$asciiName,$geonameId] = $row;
        [$countryCode,$admin1Code,$admin2Code] = explode('.', $code, 3);
        $admin2Statement->execute(['geoname_id'=>(int)$geonameId,'code'=>$code,'country_code'=>$countryCode,'admin1_code'=>$admin1Code,'admin2_code'=>$admin2Code,'name'=>$name,'ascii_name'=>normalizeNullable($asciiName)]);
        insertAlias($aliasStatement, $code, 'admin2', (int) $geonameId);
        insertAlias($aliasStatement, strtolower($countryCode.'-'.$admin1Code.'-'.$admin2Code), 'admin2', (int) $geonameId);
        ++$admin2Count;
    }
    $pdo->commit();
    $pdo->exec("CREATE INDEX idx_country_name ON country(name); CREATE INDEX idx_admin1_country_code ON admin1(country_code); CREATE INDEX idx_admin1_name ON admin1(name); CREATE INDEX idx_admin2_country_code ON admin2(country_code); CREATE INDEX idx_admin2_admin1_code ON admin2(country_code, admin1_code); CREATE INDEX idx_admin2_name ON admin2(name); CREATE INDEX idx_alias_lookup ON alias(alias, target_table); ANALYZE; VACUUM;");
    $io->text(sprintf('Inserted %d countries, %d admin1 rows, %d admin2 rows.', $countryCount, $admin1Count, $admin2Count));
}

function buildCityDatabase(string $databasePath, string $countryZipPath, string $countryCode, SymfonyStyle $io): void
{
    if (!is_file($countryZipPath)) throw new RuntimeException(sprintf('Missing required GeoNames country archive: %s. Run ./admin download --country=%s first.', $countryZipPath, strtoupper($countryCode)));
    @unlink($databasePath); @unlink($databasePath.'-wal'); @unlink($databasePath.'-shm');
    $pdo = createSqliteConnection($databasePath);
    $io->section(sprintf('Building %s', basename($databasePath)));
    $pdo->exec("CREATE TABLE city ( geoname_id INTEGER PRIMARY KEY, name TEXT NOT NULL, ascii_name TEXT NULL, alternate_names TEXT NULL, latitude REAL NULL, longitude REAL NULL, feature_class TEXT NULL, feature_code TEXT NULL, country_code TEXT NOT NULL, admin1_code TEXT NULL, admin2_code TEXT NULL, population INTEGER NULL, timezone TEXT NULL, modification_date TEXT NULL ); CREATE TABLE alias ( alias TEXT NOT NULL, geoname_id INTEGER NOT NULL, PRIMARY KEY (alias, geoname_id) );");
    $pdo->beginTransaction();
    $cityStatement = $pdo->prepare("INSERT INTO city (geoname_id, name, ascii_name, alternate_names, latitude, longitude, feature_class, feature_code, country_code, admin1_code, admin2_code, population, timezone, modification_date) VALUES (:geoname_id, :name, :ascii_name, :alternate_names, :latitude, :longitude, :feature_class, :feature_code, :country_code, :admin1_code, :admin2_code, :population, :timezone, :modification_date)");
    $aliasStatement = $pdo->prepare("INSERT OR IGNORE INTO alias (alias, geoname_id) VALUES (:alias, :geoname_id)");
    [$zipMember,$memberSize] = resolveZipMember($countryZipPath, strtoupper($countryCode));
    $io->title(sprintf('Importing %s from %s into %s', $zipMember, basename($countryZipPath), basename($databasePath)));
    $progressBar = new ProgressBar($io, $memberSize);
    $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
    $progressBar->setMessage(sprintf('Importing %s cities', strtoupper($countryCode)));
    $progressBar->start();
    $cityCount = 0;
    foreach (iterateCountryZip($countryZipPath, $zipMember) as [$row,$position]) {
        $progressBar->setProgress(min($memberSize, $position));
        if (count($row) < 19) continue;
        [$geonameId,$name,$asciiName,$alternateNames,$latitude,$longitude,$featureClass,$featureCode,$rowCountryCode,$cc2,$admin1Code,$admin2Code,$admin3Code,$admin4Code,$population,$elevation,$dem,$timezone,$modificationDate] = array_pad($row, 19, null);
        if (strtoupper((string)$rowCountryCode) !== strtoupper($countryCode) || $featureClass !== 'P') continue;
        $cityStatement->execute(['geoname_id'=>(int)$geonameId,'name'=>$name,'ascii_name'=>normalizeNullable($asciiName),'alternate_names'=>normalizeNullable($alternateNames),'latitude'=>normalizeNullableNumeric($latitude),'longitude'=>normalizeNullableNumeric($longitude),'feature_class'=>normalizeNullable($featureClass),'feature_code'=>normalizeNullable($featureCode),'country_code'=>$rowCountryCode,'admin1_code'=>normalizeNullable($admin1Code),'admin2_code'=>normalizeNullable($admin2Code),'population'=>normalizeNullableInt($population),'timezone'=>normalizeNullable($timezone),'modification_date'=>normalizeNullable($modificationDate)]);
        insertCityAliases($aliasStatement, (int)$geonameId, $name, $asciiName, $alternateNames);
        ++$cityCount;
    }
    $progressBar->setProgress($memberSize); $progressBar->finish(); $io->newLine(2);
    $pdo->commit();
    $pdo->exec("CREATE INDEX idx_city_name ON city(name); CREATE INDEX idx_city_ascii_name ON city(ascii_name); CREATE INDEX idx_city_admin1_code ON city(admin1_code); CREATE INDEX idx_city_population ON city(population); CREATE INDEX idx_city_alias ON alias(alias); ANALYZE; VACUUM;");
    $io->text(sprintf('Inserted %d city rows for %s.', $cityCount, strtoupper($countryCode)));
}

function createSqliteConnection(string $databasePath): PDO { try { $pdo = new PDO('sqlite:' . $databasePath); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->exec('PRAGMA journal_mode = WAL;'); $pdo->exec('PRAGMA synchronous = NORMAL;'); $pdo->exec('PRAGMA temp_store = MEMORY;'); $pdo->exec('PRAGMA foreign_keys = OFF;'); return $pdo; } catch (PDOException $exception) { throw new RuntimeException(sprintf('Unable to create SQLite database at %s', $databasePath), 0, $exception); } }
function iterateTabFile(string $path, bool $skipComments = false): iterable { $handle = fopen($path, 'rb'); if (false === $handle) throw new RuntimeException(sprintf('Unable to open %s', $path)); try { while (($row = fgetcsv($handle, 0, "\t")) !== false) { if (!is_array($row) || $row === [null]) continue; if ($skipComments && isset($row[0]) && str_starts_with((string) $row[0], '#')) continue; yield array_map(static fn($value): string => trim((string) $value), $row); } } finally { fclose($handle); } }
function iterateCountryZip(string $zipPath, string $memberName): iterable { $streamPath = sprintf('zip://%s#%s', $zipPath, $memberName); $handle = fopen($streamPath, 'rb'); if (false === $handle) throw new RuntimeException(sprintf('Unable to open %s', $streamPath)); try { while (($row = fgetcsv($handle, 0, "\t")) !== false) { if (!is_array($row) || $row === [null]) continue; yield [array_map(static fn($value): string => trim((string) $value), $row), (int) ftell($handle)]; } } finally { fclose($handle); } }
function resolveZipMember(string $zipPath, string $countryCode): array { $zipArchive = new ZipArchive(); if (true !== $zipArchive->open($zipPath)) throw new RuntimeException(sprintf('Unable to open zip archive %s', $zipPath)); try { $preferredMember = $countryCode . '.txt'; $preferredStat = $zipArchive->statName($preferredMember); if (is_array($preferredStat) && isset($preferredStat['size'])) return [$preferredMember, (int)$preferredStat['size']]; for ($index = 0; $index < $zipArchive->numFiles; ++$index) { $stat = $zipArchive->statIndex($index); if (!is_array($stat) || !isset($stat['name'], $stat['size'])) continue; $name = (string) $stat['name']; if ($name === '' || str_ends_with($name, '/')) continue; $lowerName = strtolower($name); if ($lowerName === 'readme.txt' || !str_ends_with($lowerName, '.txt')) continue; return [$name, (int)$stat['size']]; } } finally { $zipArchive->close(); } throw new RuntimeException(sprintf('Unable to locate an importable member in %s', $zipPath)); }
function insertAlias(PDOStatement $statement, ?string $alias, string $targetTable, int $geonameId): void { $alias = normalizeNullable($alias); if (null === $alias) return; $statement->execute(['alias'=>strtolower($alias),'target_table'=>$targetTable,'geoname_id'=>$geonameId]); }
function insertCityAliases(PDOStatement $statement, int $geonameId, string $name, ?string $asciiName, ?string $alternateNames): void { $aliases = array_filter([$name,$asciiName,...explode(',', (string)$alternateNames)]); foreach ($aliases as $alias) { $normalized = normalizeNullable($alias); if (null === $normalized) continue; $statement->execute(['alias'=>strtolower($normalized),'geoname_id'=>$geonameId]); } }
function normalizeNullable(?string $value): ?string { if (null === $value) return null; $trimmed = trim($value); return '' === $trimmed ? null : $trimmed; }
function normalizeNullableInt(?string $value): ?int { $value = normalizeNullable($value); return null === $value || !is_numeric($value) ? null : (int)$value; }
function normalizeNullableNumeric(?string $value): ?float { $value = normalizeNullable($value); return null === $value || !is_numeric($value) ? null : (float)$value; }
function writeDatasetCard(string $path, string $contents): void { if (false === file_put_contents($path, $contents)) throw new RuntimeException(sprintf('Unable to write dataset card %s', $path)); }
function datasetCard(string $datasetId, string $prettyName, string $summary, array $entries, string $usageExample): string { $samples = collectReadmeSqliteExamples($entries); $fileLines = implode(PHP_EOL, array_map(static fn(array $stat): string => sprintf('| `%s` | %s | %s | %s |', $stat['file'], $stat['kind'], $stat['rows'], $stat['size']), $entries)); return <<<MD
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

| File | Contents | Rows | Size |
| --- | --- | ---: | ---: |
{$fileLines}

## Publishing

Suggested Hugging Face dataset id:

`{$datasetId}`

## Sample Rows

### `geo.sqlite` `country`

```bash
{$samples['country_command']}
```

```text
{$samples['country_output']}
```

### `geo.sqlite` `admin1`

```bash
{$samples['admin1_command']}
```

```text
{$samples['admin1_output']}
```

### `geo.sqlite` `admin2`

```bash
{$samples['admin2_command']}
```

```text
{$samples['admin2_output']}
```

### `us.sqlite` `city`

```bash
{$samples['city_command']}
```

```text
{$samples['city_output']}
```

## Bundle Usage

```bash
{$usageExample}
```

The SQLite file is intended to be fetched by `survos/geonames-bundle` and queried locally through the bundle's runtime lookup service.
MD;
}
function buildOutputSummary(string $outputDir, array $files): array { $summary=[]; foreach ($files as $file) { $path=$outputDir.'/'.$file; $size=is_file($path)?filesize($path):false; $summary[] = false === $size ? $path : sprintf('%s (%s)', $path, (string) Bytes::parse((int)$size)); } return $summary; }
function generatedSqliteFiles(string $outputDir): array { $matches = glob($outputDir . '/*.sqlite'); if (false === $matches) return []; $files = array_map(static fn(string $path): string => basename($path), $matches); sort($files); return $files; }
function collectDatasetEntries(string $outputDir, array $files): array { $stats=[]; $countryNames = countryNameMap($outputDir.'/geo.sqlite'); foreach ($files as $file) { $path=$outputDir.'/'.$file; if (!is_file($path)) continue; $bytes=(int)filesize($path); $size=(string)Bytes::parse($bytes); if ($file==='geo.sqlite') { $countryCount=countRows($path,'country'); $admin1Count=countRows($path,'admin1'); $admin2Count=countRows($path,'admin2'); $stats[]=['file'=>$file,'path'=>$path,'kind'=>'countries, admin1, admin2','rows'=>sprintf('%d / %d / %d',$countryCount,$admin1Count,$admin2Count),'size'=>$size,'bytes'=>$bytes,'country_code'=>null,'country_name'=>null,'table_counts'=>['country'=>$countryCount,'admin1'=>$admin1Count,'admin2'=>$admin2Count]]; continue; } $countryCode = strtoupper((string) preg_replace('/\.sqlite$/', '', $file)); $cityCount = countRows($path, 'city'); $stats[]=['file'=>$file,'path'=>$path,'kind'=>sprintf('%s cities', $countryNames[$countryCode] ?? $countryCode),'rows'=>(string)$cityCount,'size'=>$size,'bytes'=>$bytes,'country_code'=>$countryCode,'country_name'=>$countryNames[$countryCode] ?? null,'table_counts'=>['city'=>$cityCount]]; } return $stats; }
function writeDatasetsJsonl(string $path, array $entries): void { $writer = JsonlWriter::open($path, options: new JsonlWriterOptions(ensureDir: true)); try { foreach ($entries as $entry) { $writer->write(['type'=>$entry['file']==='geo.sqlite'?'geo':'country-cities','file'=>$entry['file'],'path'=>$entry['path'],'countryCode'=>$entry['country_code'],'countryName'=>$entry['country_name'],'kind'=>$entry['kind'],'size'=>['bytes'=>$entry['bytes'],'human'=>$entry['size']],'tableCounts'=>$entry['table_counts']]); } } finally { $writer->finish(); } }
function countryNameMap(string $geoDatabasePath): array { if (!is_file($geoDatabasePath)) return []; $pdo=createSqliteConnection($geoDatabasePath); $statement=$pdo->query('SELECT iso2, name FROM country'); if (false === $statement) return []; $map=[]; foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) { $iso2=strtoupper((string)($row['iso2']??'')); $name=trim((string)($row['name']??'')); if ($iso2!=='' && $name!=='') $map[$iso2]=$name; } return $map; }
function collectReadmeSqliteExamples(array $entries): array { $samples=['country_command'=>'sqlite3 -header -column geo.sqlite "select * from country limit 3;"','country_output'=>'','admin1_command'=>'sqlite3 -header -column geo.sqlite "select * from admin1 limit 3;"','admin1_output'=>'','admin2_command'=>'sqlite3 -header -column geo.sqlite "select * from admin2 limit 3;"','admin2_output'=>'','city_command'=>'sqlite3 -header -column us.sqlite "select * from city limit 1;"','city_output'=>'']; foreach ($entries as $entry) { if ($entry['file']==='geo.sqlite') { $samples['country_output']=sqliteTextSample($entry['path'],'country',3); $samples['admin1_output']=sqliteTextSample($entry['path'],'admin1',3); $samples['admin2_output']=sqliteTextSample($entry['path'],'admin2',3); continue; } if ($entry['country_code']==='US') { $samples['city_output']=sqliteTextSample($entry['path'],'city',1); break; } } if ($samples['city_output']==='') { foreach ($entries as $entry) { if (($entry['country_code'] ?? null) !== null) { $samples['city_command']=sprintf('sqlite3 -header -column %s "select * from city limit 1;"', basename($entry['path'])); $samples['city_output']=sqliteTextSample($entry['path'],'city',1); break; } } } foreach (['country_output','admin1_output','admin2_output','city_output'] as $k) $samples[$k] = $samples[$k] !== '' ? $samples[$k] : '(no rows)'; return $samples; }
function sampleRows(string $databasePath, string $tableName, int $limit): array { if (!is_file($databasePath)) return []; $pdo=createSqliteConnection($databasePath); $statement=$pdo->query(sprintf('SELECT * FROM %s LIMIT %d', $tableName, $limit)); if (false === $statement) return []; $rows=$statement->fetchAll(PDO::FETCH_ASSOC); return is_array($rows)?$rows:[]; }
function sqliteTextSample(string $databasePath, string $tableName, int $limit): string { $rows=sampleRows($databasePath,$tableName,$limit); if ($rows===[]) return ''; $columns=array_keys($rows[0]); $widths=[]; foreach ($columns as $column) $widths[$column]=strlen($column); foreach ($rows as $row) foreach ($columns as $column) $widths[$column]=max($widths[$column], strlen(sqliteScalar($row[$column] ?? null))); $lines=[]; $lines[] = implode('  ', array_map(static fn(string $column): string => str_pad($column, $widths[$column]), $columns)); $lines[] = implode('  ', array_map(static fn(string $column): string => str_repeat('-', $widths[$column]), $columns)); foreach ($rows as $row) $lines[] = implode('  ', array_map(static fn(string $column): string => str_pad(sqliteScalar($row[$column] ?? null), $widths[$column]), $columns)); return implode(PHP_EOL, $lines); }
function sqliteScalar(mixed $value): string { if ($value===null) return ''; if (is_bool($value)) return $value?'1':'0'; return (string)$value; }
function countRows(string $databasePath, string $tableName): int { $pdo=createSqliteConnection($databasePath); $statement=$pdo->query(sprintf('SELECT COUNT(*) FROM %s', $tableName)); return false === $statement ? 0 : (int)$statement->fetchColumn(); }
function allCountryCodes(string $countryInfoPath): array { $codes=[]; foreach (iterateTabFile($countryInfoPath, true) as $row) { if (!isset($row[0], $row[16]) || !is_numeric($row[16])) continue; $iso2=strtoupper(trim((string)$row[0])); if ($iso2!=='') $codes[$iso2]=true; } $countryCodes=array_keys($codes); sort($countryCodes); return $countryCodes; }
function allCountryCodesFromEndpoint(HttpClientInterface $httpClient, string $countryInfoUrl): array { $response = $httpClient->request('GET', $countryInfoUrl); if (200 !== $response->getStatusCode()) throw new RuntimeException(sprintf('Unable to fetch %s (HTTP %d).', $countryInfoUrl, $response->getStatusCode())); $codes=[]; foreach (preg_split('/\R/', $response->getContent()) ?: [] as $line) { $line=trim($line); if ($line==='' || str_starts_with($line, '#')) continue; $row=str_getcsv($line, "\t"); if (!is_array($row) || !isset($row[0], $row[16]) || !is_numeric((string)$row[16])) continue; $iso2=strtoupper(trim((string)$row[0])); if ($iso2!=='') $codes[$iso2]=true; } $countryCodes=array_keys($codes); sort($countryCodes); return $countryCodes; }
function arrayValuesUniqueUpper(array $values): array { return array_values(array_unique(array_map(static fn(string $code): string => strtoupper(trim($code)), $values))); }
