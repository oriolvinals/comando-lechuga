<?php

declare(strict_types=1);

namespace App\Enums;

enum MaxBidStatus: string
{
    case Profitable = 'profitable';
    case Unprofitable = 'unprofitable';
    case Unavailable = 'unavailable';
    case NoData = 'no_data';
}
