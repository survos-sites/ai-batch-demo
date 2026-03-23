<?php
declare(strict_types=1);

namespace App\Import;

final class PostcardImportRow
{
    public string $id = '';
    public string $title = '';
    public ?string $description = null;
    public ?string $thumbnail_url = null;
    public ?string $iiif_manifest = null;
    public ?string $iiif_base = null;
    public ?string $country = null;
    public ?string $state = null;
    public ?string $city = null;

    /** @var array<int, string> */
    public array $subject_facet = [];

    /** @var array<int, string> */
    public array $subject_geographic = [];

    public static function fromArray(array $row): self
    {
        $self = new self();
        $self->id = (string) ($row['id'] ?? '');
        $self->title = (string) ($row['title'] ?? '');
        $self->description = isset($row['description']) ? (string) $row['description'] : null;
        $self->thumbnail_url = isset($row['thumbnail_url']) ? (string) $row['thumbnail_url'] : null;
        $self->iiif_manifest = isset($row['iiif_manifest']) ? (string) $row['iiif_manifest'] : null;
        $self->iiif_base = isset($row['iiif_base']) ? (string) $row['iiif_base'] : null;
        $self->country = isset($row['country']) ? (string) $row['country'] : null;
        $self->state = isset($row['state']) ? (string) $row['state'] : null;
        $self->city = isset($row['city']) ? (string) $row['city'] : null;
        $self->subject_facet = is_array($row['subject_facet'] ?? null) ? array_values($row['subject_facet']) : [];
        $self->subject_geographic = is_array($row['subject_geographic'] ?? null) ? array_values($row['subject_geographic']) : [];

        return $self;
    }
}
