<?php

use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;
use App\Services\PredictedFormation;

/**
 * Probable XIs as FútbolFantasy drew them on cached team pages (J6–J8,
 * 2026-27), name => [left %, top %].
 *
 * @return array<string, array<string, array{x: int, y: int}>>
 */
function cachedFutbolFantasyXis(): array
{
    $xis = [
        'betis' => [
            'Valles' => [50, 87], 'Bellerín' => [89, 66], 'D. Llorente' => [68, 70], 'Natan' => [32, 70], 'Fran García' => [11, 66],
            'Bernal' => [68, 48], 'Fornals' => [32, 48], 'Isco' => [50, 34],
            'Antony' => [89, 27], 'Cucho' => [50, 18], 'Abde' => [11, 27],
        ],
        'elche' => [
            'Dituro' => [50, 87], 'Rubén Sánchez' => [89, 66], 'Chust' => [68, 70], 'Bigas' => [32, 70], 'Revivo' => [11, 66],
            'Villar' => [68, 48], 'Morcillo' => [32, 48], 'Lemar' => [50, 34],
            'Cepeda' => [89, 27], 'Fer Niño' => [50, 18], 'Valera' => [11, 27],
        ],
        'real-madrid' => [
            'Courtois' => [50, 87], 'Dumfries' => [89, 66], 'Konaté' => [68, 70], 'Rüdiger' => [32, 70], 'Cucurella' => [11, 66],
            'Bernardo Silva' => [68, 48], 'Tchouaméni' => [32, 48], 'Bellingham' => [50, 34],
            'Güler' => [89, 27], 'Mbappé' => [50, 18], 'Vinicius' => [11, 27],
        ],
        'racing' => [
            'Agirrezabala' => [50, 87], 'Mantilla' => [89, 66], 'Pablo Ramón' => [68, 70], 'Belocian' => [32, 70], 'Salinas' => [11, 66],
            'Iván Martín' => [68, 48], 'Prati' => [32, 48], 'Canales' => [50, 34],
            'Pablo García' => [89, 27], 'Zabiri' => [50, 18], 'Iñigo Vicente' => [11, 27],
        ],
        'levante' => [
            'Ryan' => [50, 87], 'Toljan' => [89, 66], 'De la Fuente' => [68, 70], 'Mandi' => [32, 70], 'Manu Sánchez' => [11, 66],
            'Oriol Rey' => [50, 56], 'Olasagasti' => [74, 42], 'Bardeli' => [26, 42],
            'Brugui' => [89, 27], 'Iván Romero' => [50, 18], 'Thiago' => [11, 27],
        ],
        'atletico' => [
            'Oblak' => [50, 87], 'Pubill' => [76, 69], 'Romero' => [50, 72], 'Hancko' => [23, 69],
            'Giuliano' => [89, 55], 'Grimaldo' => [11, 55],
            'M. Llorente' => [68, 48], 'Cardoso' => [32, 48],
            'Kang-In Lee' => [73, 29], 'Baena' => [27, 29], 'Jonathan David' => [50, 18],
        ],
    ];

    return array_map(
        fn (array $xi): array => array_map(fn (array $spot): array => ['x' => $spot[0], 'y' => $spot[1]], $xi),
        $xis,
    );
}

test('reads a 4-3-3 off a back four, a midfield trio and a front three', function (string $team): void {
    expect((new PredictedFormation)->derive(cachedFutbolFantasyXis()[$team])['formation'])->toBe('4-3-3');
})->with(['betis', 'elche', 'real-madrid', 'racing', 'levante']);

test('names a back four, a midfield trio and a front three like worldcup26 does', function (): void {
    $positions = (new PredictedFormation)->derive(cachedFutbolFantasyXis()['betis'])['positions'];

    expect($positions)->toBe([
        'Valles' => 'Goalkeeper',
        'Fran García' => 'Left Back',
        'Natan' => 'Center Left Defender',
        'D. Llorente' => 'Center Right Defender',
        'Bellerín' => 'Right Back',
        'Fornals' => 'Center Left Midfielder',
        'Bernal' => 'Center Right Midfielder',
        'Isco' => 'Attacking Midfielder',
        'Abde' => 'Left Forward',
        'Cucho' => 'Forward',
        'Antony' => 'Right Forward',
    ]);
});

