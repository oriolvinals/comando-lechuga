<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which rule linked a FútbolFantasy player to ours — in the order they're tried.
 */
enum FutbolFantasyLinkRule: string
{
    case StoredId = 'stored_id';
    case MarketValue = 'market_value';
    case Name = 'name';
    case ManualMap = 'manual_map';
}
