<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use DateTimeImmutable;
use DateTimeZone;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\SelectBuilder;
use PDO;

/**
 * Permanently deletes archived emails older than the retention period.
 */
class RetentionPurger
{
    public function __construct(
        private EntityManager $entityManager,
        private AttachmentRemover $attachmentRemover,
        private RowDeleter $rowDeleter
    ) {}

    public function purge(Params $params, Report $report): void
    {
        if ($params->retentionDays === 0) {
            return;
        }

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify("-{$params->retentionDays} days")
            ->format('Y-m-d H:i:s');

        while ($report->purged < $params->maxPerRun) {
            $ids = $this->entityManager
                ->getQueryExecutor()
                ->execute(
                    SelectBuilder::create()
                        ->from(EmailArchiver::ENTITY_TYPE)
                        ->select([Attribute::ID])
                        ->where(
                            Cond::or(
                                Cond::less(Cond::column('dateSent'), $cutoff),
                                Cond::and(
                                    Cond::equal(Cond::column('dateSent'), null),
                                    Cond::less(Cond::column('createdAt'), $cutoff)
                                )
                            )
                        )
                        ->limit(0, min($params->batchSize, $params->maxPerRun - $report->purged))
                        ->build()
                )
                ->fetchAll(PDO::FETCH_COLUMN);

            if ($ids === []) {
                return;
            }

            $this->entityManager->getTransactionManager()->run(function () use ($ids) {
                foreach ($ids as $id) {
                    $this->attachmentRemover->removeFor($id);
                }

                $this->rowDeleter->delete(EmailArchiver::ENTITY_TYPE, $ids);
            });

            $report->purged += count($ids);
        }
    }
}
