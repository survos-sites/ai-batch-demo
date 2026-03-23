<?php
declare(strict_types=1);

namespace App\AiTask;

use App\Entity\Postcard;
use App\Enum\EnrichmentStatus;
use App\Message\EnrichPostcardMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tacman\AiBatch\Contract\BatchableAiTaskHandlerInterface;
use Tacman\AiBatch\Model\BatchRequest;
use Tacman\AiBatch\Model\BatchResult;

final class EnrichPostcardAiTask implements BatchableAiTaskHandlerInterface
{
    private PlatformInterface $platform;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $openaiApiKey,
    ) {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new PlatformSubscriber());

        $this->platform = PlatformFactory::create($this->openaiApiKey, $httpClient, eventDispatcher: $eventDispatcher);
    }

    public static function taskName(): string
    {
        return EnrichPostcardMessage::taskName();
    }

    public function run(object $message): object
    {
        if (!$message instanceof EnrichPostcardMessage) {
            throw new \InvalidArgumentException(sprintf('Unsupported message type "%s".', $message::class));
        }

        $postcard = $this->requirePostcard($message->postcardId);
        $output = $this->invokeStructuredOutput($postcard, $postcard);

        $postcard->aiTitle = $output->title;
        $postcard->aiDescription = $output->description;
        $postcard->aiCountry = $output->country;
        $postcard->aiState = $output->state;
        $postcard->aiCity = $output->city;
        $postcard->syncKeywordDetails($this->normalizeKeywordDetails($output->keywords));
        $postcard->enriched = true;
        $postcard->enrichmentStatus = EnrichmentStatus::FINISHED;
        $postcard->enrichedAt = new \DateTimeImmutable();
        $postcard->updatedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        return $output;
    }

    public function toBatchRequest(object $message): BatchRequest
    {
        if (!$message instanceof EnrichPostcardMessage) {
            throw new \InvalidArgumentException(sprintf('Unsupported message type "%s".', $message::class));
        }

        $postcard = $this->requirePostcard($message->postcardId);

        return new BatchRequest(
            customId: $message->postcardId,
            systemPrompt: $this->systemPrompt(),
            userPrompt: $this->userPrompt($postcard),
            model: 'gpt-4o-mini',
            imageUrl: $postcard->thumbnailUrl,
            responseFormatClass: PostcardEnrichmentOutput::class,
            options: ['max_tokens' => 500],
        );
    }

    public function applyBatchResult(object $message, BatchResult $result): void
    {
        if (!$message instanceof EnrichPostcardMessage || !$result->success) {
            return;
        }

        $postcard = $this->requirePostcard($message->postcardId);
        $output = new PostcardEnrichmentOutput();

        $content = $result->content;
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $content = $decoded;
            }
        }

        if (is_array($content)) {
            $output->title = is_string($content['title'] ?? null) ? $content['title'] : null;
            $output->description = is_string($content['description'] ?? null) ? $content['description'] : null;
            $output->country = is_string($content['country'] ?? null) ? $content['country'] : null;
            $output->state = is_string($content['state'] ?? null) ? $content['state'] : null;
            $output->city = is_string($content['city'] ?? null) ? $content['city'] : null;
            $output->keywords = is_array($content['keywords'] ?? null) ? $content['keywords'] : [];
        }

        $postcard->aiTitle = $output->title;
        $postcard->aiDescription = $output->description;
        $postcard->aiCountry = $output->country;
        $postcard->aiState = $output->state;
        $postcard->aiCity = $output->city;
        $postcard->syncKeywordDetails($this->normalizeKeywordDetails($output->keywords));
        $postcard->enriched = true;
        $postcard->enrichmentStatus = EnrichmentStatus::FINISHED;
        $postcard->enrichedAt = new \DateTimeImmutable();
        $postcard->updatedAt = new \DateTimeImmutable();
        $this->entityManager->flush();
    }

    private function requirePostcard(string $postcardId): Postcard
    {
        $postcard = $this->entityManager->find(Postcard::class, $postcardId);
        if (!$postcard instanceof Postcard) {
            throw new \RuntimeException(sprintf('Postcard "%s" not found.', $postcardId));
        }

        return $postcard;
    }

    private function invokeStructuredOutput(Postcard $postcard): PostcardEnrichmentOutput
    {
        $output = new PostcardEnrichmentOutput();
        $messages = new MessageBag(
            Message::forSystem($this->systemPrompt()),
            $this->userMessage($postcard),
        );

        $result = $this->platform->invoke('gpt-4o-mini', $messages, [
            'response_format' => $output,
        ]);

        $mapped = $result->asObject();
        
        $metadata = $result->getMetadata();
        $allMetadata = is_object($metadata) && method_exists($metadata, 'all') ? $metadata->all() : [];
        if (isset($allMetadata['token_usage'])) {
            $tokenUsage = $allMetadata['token_usage'];
            if ($tokenUsage instanceof \Symfony\AI\Platform\TokenUsage\TokenUsageInterface) {
                $postcard->promptTokens = $tokenUsage->getPromptTokens();
                $postcard->outputTokens = $tokenUsage->getCompletionTokens();
            }
        }

        if ($mapped instanceof PostcardEnrichmentOutput) {
            return $mapped;
        }

        return $output;
    }

    private function systemPrompt(): string
    {
        return 'You enrich vintage postcard records. Return ONLY valid JSON with this exact structure: {"title":"...","description":"...","country":"...","state":"...","city":"...","keywords":[{"value":"...","confidence":0.9,"basis":"..."}]}';
    }

    private function userPrompt(Postcard $postcard): string
    {
        return trim(sprintf(
            "Title: %s\nCatalog description: %s\nCountry: %s\nState: %s\nCity: %s\n\nReturn ONLY valid JSON with keys: title (short, title case), description (one sentence), country, state, city, keywords (array with value, confidence 0-1, basis).",
            $postcard->title,
            $postcard->description ?? '',
            $postcard->country ?? '',
            $postcard->state ?? '',
            $postcard->city ?? '',
        ));
    }

    /**
     * @param array<int, mixed> $keywords
     * @return array<string, array{confidence: ?float, basis: ?string}>
     */
    private function normalizeKeywordDetails(array $keywords): array
    {
        $normalized = [];

        foreach ($keywords as $keyword) {
            if ($keyword instanceof PostcardKeywordOutput) {
                $value = strtolower(trim($keyword->value));
                if ('' === $value) {
                    continue;
                }

                $normalized[$value] = [
                    'confidence' => $keyword->confidence,
                    'basis' => $keyword->basis,
                ];
                continue;
            }

            if (is_array($keyword)) {
                $value = strtolower(trim((string) ($keyword['value'] ?? '')));
                if ('' === $value) {
                    continue;
                }

                $confidence = $keyword['confidence'] ?? null;
                if (is_numeric($confidence)) {
                    $confidence = (float) $confidence;
                } else {
                    $confidence = null;
                }

                $basis = isset($keyword['basis']) ? (string) $keyword['basis'] : null;

                $normalized[$value] = [
                    'confidence' => $confidence,
                    'basis' => $basis,
                ];
            }
        }

        return $normalized;
    }

    private function userMessage(Postcard $postcard): MessageInterface
    {
        $userPrompt = $this->userPrompt($postcard);

        if (null !== $postcard->thumbnailUrl && '' !== $postcard->thumbnailUrl) {
            return Message::ofUser($userPrompt, new ImageUrl($postcard->thumbnailUrl, 'low'));
        }

        return Message::ofUser($userPrompt);
    }
}
