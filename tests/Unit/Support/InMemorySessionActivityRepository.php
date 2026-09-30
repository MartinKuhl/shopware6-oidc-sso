<?php declare(strict_types=1);

namespace MartinKuhl\Sw6Oidc\Tests\Unit\Support;

use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityCollection;
use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityDefinition;
use MartinKuhl\Sw6Oidc\Core\Content\SessionActivity\Sw6OidcSessionActivityEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * A minimal in-memory stand-in for sw6oidc_session_activity.repository:
 * create/update merge payloads into rows, search supports id criteria,
 * EqualsFilter and a loggedInAt DESC sort — exactly what the recorder uses.
 */
final class InMemorySessionActivityRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /**
     * @param \Closure(class-string): object $createMock the test case's createMock
     */
    public function mock(\Closure $createMock): EntityRepository
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository $repository */
        $repository = $createMock(EntityRepository::class);

        $repository->method('create')->willReturnCallback(function (array $payload, Context $context): EntityWrittenContainerEvent {
            foreach ($payload as $row) {
                $this->rows[$row['id']] = $row;
            }

            return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
        });

        $repository->method('update')->willReturnCallback(function (array $payload, Context $context): EntityWrittenContainerEvent {
            foreach ($payload as $row) {
                $this->rows[$row['id']] = array_merge($this->rows[$row['id']] ?? [], $row);
            }

            return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
        });

        $repository->method('search')->willReturnCallback(function (Criteria $criteria, Context $context): EntitySearchResult {
            $rows = $criteria->getIds() !== [] ? array_intersect_key($this->rows, array_flip(array_map('strval', $criteria->getIds()))) : $this->rows;

            foreach ($criteria->getFilters() as $filter) {
                \assert($filter instanceof EqualsFilter);
                $rows = array_filter($rows, static fn (array $row): bool => ($row[$filter->getField()] ?? null) === $filter->getValue());
            }

            usort($rows, static fn (array $a, array $b): int => $b['loggedInAt'] <=> $a['loggedInAt']);

            $entities = array_map(static function (array $row): Sw6OidcSessionActivityEntity {
                $entity = new Sw6OidcSessionActivityEntity();
                $entity->assign($row);

                return $entity;
            }, $rows);

            return new EntitySearchResult(Sw6OidcSessionActivityDefinition::ENTITY_NAME, \count($entities), new Sw6OidcSessionActivityCollection($entities), null, $criteria, $context);
        });

        return $repository;
    }
}
