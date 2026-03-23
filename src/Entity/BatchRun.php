<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\BatchRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\AI\Platform\Batch\BatchJob;

#[ORM\Entity(repositoryClass: BatchRunRepository::class)]
#[ORM\Table(name: 'batch_run')]
#[ORM\UniqueConstraint(name: 'uniq_batch_run_provider_batch_id', columns: ['provider_batch_id'])]
class BatchRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 191)]
    private string $providerBatchId;

    #[ORM\Column(length: 64)]
    private string $task;

    #[ORM\Column(length: 64)]
    private string $model;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column]
    private int $requestCount;

    #[ORM\Column]
    private int $processedCount = 0;

    #[ORM\Column]
    private int $failedCount = 0;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $outputFileId = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $errorFileId = null;

    #[ORM\Column(type: Types::JSON)]
    private array $meta = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastPolledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resultsPath = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resultsFetchedAt = null;

    public static function fromSubmission(
        string $task,
        string $model,
        int $requestCount,
        BatchJob $job,
        array $meta = [],
    ): self {
        $now = new \DateTimeImmutable();

        $self = new self();
        $self->task = $task;
        $self->model = $model;
        $self->requestCount = $requestCount;
        $self->meta = $meta;
        $self->createdAt = $now;
        $self->submittedAt = $now;
        $self->applyJobSnapshot($job, $now);

        return $self;
    }

    public function applyJobSnapshot(BatchJob $job, ?\DateTimeImmutable $polledAt = null): void
    {
        $this->providerBatchId = $job->getId();
        $this->status = $job->getStatus()->value;
        $this->processedCount = $job->getProcessedCount();
        $this->failedCount = $job->getFailedCount();
        $this->outputFileId = $job->getOutputFileId();
        $this->errorFileId = $job->getErrorFileId();
        $this->lastPolledAt = $polledAt ?? new \DateTimeImmutable();

        if ($job->isTerminal() && null === $this->completedAt) {
            $this->completedAt = new \DateTimeImmutable();
        }
    }

    public function markResultsStored(string $path): void
    {
        $this->resultsPath = $path;
        $this->resultsFetchedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderBatchId(): string
    {
        return $this->providerBatchId;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getOutputFileId(): ?string
    {
        return $this->outputFileId;
    }

    public function getErrorFileId(): ?string
    {
        return $this->errorFileId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getLastPolledAt(): ?\DateTimeImmutable
    {
        return $this->lastPolledAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getResultsPath(): ?string
    {
        return $this->resultsPath;
    }

    public function getResultsFetchedAt(): ?\DateTimeImmutable
    {
        return $this->resultsFetchedAt;
    }
}
