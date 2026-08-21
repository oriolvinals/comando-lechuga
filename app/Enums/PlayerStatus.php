<?php

namespace App\Enums;

enum PlayerStatus: string
{
    case Ok = 'ok';
    case Injured = 'injured';
    case OutOfLeague = 'out_of_league';
    case Suspended = 'suspended';
    case Doubtful = 'doubtful';
}
