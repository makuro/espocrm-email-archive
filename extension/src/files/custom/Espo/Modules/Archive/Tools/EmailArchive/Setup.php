<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Metadata;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

/**
 * Runs after installation: writes missing settings with their defaults (so the settings
 * form shows and keeps the safe values) and adds the detail panels. Safe to run again.
 *
 * Before uninstalling, teardown() deactivates the scheduled jobs and removes the panels.
 * Settings and archived data are kept. What teardown changed is written to a state file and
 * undone by the next run(): EspoCRM before 10.1 does not tell the uninstall script that it is
 * part of an upgrade, so an upgrade would otherwise leave the jobs off and move the panels.
 */
class Setup
{
    private const STATE_FILE = 'data/archive-emails-teardown.json';
    private const JOB = 'ArchiveEmails';
    private const STATUS_INACTIVE = 'Inactive';

    private const SETTINGS = [
        'archiveEmailMode',
        'archiveEmailOlderThanDays',
        'archiveEmailSkipActiveParent',
        'archiveEmailBatchSize',
        'archiveEmailMaxPerRun',
        'archiveEmailRetentionDays',
    ];

    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private Metadata $metadata,
        private LayoutInstaller $layoutInstaller,
        private EntityManager $entityManager
    ) {}

    /**
     * @return string[] What was changed.
     */
    public function run(): array
    {
        $changes = [];

        foreach (self::SETTINGS as $name) {
            if ($this->config->has($name)) {
                continue;
            }

            $this->configWriter->set($name, $this->metadata->get(['entityDefs', 'Settings', 'fields', $name, 'default']));
            $changes[] = "setting $name";
        }

        $changes = [...$changes, ...$this->restoreTeardown()];

        foreach ($this->layoutInstaller->install() as $scope) {
            $changes[] = "layout $scope";
        }

        if ($changes !== []) {
            // Browsers cache settings and layouts until the timestamp changes.
            $this->configWriter->updateCacheTimestamp();
        }

        $this->configWriter->save();

        return $changes;
    }

    /**
     * @return string[] What was changed.
     */
    public function teardown(): array
    {
        $jobIds = [];

        $jobs = $this->entityManager
            ->getRDBRepositoryByClass(ScheduledJob::class)
            ->where(['job' => self::JOB, 'status' => ScheduledJob::STATUS_ACTIVE])
            ->find();

        foreach ($jobs as $job) {
            // The job class is removed with the extension; an active job would fail on every run.
            $job->set('status', self::STATUS_INACTIVE);
            $this->entityManager->saveEntity($job);
            $jobIds[] = $job->getId();
        }

        $layouts = $this->layoutInstaller->uninstall();

        file_put_contents(self::STATE_FILE, json_encode(['jobIds' => $jobIds, 'layouts' => $layouts]));

        $this->configWriter->updateCacheTimestamp();
        $this->configWriter->save();

        return [
            ...array_map(fn ($id) => "deactivated job $id", $jobIds),
            ...array_map(fn ($change) => "layout {$change['scope']}", $layouts),
        ];
    }

    /**
     * @return string[]
     */
    private function restoreTeardown(): array
    {
        if (!is_file(self::STATE_FILE)) {
            return [];
        }

        $state = json_decode((string) file_get_contents(self::STATE_FILE), true) ?: [];
        $changes = [];

        foreach ($state['jobIds'] ?? [] as $id) {
            $job = $this->entityManager->getRDBRepositoryByClass(ScheduledJob::class)->getById($id);

            if ($job && $job->getJob() === self::JOB && $job->get('status') === self::STATUS_INACTIVE) {
                $job->setActive();
                $this->entityManager->saveEntity($job);
                $changes[] = "reactivated job $id";
            }
        }

        foreach ($this->layoutInstaller->restore($state['layouts'] ?? []) as $scope) {
            $changes[] = "restored layout $scope";
        }

        unlink(self::STATE_FILE);

        return $changes;
    }
}
