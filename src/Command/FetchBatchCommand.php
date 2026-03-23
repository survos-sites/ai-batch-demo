<?php
declare(strict_types=1);

namespace App\Command;

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
        #[Option('Poll interval in seconds')] int $interval = 30,
    ): int {
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

            $this->entityManager->persist($resultEntity);
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
