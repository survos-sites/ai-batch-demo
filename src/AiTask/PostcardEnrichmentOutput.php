<?php
declare(strict_types=1);

namespace App\AiTask;

final class PostcardEnrichmentOutput
{
    public ?string $title = null;
    public ?string $description = null;
    public ?string $country = null;
    public ?string $state = null;
    public ?string $city = null;

    /** @var array<int, PostcardKeywordOutput> */
    public array $keywords = [];
}
