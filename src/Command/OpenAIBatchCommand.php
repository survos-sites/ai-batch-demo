<?php

namespace App\Command;

use Symfony\AI\Platform\Batch\BatchInput;
use Symfony\AI\Platform\Batch\BatchJob;
use Symfony\AI\Platform\Batch\BatchResult;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\VarDumper\VarDumper;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:openai:batch',
    description: 'Submit an OpenAI batch job',
)]
class OpenAIBatchCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openaiApiKey,
    ) {
        parent::__construct();
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'The OpenAI model to use')] ?string $model = null,
        #[Option(description: 'Poll interval in seconds')] ?int $pollInterval = null,
        #[Option(description: 'Submit batch without waiting for completion')] bool $noWait = false,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $isVerbose = $output->isVerbose();

        $platform = PlatformFactory::createBatch($this->openaiApiKey, $this->httpClient);
        $model = $model ?? 'gpt-4o-mini';

        $questions = [
            'req-1' => 'What is the capital of France? Answer in one word.',
            'req-2' => 'What is the capital of Germany? Answer in one word.',
            'req-3' => 'What is the capital of Spain? Answer in one word.',
        ];

        $inputs = (static function () use ($questions): \Generator {
            foreach ($questions as $id => $question) {
                yield new BatchInput($id, new MessageBag(Message::ofUser($question)));
            }
        })();

        if ($isVerbose) {
            $io->section('Submitting batch job');
            $io->writeln(sprintf('Model: <comment>%s</comment>', $model));
            $io->writeln('Inputs:');
            foreach ($questions as $id => $question) {
                $io->writeln(sprintf('  [%s] %s', $id, $question));
            }
        }

        $job = $platform->submitBatch($model, $inputs, ['max_tokens' => 50]);

        $io->success('Batch submitted successfully!');
        $io->table(
            ['Property', 'Value'],
            [
                ['Batch ID', $job->getId()],
                ['Status', $job->getStatus()->value],
                ['Model', $model],
            ]
        );

        if ($noWait) {
            $io->note('Use the batch ID above to retrieve results later.');
            return Command::SUCCESS;
        }

        $pollInterval = $pollInterval ?? 5;
        $io->note(sprintf('Polling every %ds until complete...', $pollInterval));

        while (!$job->isTerminal()) {
            sleep($pollInterval);
            $job = $platform->getBatch($job->getId());

            if ($isVerbose) {
                $io->writeln(sprintf(
                    '[%s] Status: <comment>%s</comment> (%d/%d processed)',
                    (new \DateTimeImmutable())->format('H:i:s'),
                    $job->getStatus()->value,
                    $job->getProcessedCount(),
                    $job->getTotalCount()
                ));

                if ($output->isVeryVerbose()) {
                    $io->section('Full job response');
                    VarDumper::dump($this->extractJobData($job));
                }
            } else {
                $io->write(sprintf(
                    "\r  Status: %s (%d/%d processed)   ",
                    $job->getStatus()->value,
                    $job->getProcessedCount(),
                    $job->getTotalCount()
                ));
            }
        }

        if (!$isVerbose) {
            $io->newLine();
        }

        if ($job->isFailed()) {
            $io->error('Batch failed.');
            return Command::FAILURE;
        }

        $io->success('Batch complete!');
        $io->section('Results');

        $results = [];
        foreach ($platform->fetchResults($job) as $result) {
            $results[] = $result;

            if ($result->isSuccess()) {
                $io->writeln(sprintf(
                    '  [%s] <info>%s</info> (tokens: %d in / %d out)',
                    $result->getId(),
                    $result->getContent(),
                    $result->getInputTokens(),
                    $result->getOutputTokens()
                ));
            } else {
                $io->error(sprintf('  [%s] ERROR: %s', $result->getId(), $result->getError()));
            }
        }

        if ($output->isVeryVerbose()) {
            $io->section('Full response dump');
            VarDumper::dump($this->extractResultsData($results));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJobData(BatchJob $job): array
    {
        return [
            'id' => $job->getId(),
            'status' => $job->getStatus()->value,
            'total_count' => $job->getTotalCount(),
            'processed_count' => $job->getProcessedCount(),
            'failed_count' => method_exists($job, 'getFailedCount') ? $job->getFailedCount() : null,
            'cancelled_count' => method_exists($job, 'getCancelledCount') ? $job->getCancelledCount() : null,
            'completed_at' => method_exists($job, 'getCompletedAt') ? $job->getCompletedAt()?->format('c') : null,
            'expires_at' => method_exists($job, 'getExpiresAt') ? $job->getExpiresAt()?->format('c') : null,
            'created_at' => method_exists($job, 'getCreatedAt') ? $job->getCreatedAt()?->format('c') : null,
            'finalization_started_at' => method_exists($job, 'getFinalizationStartedAt') ? $job->getFinalizationStartedAt()?->format('c') : null,
            'pending' => $job->getTotalCount() - $job->getProcessedCount(),
            'is_terminal' => $job->isTerminal(),
            'is_failed' => $job->isFailed(),
            'is_cancelled' => method_exists($job, 'isCancelled') ? $job->isCancelled() : false,
            'is_completed' => method_exists($job, 'isCompleted') ? $job->isCompleted() : false,
        ];
    }

    /**
     * @param array<int, BatchResult> $results
     * @return array<int, array<string, mixed>>
     */
    private function extractResultsData(array $results): array
    {
        return array_map(function (BatchResult $result): array {
            $data = [
                'id' => $result->getId(),
                'success' => $result->isSuccess(),
                'content' => $result->getContent(),
                'input_tokens' => $result->getInputTokens(),
                'output_tokens' => $result->getOutputTokens(),
            ];

            if (!$result->isSuccess()) {
                $data['error'] = $result->getError();
            }

            return $data;
        }, $results);
    }
}
