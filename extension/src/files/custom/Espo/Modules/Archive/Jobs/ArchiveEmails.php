<?php

namespace Espo\Modules\Archive\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\Archive\Tools\EmailArchive\Params;
use Espo\Modules\Archive\Tools\EmailArchive\Runner;

class ArchiveEmails implements JobDataLess
{
    public function __construct(
        private Runner $runner,
        private Config $config,
        private Log $log
    ) {}

    public function run(): void
    {
        $params = Params::fromConfig($this->config);

        $report = $this->runner->run($params);

        $this->log->info('Email archive: ' . $report->toString($params->mode));
    }
}
