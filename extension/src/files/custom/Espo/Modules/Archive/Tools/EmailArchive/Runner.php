<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use RuntimeException;

/**
 * Runs one archiving pass. Only one pass runs at a time; a second one fails fast instead of
 * waiting, so the scheduled job and the console command cannot overlap.
 */
class Runner
{
    private const LOCK_FILE = 'data/archive-emails.lock';

    public function __construct(
        private CandidateFinder $candidateFinder,
        private EmailArchiver $archiver,
        private RetentionPurger $retentionPurger
    ) {}

    /**
     * @param ?callable(Report): void $onBatch
     */
    public function run(Params $params, ?callable $onBatch = null): Report
    {
        $lock = fopen(self::LOCK_FILE, 'c');

        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another email archive run is in progress.');
        }

        try {
            return $this->runLocked($params, $onBatch);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function purgeRetention(Params $params): Report
    {
        $report = new Report();

        $this->retentionPurger->purge($params, $report);

        return $report;
    }

    /**
     * @param ?callable(Report): void $onBatch
     */
    private function runLocked(Params $params, ?callable $onBatch): Report
    {
        $report = new Report();

        if ($params->mode === Mode::DryRun) {
            $report->candidates = $this->candidateFinder->count($params);
            [$report->attachments, $report->attachmentBytes] = $this->candidateFinder->attachmentStats($params);

            return $report;
        }

        $afterId = null;

        while ($report->candidates < $params->maxPerRun) {
            $ids = $this->candidateFinder->find(
                $params,
                min($params->batchSize, $params->maxPerRun - $report->candidates),
                $afterId
            );

            if ($ids === []) {
                break;
            }

            foreach ($ids as $id) {
                $this->archiver->process($id, $params->mode, $report);
                $report->candidates++;
            }

            $afterId = end($ids);

            if ($onBatch) {
                $onBatch($report);
            }
        }

        $this->retentionPurger->purge($params, $report);

        return $report;
    }
}
