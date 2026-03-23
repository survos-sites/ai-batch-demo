<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\BatchRun;
use App\Repository\BatchRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Batch\BatchInput;
use Symfony\AI\Platform\Batch\BatchResult;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BatchRunManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BatchRunRepository $batchRuns,
        private readonly HttpClientInterface $httpClient,
        private readonly string $openaiApiKey,
    ) {
    }

    /**
     * @param iterable<BatchInput> $inputs
     * @param array<string, mixed> $meta
     */
    public function submit(string $task, string $model, iterable $inputs, int $requestCount, array $meta = []): BatchRun
    {
        $platform = PlatformFactory::createBatch($this->openaiApiKey, $this->httpClient);
        $job = $platform->submitBatch($model, $inputs, ['max_tokens' => 150]);

        $run = BatchRun::fromSubmission($task, $model, $requestCount, $job, $meta);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    public function refresh(BatchRun $run): BatchRun
    {
        $platform = PlatformFactory::createBatch($this->openaiApiKey, $this->httpClient);
        $job = $platform->getBatch($run->getProviderBatchId());

        $run->applyJobSnapshot($job);
        $this->entityManager->flush();

        return $run;
    }

    /**
     * @return list<BatchResult>
     */
    public function fetchResults(BatchRun $run): array
    {
        $platform = PlatformFactory::createBatch($this->openaiApiKey, $this->httpClient);
        $job = $platform->getBatch($run->getProviderBatchId());

        $run->applyJobSnapshot($job);
        $this->entityManager->flush();

        if (!$job->isComplete()) {
            return [];
        }

        return [...$platform->fetchResults($job)];
    }

    public function findByReference(string $reference): ?BatchRun
    {
        if (ctype_digit($reference)) {
            return $this->batchRuns->find((int) $reference);
        }

        return $this->batchRuns->findOneByProviderBatchId($reference);
    }

    public function saveResults(BatchRun $run, string $path, array $results): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $lines = [];
        foreach ($results as $result) {
            if ($result instanceof BatchResult && !$result->isSuccess()) {
                $lines[] = json_encode(['custom_id' => $result->getId(), 'error' => $result->getError()], \JSON_THROW_ON_ERROR);
                continue;
            }

            if ($result instanceof BatchResult) {
                $lines[] = json_encode([
                    'custom_id' => $result->getId(),
                    'response' => [
                        'content' => $result->getContent(),
                        'input_tokens' => $result->getInputTokens(),
                        'output_tokens' => $result->getOutputTokens(),
                    ],
                ], \JSON_THROW_ON_ERROR);
            }
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        $run->markResultsStored($path);
        $this->entityManager->flush();
    }
}
