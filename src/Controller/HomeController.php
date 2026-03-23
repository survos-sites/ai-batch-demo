<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Postcard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tacman\AiBatch\Entity\AiBatch;
use Tacman\AiBatch\Entity\AiBatchResult;

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
            'latestPostcards' => $postcardRepository->findBy([], ['enrichedAt' => 'DESC'], 8),
        ]);
    }

    #[Route('/batch/{id}', name: 'app_batch_show', methods: ['GET'])]
    public function batch(int $id, EntityManagerInterface $entityManager): Response
    {
        $batch = $entityManager->getRepository(AiBatch::class)->find($id);
        if (!$batch instanceof AiBatch) {
            throw $this->createNotFoundException(sprintf('Batch %d not found.', $id));
        }

        $results = $entityManager->getRepository(AiBatchResult::class)->findBy(['aiBatchId' => $id], ['id' => 'ASC']);

        return $this->render('home/batch_show.html.twig', [
            'batch' => $batch,
            'results' => $results,
        ]);
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
            'ai_description' => $postcard->aiDescription,
            'ai_keywords' => $postcard->aiKeywords,
            'ai_keyword_details' => $keywordDetails,
            'enriched_at' => $postcard->enrichedAt?->format(DATE_ATOM),
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
