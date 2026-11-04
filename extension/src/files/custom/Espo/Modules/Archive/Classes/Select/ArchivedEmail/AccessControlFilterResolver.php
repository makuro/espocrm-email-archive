<?php

namespace Espo\Modules\Archive\Classes\Select\ArchivedEmail;

use Espo\Core\Acl;
use Espo\Core\Select\AccessControl\FilterResolver;
use Espo\Entities\User;

/**
 * Admins see the whole archive. Users whose role grants access to the scope see archived
 * emails of records they can currently read. Everybody else sees nothing.
 */
class AccessControlFilterResolver implements FilterResolver
{
    public function __construct(
        private string $entityType,
        private User $user,
        private Acl $acl
    ) {}

    public function resolve(): ?string
    {
        if ($this->user->isAdmin()) {
            return 'all';
        }

        if ($this->acl->checkScope($this->entityType, Acl\Table::ACTION_READ)) {
            return 'viaParent';
        }

        return 'no';
    }
}
