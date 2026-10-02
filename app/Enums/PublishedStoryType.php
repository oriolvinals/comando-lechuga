<?php

declare(strict_types=1);

namespace App\Enums;

/** The kind of Instagram story a PublishedStory row records. */
enum PublishedStoryType: string
{
    case MarketSignings = 'market_signings';
}
