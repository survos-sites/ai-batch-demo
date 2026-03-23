<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\BatchRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BatchRun>
 */
class BatchRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BatchRun::class);
    }

    public function findOneByProviderBatchId(string $providerBatchId): ?BatchRun
    {
        return $this->findOneBy(['providerBatchId' => $providerBatchId]);
    }
}
