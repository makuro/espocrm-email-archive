<?php

namespace Espo\Modules\Archive\Classes\Acl\ArchivedEmail;

use Espo\Core\Acl\AccessEntityCREDChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Archived emails are never created or edited through the API. Reading is allowed when the
 * user can read the record the email belonged to, evaluated with the user's current
 * permissions (not a snapshot of past teams or owners). Only admins can delete.
 */
class AccessChecker implements AccessEntityCREDChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        private AclManager $aclManager,
        private EntityManager $entityManager
    ) {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return false;
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $user->isAdmin();
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->defaultAccessChecker->checkRead($user, $data)) {
            return false;
        }

        foreach ($this->getRelatedRecords($entity) as $record) {
            if ($this->aclManager->checkEntityRead($user, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<Entity>
     */
    private function getRelatedRecords(Entity $entity): iterable
    {
        $parentType = $entity->get('parentType');
        $parentId = $entity->get('parentId');

        if ($parentType && $parentId) {
            $parent = $this->entityManager->getEntityById($parentType, $parentId);

            if ($parent) {
                yield $parent;
            }
        }

        $accountId = $entity->get('accountId');

        if ($accountId && !($parentType === 'Account' && $parentId === $accountId)) {
            $account = $this->entityManager->getEntityById('Account', $accountId);

            if ($account) {
                yield $account;
            }
        }
    }
}
