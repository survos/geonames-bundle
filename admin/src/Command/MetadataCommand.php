<?php

declare(strict_types=1);

namespace Survos\GeonamesAdmin\Command;

use Survos\GeonamesAdmin\AdminConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Survos\GeonamesAdmin\buildOutputSummary;
use function Survos\GeonamesAdmin\generatedSqliteFiles;
use function Survos\GeonamesAdmin\refreshMetadata;

#[AsCommand('metadata', 'Refresh README.md and datasets.jsonl from the existing SQLite files.')]
final class MetadataCommand
{
    public function __construct(
        private readonly AdminConfig $config,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Directory where SQLite files have been written')] ?string $outputDir = null,
        #[Option('Hugging Face account or org name for generated dataset card examples')] ?string $hfAccount = null,
    ): int {
        $outputDir = rtrim($outputDir ?? $this->config->outputDir, '/');
        $hfAccount ??= $this->config->hfAccount;

        $io->title('Refresh GeoNames metadata');
        $io->text(sprintf('Output directory: %s', $outputDir));

        refreshMetadata($outputDir, $hfAccount);
        $allFiles = generatedSqliteFiles($outputDir);

        $io->success('Dataset metadata refreshed.');
        $io->listing(buildOutputSummary($outputDir, array_merge(['README.md', 'datasets.jsonl'], $allFiles)));

        return Command::SUCCESS;
    }
}
