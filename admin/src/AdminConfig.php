<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin;

final readonly class AdminConfig
{
    public function __construct(
        public string $adminDir,
        public string $endpoint,
        public string $sourceDir,
        public string $outputDir,
        public string $hfAccount,
    ) {
    }

    public static function fromAdminDir(string $adminDir): self
    {
        return new self(
            adminDir: $adminDir,
            endpoint: self::requiredEnv('GEONAMES_ENDPOINT'),
            sourceDir: self::requiredEnv('GEONAMES_SOURCE_DIR'),
            outputDir: self::requiredEnv('GEONAMES_OUTPUT_DIR'),
            hfAccount: self::requiredEnv('HF_ACCOUNT'),
        );
    }

    private static function requiredEnv(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Missing required environment variable %s in admin/.env', $name));
        }

        return trim($value);
    }
}
