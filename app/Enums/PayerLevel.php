<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a manager's cash range reaches a clause: always, only optimistically, or not at all. */
enum PayerLevel: string
{
    case Sure = 'sure';
    case Maybe = 'maybe';
    case No = 'no';
}
