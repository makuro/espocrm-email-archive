<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Metadata;

/**
 * Runs after installation: writes missing settings with their defaults (so the settings
 * form shows and keeps the safe values) and adds the detail panels. Safe to run again.
 */
class Setup
{
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
        private LayoutInstaller $layoutInstaller
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
}