test('puts a deep central shirt on its own pivot line', function (): void {
    $positions = (new PredictedFormation)->derive(cachedFutbolFantasyXis()['levante'])['positions'];

    expect($positions['Oriol Rey'])->toBe('Defensive Midfielder')
        ->and($positions['Bardeli'])->toBe('Center Left Midfielder')
        ->and($positions['Olasagasti'])->toBe('Center Right Midfielder')
        ->and($positions['Thiago'])->toBe('Left Forward');
});

test('reads a back three and two wing-backs as a back five', function (): void {
    ['formation' => $formation, 'positions' => $positions] = (new PredictedFormation)->derive(cachedFutbolFantasyXis()['atletico']);

    expect($formation)->toBe('5-2-2-1')
        ->and($positions['Hancko'])->toBe('Center Left Defender')
        ->and($positions['Romero'])->toBe('Center Defender')
        ->and($positions['Pubill'])->toBe('Center Right Defender')
        ->and($positions['Grimaldo'])->toBe('Left Wing Back')
        ->and($positions['Cardoso'])->toBe('Center Left Midfielder')
        ->and($positions['Baena'])->toBe('Attacking Midfielder Left')
        ->and($positions['Kang-In Lee'])->toBe('Attacking Midfielder Right')
        ->and($positions['Jonathan David'])->toBe('Forward');
});

test('puts a LaLiga Fantasy forward drawn at wing-back on the back line, on the right', function (): void {
    $giuliano = (new PredictedFormation)->derive(cachedFutbolFantasyXis()['atletico'])['positions']['Giuliano'];

    expect($giuliano)->toBe('Right Wing Back')
        ->and(MatchPositionLine::fromWorldcup26Text($giuliano))->toBe(MatchPositionLine::Defender)
        ->and(MatchPositionSide::fromWorldcup26Text($giuliano))->toBe(MatchPositionSide::Right);
});

test('makes wide shirts beside a back four wide midfielders, not wing-backs', function (): void {
    $xi = cachedFutbolFantasyXis()['real-madrid'];
    $xi['Güler'] = ['x' => 89, 'y' => 55];
    $xi['Vinicius'] = ['x' => 11, 'y' => 55];
    $xi['Bellingham'] = ['x' => 40, 'y' => 18];
    $xi['Mbappé'] = ['x' => 60, 'y' => 18];

    ['formation' => $formation, 'positions' => $positions] = (new PredictedFormation)->derive($xi);

    expect($formation)->toBe('4-4-2')
        ->and($positions['Güler'])->toBe('Right Midfielder')
        ->and($positions['Vinicius'])->toBe('Left Midfielder')
        ->and($positions['Bellingham'])->toBe('Center Left Forward')
        ->and($positions['Mbappé'])->toBe('Center Right Forward');
});

test('ranks a line left to right when two shirts share a side band', function (): void {
    $positions = (new PredictedFormation)->derive([
        'a' => ['x' => 45, 'y' => 70],
        'b' => ['x' => 55, 'y' => 70],
    ])['positions'];

    expect($positions)->toBe(['a' => 'Center Left Defender', 'b' => 'Center Right Defender']);
});

test('gives positions but no formation for an XI that is not complete', function (): void {
    $xi = cachedFutbolFantasyXis()['betis'];
    unset($xi['Cucho']);

    ['formation' => $formation, 'positions' => $positions] = (new PredictedFormation)->derive($xi);

    expect($formation)->toBeNull()
        ->and($positions)->toHaveCount(10)
        ->and($positions['Isco'])->toBe('Attacking Midfielder');
});

test('gives nothing for no spots', function (): void {
    expect((new PredictedFormation)->derive([]))->toBe(['formation' => null, 'positions' => []]);
});
