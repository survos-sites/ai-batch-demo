<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Postcard;
use App\Import\PostcardAiResultRow;
use App\Import\PostcardImportRow;
use Doctrine\ORM\EntityManagerInterface;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Service\SymfonyBatchPlatformClient;

#[AsCommand('app:load:postcards', 'Load postcard records from data/postcards.jsonl')]
final class LoadPostcardsCommand
{
    private string $resultsDir;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectMapperInterface $objectMapper,
        private readonly SymfonyBatchPlatformClient $batchClient,
    ) {
        $this->resultsDir = 'var/batch-results';
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Input JSONL file path')]
        string $path = 'data/postcards.jsonl',
        #[Option('Limit number of rows')]
        ?int $limit = null,
        #[Option('Delete existing postcards first')]
        bool $reset = false,
        #[Option('Also load source metadata (title, description, etc.)')]
        bool $withSource = false,
        #[Option('Fetch and apply batch results after loading')]
        bool $includeBatch = false,
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

            $postcard->thumbnailUrl = (string) ($row['thumbnail_url'] ?? '');

            if ($withSource) {
                $importRow = PostcardImportRow::fromArray($row);
                $postcard->title = $importRow->title;
                $postcard->description = $importRow->description;
                $postcard->country = $importRow->country;
                $postcard->state = $importRow->state;
                $postcard->city = $importRow->city;
            }
            $postcard->rawData = $row;

            ++$processed;

            if (0 === $processed % 50) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Loaded %d postcards (%d created, %d updated).', $processed, $created, $updated));

        if ($includeBatch) {
            $this->applyBatchResults($io);
        }

        return Command::SUCCESS;
    }

    private function applyBatchResults(SymfonyStyle $io): void
    {
        $io->section('Applying batch results...');

        if (!is_dir($this->resultsDir)) {
            mkdir($this->resultsDir, 0775, true);
        }

        $batches = $this->entityManager->getRepository(AiBatch::class)
            ->findBy(['status' => 'completed'], ['createdAt' => 'DESC']);

        if (empty($batches)) {
            $io->note('No completed batches found.');
            return;
        }

        $applied = 0;
        $notFound = 0;
        $savedToDisk = 0;

        foreach ($batches as $batch) {
            if (!$batch->providerBatchId) {
                continue;
            }

            try {
                $providerJob = $this->batchClient->checkBatch($batch->providerBatchId);
            } catch (\Throwable $e) {
                $io->warning(sprintf('Could not fetch batch %s: %s', $batch->providerBatchId, $e->getMessage()));
                continue;
            }

            $batchResultsFile = sprintf('%s/%s.jsonl', $this->resultsDir, $batch->providerBatchId);
            $resultsLines = [];

            foreach ($this->batchClient->fetchResults($providerJob) as $result) {
                $postcard = $this->entityManager->find(Postcard::class, $result->customId);

                if (!$postcard) {
                    $notFound++;
                    continue;
                }

                if ($postcard->enriched) {
                    continue;
                }

                $this->applyResultToPostcard($postcard, $result->content, $result->promptTokens, $result->outputTokens);
                $applied++;

                $resultsLines[] = json_encode([
                    'id' => $result->customId,
                    'success' => $result->success,
                    'content' => $result->content,
                    'prompt_tokens' => $result->promptTokens,
                    'output_tokens' => $result->outputTokens,
                ], JSON_THROW_ON_ERROR);
            }

            if (!empty($resultsLines)) {
                file_put_contents($batchResultsFile, implode("\n", $resultsLines) . "\n");
                $savedToDisk++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Applied batch results to %d postcards (%d not found in DB). Saved %d result files to %s/',
            $applied,
            $notFound,
            $savedToDisk,
            $this->resultsDir
        ));
    }

    private function applyResultToPostcard(Postcard $postcard, ?string $content, int $promptTokens = 0, int $outputTokens = 0): void
    {
        if (!$content) {
            return;
        }

        $resultRow = PostcardAiResultRow::fromJson($content);
        if (!$resultRow) {
            return;
        }

        $this->objectMapper->map($resultRow, $postcard);

        $postcard->enriched = true;
        $postcard->enrichedAt = new \DateTimeImmutable();
        $postcard->updatedAt = new \DateTimeImmutable();
        $postcard->promptTokens = $promptTokens ?: null;
        $postcard->outputTokens = $outputTokens ?: null;

        if (!empty($resultRow->keywords)) {
            $postcard->syncKeywordDetails($resultRow->keywords);
        }
    }
}
