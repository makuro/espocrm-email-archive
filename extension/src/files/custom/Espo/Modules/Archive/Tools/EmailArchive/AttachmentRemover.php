<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\Entities\Attachment;
use Espo\ORM\EntityManager;

/**
 * Removes the attachments of an archived email. Records go through the repository so the
 * core hook deletes a file only when no other attachment shares it.
 */
class AttachmentRemover
{
    public function __construct(private EntityManager $entityManager) {}

    public function removeFor(string $archivedEmailId): void
    {
        $repository = $this->entityManager->getRDBRepositoryByClass(Attachment::class);

        $attachments = $repository
            ->where(['parentType' => EmailArchiver::ENTITY_TYPE, 'parentId' => $archivedEmailId])
            ->find();

        foreach ($attachments as $attachment) {
            $this->entityManager->removeEntity($attachment);
            $repository->deleteFromDb($attachment->getId(), true);
        }
    }
}
