<?php

declare(strict_types=1);

namespace Survos\GeonamesBundle\Service;

use PDO;
use PDOException;
use RuntimeException;
use Survos\GeonamesBundle\Dto\GeoRecord;

final class GeoService
{
    /** @var array<string, PDO> */
    private array $connections = [];

    public function __construct(
        private readonly ?string $sqliteDir,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<GeoRecord>
     */
    public function countries(): array
    {
        $statement = $this->geoConnection()->query(
            'SELECT geoname_id, name, iso2 FROM country ORDER BY name ASC'
        );

        return array_map(
            fn (array $row): GeoRecord => new GeoRecord(
                geonameId: (int) $row['geoname_id'],
                type: 'country',
                name: $row['name'],
                countryCode: $row['iso2'],
            ),
            $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [],
        );
    }

    /**
     * @return list<GeoRecord>
     */
    public function states(string $countryCode): array
    {
        $statement = $this->geoConnection()->prepare(
            'SELECT geoname_id, name, ascii_name, country_code, admin1_code
             FROM admin1
             WHERE country_code = :country_code
             ORDER BY name ASC'
        );
        $statement->execute(['country_code' => strtoupper($countryCode)]);

        return array_map(
            fn (array $row): GeoRecord => new GeoRecord(
                geonameId: (int) $row['geoname_id'],
                type: 'admin1',
                name: $row['name'],
                asciiName: $row['ascii_name'],
                countryCode: $row['country_code'],
                admin1Code: $row['admin1_code'],
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return list<GeoRecord>
     */
    public function admin2(string $countryCode, string $admin1Code): array
    {
        $statement = $this->geoConnection()->prepare(
            'SELECT geoname_id, name, ascii_name, country_code, admin1_code, admin2_code
             FROM admin2
             WHERE country_code = :country_code AND admin1_code = :admin1_code
             ORDER BY name ASC'
        );
        $statement->execute([
            'country_code' => strtoupper($countryCode),
            'admin1_code' => $admin1Code,
        ]);

        return array_map(
            fn (array $row): GeoRecord => new GeoRecord(
                geonameId: (int) $row['geoname_id'],
                type: 'admin2',
                name: $row['name'],
                asciiName: $row['ascii_name'],
                countryCode: $row['country_code'],
                admin1Code: $row['admin1_code'],
                admin2Code: $row['admin2_code'],
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * @return list<GeoRecord>
     */
    public function cities(string $countryCode, ?string $admin1Code = null): array
    {
        $connection = $this->countryConnection($countryCode);
        $sql = 'SELECT geoname_id, name, ascii_name, country_code, admin1_code, admin2_code, feature_code, population, latitude, longitude
                FROM city';
        $params = [];

        if (null !== $admin1Code) {
            $sql .= ' WHERE admin1_code = :admin1_code';
            $params['admin1_code'] = $admin1Code;
        }

        $sql .= ' ORDER BY population DESC, name ASC';
        $statement = $connection->prepare($sql);
        $statement->execute($params);

        return array_map(
            fn (array $row): GeoRecord => new GeoRecord(
                geonameId: (int) $row['geoname_id'],
                type: 'city',
                name: $row['name'],
                asciiName: $row['ascii_name'],
                countryCode: $row['country_code'],
                admin1Code: $row['admin1_code'],
                admin2Code: $row['admin2_code'],
                featureCode: $row['feature_code'],
                population: null !== $row['population'] ? (int) $row['population'] : null,
                latitude: null !== $row['latitude'] ? (float) $row['latitude'] : null,
                longitude: null !== $row['longitude'] ? (float) $row['longitude'] : null,
            ),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function find(string $query, ?string $countryCode = null): ?GeoRecord
    {
        $parsed = $this->parseLookup($query, $countryCode);

        if (null !== $parsed['city'] && null !== $parsed['country']) {
            $city = $this->findCity($parsed['city'], $parsed['country'], $parsed['admin1']);
            if (null !== $city) {
                return $city;
            }
        }

        if (1 === count($parsed['parts'])) {
            return $this->findGeoOnly($parsed['parts'][0]);
        }

        return null;
    }

    public function findByGeoId(int $geonameId, ?string $countryCode = null): ?GeoRecord
    {
        if (null !== $countryCode) {
            $statement = $this->countryConnection($countryCode)->prepare(
                'SELECT geoname_id, name, ascii_name, country_code, admin1_code, admin2_code, feature_code, population, latitude, longitude
                 FROM city
                 WHERE geoname_id = :geoname_id'
            );
            $statement->execute(['geoname_id' => $geonameId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                return new GeoRecord(
                    geonameId: (int) $row['geoname_id'],
                    type: 'city',
                    name: $row['name'],
                    asciiName: $row['ascii_name'],
                    countryCode: $row['country_code'],
                    admin1Code: $row['admin1_code'],
                    admin2Code: $row['admin2_code'],
                    featureCode: $row['feature_code'],
                    population: null !== $row['population'] ? (int) $row['population'] : null,
                    latitude: null !== $row['latitude'] ? (float) $row['latitude'] : null,
                    longitude: null !== $row['longitude'] ? (float) $row['longitude'] : null,
                );
            }
        }

        foreach (['country', 'admin1', 'admin2'] as $table) {
            $row = $this->geoConnection()->query(sprintf(
                'SELECT * FROM %s WHERE geoname_id = %d',
                $table,
                $geonameId,
            ))?->fetch(PDO::FETCH_ASSOC);

            if (is_array($row)) {
                return match ($table) {
                    'country' => new GeoRecord(
                        geonameId: (int) $row['geoname_id'],
                        type: 'country',
                        name: $row['name'],
                        countryCode: $row['iso2'],
                    ),
                    'admin1' => new GeoRecord(
                        geonameId: (int) $row['geoname_id'],
                        type: 'admin1',
                        name: $row['name'],
                        asciiName: $row['ascii_name'],
                        countryCode: $row['country_code'],
                        admin1Code: $row['admin1_code'],
                    ),
                    'admin2' => new GeoRecord(
                        geonameId: (int) $row['geoname_id'],
                        type: 'admin2',
                        name: $row['name'],
                        asciiName: $row['ascii_name'],
                        countryCode: $row['country_code'],
                        admin1Code: $row['admin1_code'],
                        admin2Code: $row['admin2_code'],
                    ),
                };
            }
        }

        return null;
    }

    public function sqliteDir(): string
    {
        if (null !== $this->sqliteDir && '' !== trim($this->sqliteDir)) {
            return rtrim($this->sqliteDir, '/');
        }

        $sharedDirectory = $_SERVER['APP_SHARED_DIRECTORY'] ?? $_ENV['APP_SHARED_DIRECTORY'] ?? null;
        if (is_string($sharedDirectory) && '' !== trim($sharedDirectory)) {
            return rtrim($sharedDirectory, '/') . '/geonames-data';
        }

        return $this->projectDir . '/var/geonames-data';
    }

    private function findGeoOnly(string $query): ?GeoRecord
    {
        $normalized = strtolower(trim($query));
        if ('' === $normalized) {
            return null;
        }

        $statement = $this->geoConnection()->prepare(
            'SELECT target_table, geoname_id FROM alias WHERE alias = :alias LIMIT 1'
        );
        $statement->execute(['alias' => $normalized]);
        $alias = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($alias)) {
            return $this->findByGeoId((int) $alias['geoname_id']);
        }

        $country = $this->geoConnection()->prepare(
            'SELECT geoname_id, name, iso2 FROM country WHERE lower(name) = :name LIMIT 1'
        );
        $country->execute(['name' => $normalized]);
        $row = $country->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return new GeoRecord(
                geonameId: (int) $row['geoname_id'],
                type: 'country',
                name: $row['name'],
                countryCode: $row['iso2'],
            );
        }

        return null;
    }

    private function findCity(string $city, string $countryCode, ?string $admin1Code = null): ?GeoRecord
    {
        $sql = 'SELECT DISTINCT city.geoname_id, city.name, city.ascii_name, city.country_code, city.admin1_code, city.admin2_code, city.feature_code, city.population, city.latitude, city.longitude
                FROM city
                LEFT JOIN alias ON alias.geoname_id = city.geoname_id
                WHERE (lower(city.name) = :name OR lower(city.ascii_name) = :name OR alias.alias = :name)';
        $params = ['name' => strtolower($city)];

        if (null !== $admin1Code) {
            $sql .= ' AND admin1_code = :admin1_code';
            $params['admin1_code'] = $admin1Code;
        }

        $sql .= ' ORDER BY city.population DESC, city.geoname_id ASC LIMIT 1';
        $statement = $this->countryConnection($countryCode)->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return new GeoRecord(
            geonameId: (int) $row['geoname_id'],
            type: 'city',
            name: $row['name'],
            asciiName: $row['ascii_name'],
            countryCode: $row['country_code'],
            admin1Code: $row['admin1_code'],
            admin2Code: $row['admin2_code'],
            featureCode: $row['feature_code'],
            population: null !== $row['population'] ? (int) $row['population'] : null,
            latitude: null !== $row['latitude'] ? (float) $row['latitude'] : null,
            longitude: null !== $row['longitude'] ? (float) $row['longitude'] : null,
        );
    }

    /**
     * @return array{parts: list<string>, city: ?string, country: ?string, admin1: ?string}
     */
    private function parseLookup(string $query, ?string $countryCode = null): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/,/', $query) ?: [])));
        if ([] === $parts) {
            return ['parts' => [], 'city' => null, 'country' => null, 'admin1' => null];
        }

        $resolvedCountry = null !== $countryCode ? strtoupper($countryCode) : null;
        $resolvedAdmin1 = null;

        if (count($parts) >= 2) {
            $last = end($parts);
            $country = $this->resolveCountry((string) $last);
            if (null === $country && str_contains((string) $last, ' ')) {
                $tokens = preg_split('/\s+/', trim((string) $last)) ?: [];
                $candidateCountry = array_pop($tokens);
                $country = null !== $candidateCountry ? $this->resolveCountry($candidateCountry) : null;
                if (null !== $country) {
                    $remaining = trim(implode(' ', $tokens));
                    if ('' === $remaining) {
                        array_pop($parts);
                    } else {
                        $parts[count($parts) - 1] = $remaining;
                    }
                }
            }
            if (null !== $country) {
                $resolvedCountry = $country;
                if (($parts[count($parts) - 1] ?? null) === $last) {
                    array_pop($parts);
                }
            }
        }

        if (null === $resolvedCountry && count($parts) >= 2) {
            $admin1 = $this->resolveAdmin1($parts[count($parts) - 1]);
            if (null !== $admin1) {
                $resolvedCountry = $admin1['country_code'];
                $resolvedAdmin1 = $admin1['admin1_code'];
                array_pop($parts);
            }
        }

        if (null !== $resolvedCountry && count($parts) >= 2 && null === $resolvedAdmin1) {
            $admin1 = $this->resolveAdmin1($parts[count($parts) - 1], $resolvedCountry);
            if (null !== $admin1) {
                $resolvedAdmin1 = $admin1['admin1_code'];
                array_pop($parts);
            }
        }

        return [
            'parts' => $parts,
            'city' => $parts[0] ?? null,
            'country' => $resolvedCountry,
            'admin1' => $resolvedAdmin1,
        ];
    }

    private function resolveCountry(string $value): ?string
    {
        $statement = $this->geoConnection()->prepare(
            'SELECT iso2 FROM country WHERE lower(name) = :value OR lower(iso2) = :value OR lower(iso3) = :value LIMIT 1'
        );
        $statement->execute(['value' => strtolower(trim($value))]);
        $iso2 = $statement->fetchColumn();

        return is_string($iso2) ? $iso2 : null;
    }

    /**
     * @return array{country_code: string, admin1_code: string}|null
     */
    private function resolveAdmin1(string $value, ?string $countryCode = null): ?array
    {
        $sql = 'SELECT country_code, admin1_code FROM admin1
                WHERE (lower(name) = :value OR lower(ascii_name) = :value OR lower(admin1_code) = :value OR lower(code) = :value)';
        $params = ['value' => strtolower(trim($value))];
        if (null !== $countryCode) {
            $sql .= ' AND country_code = :country_code';
            $params['country_code'] = strtoupper($countryCode);
        }
        $sql .= ' LIMIT 1';

        $statement = $this->geoConnection()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function geoConnection(): PDO
    {
        return $this->connectionFor($this->sqliteDir() . '/geo.sqlite');
    }

    private function countryConnection(string $countryCode): PDO
    {
        return $this->connectionFor($this->sqliteDir() . '/' . strtolower($countryCode) . '.sqlite');
    }

    private function connectionFor(string $path): PDO
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('GeoNames SQLite file not found: %s', $path));
        }

        if (isset($this->connections[$path])) {
            return $this->connections[$path];
        }

        try {
            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->connections[$path] = $pdo;
        } catch (PDOException $exception) {
            throw new RuntimeException(sprintf('Unable to open SQLite database %s', $path), 0, $exception);
        }
    }
}
