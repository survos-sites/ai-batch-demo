<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\BatchRun;
use App\Service\BatchRunManager;
use Symfony\AI\Platform\Batch\BatchResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Check the status of a submitted batch and display results when ready.
 *
 *   bin/console app:fetch-batch 1
 *   bin/console app:fetch-batch batch_123
 *   bin/console app:fetch-batch 1 --watch
 *   bin/console app:fetch-batch 1 --output=results/batch_123.jsonl
 */
#[AsCommand('app:fetch-batch', 'Check status and fetch results of a submitted AI batch')]
final class FetchBatchCommand
{
    public function __construct(
        private readonly BatchRunManager $batchRuns,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Local ID or provider batch ID')] string $batch,
        #[Option('Poll every 30 seconds until complete')] bool $watch = false,
        #[Option('Poll interval in seconds')] int $interval = 30,
        #[Option('Save results to file (defaults to var/batch-results/{providerId}.jsonl)')] ?string $output = null,
    ): int {
        $run = $this->batchRuns->findByReference($batch);
        if (null === $run) {
            $io->error(sprintf('No batch run found for "%s".', $batch));

            return Command::FAILURE;
        }

        // Skip if already downloaded
        if ($output && file_exists($output)) {
            $io->success(sprintf('Results already saved to %s', $output));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Batch %d (%s)', $run->getId(), $run->getProviderBatchId()));

        do {
            $run = $this->batchRuns->refresh($run);
            $this->printStatus($io, $run);

            if ('completed' === $run->getStatus()) {
                $results = $this->batchRuns->fetchResults($run);
                $this->printResults($io, $results);

                if (null === $output) {
                    $output = sprintf('var/batch-results/%s.jsonl', $run->getProviderBatchId());
                }

                if ($output) {
                    $this->batchRuns->saveResults($run, $output, $results);
                    $io->success(sprintf('Results saved to %s', $output));
                }

                return Command::SUCCESS;
            }

            if (in_array($run->getStatus(), ['failed', 'cancelled', 'expired'], true)) {
                $io->error(sprintf('Batch failed with status: %s', $run->getStatus()));
                return Command::FAILURE;
            }

            if ($watch) {
                $io->writeln(sprintf('  Waiting %d seconds...', $interval));
                sleep($interval);
            }

        } while ($watch && !in_array($run->getStatus(), ['completed', 'failed', 'cancelled', 'expired'], true));

        if (!in_array($run->getStatus(), ['completed', 'failed', 'cancelled', 'expired'], true)) {
            $io->note([
                'Still processing. Check again with:',
                sprintf('  bin/console app:fetch-batch %s', (string) $run->getId()),
                '',
                'Or watch continuously with:',
                sprintf('  bin/console app:fetch-batch %s --watch', (string) $run->getId()),
            ]);
        }

        return Command::SUCCESS;
    }

    private function printStatus(SymfonyStyle $io, BatchRun $run): void
    {
        $isTerminal = in_array($run->getStatus(), ['completed', 'failed', 'cancelled', 'expired'], true);
        $statusEmoji = in_array($run->getStatus(), ['failed', 'cancelled', 'expired'], true) ? 'X' : ($isTerminal ? 'OK' : '...');

        $io->table(
            ['Field', 'Value'],
            [
                ['Status', $statusEmoji.' '.$run->getStatus()],
                ['Local ID', (string) $run->getId()],
                ['Provider ID', $run->getProviderBatchId()],
                ['Model', $run->getModel()],
                ['Progress', sprintf('%d / %d (failed: %d)', $run->getProcessedCount(), $run->getRequestCount(), $run->getFailedCount())],
                ['Submitted', $run->getSubmittedAt()->format('Y-m-d H:i:s')],
                ['Last polled', $run->getLastPolledAt()?->format('Y-m-d H:i:s') ?? '-'],
            ]
        );
    }

    /**
     * @param iterable<BatchResult> $results
     */
    private function printResults(SymfonyStyle $io, iterable $results): void
    {
        $io->section('Results');

        $count = 0;

        foreach ($results as $result) {
            if (!$result->isSuccess()) {
                $io->writeln(sprintf('  [ERROR] <error>%s: %s</error>', $result->getId(), $result->getError()));
                continue;
            }

            // Parse product id from custom_id "product_{id}"
            $productId = str_replace('product_', '', $result->getId());
            $copy = $result->getContent();

            $io->writeln(sprintf('<info>Product #%s</info>', $productId));
            $io->writeln(sprintf('  %s', $copy));
            $io->newLine();

            $count++;
        }

        $io->success(sprintf('%d results displayed.', $count));
    }
}
