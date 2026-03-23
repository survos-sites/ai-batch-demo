<?php
declare(strict_types=1);

namespace App\AiTask;

final class PostcardKeywordOutput
{
    public string $value = '';
    public ?float $confidence = null;
    public ?string $basis = null;
}
