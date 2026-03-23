<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'postcard_keyword')]
#[ORM\UniqueConstraint(name: 'uniq_postcard_keyword', columns: ['postcard_id', 'value'])]
class PostcardKeyword
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Postcard::class, inversedBy: 'keywords')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Postcard $postcard;

    #[ORM\Column(length: 191)]
    public string $value;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    public ?float $confidence = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $basis = null;

    public function __construct(Postcard $postcard, string $value, ?float $confidence = null, ?string $basis = null)
    {
        $this->postcard = $postcard;
        $this->value = $value;
        $this->confidence = $confidence;
        $this->basis = $basis;
    }
}
