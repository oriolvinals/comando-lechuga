<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a squad player's buyout clause can be paid right now. */
enum ClauseState: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Shielded = 'shielded';
    case Listed = 'listed';
}
