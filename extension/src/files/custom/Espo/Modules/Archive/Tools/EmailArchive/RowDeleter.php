<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\DeleteBuilder;

/**
 * Hard-deletes records together with their rows in many-to-many relationship tables.
 * Relationship tables are derived from ORM definitions, so this follows the actual schema
 * (e.g. entity_team, entity_user, email_user, email_email_address) instead of a fixed list.
 */
class RowDeleter
{
    public function __construct(private EntityManager $entityManager) {}

    /**
     * @param string[] $ids
     */
    public function delete(string $entityType, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->relationshipTables($entityType) as $relationshipEntityType => [$midKey, $conditions]) {
            $this->execute(
                DeleteBuilder::create()
                    ->from($relationshipEntityType)
                    ->where([$midKey => $ids])
                    ->where($conditions)
            );
        }

        $this->execute(
            DeleteBuilder::create()
                ->from($entityType)
                ->where([Attribute::ID => $ids])
        );
    }

    /**
     * Relations sharing one table (like to/cc/bcc addresses) differ only in their conditions.
     * Only the conditions common to all of them are kept, so every row of the record goes,
     * while conditions that identify the owner type (entityType = 'Email') still apply.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    private function relationshipTables(string $entityType): array
    {
        $tables = [];

        foreach ($this->entityManager->getDefs()->getEntity($entityType)->getRelationList() as $relation) {
            if (!$relation->isManyToMany()) {
                continue;
            }

            $name = ucfirst($relation->getRelationshipName());
            $conditions = $relation->getConditions();

            $tables[$name] = isset($tables[$name]) ?
                [$tables[$name][0], array_intersect_assoc($tables[$name][1], $conditions)] :
                [$relation->getMidKey(), $conditions];
        }

        return $tables;
    }

    private function execute(DeleteBuilder $builder): void
    {
        $this->entityManager->getQueryExecutor()->execute($builder->build());
    }
}
