<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Postcard;
use App\Import\PostcardImportRow;
use Doctrine\ORM\EntityManagerInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

#[AsCommand('app:load:postcards', 'Load postcard records from data/postcards.jsonl')]
final class LoadPostcardsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectMapperInterface $objectMapper,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Input JSONL file path')]
        string $path = 'data/postcards.jsonl',
        #[Option('Limit number of rows')]
        ?int $limit = null,
        #[Option('Delete existing postcards first')]
        bool $reset = false,
        #[Option('Only import ID and image (skip metadata, for reloading after AI processing)')]
        bool $imageOnly = false,
    ): int {
        if (!is_file($path)) {
            $io->error(sprintf('File not found: %s', $path));

            return Command::FAILURE;
        }

        if ($reset) {
            $this->entityManager->createQuery('DELETE FROM App\\Entity\\Postcard p')->execute();
        }

        $repository = $this->entityManager->getRepository(Postcard::class);
        $created = 0;
        $updated = 0;
        $processed = 0;

        foreach (JsonlReader::open($path) as $row) {
            if (null !== $limit && $processed >= $limit) {
                break;
            }

            $id = (string) ($row['id'] ?? '');
            if ('' === $id) {
                continue;
            }

            $postcard = $repository->find($id);
            if (null === $postcard) {
                $postcard = new Postcard($id);
                $this->entityManager->persist($postcard);
                ++$created;
            } else {
                ++$updated;
            }

            if ($imageOnly) {
                $postcard->thumbnailUrl = (string) ($row['thumbnail_url'] ?? '');
            } else {
                $this->objectMapper->map(PostcardImportRow::fromArray($row), $postcard);
            }
            $postcard->rawData = $row;

            ++$processed;

            if (0 === $processed % 250) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Loaded %d postcards (%d created, %d updated).', $processed, $created, $updated));

        return Command::SUCCESS;
    }
}
