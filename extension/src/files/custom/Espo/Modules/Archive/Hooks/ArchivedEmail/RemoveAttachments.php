<?php

namespace Espo\Modules\Archive\Hooks\ArchivedEmail;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Modules\Archive\Tools\EmailArchive\AttachmentRemover;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RemoveOptions;

/**
 * When an admin deletes an archived email in the UI, its attachments go with it.
 *
 * @implements AfterRemove<Entity>
 */
class RemoveAttachments implements AfterRemove
{
    public function __construct(private AttachmentRemover $attachmentRemover) {}

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->attachmentRemover->removeFor($entity->getId());
    }
}
