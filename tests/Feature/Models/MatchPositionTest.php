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
    ['Center Right Defender', MatchPositionSide::CenterRight],
    ['Left Back', MatchPositionSide::Left],
    ['Center Left Forward', MatchPositionSide::CenterLeft],
    ['Right Midfielder', MatchPositionSide::Right],
    ['Center Midfielder', MatchPositionSide::Center],
    ['Goalkeeper', MatchPositionSide::Center],
]);

test('orders sides from the player left flank to his right flank', function (): void {
    $order = array_map(
        fn (string $text): int => MatchPositionSide::fromWorldcup26Text($text)->leftToRight(),
        ['Left Back', 'Center Left Defender', 'Center Midfielder', 'Center Right Forward', 'Right Back'],
    );

    expect($order)->toBe([0, 1, 2, 3, 4]);
});
