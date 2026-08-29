<?php

use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;

test('classifies worldcup26 position text into a pitch line', function (string $text, MatchPositionLine $line): void {
    expect(MatchPositionLine::fromWorldcup26Text($text))->toBe($line);
})->with([
    ['Goalkeeper', MatchPositionLine::Goalkeeper],
    ['Center Right Defender', MatchPositionLine::Defender],
    ['Left Back', MatchPositionLine::Defender],
    ['Right Back', MatchPositionLine::Defender],
    ['Center Midfielder', MatchPositionLine::Midfielder],
    ['Right Midfielder', MatchPositionLine::Midfielder],
    ['Center Left Forward', MatchPositionLine::Forward],
    ['Substitute', MatchPositionLine::Substitute],
    ['Something Unseen', MatchPositionLine::Unknown],
]);

test('classifies worldcup26 position text into a pitch side', function (string $text, MatchPositionSide $side): void {
    expect(MatchPositionSide::fromWorldcup26Text($text))->toBe($side);
})->with([
    ['Center Right Defender', MatchPositionSide::Right],
    ['Left Back', MatchPositionSide::Left],
    ['Center Left Forward', MatchPositionSide::Left],
    ['Center Midfielder', MatchPositionSide::Center],
    ['Goalkeeper', MatchPositionSide::Center],
]);
