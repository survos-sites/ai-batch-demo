<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[ORM\Entity]
#[ORM\Table(name: 'postcard')]
class Postcard
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public string $id;

    #[ORM\Column(length: 255)]
    public string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $description = null;

    #[Map(source: 'thumbnail_url')]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $thumbnailUrl = null;

    #[Map(source: 'iiif_manifest')]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $iiifManifest = null;

    #[Map(source: 'iiif_base')]
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $iiifBase = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $country = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $state = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $city = null;

    #[Map(source: 'subject_facet')]
    #[ORM\Column(type: Types::JSON)]
    public array $subjectFacet = [];

    #[Map(source: 'subject_geographic')]
    #[ORM\Column(type: Types::JSON)]
    public array $subjectGeographic = [];

    #[ORM\Column(type: Types::JSON)]
    public array $rawData = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $aiDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $aiTitle = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $aiCountry = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $aiState = null;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $aiCity = null;

    #[ORM\Column(type: Types::JSON)]
    public array $aiKeywords = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $enrichedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    public ?bool $enriched = null;

    #[ORM\Column(nullable: true)]
    public ?int $promptTokens = null;

    #[ORM\Column(nullable: true)]
    public ?int $outputTokens = null;

    /** @var Collection<int, PostcardKeyword> */
    #[ORM\OneToMany(mappedBy: 'postcard', targetEntity: PostcardKeyword::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $keywords;

    public function __construct(string $id)
    {
        $this->id = $id;
        $this->keywords = new ArrayCollection();
    }

    public function syncKeywords(array $values): void
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $keyword = strtolower(trim($value));
            if ('' === $keyword) {
                continue;
            }

            $normalized[$keyword] = [
                'confidence' => null,
                'basis' => null,
            ];
        }

        $this->syncKeywordDetails($normalized);
    }

    /**
     * @param array<string, array{confidence: ?float, basis: ?string}> $keywords
     */
    public function syncKeywordDetails(array $keywords): void
    {
        $normalized = [];
        foreach ($keywords as $value => $details) {
            $keyword = strtolower(trim((string) $value));
            if ('' === $keyword) {
                continue;
            }

            $confidence = $details['confidence'] ?? null;
            if (null !== $confidence) {
                $confidence = max(0.0, min(1.0, $confidence));
            }

            $normalized[$keyword] = [
                'confidence' => $confidence,
                'basis' => isset($details['basis']) ? trim((string) $details['basis']) : null,
            ];
        }

        $this->aiKeywords = array_keys($normalized);

        foreach ($this->keywords as $existing) {
            if (isset($normalized[$existing->value])) {
                $existing->confidence = $normalized[$existing->value]['confidence'];
                $existing->basis = $normalized[$existing->value]['basis'];
                continue;
            }

            $this->keywords->removeElement($existing);
        }

        foreach ($normalized as $keyword => $details) {
            $alreadyPresent = $this->keywords->exists(
                static fn(int $index, PostcardKeyword $item): bool => $item->value === $keyword
            );

            if ($alreadyPresent) {
                continue;
            }

            $this->keywords->add(new PostcardKeyword($this, $keyword, $details['confidence'], $details['basis']));
        }
    }
}
