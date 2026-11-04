<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\Entities\Note;
use Espo\Entities\Notification;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\DeleteBuilder;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UpdateBuilder;
use PDO;

/**
 * Deletes an original email and everything that only exists because of it.
 *
 * References to an email, per entity:
 * - Attachment (parent, related): records removed through the repository, so the core hook
 *   keeps the file while another attachment (e.g. the archive copy) shares its source.
 * - Note (parent, related): EmailReceived/EmailSent stream entries, deleted with their
 *   attachments, notifications and relationship rows.
 * - Notification (related, relatedParent): deleted.
 * - Task (email), Email (replied): reference set to null, the records stay.
 * - Many-to-many tables of the email: deleted (see RowDeleter).
 * - ActionHistoryRecord, UniqueId, webhook and email queue items: left as they are, they
 *   are logs or expire on their own.
 */
class EmailPurger
{
    public function __construct(
        private EntityManager $entityManager,
        private RowDeleter $rowDeleter
    ) {}

    public function purge(Email $email): void
    {
        $id = $email->getId();

        $this->removeAttachments(Cond::or(
            Cond::and(Cond::equal(Cond::column('parentType'), Email::ENTITY_TYPE), Cond::equal(Cond::column('parentId'), $id)),
            Cond::and(Cond::equal(Cond::column('relatedType'), Email::ENTITY_TYPE), Cond::equal(Cond::column('relatedId'), $id)),
        ));

        $noteIds = $this->ids(
            SelectBuilder::create()
                ->from(Note::ENTITY_TYPE)
                ->select([Attribute::ID])
                ->where(Cond::or(
                    Cond::and(Cond::equal(Cond::column('parentType'), Email::ENTITY_TYPE), Cond::equal(Cond::column('parentId'), $id)),
                    Cond::and(Cond::equal(Cond::column('relatedType'), Email::ENTITY_TYPE), Cond::equal(Cond::column('relatedId'), $id)),
                ))
        );

        if ($noteIds !== []) {
            $this->removeAttachments(Cond::and(
                Cond::equal(Cond::column('parentType'), Note::ENTITY_TYPE),
                Cond::in(Cond::column('parentId'), $noteIds)
            ));

            $this->execute(
                DeleteBuilder::create()
                    ->from(Notification::ENTITY_TYPE)
                    ->where(['relatedType' => Note::ENTITY_TYPE, 'relatedId' => $noteIds])
            );

            $this->rowDeleter->delete(Note::ENTITY_TYPE, $noteIds);
        }

        $this->execute(
            DeleteBuilder::create()
                ->from(Notification::ENTITY_TYPE)
                ->where(Cond::or(
                    Cond::and(Cond::equal(Cond::column('relatedType'), Email::ENTITY_TYPE), Cond::equal(Cond::column('relatedId'), $id)),
                    Cond::and(Cond::equal(Cond::column('relatedParentType'), Email::ENTITY_TYPE), Cond::equal(Cond::column('relatedParentId'), $id)),
                ))
        );

        $this->execute(UpdateBuilder::create()->in('Task')->set(['emailId' => null])->where(['emailId' => $id]));
        $this->execute(UpdateBuilder::create()->in(Email::ENTITY_TYPE)->set(['repliedId' => null])->where(['repliedId' => $id]));

        $this->rowDeleter->delete(Email::ENTITY_TYPE, [$id]);
    }

    private function removeAttachments(WhereItem $where): void
    {
        $repository = $this->entityManager->getRDBRepositoryByClass(Attachment::class);

        foreach ($repository->where($where)->find() as $attachment) {
            $this->entityManager->removeEntity($attachment);
            $repository->deleteFromDb($attachment->getId(), true);
        }
    }

    /**
     * @return string[]
     */
    private function ids(SelectBuilder $builder): array
    {
        return $this->entityManager->getQueryExecutor()->execute($builder->build())->fetchAll(PDO::FETCH_COLUMN);
    }

    private function execute(DeleteBuilder|UpdateBuilder $builder): void
    {
        $this->entityManager->getQueryExecutor()->execute($builder->build());
    }
}
