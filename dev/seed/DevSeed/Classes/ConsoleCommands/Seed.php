<?php

namespace Espo\Modules\DevSeed\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Util;
use Espo\ORM\EntityManager;
use PDO;

/**
 * Fills the instance with a large, realistic-looking data set for performance work.
 *
 * Writes directly with multi-row SQL inserts (no hooks) but produces the same rows Espo
 * creates when importing mail: emails with address relations and user links, stream notes
 * with super-parent, case history notes and attachments stored in the upload directory.
 *
 * Usage: bin/command dev-seed [--scale=1] [--seed=42] [--attachment-kb=16]
 */
class Seed implements Command
{
    private const BATCH = 250;
    private const YEARS = 8;
    private const UPLOAD_DIR = 'data/upload/';

    private const BASE_ACCOUNTS = 2000;
    private const BASE_CONTACTS_PER_ACCOUNT = 4;
    private const BASE_CASES = 30000;
    private const BASE_EMAILS = 100000;
    private const USERS = 12;
    private const TEAMS = 3;

    private const WORDS = [
        'account', 'invoice', 'contract', 'renewal', 'delivery', 'support', 'ticket', 'meeting', 'proposal',
        'pricing', 'discount', 'integration', 'license', 'upgrade', 'migration', 'onboarding', 'training',
        'feedback', 'escalation', 'deadline', 'schedule', 'quarter', 'budget', 'forecast', 'payment',
        'reminder', 'question', 'request', 'issue', 'problem', 'solution', 'update', 'status', 'report',
        'customer', 'partner', 'vendor', 'order', 'shipment', 'return', 'refund', 'warranty', 'service',
        'the', 'a', 'we', 'you', 'please', 'could', 'would', 'should', 'regarding', 'attached', 'find',
        'kindly', 'thanks', 'best', 'regards', 'next', 'week', 'monday', 'friday', 'today', 'tomorrow',
        'confirm', 'review', 'approve', 'send', 'check', 'discuss', 'call', 'follow', 'up', 'on', 'for',
        'with', 'and', 'to', 'of', 'in', 'our', 'your', 'this', 'that', 'it', 'is', 'are', 'was', 'be',
        'server', 'login', 'password', 'error', 'timeout', 'export', 'import', 'dashboard', 'api', 'sync',
    ];
    private const FIRST_NAMES = [
        'Anna', 'Ben', 'Clara', 'David', 'Eva', 'Felix', 'Greta', 'Hannes', 'Ida', 'Jonas', 'Lena', 'Max',
        'Nora', 'Oskar', 'Paula', 'Quentin', 'Rosa', 'Simon', 'Tara', 'Uwe', 'Vera', 'Wim', 'Yara', 'Zoe',
    ];
    private const LAST_NAMES = [
        'Bauer', 'Fischer', 'Hartmann', 'Keller', 'Lange', 'Meyer', 'Neumann', 'Richter', 'Schmidt',
        'Schulz', 'Vogel', 'Wagner', 'Weber', 'Wolf', 'Zimmermann', 'Becker', 'Hoffmann', 'Koch',
    ];
    private const COMPANY_SUFFIXES = ['GmbH', 'AG', 'KG', 'SE', 'Ltd', 'Inc'];
    private const CASE_STATUSES = [
        'Closed' => 70, 'Rejected' => 5, 'Duplicate' => 3, 'New' => 8, 'Assigned' => 8, 'Pending' => 6,
    ];

    private PDO $pdo;
    private int $now;
    private int $attachmentBytes;

    /** @var string[] */
    private array $userIds = [];
    /** @var string[] */
    private array $teamIds = [];
    /** @var array<string, array{name: string, contactIds: string[]}> */
    private array $accounts = [];
    /** @var array<string, array{name: string, addressId: string, address: string, accountId: string}> */
    private array $contacts = [];
    /** @var array<string, string> userId => emailAddressId */
    private array $userAddressIds = [];
    /** @var array<string, string> */
    private array $userAddresses = [];
    /** @var array<string, array{accountId: string, contactId: ?string, name: string}> */
    private array $cases = [];

    public function __construct(private EntityManager $entityManager) {}

