<?php

declare(strict_types=1);

namespace Survos\GeonamesBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'survos:geo',
    description: 'Fetch a packaged GeoNames authority database.',
)]
final class GeoAuthorityCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Authority variant to fetch, for example us.')]
        ?string $country = null,
        #[Option('Fetch the largest published dataset when available.')]
        bool $all = false,
    ): int {
        $variant = $all ? 'all' : ($country ? sprintf('country=%s', strtolower($country)) : 'default');

        $io->title('Geo authority fetch');
        $io->text(sprintf('Requested variant: %s', $variant));
        $io->note('Stub only for now. This command will eventually download a published SQLite authority database.');

        return Command::SUCCESS;
    }
}
