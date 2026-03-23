<?php
declare(strict_types=1);

namespace App\Import;

final class PostcardAiResultRow
{
    public ?string $title = null;
    public ?string $description = null;
    public ?string $country = null;
    public ?string $state = null;
    public ?string $city = null;

    /** @var array<int, array{value: string, confidence?: float, basis?: string}> */
    public array $keywords = [];

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        $self = new self();
        $self->title = is_string($data['title'] ?? null) ? $data['title'] : null;
        $self->description = is_string($data['description'] ?? null) ? $data['description'] : null;
        $self->country = is_string($data['country'] ?? null) ? $data['country'] : null;
        $self->state = is_string($data['state'] ?? null) ? $data['state'] : null;
        $self->city = is_string($data['city'] ?? null) ? $data['city'] : null;
        $self->keywords = is_array($data['keywords'] ?? null) ? $data['keywords'] : [];

        return $self;
    }
}
