<?php
declare(strict_types=1);

namespace App\Search;

use App\Entity\Postcard;
use Doctrine\ORM\QueryBuilder;
use Mezcalito\UxSearchBundle\Adapter\Doctrine\DoctrineAdapter;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Mezcalito\UxSearchBundle\Search\AbstractSearch;

#[AsSearch(index: Postcard::class, name: 'postcard', adapter: 'default')]
final class PostcardSearch extends AbstractSearch
{
    public function build(array $options = []): void
    {
        $this
            ->setAdapterParameters([
                DoctrineAdapter::SEARCH_FIELDS => ['o.title', 'o.description', 'o.aiDescription', 'keywords.value'],
                DoctrineAdapter::QUERY_BUILDER_ALIAS => 'o',
                DoctrineAdapter::QUERY_BUILDER => static function (QueryBuilder $queryBuilder): void {
                    $queryBuilder->leftJoin('o.keywords', 'keywords');
                },
            ])
            ->addFacet('keywords.value', 'Keywords')
            ->addFacet('o.aiCountry', 'Country')
            ->addFacet('o.aiState', 'State')
            ->addFacet('o.aiCity', 'City')
            ->addFacet('o.enrichmentStatus', 'Enrichment Status')
            ->addAvailableSort('o.updatedAt:desc', 'Recently updated')
            ->addAvailableSort('o.aiTitle:asc', 'Title A-Z')
            ->setAvailableHitsPerPage([12, 24, 48]);
    }
}