    public function run(Params $params, IO $io): void
    {
        $scale = (float) ($params->getOption('scale') ?? 1);
        $this->attachmentBytes = (int) ($params->getOption('attachmentKb') ?? 16) * 1024;
        mt_srand((int) ($params->getOption('seed') ?? 42));

        $this->pdo = $this->entityManager->getPDO();
        $this->now = time();

        $started = microtime(true);

        $this->step($io, 'users & teams', fn () => $this->seedUsersAndTeams());
        $this->step($io, 'accounts & contacts', fn () => $this->seedAccountsAndContacts(
            (int) round(self::BASE_ACCOUNTS * $scale)
        ));
        $this->step($io, 'cases & case history', fn () => $this->seedCases((int) round(self::BASE_CASES * $scale)));
        $this->step($io, 'emails, stream notes & attachments', fn () => $this->seedEmails(
            (int) round(self::BASE_EMAILS * $scale),
            $io
        ));

        $io->writeLine(sprintf('Done in %.1fs.', microtime(true) - $started));
    }

    private function step(IO $io, string $label, callable $fn): void
    {
        $io->writeLine("Seeding $label ...");
        $t = microtime(true);
        $fn();
        $io->writeLine(sprintf('  %s done (%.1fs)', $label, microtime(true) - $t));
    }

    private function seedUsersAndTeams(): void
    {
        for ($i = 1; $i <= self::TEAMS; $i++) {
            $team = $this->entityManager->createEntity('Team', ['name' => "Team $i"]);
            $this->teamIds[] = $team->getId();
        }

        for ($i = 1; $i <= self::USERS; $i++) {
            $first = $this->pick(self::FIRST_NAMES);
            $last = $this->pick(self::LAST_NAMES);
            $address = strtolower("agent$i@crm.example");

            $user = $this->entityManager->createEntity('User', [
                'userName' => "agent$i",
                'firstName' => $first,
                'lastName' => $last,
                'type' => 'regular',
                'emailAddress' => $address,
                'teamsIds' => [$this->teamIds[$i % self::TEAMS]],
                'defaultTeamId' => $this->teamIds[$i % self::TEAMS],
            ]);

            $this->userIds[] = $user->getId();
            $this->userAddresses[$user->getId()] = $address;
        }

        $in = implode(',', array_map(fn ($id) => $this->pdo->quote($id), $this->userIds));

        $rows = $this->pdo->query(
            "SELECT entity_id, email_address_id FROM entity_email_address
             WHERE entity_type = 'User' AND deleted = 0 AND entity_id IN ($in)"
        )->fetchAll(PDO::FETCH_NUM);

        foreach ($rows as [$userId, $addressId]) {
            $this->userAddressIds[$userId] = $addressId;
        }
    }

    private function seedAccountsAndContacts(int $count): void
    {
        $accountRows = [];
        $contactRows = [];
        $addressRows = [];
        $entityAddressRows = [];
        $accountContactRows = [];
        $teamRows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = Util::generateId();
            $name = ucfirst($this->pick(self::WORDS)) . ' ' . ucfirst($this->pick(self::WORDS)) . ' ' .
                $this->pick(self::COMPANY_SUFFIXES) . " #$i";
            $created = $this->date($this->randomTime());

            $accountRows[] = [$id, $name, 0, $this->pick(['Customer', 'Partner', 'Reseller']), 0, $created,
                $created, $created, $this->pick($this->userIds)];
            $teamRows[] = [$id, $this->pick($this->teamIds), 'Account', 0];
            $this->accounts[$id] = ['name' => $name, 'contactIds' => []];

            $contactCount = max(1, (int) round(self::BASE_CONTACTS_PER_ACCOUNT * (0.5 + $this->rand())));

            for ($c = 0; $c < $contactCount; $c++) {
                $contactId = Util::generateId();
                $first = $this->pick(self::FIRST_NAMES);
                $last = $this->pick(self::LAST_NAMES);
                $address = strtolower("$first.$last.$contactId@customer.example");
                $addressId = Util::generateId();

                $contactRows[] = [$contactId, 0, $first, $last, 0, $created, $created, $created, $id,
                    $this->pick($this->userIds)];
                $addressRows[] = [$addressId, $address, 0, $address, 0, 0];
                $entityAddressRows[] = [$contactId, $addressId, 'Contact', 1, 0];
                $accountContactRows[] = [$id, $contactId, 0, 0];

                $this->accounts[$id]['contactIds'][] = $contactId;
                $this->contacts[$contactId] = [
                    'name' => "$first $last",
                    'addressId' => $addressId,
                    'address' => $address,
                    'accountId' => $id,
                ];
            }
        }

