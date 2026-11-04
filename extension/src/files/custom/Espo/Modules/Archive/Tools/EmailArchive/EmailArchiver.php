<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\Attachment as AttachmentRepository;
use Throwable;

/**
 * Archives a single email: copy into an ArchivedEmail record, verify the copy, and in move
 * mode delete the original. Every step is idempotent, so an interrupted run is resumed by
 * simply running again.
 */
class EmailArchiver
{
    public const ENTITY_TYPE = 'ArchivedEmail';

    public function __construct(
        private EntityManager $entityManager,
        private EmailPurger $purger,
        private Log $log
    ) {}

    public function process(string $emailId, Mode $mode, Report $report): void
    {
        $email = $this->entityManager->getRDBRepositoryByClass(Email::class)->getById($emailId);

        if (!$email) {
            $report->skip('gone');

            return;
        }

        try {
            $archived = $this->findArchived($emailId);

            if (!$archived) {
                $archived = $this->entityManager
                    ->getTransactionManager()
                    ->run(fn () => $this->copy($email, $report));

                $report->copied++;
            }

            $problem = $this->verify($email, $archived);

            if ($problem) {
                $this->log->warning("Email archive: copy of email $emailId failed verification: $problem.");
                $report->failed++;

                return;
            }

            if ($mode !== Mode::Move) {
                return;
            }

            $this->entityManager
                ->getTransactionManager()
                ->run(fn () => $this->purger->purge($email));

            $report->archived++;
        } catch (Throwable $e) {
            $this->log->error("Email archive: email $emailId failed: {$e->getMessage()}", ['exception' => $e]);
            $report->failed++;
        }
    }

    private function findArchived(string $emailId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['originalId' => $emailId])
            ->findOne();
    }

    private function copy(Email $email, Report $report): Entity
    {
        $archived = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $fromString = $email->getFromString() ?: $email->getFromAddress();

        $archived->setMultiple([
            'name' => $email->getSubject(),
            'dateSent' => $email->get('dateSent'),
            'fromString' => $fromString,
            'toString' => implode(', ', $email->getToAddressList()),
            'ccString' => implode(', ', $email->getCcAddressList()),
            'bodyPlain' => $email->getBodyPlain(),
            'isHtml' => $email->get('isHtml'),
            'status' => $email->getStatus(),
            'messageId' => $email->get('messageId'),
            'originalId' => $email->getId(),
            'parentType' => $email->get('parentType'),
            'parentId' => $email->get('parentId'),
            'accountId' => $email->get('accountId'),
        ]);

        $this->entityManager->saveEntity($archived);

        $attachmentMap = $this->copyAttachments($email, $archived, $report);

        $archived->set('body', $this->rewriteAttachmentIds($email->getBody(), $attachmentMap));
        $archived->set('snapshot', $this->snapshot($email, $attachmentMap));

        $this->entityManager->saveEntity($archived);

        return $archived;
    }

    /**
     * Creates attachment records for the archive that point to the same stored file
     * (shared source id), so no file is duplicated or moved.
     *
     * @return array<string, array<string, mixed>> Original attachment id => copy data.
     */
    private function copyAttachments(Email $email, Entity $archived, Report $report): array
    {
        /** @var AttachmentRepository $repository */
        $repository = $this->entityManager->getRDBRepositoryByClass(Attachment::class);

        $map = [];

        $originals = $repository
            ->where(['parentType' => Email::ENTITY_TYPE, 'parentId' => $email->getId()])
            ->find();

        foreach ($originals as $original) {
            $copy = $repository->getCopiedAttachment($original);

            $copy->setMultiple([
                'parentType' => self::ENTITY_TYPE,
                'parentId' => $archived->getId(),
                'field' => $original->getTargetField() ?? 'attachments',
                'storage' => $original->getStorage(),
                'storageFilePath' => $original->get('storageFilePath'),
            ]);

            $repository->save($copy);

            $map[$original->getId()] = [
                'copyId' => $copy->getId(),
                'name' => $original->getName(),
                'type' => $original->getType(),
                'size' => $original->getSize(),
                'role' => $original->getRole(),
                'field' => $original->getTargetField(),
            ];

            $report->attachments++;
            $report->attachmentBytes += (int) $original->getSize();
        }

        return $map;
    }

    /**
     * Inline images reference attachments by id in the HTML body.
     *
     * @param array<string, array<string, mixed>> $attachmentMap
     */
    private function rewriteAttachmentIds(?string $body, array $attachmentMap): ?string
    {
        if ($body === null || $attachmentMap === []) {
            return $body;
        }

        return strtr($body, array_map(fn ($item) => $item['copyId'], $attachmentMap));
    }

    /**
     * Everything needed to understand the email later, without restoring it.
     *
     * @param array<string, array<string, mixed>> $attachmentMap
     * @return array<string, mixed>
     */
    private function snapshot(Email $email, array $attachmentMap): array
    {
        $attributes = get_object_vars($email->getValueMap());

        unset($attributes['body'], $attributes['bodyPlain']);

        $relations = [];

        $defs = $this->entityManager->getDefs()->getEntity(Email::ENTITY_TYPE);

        foreach ($defs->getRelationList() as $relation) {
            if (!$relation->isManyToMany()) {
                continue;
            }

            $ids = [];

            $related = $this->entityManager
                ->getRDBRepositoryByClass(Email::class)
                ->getRelation($email, $relation->getName())
                ->select(['id'])
                ->find();

            foreach ($related as $item) {
                $ids[] = $item->getId();
            }

            if ($ids !== []) {
                $relations[$relation->getName()] = $ids;
            }
        }

        return [
            'version' => 1,
            'archivedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'attributes' => $attributes,
            'addresses' => [
                'from' => $email->getFromAddress(),
                'to' => $email->getToAddressList(),
                'cc' => $email->getCcAddressList(),
                'bcc' => $email->getBccAddressList(),
                'replyTo' => $email->getReplyToAddressList(),
            ],
            'relations' => $relations,
            'attachments' => $attachmentMap,
        ];
    }

    /**
     * Returns a description of the first problem found, or null if the copy is complete.
     */
    private function verify(Email $email, Entity $archived): ?string
    {
        $stored = $this->entityManager->getEntityById(self::ENTITY_TYPE, $archived->getId());

        if (!$stored) {
            return 'archive record missing';
        }

        /** @var array<string, array<string, mixed>> $attachmentMap */
        $attachmentMap = json_decode(json_encode($stored->get('snapshot')->attachments ?? []), true);

        if ((string) $stored->get('name') !== (string) $email->getSubject()) {
            return 'subject differs';
        }

        $expectedBody = $this->rewriteAttachmentIds($email->getBody(), $attachmentMap);

        if (sha1((string) $stored->get('body')) !== sha1((string) $expectedBody)) {
            return 'body differs';
        }

        $originalCount = $this->entityManager
            ->getRDBRepositoryByClass(Attachment::class)
            ->where(['parentType' => Email::ENTITY_TYPE, 'parentId' => $email->getId()])
            ->count();

        $copyCount = $this->entityManager
            ->getRDBRepositoryByClass(Attachment::class)
            ->where([
                'parentType' => self::ENTITY_TYPE,
                'parentId' => $stored->getId(),
                'id' => array_column($attachmentMap, 'copyId') ?: [''],
            ])
            ->count();

        if ($originalCount !== count($attachmentMap) || $copyCount !== count($attachmentMap)) {
            return "attachments differ (original $originalCount, recorded " . count($attachmentMap) .
                ", copies $copyCount)";
        }

        return null;
    }
}
