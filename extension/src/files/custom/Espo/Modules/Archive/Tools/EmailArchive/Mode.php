<?php

namespace Espo\Modules\Archive\Tools\EmailArchive;

enum Mode: string
{
    /** Only report what would be archived. */
    case DryRun = 'dryRun';
    /** Create archive records, keep the originals. */
    case CopyOnly = 'copyOnly';
    /** Create archive records, verify them, then delete the originals. */
    case Move = 'move';
}
