<?php

namespace Espo\Modules\DevSeed\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Entities\Attachment;
use Espo\Entities\Email;
use Espo\Modules\Archive\Tools\EmailArchive\EmailArchiver;
use Espo\Modules\Archive\Tools\EmailArchive\Mode;
use Espo\Modules\Archive\Tools\EmailArchive\Params as ArchiveParams;
use Espo\Modules\Archive\Tools\EmailArchive\Report;
use Espo\Modules\Archive\Tools\EmailArchive\RetentionPurger;
use Espo\ORM\EntityManager;
use Espo\Repositories\Attachment as AttachmentRepository;
use Espo\Tools\Stream\Service as StreamService;

/**
 * Integration check for the archive extension: builds the tricky cases through the ORM
 * (with hooks, as Espo itself would), archives in move mode, purges by retention and asserts
 * the invariants after each step.
 *
 * Usage: bin/command dev-archive-check
 */
class ArchiveCheck implements Command
{
    private IO $io;
    private int $failures = 0;

    public function __construct(
        private EntityManager $entityManager,
        private EmailArchiver $archiver,
        private RetentionPurger $retentionPurger,
        private FileStorageManager $fileStorageManager,
        private StreamService $streamService
    ) {}

    public function run(Params $params, IO $io): void
    {
        $this->io = $io;

        /** @var AttachmentRepository $attachments */
        $attachments = $this->entityManager->getRDBRepositoryByClass(Attachment::class);

        $user = $this->entityManager->getRDBRepository('User')->where(['type' => 'regular'])->findOne();
        $team = $this->entityManager->getRDBRepository('Team')->findOne();
        $account = $this->entityManager->createEntity('Account', ['name' => 'Archive Check ' . uniqid()]);
        $case = $this->entityManager->createEntity('Case', [
            'name' => 'Archive check case',
            'status' => 'Closed',
            'accountId' => $account->getId(),
        ]);

        $old = '2019-03-01 10:00:00';

        $plain = $this->newAttachment('report.pdf', 'application/pdf', 'attachments', 'pdf-content-' . uniqid());
        $inline = $this->newAttachment('logo.png', 'image/png', 'body', 'png-content-' . uniqid());

        /** @var Email $email */
        $email = $this->entityManager->getNewEntity(Email::ENTITY_TYPE);
        $email->setMultiple([
            'name' => 'Archive check ' . uniqid(),
            'status' => Email::STATUS_ARCHIVED,
            'isHtml' => true,
            'body' => '<p>Hello</p><img src="?entryPoint=attachment&amp;id=' . $inline->getId() . '">',
            'from' => 'customer@archive-check.example',
            'to' => 'agent@archive-check.example',
            'dateSent' => $old,
            'parentType' => 'Case',
            'parentId' => $case->getId(),
            'accountId' => $account->getId(),
            'assignedUserId' => $user?->getId(),
            'usersIds' => $user ? [$user->getId()] : [],
            'teamsIds' => $team ? [$team->getId()] : [],
            'attachmentsIds' => [$plain->getId()],
        ]);
        $this->entityManager->saveEntity($email);

        $inline->setMultiple(['parentType' => Email::ENTITY_TYPE, 'parentId' => $email->getId()]);
        $attachments->save($inline);

        $emailId = $email->getId();

        // A newer email that forwarded the attachment (shares the file) and replies to the old one.
        $forward = $this->entityManager->createEntity(Email::ENTITY_TYPE, [
            'name' => 'Fwd: ' . $email->get('name'),
            'status' => Email::STATUS_ARCHIVED,
            'body' => 'see attached',
            'from' => 'agent@archive-check.example',
            'to' => 'other@archive-check.example',
            'dateSent' => date('Y-m-d H:i:s'),
            'repliedId' => $emailId,
        ]);
        $forwardCopy = $attachments->getCopiedAttachment($plain);
        $forwardCopy->setMultiple(['parentType' => Email::ENTITY_TYPE, 'parentId' => $forward->getId(), 'field' => 'attachments']);
        $attachments->save($forwardCopy);

        $task = $this->entityManager->createEntity('Task', ['name' => 'From email', 'emailId' => $emailId]);

        // Created the way mail import does it.
        $this->streamService->noteEmailReceived($case, $email);

        $notes = $this->count('Note', ['relatedType' => Email::ENTITY_TYPE, 'relatedId' => $emailId]);
        $this->check('fixture: stream note exists', $notes > 0, "notes: $notes");

        $this->entityManager->createEntity('Notification', [
            'type' => 'EmailReceived',
            'relatedType' => Email::ENTITY_TYPE,
            'relatedId' => $emailId,
            'userId' => $user?->getId(),
        ]);

        // Move.
        $report = new Report();
        $this->archiver->process($emailId, Mode::Move, $report);

        $this->check('move reported', $report->archived === 1 && $report->failed === 0, json_encode($report));

        $archived = $this->entityManager->getRDBRepository(EmailArchiver::ENTITY_TYPE)
            ->where(['originalId' => $emailId])->findOne();

        $this->check('archive record exists', $archived !== null);

        if (!$archived) {
            $this->finish();

            return;
        }

        $this->check('subject kept', $archived->get('name') === $email->get('name'));
        $this->check('to kept', str_contains((string) $archived->get('toString'), 'agent@archive-check.example'));
        $this->check('parent and account kept',
            $archived->get('parentId') === $case->getId() && $archived->get('accountId') === $account->getId());

        $copies = $attachments->where(['parentType' => EmailArchiver::ENTITY_TYPE, 'parentId' => $archived->getId()])->find();
        $this->check('both attachments copied', count($copies) === 2, 'copies: ' . count($copies));

        $inlineCopyId = null;

        foreach ($copies as $copy) {
            $this->check("file of copy '{$copy->getName()}' readable",
                $this->fileStorageManager->exists($copy) &&
                $this->fileStorageManager->getContents($copy) !== '');

            if ($copy->getTargetField() === 'body') {
                $inlineCopyId = $copy->getId();
            }
        }

        $body = (string) $archived->get('body');
        $this->check('inline image points to archive copy',
            $inlineCopyId && str_contains($body, $inlineCopyId) && !str_contains($body, $inline->getId()));

        $this->check('original email deleted', $this->countRaw('email', 'id', $emailId) === 0);
        $this->check('original attachments deleted',
            $this->countRaw('attachment', 'id', $plain->getId()) === 0 &&
            $this->countRaw('attachment', 'id', $inline->getId()) === 0);
        $this->check('stream notes deleted', $this->countRaw('note', 'related_id', $emailId) === 0);
        $this->check('notifications deleted', $this->countRaw('notification', 'related_id', $emailId) === 0);
        $this->check('email_user rows deleted', $this->countRaw('email_user', 'email_id', $emailId) === 0);
        $this->check('address rows deleted', $this->countRaw('email_email_address', 'email_id', $emailId) === 0);
        $this->check('team rows deleted', $this->countRaw('entity_team', 'entity_id', $emailId) === 0);
        $this->check('task kept, reference cleared',
            $this->entityManager->getEntityById('Task', $task->getId())?->get('emailId') === null);
        $this->check('reply kept, reference cleared',
            $this->entityManager->getEntityById(Email::ENTITY_TYPE, $forward->getId())?->get('repliedId') === null);

        $forwardCopy = $attachments->getById($forwardCopy->getId());
        $this->check('forwarded copy still has its file',
            $forwardCopy && $this->fileStorageManager->getContents($forwardCopy) !== '');

        // Retention purge of the archived email.
        $this->entityManager->getQueryExecutor()->execute(
            \Espo\ORM\Query\UpdateBuilder::create()
                ->in(EmailArchiver::ENTITY_TYPE)
                ->set(['dateSent' => '2001-01-01 00:00:00'])
                ->where(['id' => $archived->getId()])
                ->build()
        );

        $purgeReport = new Report();
        $this->retentionPurger->purge(
            new ArchiveParams(Mode::Move, 730, true, 100, 10000, 365 * 20),
            $purgeReport
        );

        $this->check('retention purged the record', $this->countRaw('archived_email', 'id', $archived->getId()) === 0,
            "purged: $purgeReport->purged");
        $this->check('archive attachments deleted',
            $this->countRaw('attachment', 'parent_id', $archived->getId()) === 0);
        $this->check('shared file survives purge (forward still uses it)',
            $forwardCopy && $this->fileStorageManager->exists($forwardCopy));
        $this->check('unshared inline file removed by purge',
            !file_exists('data/upload/' . $inline->getSourceId()));

        $this->finish();
    }

