<?php

declare(strict_types=1);

namespace Survos\GeonamesBundle\Command;

use Survos\GeonamesBundle\Service\GeoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Bytes;

/**
 * Fetches the published GeoNames authority SQLite databases (built + published by this bundle's
 * own admin/ tooling, see PLAN.md) from the public `museado/geonames-data` Hugging Face dataset —
 * no auth needed, it's a public, non-gated repo. Downloads straight to GeoService::sqliteDir(), the
 * SAME persistent-directory resolution GeoService itself uses at query time (config sqlite_dir ->
 * APP_SHARED_DIRECTORY -> kernel var/ as a last resort) — one source of truth for "where do these
 * files live", so fetching here and querying via GeoService can never disagree on the path.
 */
#[AsCommand(
    name: 'survos:geo',
    description: 'Fetch published GeoNames authority SQLite databases (geo.sqlite + per-country city dbs).',
)]
final class GeoAuthorityCommand
{
    private const REPO = 'museado/geonames-data';
    private const BASE_URL = 'https://huggingface.co/datasets/' . self::REPO . '/resolve/main/';
    private const TREE_URL = 'https://huggingface.co/api/datasets/' . self::REPO . '/tree/main';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GeoService $geo,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Comma-separated country code(s) to fetch, e.g. us or hu,it,pl,ch.')]
        ?string $country = null,
        #[Option('Fetch every published per-country database (~3.4 GB total).')]
        bool $all = false,
        #[Option('Re-download even if the file already exists locally.')]
        bool $force = false,
    ): int {
        $dir = $this->geo->sqliteDir();
        $io->title('Geo authority fetch');
        $io->text(sprintf('Target directory: %s', $dir));

        $files = ['geo.sqlite'];
        if ($all) {
            foreach ($this->listPublishedFiles() as $path) {
                if (str_ends_with($path, '.sqlite') && $path !== 'geo.sqlite') {
                    $files[] = $path;
                }
            }
        } elseif (null !== $country && '' !== trim($country)) {
            foreach (explode(',', $country) as $code) {
                $code = strtolower(trim($code));
                if ($code !== '') {
                    $files[] = $code . '.sqlite';
                }
            }
        }
        $files = array_values(array_unique($files));

        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            $io->error(sprintf('Cannot create directory: %s', $dir));

            return Command::FAILURE;
        }

        $fetched = $skipped = $failed = 0;
        foreach ($files as $file) {
            $dest = $dir . '/' . $file;
            if (!$force && is_file($dest) && filesize($dest) > 0) {
                $skipped++;
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  <comment>skip</comment> %s (already present)', $file));
                }
                continue;
            }

            try {
                $bytes = $this->download($file, $dest);
                $fetched++;
                $io->writeln(sprintf('  <info>fetched</info> %s (%s)', $file, Bytes::parse($bytes)->humanize()));
            } catch (\Throwable $e) {
                $failed++;
                $io->writeln(sprintf('  <error>failed</error> %s: %s', $file, $e->getMessage()));
            }
        }

        $io->success(sprintf('fetched %d · skipped %d · failed %d  →  %s', $fetched, $skipped, $failed, $dir));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> every file path published in the dataset repo */
    private function listPublishedFiles(): array
    {
        $rows = $this->httpClient->request('GET', self::TREE_URL . '?recursive=1')->toArray();

        return array_values(array_filter(array_map(
            static fn (array $row): ?string => ($row['type'] ?? null) === 'file' ? (string) $row['path'] : null,
            $rows,
        )));
    }

    /** Stream one file to disk (atomic via a .part temp). Returns bytes written. */
    private function download(string $file, string $dest): int
    {
        $response = $this->httpClient->request('GET', self::BASE_URL . $file, ['max_redirects' => 5]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('HTTP %d', $response->getStatusCode()));
        }

        $tmp = $dest . '.part';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open for write: ' . $tmp);
        }
        $bytes = 0;
        foreach ($this->httpClient->stream($response) as $chunk) {
            $bytes += (int) fwrite($fh, $chunk->getContent());
        }
        fclose($fh);
        rename($tmp, $dest);

        return $bytes;
    }
}
