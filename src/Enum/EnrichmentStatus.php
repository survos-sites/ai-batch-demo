<?php

declare(strict_types=1);

namespace App\Enum;

enum EnrichmentStatus: string
{
    case NEW = 'new';
    case QUEUED = 'queued';
    case FINISHED = 'finished';
}