    private function newAttachment(string $name, string $type, string $field, string $contents): Attachment
    {
        /** @var Attachment $attachment */
        $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);
        $attachment->setMultiple([
            'name' => $name,
            'type' => $type,
            'role' => $field === 'body' ? 'Inline Attachment' : 'Attachment',
            'field' => $field,
            'contents' => $contents,
        ]);
        $this->entityManager->saveEntity($attachment);

        return $attachment;
    }

    /**
     * @param array<string, mixed> $where
     */
    private function count(string $entityType, array $where): int
    {
        return $this->entityManager->getRDBRepository($entityType)->where($where)->count();
    }

    /**
     * Counts raw rows, including soft-deleted ones.
     */
    private function countRaw(string $table, string $column, string $value): int
    {
        $pdo = $this->entityManager->getPDO();
        $q = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"' : '`';

        $sth = $pdo->prepare("SELECT COUNT(*) FROM $q$table$q WHERE $q$column$q = ?");
        $sth->execute([$value]);

        return (int) $sth->fetchColumn();
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        if (!$ok) {
            $this->failures++;
        }

        $this->io->writeLine(($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || !$detail ? '' : " ($detail)"));
    }

    private function finish(): void
    {
        $this->io->writeLine($this->failures ? "$this->failures check(s) failed." : 'All checks passed.');
        $this->io->setExitStatus($this->failures ? 1 : 0);
    }
}
