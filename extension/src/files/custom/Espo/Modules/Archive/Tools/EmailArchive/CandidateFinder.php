<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Metadata;
use Espo\Entities\Email;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;
use PDO;

/**
 * Finds emails eligible for archiving.
 */
class CandidateFinder
{
    /** Drafts and emails in the sending pipeline are never archived. */
    private const STATUS_LIST = [Email::STATUS_ARCHIVED, Email::STATUS_SENT];

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata
    ) {}

    /**
     * @return string[]
     */
    public function find(Params $params, int $limit, ?string $afterId = null): array
    {
        $builder = $this->baseQuery($params)
            ->select([Attribute::ID])
            ->order(Attribute::ID, 'ASC')
            ->limit(0, $limit);

        if ($afterId) {
            // Skipped or failed emails stay in the table; a cursor keeps the run from looping on them.
            $builder->where(Cond::greater(Cond::column(Attribute::ID), $afterId));
        }

        return $this->entityManager
            ->getQueryExecutor()
            ->execute($builder->build())
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function count(Params $params): int
    {
        $row = $this->entityManager
            ->getQueryExecutor()
            ->execute($this->baseQuery($params)->select(['COUNT:(id)'])->build())
            ->fetch(PDO::FETCH_NUM);

        return (int) ($row[0] ?? 0);
    }

    /**
     * @return array{int, int} Attachment count and total size in bytes.
     */
    public function attachmentStats(Params $params): array
    {
        $emailQuery = $this->baseQuery($params)->select([Attribute::ID])->build();

        $row = $this->entityManager
            ->getQueryExecutor()
            ->execute(
                SelectBuilder::create()
                    ->from('Attachment')
                    ->select(['COUNT:(id)', 'SUM:(size)'])
                    ->where(['parentType' => Email::ENTITY_TYPE])
                    ->where(Cond::in(Cond::column('parentId'), $emailQuery))
                    ->build()
            )
            ->fetch(PDO::FETCH_NUM);

        return [(int) ($row[0] ?? 0), (int) ($row[1] ?? 0)];
    }

    private function baseQuery(Params $params): SelectBuilder
    {
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("-{$params->olderThanDays} days")
            ->format('Y-m-d H:i:s');

        $builder = SelectBuilder::create()
            ->from(Email::ENTITY_TYPE)
            ->where([
                'deleted' => false,
                'status' => self::STATUS_LIST,
            ])
            ->where(
                Cond::or(
                    Cond::less(Cond::column('dateSent'), $cutoff),
                    Cond::and(
                        Cond::equal(Cond::column('dateSent'), null),
                        Cond::less(Cond::column('createdAt'), $cutoff)
                    )
                )
            );

        if ($params->mode === Mode::CopyOnly) {
            // Copies are kept next to the originals; without this, every run would
            // revisit the same emails. Move mode keeps them to finish interrupted runs.
            $builder->where(
                Cond::notIn(
                    Cond::column(Attribute::ID),
                    SelectBuilder::create()
                        ->from(EmailArchiver::ENTITY_TYPE)
                        ->select(['originalId'])
                        ->build()
                )
            );
        }

        if ($params->skipActiveParent) {
            foreach ($this->activeParentConditions() as $condition) {
                $builder->where(Cond::not($condition));
            }
        }

        return $builder;
    }

    /**
     * For every parent entity type with a status field, a condition matching emails whose
     * parent is still active (status not in the type's "not actual" options).
     *
     * @return WhereItem[]
     */
    private function activeParentConditions(): array
    {
        $conditions = [];

        /** @var string[] $parentTypeList */
        $parentTypeList = $this->metadata->get(['entityDefs', Email::ENTITY_TYPE, 'fields', 'parent', 'entityList']) ?? [];

        foreach ($parentTypeList as $parentType) {
            $statusField = $this->metadata->get(['scopes', $parentType, 'statusField']);

            /** @var ?string[] $notActualList */
            $notActualList = $statusField ?
                $this->metadata->get(['entityDefs', $parentType, 'fields', $statusField, 'notActualOptions']) :
                null;

            if (!$notActualList) {
                continue;
            }

            $activeQuery = SelectBuilder::create()
                ->from($parentType)
                ->select([Attribute::ID])
                ->where(Cond::notIn(Cond::column($statusField), $notActualList))
                ->build();

            $conditions[] = Cond::and(
                Cond::equal(Cond::column('parentType'), $parentType),
                Cond::in(Cond::column('parentId'), $activeQuery)
            );
        }

        return $conditions;
    }
}
