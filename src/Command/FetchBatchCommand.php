<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Postcard;
use App\Enum\EnrichmentStatus;
use App\Import\PostcardAiResultRow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Entity\AiBatchResult;
use Tacman\AiBatch\Service\SymfonyBatchPlatformClient;

#[AsCommand('app:fetch-batch', 'Check status and fetch results of a submitted AI batch')]
final class FetchBatchCommand
{
    private string $resultsDir;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SymfonyBatchPlatformClient $batchClient,
        private readonly ObjectMapperInterface $objectMapper,
    ) {
        $this->resultsDir = 'var/batch-results';
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Batch ID (local DB ID or provider batch ID)')] ?string $batchId = null,
        #[Option('Fetch and apply results')] bool $fetch = false,
        #[Option('Poll until complete')] bool $watch = false,
        #[Option('Watch all incomplete batches')] bool $watchAll = false,
        #[Option('Poll interval in seconds')] int $interval = 30,
    ): int {
        if ($watchAll) {
            return $this->watchAllBatches($io, $fetch, $interval);
        }

        $batch = null;

        if ($batchId) {
            $batch = $this->findBatch($batchId);
        } else {
            $batch = $this->promptForBatch($io);
        }

        if (!$batch) {
            return Command::FAILURE;
        }

        $io->title(sprintf('Batch #%d (%s)', $batch->id, $batch->providerBatchId ?? 'N/A'));
        $this->printStatus($io, $batch);

        if ($batch->providerBatchId) {
            $providerBatch = $this->batchClient->checkBatch($batch->providerBatchId);
            $batch->applyProviderStatus(
                $providerBatch->status ?? 'in_progress',
                $providerBatch->completedCount ?? 0,
                $providerBatch->failedCount ?? 0,
                $providerBatch->outputFileId ?? null,
                $providerBatch->errorFileId ?? null,
            );
            $this->entityManager->flush();
            $this->printStatus($io, $batch);
        }

        if ($watch && $batch->status !== 'completed' && $batch->status !== 'failed') {
            do {
                $io->writeln(sprintf('Waiting %d seconds...', $interval));
                sleep($interval);

                $providerBatch = $this->batchClient->checkBatch($batch->providerBatchId);
                $batch->applyProviderStatus(
                    $providerBatch->status ?? 'in_progress',
                    $providerBatch->completedCount ?? 0,
                    $providerBatch->failedCount ?? 0,
                    $providerBatch->outputFileId ?? null,
                    $providerBatch->errorFileId ?? null,
                );
                $this->entityManager->flush();
                $this->printStatus($io, $batch);
            } while ($batch->status !== 'completed' && $batch->status !== 'failed');
        }

        if ($fetch && $batch->status === 'completed') {
            $this->fetchAndApplyResults($io, $batch);
        }

        return Command::SUCCESS;
    }

    private function fetchAndApplyResults(SymfonyStyle $io, AiBatch $batch): void
    {
        $io->section('Fetching results...');

        if (!is_dir($this->resultsDir)) {
            mkdir($this->resultsDir, 0775, true);
        }

        $providerJob = $this->batchClient->checkBatch($batch->providerBatchId);
        $count = 0;
        $failed = 0;
        $resultsLines = [];

        foreach ($this->batchClient->fetchResults($providerJob) as $result) {
            $io->writeln(sprintf('  Result: %s -> %s', $result->customId, $result->success ? 'ok' : 'error'));
            if ($result->success && $io->isVeryVerbose()) {
                $io->writeln(sprintf('    Content: %s', substr($result->content ?? '', 0, 100)));
            }

            $existingResult = $this->entityManager->getRepository(AiBatchResult::class)
                ->findOneBy(['aiBatchId' => $batch->id, 'customId' => $result->customId]);

            if ($existingResult) {
                continue;
            }

            $resultEntity = new AiBatchResult();
            $resultEntity->aiBatchId = $batch->id;
            $resultEntity->customId = $result->customId;
            $resultEntity->success = $result->success;
            $resultEntity->content = $result->content;
            $resultEntity->promptTokens = $result->promptTokens;
            $resultEntity->outputTokens = $result->outputTokens;
            $resultEntity->createdAt = new \DateTimeImmutable();

            $resultsLines[] = json_encode([
                'id' => $result->customId,
                'success' => $result->success,
                'content' => $result->content,
                'prompt_tokens' => $result->promptTokens,
                'output_tokens' => $result->outputTokens,
            ], JSON_THROW_ON_ERROR);

            if ($result->success) {
                $count++;
                $postcard = $this->entityManager->find(\App\Entity\Postcard::class, $result->customId);
                if ($postcard) {
                    $this->applyResultToPostcard($postcard, $result->content, $result->promptTokens, $result->outputTokens);
                    $io->writeln(sprintf('  Applied: %s', $result->customId));
                }
            } else {
                $failed++;
                $resultEntity->error = $result->error ?? 'Unknown error';
                $io->error(sprintf('  Failed: %s - %s', $result->customId, $result->error));
            }

            try {
                $this->entityManager->persist($resultEntity);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'duplicate key')) {
                    $io->note(sprintf('  Skipped existing: %s', $result->customId));
                } else {
                    throw $e;
                }
            }
        }

        if (!empty($resultsLines)) {
            $resultsFile = sprintf('%s/%s.jsonl', $this->resultsDir, $batch->providerBatchId);
            file_put_contents($resultsFile, implode("\n", $resultsLines) . "\n");
            $io->writeln(sprintf('  Saved results to: %s', $resultsFile));
        }

        $batch->appliedCount += $count;
        $this->entityManager->flush();

        $io->success(sprintf('Applied %d results (%d failed).', $count, $failed));
    }

    private function applyResultToPostcard(\App\Entity\Postcard $postcard, ?string $content, int $promptTokens = 0, int $outputTokens = 0): void
    {
        if (!$content) {
            return;
        }

        $content = $this->stripMarkdownCodeBlocks($content);
        $resultRow = PostcardAiResultRow::fromJson($content);
        if (!$resultRow) {
            return;
        }

        $postcard->aiTitle = $resultRow->title;
        $postcard->aiDescription = $resultRow->description;
        $postcard->aiCountry = $resultRow->country;
        $postcard->aiState = $resultRow->state;
        $postcard->aiCity = $resultRow->city;

        $postcard->enriched = true;
        $postcard->enrichmentStatus = EnrichmentStatus::FINISHED;
        $postcard->enrichedAt = new \DateTimeImmutable();
        $postcard->updatedAt = new \DateTimeImmutable();
        $postcard->promptTokens = $promptTokens ?: null;
        $postcard->outputTokens = $outputTokens ?: null;

        if (!empty($resultRow->keywords)) {
            $normalized = [];
            foreach ($resultRow->keywords as $kw) {
                if (!is_array($kw)) {
                    continue;
                }
                $value = $kw['value'] ?? null;
                if (!is_string($value) || '' === $value) {
                    continue;
                }
                $normalized[strtolower($value)] = [
                    'confidence' => isset($kw['confidence']) && is_numeric($kw['confidence']) ? (float) $kw['confidence'] : null,
                    'basis' => isset($kw['basis']) && is_string($kw['basis']) ? $kw['basis'] : null,
                ];
            }
            if (!empty($normalized)) {
                $postcard->syncKeywordDetails($normalized);
            }
        }
    }

    private function findBatch(string $id): ?AiBatch
    {
        if (ctype_digit($id)) {
            return $this->entityManager->find(AiBatch::class, (int) $id);
        }

        return $this->entityManager->getRepository(AiBatch::class)
            ->findOneBy(['providerBatchId' => $id]);
    }

    private function promptForBatch(SymfonyStyle $io): ?AiBatch
    {
        $batches = $this->entityManager->getRepository(AiBatch::class)
            ->findBy([], ['createdAt' => 'DESC'], 10);

        if (empty($batches)) {
            $io->error('No batches found.');
            return null;
        }

        $choices = [];
        foreach ($batches as $index => $batch) {
            $ago = $this->timeAgo($batch->createdAt);
            $status = $batch->status;
            $count = $batch->requestCount;
            $choices[$index] = sprintf('#%d %s %s (%d requests) %s',
                $batch->id,
                $status === 'completed' ? '✅' : ($status === 'failed' ? '❌' : '⏳'),
                $status,
                $count,
                $ago
            );
        }
        $choices[] = 'Exit';

        $selected = $io->choice('Select a batch:', $choices, 0);

        if ($selected === 'Exit') {
            return null;
        }

        $selectedIndex = array_search($selected, $choices);
        return $batches[$selectedIndex];
    }

    private function printStatus(SymfonyStyle $io, AiBatch $batch): void
    {
        $emoji = match ($batch->status) {
            'completed' => '✅',
            'failed' => '❌',
            'submitted', 'processing' => '⏳',
            default => '❓',
        };

        $io->table(
            ['Field', 'Value'],
            [
                ['Status', $emoji . ' ' . $batch->status],
                ['Local ID', (string) $batch->id],
                ['Provider ID', $batch->providerBatchId ?? '-'],
                ['Model', $batch->meta['model'] ?? '-'],
                ['Requests', (string) $batch->requestCount],
                ['Completed', (string) $batch->completedCount],
                ['Failed', (string) $batch->failedCount],
                ['Applied', (string) $batch->appliedCount],
                ['Created', $batch->createdAt->format('Y-m-d H:i:s')],
            ]
        );
    }

    private function watchAllBatches(SymfonyStyle $io, bool $fetch, int $interval): int
    {
        $io->title('Watching all incomplete batches');

        while (true) {
            $batches = $this->entityManager->getRepository(AiBatch::class)
                ->findBy(['status' => ['submitted', 'processing']], ['createdAt' => 'DESC']);

            if (empty($batches)) {
                $io->success('All batches are complete!');
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Watching %d incomplete batch(es)...', count($batches)));

            $allComplete = true;
            foreach ($batches as $batch) {
                if (!in_array($batch->status, ['completed', 'failed'])) {
                    $allComplete = false;
                }

                $io->writeln(sprintf('Checking batch #%d (%s)...', $batch->id, $batch->providerBatchId ?? 'N/A'));

                $this->refreshBatch($batch);
                $this->printStatus($io, $batch);

                if ($batch->status === 'completed') {
                    if ($fetch) {
                        $this->fetchAndApplyResults($io, $batch);
                    } else {
                        $io->writeln(sprintf('  Batch #%d complete! Use --fetch to apply results.', $batch->id));
                    }
                }
            }

            if ($allComplete) {
                $io->success('All batches are complete!');
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Waiting %d seconds...', $interval));
            sleep($interval);
        }
    }

    private function refreshBatch(AiBatch $batch): void
    {
        if (!$batch->providerBatchId) {
            return;
        }

        try {
            $providerJob = $this->batchClient->checkBatch($batch->providerBatchId);
            $batch->applyProviderStatus(
                $providerJob->status ?? 'in_progress',
                $providerJob->completedCount ?? 0,
                $providerJob->failedCount ?? 0,
                $providerJob->outputFileId ?? null,
                $providerJob->errorFileId ?? null,
            );
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // Silently fail for individual batch refresh
        }
    }

    private function stripMarkdownCodeBlocks(string $content): string
    {
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }
        return $content;
    }

    private function timeAgo(\DateTimeInterface $date): string
    {
        $diff = (new \DateTimeImmutable())->diff($date);
        if ($diff->days > 0) {
            return $diff->days . 'd ago';
        }
        if ($diff->h > 0) {
            return $diff->h . 'h ago';
        }
        if ($diff->i > 0) {
            return $diff->i . 'm ago';
        }
        return 'just now';
    }
}
