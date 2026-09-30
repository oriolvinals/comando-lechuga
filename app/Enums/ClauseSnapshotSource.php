<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a clause history row comes from: the minute sync, or the user by hand (authoritative). */
enum ClauseSnapshotSource: string
{
    case Sync = 'sync';
    case Manual = 'manual';
}
