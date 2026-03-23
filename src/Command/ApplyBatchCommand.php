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
use Tacman\AiBatch\Entity\AiBatch;

#[AsCommand('app:apply-batch', 'Apply batch results to postcards')]
final class ApplyBatchCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Batch ID')] ?string $batchId = null,
    ): int {
        $batchRepo = $this->entityManager->getRepository(AiBatch::class);
        $postcardRepo = $this->entityManager->getRepository(Postcard::class);

        if ($batchId) {
            $batches = [$batchRepo->find($batchId)];
        } else {
            $batches = $batchRepo->findBy(['status' => 'completed'], ['createdAt' => 'DESC']);
        }

        if (empty($batches) || (count($batches) === 1 && $batches[0] === null)) {
            $io->error('No batch found.');
            return Command::FAILURE;
        }

        $totalApplied = 0;
        $totalNotFound = 0;
        $totalSkipped = 0;

        foreach ($batches as $batch) {
            if (!$batch) {
                continue;
            }

            $io->writeln(sprintf('Processing batch #%d (%s)...', $batch->id, $batch->providerBatchId ?? 'N/A'));

            $results = $this->entityManager->getRepository(\Tacman\AiBatch\Entity\AiBatchResult::class)
                ->findBy(['aiBatchId' => $batch->id]);

            if (empty($results)) {
                $io->note('No results in this batch yet. Run `app:fetch-batch --fetch` first.');
                continue;
            }

            foreach ($results as $result) {
                if (!$result->success || !$result->content) {
                    continue;
                }

                $postcard = $postcardRepo->find($result->customId);
                if (!$postcard) {
                    $totalNotFound++;
                    $io->writeln(sprintf('  Not found: %s', $result->customId));
                    continue;
                }

                if ($postcard->enriched) {
                    $totalSkipped++;
                    continue;
                }

                $this->applyResultToPostcard($postcard, $result->content, $result->promptTokens, $result->outputTokens);
                $totalApplied++;
                $io->writeln(sprintf('  Applied: %s', $result->customId));
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Applied: %d, Skipped (already enriched): %d, Not found in DB: %d',
            $totalApplied,
            $totalSkipped,
            $totalNotFound
        ));

        return Command::SUCCESS;
    }

    private function applyResultToPostcard(Postcard $postcard, ?string $content, int $promptTokens = 0, int $outputTokens = 0): void
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
            $postcard->syncKeywordDetails($resultRow->keywords);
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
}
