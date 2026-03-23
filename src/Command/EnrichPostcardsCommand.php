<?php
declare(strict_types=1);

namespace App\Command;

use App\AiTask\EnrichPostcardAiTask;
use App\Entity\Postcard;
use App\Message\EnrichPostcardMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tacman\AiBatch\Model\AiExecutionMode;
use Tacman\AiBatch\Service\AiTaskDispatcher;

#[AsCommand('app:enrich:postcards', 'Generate AI description and keywords for postcards')]
final class EnrichPostcardsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EnrichPostcardAiTask $task,
        private readonly AiTaskDispatcher $dispatcher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Limit postcards to enrich')]
        int $limit = 20,
        #[Option('Execution mode: sync or batch')]
        string $mode = 'sync',
        #[Option('Force re-enrichment even if already filled')]
        bool $force = false,
    ): int {
        $executionMode = AiExecutionMode::tryFrom(strtolower($mode));
        if (null === $executionMode) {
            $io->error('Invalid mode. Use "sync" or "batch".');

            return Command::FAILURE;
        }

        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Postcard::class, 'p')
            ->setMaxResults($limit)
            ->orderBy('p.id', 'ASC');

        if (!$force) {
            $query->andWhere('p.enriched IS NULL OR p.enriched = :enriched')
                ->setParameter('enriched', false);
        }

        /** @var list<Postcard> $postcards */
        $postcards = $query->getQuery()->getResult();

        if ([] === $postcards) {
            $io->note('No postcards to enrich.');

            return Command::SUCCESS;
        }

        $messages = array_map(
            static fn(Postcard $postcard): EnrichPostcardMessage => new EnrichPostcardMessage($postcard->id),
            $postcards,
        );

        if ($io->isVerbose()) {
            $io->writeln(sprintf('Mode: <comment>%s</comment>', $executionMode->value));
            $io->writeln(sprintf('Selected postcards: <comment>%d</comment>', count($messages)));
        }

        if (AiExecutionMode::Batch === $executionMode) {
            if ($io->isVerbose()) {
                $io->writeln('Submitting requests to provider batch API...');
            }

            $batch = $this->dispatcher->dispatchBatch($messages, $this->task);
            $io->success(sprintf('Submitted batch #%d (%s) with %d postcards.', $batch->id ?? 0, $batch->providerBatchId ?? '-', count($messages)));

            return Command::SUCCESS;
        }

        $io->progressStart(count($messages));

        foreach ($messages as $index => $message) {
            if ($io->isVerbose()) {
                $io->writeln(sprintf('Processing postcard <comment>%s</comment> in sync mode...', $message->postcardId));
            }

            $this->dispatcher->dispatch($message, $this->task, $executionMode);

            if ($io->isVeryVerbose()) {
                $postcard = $this->entityManager->find(Postcard::class, $message->postcardId);

                if ($postcard instanceof Postcard) {
                    $io->writeln(sprintf('  description: %s', $postcard->aiDescription ?? '-'));
                    $io->writeln(sprintf('  keywords: %s', [] === $postcard->aiKeywords ? '-' : implode(', ', $postcard->aiKeywords)));
                }
            }

            if (0 === ($index + 1) % 20) {
                $this->entityManager->clear();
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf('Enriched %d postcards in sync mode.', count($messages)));

        return Command::SUCCESS;
    }
}
