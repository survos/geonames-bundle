<?php

declare(strict_types=1);

namespace Survos\GeonamesBundle\Dto;

final readonly class GeoRecord
{
    public function __construct(
        public int $geonameId,
        public string $type,
        public string $name,
        public ?string $asciiName = null,
        public ?string $countryCode = null,
        public ?string $admin1Code = null,
        public ?string $admin2Code = null,
        public ?string $featureCode = null,
        public ?int $population = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {
    }
}
