<?php

namespace Espo\Modules\Archive\Classes\Select\ArchivedEmail\AccessControlFilters;

use Espo\Core\Acl;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;

/**
 * Restricts the list to archived emails whose parent or account the user can currently read.
 */
class ViaParent implements Filter
{
    public function __construct(
        private string $entityType,
        private User $user,
        private Acl $acl,
        private Metadata $metadata,
        private SelectBuilderFactory $selectBuilderFactory
    ) {}

    public function apply(QueryBuilder $queryBuilder): void
    {
        $orList = [];

        /** @var string[] $parentTypeList */
        $parentTypeList = $this->metadata->get(['entityDefs', $this->entityType, 'fields', 'parent', 'entityList']) ?? [];

        foreach ($parentTypeList as $parentType) {
            $condition = $this->readableCondition($parentType, 'parentId');

            if ($condition) {
                $orList[] = Cond::and(Cond::equal(Cond::column('parentType'), $parentType), $condition);
            }
        }

        $accountCondition = $this->readableCondition('Account', 'accountId');

        if ($accountCondition) {
            $orList[] = $accountCondition;
        }

        if ($orList === []) {
            $queryBuilder->where([Attribute::ID => null]);

            return;
        }

        $queryBuilder->where(Cond::or(...$orList));
    }

    /**
     * A condition matching rows whose $idColumn points to a record of $entityType that the
     * user can read, or null if the user cannot read that entity type at all.
     */
    private function readableCondition(string $entityType, string $idColumn): ?WhereItem
    {
        if (!$this->acl->checkScope($entityType, Acl\Table::ACTION_READ)) {
            return null;
        }

        if ($this->acl->checkReadAll($entityType)) {
            return Cond::notEqual(Cond::column($idColumn), null);
        }

        $subQuery = $this->selectBuilderFactory
            ->create()
            ->from($entityType)
            ->forUser($this->user)
            ->withAccessControlFilter()
            ->buildQueryBuilder()
            ->select([Attribute::ID])
            ->build();

        return Cond::in(Cond::column($idColumn), $subQuery);
    }
}
