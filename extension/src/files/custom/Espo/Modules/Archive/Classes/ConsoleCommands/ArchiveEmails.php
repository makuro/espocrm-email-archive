<?php

namespace Espo\Modules\Archive\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params as CommandParams;
use Espo\Core\Console\IO;
use Espo\Core\Utils\Config;
use Espo\Modules\Archive\Tools\EmailArchive\Mode;
use Espo\Modules\Archive\Tools\EmailArchive\Params;
use Espo\Modules\Archive\Tools\EmailArchive\Report;
use Espo\Modules\Archive\Tools\EmailArchive\Runner;
use Espo\Modules\Archive\Tools\EmailArchive\Setup;

/**
 * bin/command archive-emails [--mode=dryRun|copyOnly|move] [--older-than-days=N] [--limit=N]
 *     [--batch-size=N] [--include-active-parent] [--purge] [--setup]
 *
 * Without --mode the command only reports (dry run), regardless of the configured mode.
 * --purge only runs the retention purge. --setup writes default settings and adds the
 * "Archived Emails" panels (done automatically when the extension is installed).
 */
class ArchiveEmails implements Command
{
    public function __construct(
        private Runner $runner,
        private Config $config,
        private Setup $setup
    ) {}

    public function run(CommandParams $params, IO $io): void
    {
        if ($params->hasFlag('setup')) {
            $changes = $this->setup->run();
            $io->writeLine('Setup: ' . ($changes ? implode(', ', $changes) : 'nothing to do'));

            return;
        }

        $modeOption = $params->getOption('mode');
        $mode = $modeOption === null ? Mode::DryRun : Mode::tryFrom($modeOption);

        if (!$mode) {
            $io->writeLine('Unknown mode. Use dryRun, copyOnly or move.');
            $io->setExitStatus(1);

            return;
        }

        $archiveParams = Params::fromConfig($this->config)->with(
            mode: $mode,
            olderThanDays: $this->intOption($params, 'olderThanDays'),
            skipActiveParent: $params->hasFlag('includeActiveParent') ? false : null,
            batchSize: $this->intOption($params, 'batchSize'),
            maxPerRun: $this->intOption($params, 'limit'),
        );

        if ($params->hasFlag('purge')) {
            $report = $this->runner->purgeRetention($archiveParams);
            $io->writeLine("Purged archived emails: $report->purged");

            return;
        }

        $started = microtime(true);

        $report = $this->runner->run(
            $archiveParams,
            fn (Report $report) => $io->writeLine("  processed $report->candidates")
        );

        $io->writeLine($report->toString($mode));
        $io->writeLine(sprintf('Took %.1fs.', microtime(true) - $started));

        if ($report->failed) {
            $io->setExitStatus(1);
        }
    }

    private function intOption(CommandParams $params, string $name): ?int
    {
        $value = $params->getOption($name);

        return $value === null ? null : max(1, (int) $value);
    }
}
