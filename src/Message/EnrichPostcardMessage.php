<?php
declare(strict_types=1);

namespace App\Message;

use Tacman\AiBatch\Contract\AiTaskMessageInterface;

final class EnrichPostcardMessage implements AiTaskMessageInterface
{
    public function __construct(
        public readonly string $postcardId,
    ) {
    }

    public static function taskName(): string
    {
        return 'postcard.enrich';
    }

    public function subjectId(): string|int
    {
        return $this->postcardId;
    }

    public function dedupeKey(): ?string
    {
        return sprintf('%s:%s', self::taskName(), $this->postcardId);
    }
}
