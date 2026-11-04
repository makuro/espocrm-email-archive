<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

class Report
{
    public int $candidates = 0;
    public int $archived = 0;
    public int $copied = 0;
    public int $failed = 0;
    public int $attachments = 0;
    public int $attachmentBytes = 0;
    public int $purged = 0;
    /** @var array<string, int> */
    public array $skipped = [];

    public function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    public function toString(Mode $mode): string
    {
        $parts = ["mode: {$mode->value}", "candidates: $this->candidates"];

        if ($mode !== Mode::DryRun) {
            $parts[] = "copied: $this->copied";
            $parts[] = "moved: $this->archived";
            $parts[] = "failed: $this->failed";
        }

        $parts[] = "attachments: $this->attachments (" . round($this->attachmentBytes / 1048576, 1) . ' MB)';

        foreach ($this->skipped as $reason => $count) {
            $parts[] = "skipped ($reason): $count";
        }

        if ($this->purged) {
            $parts[] = "purged by retention: $this->purged";
        }

        return implode(', ', $parts);
    }
}
