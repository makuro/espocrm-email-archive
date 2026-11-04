<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

use Espo\Core\Utils\Config;

readonly class Params
{
    public function __construct(
        public Mode $mode,
        public int $olderThanDays,
        public bool $skipActiveParent,
        public int $batchSize,
        public int $maxPerRun,
        public int $retentionDays,
    ) {}

    public static function fromConfig(Config $config): self
    {
        return new self(
            mode: Mode::tryFrom((string) $config->get('archiveEmailMode')) ?? Mode::DryRun,
            olderThanDays: max(30, (int) ($config->get('archiveEmailOlderThanDays') ?? 730)),
            skipActiveParent: (bool) ($config->get('archiveEmailSkipActiveParent') ?? true),
            batchSize: min(1000, max(1, (int) ($config->get('archiveEmailBatchSize') ?? 100))),
            maxPerRun: max(1, (int) ($config->get('archiveEmailMaxPerRun') ?? 5000)),
            retentionDays: max(0, (int) ($config->get('archiveEmailRetentionDays') ?? 0)),
        );
    }

    public function with(
        ?Mode $mode = null,
        ?int $olderThanDays = null,
        ?bool $skipActiveParent = null,
        ?int $batchSize = null,
        ?int $maxPerRun = null,
    ): self {
        return new self(
            mode: $mode ?? $this->mode,
            olderThanDays: $olderThanDays ?? $this->olderThanDays,
            skipActiveParent: $skipActiveParent ?? $this->skipActiveParent,
            batchSize: $batchSize ?? $this->batchSize,
            maxPerRun: $maxPerRun ?? $this->maxPerRun,
            retentionDays: $this->retentionDays,
        );
    }
}
