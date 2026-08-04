<?php

declare(strict_types=1);

namespace Survos\GeonamesBundle\Tests\Service;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\GeonamesBundle\Service\GeoService;

final class GeoServiceTest extends TestCase
{
    private string $sqliteDir;
    private GeoService $service;

    protected function setUp(): void
    {
        $this->sqliteDir = sys_get_temp_dir() . '/geonames-bundle-tests-' . uniqid('', true);
        mkdir($this->sqliteDir, 0777, true);

        $this->createGeoDatabase();
        $this->createUsDatabase();

        $this->service = new GeoService($this->sqliteDir, $this->sqliteDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sqliteDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->sqliteDir);
    }

    #[Test]
    public function countriesReturnsConfiguredCountries(): void
    {
        $countries = $this->service->countries();

        self::assertCount(2, $countries);
        self::assertSame('Norway', $countries[0]->name);
        self::assertSame('US', $countries[1]->countryCode);
    }

    #[Test]
    public function statesReturnsStatesForCountry(): void
    {
        $states = $this->service->states('US');

        self::assertCount(2, $states);
        self::assertSame('MA', $states[0]->admin1Code);
        self::assertSame('NJ', $states[1]->admin1Code);
    }

    #[Test]
    public function admin2ReturnsChildrenForAdmin1(): void
    {
        $admin2 = $this->service->admin2('US', 'NJ');

        self::assertCount(1, $admin2);
        self::assertSame('Burlington County', $admin2[0]->name);
    }

    #[Test]
    public function citiesReturnsCitiesForState(): void
    {
        $cities = $this->service->cities('US', 'NJ');

        self::assertCount(1, $cities);
        self::assertSame('Mount Laurel', $cities[0]->name);
    }

    #[Test]
    public function findCanResolveCityFromStateAndCountryInOneLookup(): void
    {
        $record = $this->service->find('Boston, MA USA');

        self::assertNotNull($record);
        self::assertSame(4930956, $record->geonameId);
        self::assertSame('city', $record->type);
        self::assertSame('US', $record->countryCode);
    }

    #[Test]
    public function findCanInferCountryFromAdmin1Alias(): void
    {
        $record = $this->service->find('Mt. Laurel, NJ');

        self::assertNotNull($record);
        self::assertSame(4502552, $record->geonameId);
        self::assertSame('city', $record->type);
        self::assertSame('NJ', $record->admin1Code);
    }

    #[Test]
    public function findFallsBackToGeoLookupForSinglePartQueries(): void
    {
        $record = $this->service->find('Oslo');

        self::assertNotNull($record);
        self::assertSame(3143244, $record->geonameId);
        self::assertSame('admin1', $record->type);
        self::assertSame('NO', $record->countryCode);
    }

    #[Test]
    public function findByGeoIdCanResolveCityInCountryDatabase(): void
    {
        $record = $this->service->findByGeoId(4930956, 'US');

        self::assertNotNull($record);
        self::assertSame('Boston', $record->name);
        self::assertSame('city', $record->type);
    }

    #[Test]
    public function findByGeoIdCanResolveGeoRecords(): void
    {
        $record = $this->service->findByGeoId(6252001);

        self::assertNotNull($record);
        self::assertSame('country', $record->type);
        self::assertSame('US', $record->countryCode);
    }

    #[Test]
    public function alternateNamesReturnsNamesKeyedByLanguage(): void
    {
        $names = $this->service->alternateNames(4930956, 'US');

        self::assertSame(['Bostón'], $names['es']);
        self::assertSame(['Boston'], $names['de']);
        self::assertSame(['Boston'], $names['fr']);
        self::assertSame(['Boston City'], $names['']);
    }

    #[Test]
    public function alternateNamesReturnsEmptyArrayWhenNoneRecorded(): void
    {
        self::assertSame([], $this->service->alternateNames(4502552, 'US'));
    }

    private function createGeoDatabase(): void
    {
        $pdo = new PDO('sqlite:' . $this->sqliteDir . '/geo.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

        $pdo->exec(<<<'SQL'
INSERT INTO country (geoname_id, iso2, iso3, name) VALUES
    (6252001, 'US', 'USA', 'United States'),
    (3144096, 'NO', 'NOR', 'Norway');
INSERT INTO admin1 (geoname_id, code, country_code, admin1_code, name, ascii_name) VALUES
    (6254926, 'US.MA', 'US', 'MA', 'Massachusetts', 'Massachusetts'),
    (5101760, 'US.NJ', 'US', 'NJ', 'New Jersey', 'New Jersey'),
    (3143244, 'NO.12', 'NO', '12', 'Oslo', 'Oslo');
INSERT INTO admin2 (geoname_id, code, country_code, admin1_code, admin2_code, name, ascii_name) VALUES
    (4501018, 'US.NJ.005', 'US', 'NJ', '005', 'Burlington County', 'Burlington County');
INSERT INTO alias (alias, target_table, geoname_id) VALUES
    ('us', 'country', 6252001),
    ('usa', 'country', 6252001),
    ('no', 'country', 3144096),
    ('nor', 'country', 3144096),
    ('us-ma', 'admin1', 6254926),
    ('ma', 'admin1', 6254926),
    ('us-nj', 'admin1', 5101760),
    ('nj', 'admin1', 5101760),
    ('oslo', 'admin1', 3143244);
SQL);
    }

    private function createUsDatabase(): void
    {
        $pdo = new PDO('sqlite:' . $this->sqliteDir . '/us.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
CREATE TABLE alt_name (
    geoname_id INTEGER NOT NULL,
    iso_language TEXT NULL,
    alternate_name TEXT NOT NULL,
    is_preferred INTEGER NOT NULL DEFAULT 0,
    is_short INTEGER NOT NULL DEFAULT 0,
    is_colloquial INTEGER NOT NULL DEFAULT 0,
    is_historic INTEGER NOT NULL DEFAULT 0
);
SQL);

        $pdo->exec(<<<'SQL'
INSERT INTO city (
    geoname_id, name, ascii_name, alternate_names, latitude, longitude, feature_class, feature_code,
    country_code, admin1_code, admin2_code, population, timezone, modification_date
) VALUES
    (4930956, 'Boston', 'Boston', 'Boston', 42.3584, -71.0598, 'P', 'PPLA', 'US', 'MA', '025', 675647, 'America/New_York', '2024-01-01'),
    (4502552, 'Mount Laurel', 'Mount Laurel', 'Mt. Laurel,Mount Laurel Township', 39.9340, -74.8909, 'P', 'PPL', 'US', 'NJ', '005', 44773, 'America/New_York', '2024-01-01');
INSERT INTO alias (alias, geoname_id) VALUES
    ('boston', 4930956),
    ('mount laurel', 4502552),
    ('mt. laurel', 4502552),
    ('mount laurel township', 4502552);
INSERT INTO alt_name (geoname_id, iso_language, alternate_name, is_preferred) VALUES
    (4930956, 'de', 'Boston', 0),
    (4930956, 'fr', 'Boston', 0),
    (4930956, 'es', 'Bostón', 1),
    (4930956, '', 'Boston City', 0);
SQL);
    }
}