        $this->insert('account', ['id', 'name', 'deleted', 'type', 'is_locked', 'created_at', 'modified_at',
            'stream_updated_at', 'assigned_user_id'], $accountRows);
        $this->insert('contact', ['id', 'deleted', 'first_name', 'last_name', 'do_not_call', 'created_at',
            'modified_at', 'stream_updated_at', 'account_id', 'assigned_user_id'], $contactRows);
        $this->insert('email_address', ['id', 'name', 'deleted', 'lower', 'invalid', 'opt_out'], $addressRows);
        $this->insert('entity_email_address', ['entity_id', 'email_address_id', 'entity_type', 'primary',
            'deleted'], $entityAddressRows);
        $this->insert('account_contact', ['account_id', 'contact_id', 'is_inactive', 'deleted'],
            $accountContactRows);
        $this->insert('entity_team', ['entity_id', 'team_id', 'entity_type', 'deleted'], $teamRows);
    }

    private function seedCases(int $count): void
    {
        $accountIds = array_keys($this->accounts);
        $caseRows = [];
        $noteRows = [];
        $teamRows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = Util::generateId();
            $accountId = $this->pick($accountIds);
            $contactId = $this->pick($this->accounts[$accountId]['contactIds']);
            $status = $this->weighted(self::CASE_STATUSES);
            $created = $this->randomTime();
            $userId = $this->pick($this->userIds);
            $name = ucfirst($this->sentence(3, 7));

            $caseRows[] = [$id, $name, 0, $status, $this->pick(['Low', 'Normal', 'High', 'Urgent']),
                $this->pick(['Question', 'Incident', 'Problem']), $this->paragraphs(1, 3), 0,
                $this->date($created), $this->date($created), $this->date($created), $accountId, $contactId,
                $userId];
            $teamRows[] = [$id, $this->pick($this->teamIds), 'Case', 0];
            $this->cases[$id] = ['accountId' => $accountId, 'contactId' => $contactId, 'name' => $name];

            $noteRows[] = $this->noteRow('Create', 'Case', $id, $accountId, $created, $userId, null,
                ['assignedUserId' => $userId, 'statusValue' => 'New', 'statusField' => 'status']);

            $t = $created;
            $previous = 'New';

            foreach (['Assigned', 'Pending', $status] as $next) {
                if ($next === $previous || $this->rand() < 0.3) {
                    continue;
                }

                $t += mt_rand(3600, 86400 * 10);
                $noteRows[] = $this->noteRow('Update', 'Case', $id, $accountId, $t, $userId, null, [
                    'fields' => ['status'],
                    'attributes' => ['was' => ['status' => $previous], 'became' => ['status' => $next]],
                ]);
                $previous = $next;
            }

            for ($p = mt_rand(0, 3); $p > 0; $p--) {
                $t += mt_rand(600, 86400 * 3);
                $noteRows[] = $this->noteRow('Post', 'Case', $id, $accountId, $t, $this->pick($this->userIds),
                    $this->paragraphs(1, 2), null);
            }

            if (count($noteRows) >= 2000) {
                $this->flushCases($caseRows, $noteRows, $teamRows);
            }
        }

        $this->flushCases($caseRows, $noteRows, $teamRows);
    }

    /**
     * @param array<int, mixed[]> $caseRows
     * @param array<int, mixed[]> $noteRows
     * @param array<int, mixed[]> $teamRows
     */
    private function flushCases(array &$caseRows, array &$noteRows, array &$teamRows): void
    {
        $this->insert('case', ['id', 'name', 'deleted', 'status', 'priority', 'type', 'description',
            'is_internal', 'created_at', 'modified_at', 'stream_updated_at', 'account_id', 'contact_id',
            'assigned_user_id'], $caseRows);
        $this->insertNotes($noteRows);
        $this->insert('entity_team', ['entity_id', 'team_id', 'entity_type', 'deleted'], $teamRows);

        $caseRows = $noteRows = $teamRows = [];
    }

    private function seedEmails(int $count, IO $io): void
    {
        $caseIds = array_keys($this->cases);
        $accountIds = array_keys($this->accounts);

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0775, true);
        }

        $buffers = $this->emptyEmailBuffers();

        for ($i = 0; $i < $count; $i++) {
            $id = Util::generateId();

            if ($this->rand() < 0.55) {
                $parentType = 'Case';
                $parentId = $this->pick($caseIds);
                $accountId = $this->cases[$parentId]['accountId'];
                $contactId = $this->cases[$parentId]['contactId'] ?? $this->pick($this->accounts[$accountId]['contactIds']);
                $subject = 'Re: ' . $this->cases[$parentId]['name'];
            } else {
                $parentType = 'Account';
                $parentId = $this->pick($accountIds);
                $accountId = $parentId;
                $contactId = $this->pick($this->accounts[$accountId]['contactIds']);
                $subject = ucfirst($this->sentence(3, 8));
            }

            $contact = $this->contacts[$contactId];
            $userId = $this->pick($this->userIds);
            $incoming = $this->rand() < 0.6;
            $time = $this->randomTime();

            [$fromName, $fromAddress, $fromAddressId] = $incoming ?
                [$contact['name'], $contact['address'], $contact['addressId']] :
                ['Agent', $this->userAddresses[$userId], $this->userAddressIds[$userId] ?? null];
            $toAddressId = $incoming ? ($this->userAddressIds[$userId] ?? null) : $contact['addressId'];

            $plain = $this->emailBody();
            $html = '<p>' . str_replace("\n\n", '</p><p>', htmlspecialchars($plain)) . '</p>';

            $attachmentCount = $this->rand() < 0.15 ? mt_rand(1, 3) : 0;

            for ($a = 0; $a < $attachmentCount; $a++) {
                $attachmentId = Util::generateId();
                $size = (int) ($this->attachmentBytes * (0.25 + $this->rand() * 1.5));
                file_put_contents(self::UPLOAD_DIR . $attachmentId, random_bytes(max(1, $size)));

                $buffers['attachment'][] = [$attachmentId, $this->sentence(1, 3) . '.pdf', 0, 'application/pdf',
                    $size, 'attachments', 0, $this->date($time), $this->date($time), 'Attachment',
                    'EspoUploadDir', 0, $id, 'Email'];
            }

            $buffers['email'][] = [$id, $subject, 0, "$fromName <$fromAddress>", 0, "<$id@seed.example>",
                $plain, $html, 1, 'Archived', $attachmentCount > 0 ? 1 : 0, $this->date($time),
                $this->date($time), 0, $this->date($time), $this->date($time), 0, $fromAddressId, $parentId,
                $parentType, $userId, $accountId];

            $buffers['email_email_address'][] = [$id, $fromAddressId, 'from', 0];

            if ($toAddressId) {
                $buffers['email_email_address'][] = [$id, $toAddressId, 'to', 0];
            }

            $buffers['email_user'][] = [$id, $userId, 1, 0, 0, 0, 0];
            $buffers['entity_team'][] = [$id, $this->pick($this->teamIds), 'Email', 0];

            $data = [
                'emailId' => $id,
                'emailName' => $subject,
                'isInitial' => false,
                'hasAttachment' => $attachmentCount > 0,
                'personEntityType' => $incoming ? 'Contact' : 'User',
                'personEntityId' => $incoming ? $contactId : $userId,
                'personEntityName' => $fromName,
            ];

            $buffers['note'][] = $this->noteRow($incoming ? 'EmailReceived' : 'EmailSent', $parentType,
                $parentId, $parentType === 'Case' ? $accountId : null, $time, null,
                $parentType === 'Case' ? mb_substr($plain, 0, 1000) : null, $data, ['Email', $id]);

            if (count($buffers['email']) >= self::BATCH) {
                $this->flushEmails($buffers);

                if (($i + 1) % 10000 === 0) {
                    $io->writeLine(sprintf('  %d / %d emails', $i + 1, $count));
                }
            }
        }

        $this->flushEmails($buffers);
    }

    /**
     * @return array<string, array<int, mixed[]>>
     */
    private function emptyEmailBuffers(): array
    {
        return [
            'email' => [],
            'email_email_address' => [],
            'email_user' => [],
            'entity_team' => [],
            'note' => [],
            'attachment' => [],
        ];
    }

    /**
     * @param array<string, array<int, mixed[]>> $buffers
     */
    private function flushEmails(array &$buffers): void
    {
        $this->insert('email', ['id', 'name', 'deleted', 'from_string', 'is_replied', 'message_id',
            'body_plain', 'body', 'is_html', 'status', 'has_attachment', 'date_sent', 'delivery_date',
            'is_auto_reply', 'created_at', 'modified_at', 'is_system', 'from_email_address_id', 'parent_id',
            'parent_type', 'assigned_user_id', 'account_id'], $buffers['email']);
        $this->insert('email_email_address', ['email_id', 'email_address_id', 'address_type', 'deleted'],
            $buffers['email_email_address']);
        $this->insert('email_user', ['email_id', 'user_id', 'is_read', 'is_important', 'in_trash',
            'in_archive', 'deleted'], $buffers['email_user']);
        $this->insert('entity_team', ['entity_id', 'team_id', 'entity_type', 'deleted'], $buffers['entity_team']);
        $this->insertNotes($buffers['note']);
        $this->insert('attachment', ['id', 'name', 'deleted', 'type', 'size', 'field', 'is_being_uploaded',
            'created_at', 'modified_at', 'role', 'storage', 'global', 'parent_id', 'parent_type'],
            $buffers['attachment']);

        $buffers = $this->emptyEmailBuffers();
    }

    /**
     * @param ?array<string, mixed> $data
     * @param ?array{string, string} $related
     * @return mixed[]
     */
    private function noteRow(
        string $type,
        string $parentType,
        string $parentId,
        ?string $superParentAccountId,
        int $time,
        ?string $createdById,
        ?string $post,
        ?array $data,
        ?array $related = null
    ): array {
        return [
            Util::generateId(), 0, $post, $data === null ? null : json_encode($data), $type, 0, 0, 0,
            $this->date($time), $this->date($time), $parentId, $parentType, $related[1] ?? null,
            $related[0] ?? null, $createdById, $superParentAccountId,
            $superParentAccountId ? 'Account' : null,
        ];
    }

    /**
     * @param array<int, mixed[]> $rows
     */
    private function insertNotes(array $rows): void
    {
        $this->insert('note', ['id', 'deleted', 'post', 'data', 'type', 'is_global', 'is_internal', 'is_pinned',
            'created_at', 'modified_at', 'parent_id', 'parent_type', 'related_id', 'related_type',
            'created_by_id', 'super_parent_id', 'super_parent_type'], $rows);
    }

    /**
     * @param string[] $columns
     * @param array<int, mixed[]> $rows
     */
    private function insert(string $table, array $columns, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columnSql = implode(', ', array_map(fn ($c) => "`$c`", $columns));

        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = array_map(
                fn (array $row) => '(' . implode(', ', array_map(fn ($v) => $this->quote($v), $row)) . ')',
                $chunk
            );

            $this->pdo->exec("INSERT INTO `$table` ($columnSql) VALUES " . implode(', ', $values));
        }
    }

    private function quote(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value) => (string) $value,
            default => $this->pdo->quote((string) $value),
        };
    }

    /**
     * Emails get a fresh part plus a quoted thread, which is what bloats real mailboxes.
     */
    private function emailBody(): string
    {
        $body = "Hello,\n\n" . $this->paragraphs(1, 4) . "\n\nBest regards";

        for ($q = mt_rand(0, 4); $q > 0; $q--) {
            $body .= "\n\n> On a previous day someone wrote:\n> " .
                str_replace("\n\n", "\n> ", $this->paragraphs(1, 3));
        }

        return $body;
    }

    private function paragraphs(int $min, int $max): string
    {
        $out = [];

        for ($p = mt_rand($min, $max); $p > 0; $p--) {
            $sentences = [];

            for ($s = mt_rand(3, 8); $s > 0; $s--) {
                $sentences[] = ucfirst($this->sentence(6, 18)) . '.';
            }

            $out[] = implode(' ', $sentences);
        }

        return implode("\n\n", $out);
    }

    private function sentence(int $min, int $max): string
    {
        $words = [];

        for ($w = mt_rand($min, $max); $w > 0; $w--) {
            $words[] = $this->pick(self::WORDS);
        }

        return implode(' ', $words);
    }

    /**
     * Skewed towards recent dates, like real CRM data.
     */
    private function randomTime(): int
    {
        return $this->now - (int) (self::YEARS * 365 * 86400 * ($this->rand() ** 1.6));
    }

    private function date(int $time): string
    {
        return gmdate('Y-m-d H:i:s', min($time, $this->now));
    }

    private function rand(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /**
     * @template T
     * @param T[] $list
     * @return T
     */
    private function pick(array $list): mixed
    {
        return $list[array_rand($list)];
    }

    /**
     * @param array<string, int> $weights
     */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_first($weights);
    }
}
