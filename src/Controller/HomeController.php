<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Postcard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Import\PostcardAiResultRow;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\ObjectMapper\SymfonyObjectMapperInterface as SymfonySymfonyObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Entity\AiBatchResult;
use Tacman\AiBatch\Service\SymfonyBatchPlatformClient;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(EntityManagerInterface $entityManager): Response
    {
        $postcardRepository = $entityManager->getRepository(Postcard::class);
        $batchRepository = $entityManager->getRepository(AiBatch::class);
        $batchResultRepository = $entityManager->getRepository(AiBatchResult::class);
        $postcardCount = $postcardRepository->count([]);
        $notEnrichedCount = $postcardRepository->count(['enrichedAt' => null]);

        return $this->render('home/index.html.twig', [
            'postcardCount' => $postcardCount,
            'enrichedCount' => $postcardCount - $notEnrichedCount,
            'batchCount' => $batchRepository->count([]),
            'batchResultCount' => $batchResultRepository->count([]),
            'latestBatches' => $batchRepository->findBy([], ['createdAt' => 'DESC'], 10),
            'latestBatchResults' => $batchResultRepository->findBy([], ['createdAt' => 'DESC'], 10),
            'latestPostcards' => $postcardRepository->createQueryBuilder('p')
                ->where('p.updatedAt IS NOT NULL')
                ->orderBy('p.updatedAt', 'DESC')
                ->setMaxResults(8)
                ->getQuery()
                ->getResult(),
        ]);
    }

    #[Route('/batch/{id}', name: 'app_batch_show', methods: ['GET'])]
    public function batch(int $id, EntityManagerInterface $entityManager, SymfonyBatchPlatformClient $batchClient): Response
    {
        $batch = $entityManager->getRepository(AiBatch::class)->find($id);
        if (!$batch instanceof AiBatch) {
            throw $this->createNotFoundException(sprintf('Batch %d not found.', $id));
        }

        if ($batch->providerBatchId) {
            try {
                $providerJob = $batchClient->checkBatch($batch->providerBatchId);
                $batch->applyProviderStatus(
                    $providerJob->status ?? 'in_progress',
                    $providerJob->completedCount ?? 0,
                    $providerJob->failedCount ?? 0,
                    $providerJob->outputFileId,
                    $providerJob->errorFileId,
                );
                $entityManager->flush();
            } catch (\Exception $e) {
            }
        }

        $results = $entityManager->getRepository(AiBatchResult::class)->findBy(['aiBatchId' => $id], ['id' => 'ASC']);

        return $this->render('home/batch_show.html.twig', [
            'batch' => $batch,
            'results' => $results,
        ]);
    }

    #[Route('/batch/{id}/apply', name: 'app_batch_apply', methods: ['POST'])]
    public function applyBatch(int $id, EntityManagerInterface $entityManager, SymfonyBatchPlatformClient $batchClient, SymfonyObjectMapperInterface $objectMapper): RedirectResponse
    {
        $batch = $entityManager->getRepository(AiBatch::class)->find($id);
        if (!$batch instanceof AiBatch) {
            throw $this->createNotFoundException(sprintf('Batch %d not found.', $id));
        }

        if (!$batch->providerBatchId) {
            $this->addFlash('error', 'Batch has no provider ID.');
            return $this->redirectToRoute('app_batch_show', ['id' => $id]);
        }

        try {
            $providerJob = $batchClient->checkBatch($batch->providerBatchId);
            $count = 0;

            foreach ($batchClient->fetchResults($providerJob) as $result) {
                $existingResult = $entityManager->getRepository(\Tacman\AiBatch\Entity\AiBatchResult::class)
                    ->findOneBy(['aiBatchId' => $batch->id, 'customId' => $result->customId]);

                if ($existingResult) {
                    continue;
                }

                $resultEntity = new \Tacman\AiBatch\Entity\AiBatchResult();
                $resultEntity->aiBatchId = $batch->id;
                $resultEntity->customId = $result->customId;
                $resultEntity->success = $result->success;
                $resultEntity->content = $result->content;
                $resultEntity->promptTokens = $result->promptTokens;
                $resultEntity->outputTokens = $result->outputTokens;
                $resultEntity->createdAt = new \DateTimeImmutable();

                if ($result->success) {
                    $postcard = $entityManager->find(Postcard::class, $result->customId);
                    if ($postcard) {
                        $this->applyResultToPostcard($postcard, $result->content, $result->promptTokens, $result->outputTokens, $objectMapper);
                        $count++;
                    }
                }

                try {
                    $entityManager->persist($resultEntity);
                } catch (\Throwable $e) {
                    if (!str_contains($e->getMessage(), 'duplicate key')) {
                        throw $e;
                    }
                }
            }

            $batch->appliedCount += $count;
            $entityManager->flush();
            $this->addFlash('success', sprintf('Applied %d results.', $count));
        } catch (\Exception $e) {
            $this->addFlash('error', sprintf('Error: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('app_batch_show', ['id' => $id]);
    }

    private function applyResultToPostcard(Postcard $postcard, ?string $content, int $promptTokens = 0, int $outputTokens = 0, SymfonyObjectMapperInterface $objectMapper): void
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
        $postcard->enrichmentStatus = \App\Enum\EnrichmentStatus::FINISHED;
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

    private function stripMarkdownCodeBlocks(string $content): string
    {
        if (preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }
        if (preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            return trim($matches[1]);
        }

        return trim($content);
    }

    #[Route('/postcard/{id}', name: 'app_postcard_show', methods: ['GET'])]
    public function postcard(string $id, EntityManagerInterface $entityManager): Response
    {
        $postcard = $entityManager->getRepository(Postcard::class)->find($id);
        if (!$postcard instanceof Postcard) {
            throw $this->createNotFoundException(sprintf('Postcard %s not found.', $id));
        }

        $latestBatchResult = $entityManager->getRepository(AiBatchResult::class)->findOneBy(['customId' => $id], ['id' => 'DESC']);
        $keywordDetails = [];
        foreach ($postcard->keywords as $keyword) {
            $keywordDetails[] = [
                'value' => $keyword->value,
                'confidence' => $keyword->confidence,
                'basis' => $keyword->basis,
            ];
        }

        $promptPreview = trim(sprintf(
            "Title: %s\nCatalog description: %s\nCountry: %s\nState: %s\nCity: %s\n\nRules:\n- description: one sentence, factual, no speculation\n- keywords: array of objects\n- each keyword object has value, confidence (0..1), basis\n- value should be lowercase and deduplicated",
            $postcard->title,
            $postcard->description ?? '',
            $postcard->country ?? '',
            $postcard->state ?? '',
            $postcard->city ?? '',
        ));

        $finalRecord = array_merge($postcard->rawData, [
            'ai_title' => $postcard->aiTitle,
            'ai_description' => $postcard->aiDescription,
            'ai_country' => $postcard->aiCountry,
            'ai_state' => $postcard->aiState,
            'ai_city' => $postcard->aiCity,
            'ai_keywords' => $postcard->aiKeywords,
            'ai_keyword_details' => $keywordDetails,
            'enriched_at' => $postcard->enrichedAt?->format(DATE_ATOM),
            'updated_at' => $postcard->updatedAt?->format(DATE_ATOM),
        ]);

        return $this->render('home/postcard_show.html.twig', [
            'postcard' => $postcard,
            'finalRecord' => $finalRecord,
            'promptPreview' => $promptPreview,
            'modelName' => 'gpt-4o-mini',
            'latestBatchResult' => $latestBatchResult,
            'keywordDetails' => $keywordDetails,
        ]);
    }
}
