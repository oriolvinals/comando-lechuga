<?php

use App\Enums\PlayerPosition;
use App\Services\FutbolFantasyAlternative;
use App\Services\FutbolFantasyPageException;
use App\Services\FutbolFantasyPlayer;
use App\Services\FutbolFantasyTeamPage;
use App\Services\FutbolFantasyTeamPageParser;

function parsedFutbolFantasyPage(string $name): FutbolFantasyTeamPage
{
    return (new FutbolFantasyTeamPageParser)->parse((string) file_get_contents(__DIR__."/../../Fixtures/futbolfantasy/{$name}.html"));
}

/**
 * @return array<int, FutbolFantasyPlayer>
 */
function parsedPlayersById(FutbolFantasyTeamPage $page): array
{
    $byId = [];

    foreach ($page->players as $player) {
        $byId[$player->futbolfantasyId] = $player;
    }

    return $byId;
}

/**
 * The smallest page the parser accepts, wrapping the given lineup blocks.
 */
function lineupSectionHtml(string $heading, string $blocks): string
{
    return '<!DOCTYPE html><html><body><section class="mod alineacion_wrapper block-new-only-header"><header><h2>'.$heading.'</h2></header>'.$blocks.'</section></body></html>';
}

test('reads the jornada of a probable lineup', function (): void {
    $page = parsedFutbolFantasyPage('real-madrid-posible');

    expect($page->weekNumber)->toBe(8)
        ->and($page->confirmed)->toBeFalse()
        ->and($page->rivalCode())->toBe('VIL');
});

test('keeps each player once although the page prints him twice, and ignores the "once tipo" section', function (): void {
    $page = parsedFutbolFantasyPage('real-madrid-posible');

    expect(array_map(fn (FutbolFantasyPlayer $player): int => $player->futbolfantasyId, $page->players))
        ->toBe([59, 6055, 5565, 13564, 17000]);
});

test('reads every field of a player block', function (): void {
    $courtois = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[59];

    expect($courtois->name)->toBe('Courtois')
        ->and($courtois->slug)->toBe('thibaut-courtois')
        ->and($courtois->probability)->toBe(95)
        ->and($courtois->confirmedStarter)->toBeNull()
        ->and($courtois->predictedStarter)->toBeTrue()
        ->and($courtois->rivalCode)->toBe('VIL')
        ->and($courtois->marketValue)->toBe(56825743)
        ->and($courtois->totalPoints)->toBe(41)
        ->and($courtois->position)->toBe(PlayerPosition::Goalkeeper);
});

test('takes the name and slug of the slot\'s own player when the shirt links nowhere', function (): void {
    $vinicius = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[5565];

    expect($vinicius->name)->toBe('Vinicius')
        ->and($vinicius->slug)->toBe('vinicius-junior')
        ->and($vinicius->position)->toBe(PlayerPosition::Striker);
});

test('reads the player FútbolFantasy lists under a starter as the one who could start instead', function (): void {
    $players = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'));

    expect($players[5565]->alternatives)->toEqual([new FutbolFantasyAlternative(1, 'Y. Diomande', 'yan-diomande')])
        ->and($players[59]->alternatives)->toBe([])
        ->and($players[13564]->alternatives)->toBe([]);
});

test('reads every alternative of a starter in FútbolFantasy\'s order, whatever his %, skipping the player himself and a change of system', function (): void {
    $html = lineupSectionHtml(
        '<span class="posible">Posible alineación</span><span class="jornada">9</span>',
        '<div class="jugador_7 camiseta-wrapper" style="left: 50%; top: 20%" data-onceFF="titular">'
            .'<a class="camiseta" data-probabilidad="95%" data-rival="BAR" href="https://www.futbolfantasy.com/jugadores/kylian-mbappe/laliga-26-27"></a>'
            .'<div class="juggadores">'
            .'<a class="juggador pos-0" href="https://www.futbolfantasy.com/jugadores/kylian-mbappe/laliga-26-27"><span class="truncate-name">Mbappé</span></a>'
            .'<a class="juggador pos-2" href="https://www.futbolfantasy.com/jugadores/gonzalo-garcia/laliga-26-27"><span class="truncate-name">Gonzalo</span></a>'
            .'<a class="juggador pos-1" href="https://www.futbolfantasy.com/jugadores/endrick/laliga-26-27"><span class="truncate-name">Endrick</span></a>'
            .'<a class="juggador pos-3" href="https://www.futbolfantasy.com/jugadores/kylian-mbappe/laliga-26-27"><span class="truncate-name">Mbappé</span></a>'
            .'<a class="juggador pos-4" href="#"><span class="truncate-name">(4-2-3-1)</span></a>'
            .'</div></div>',
    );

    $mbappe = parsedPlayersById((new FutbolFantasyTeamPageParser)->parse($html))[7];

    expect($mbappe->alternatives)->toEqual([
        new FutbolFantasyAlternative(1, 'Endrick', 'endrick'),
        new FutbolFantasyAlternative(2, 'Gonzalo', 'gonzalo-garcia'),
    ]);
});

test('reads no alternatives on a confirmed lineup', function (): void {
    $players = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-confirmada'));

    expect(array_merge(...array_map(fn (FutbolFantasyPlayer $player): array => $player->alternatives, array_values($players))))->toBe([]);
});

test('reads a bench player out of FútbolFantasy\'s probable XI', function (): void {
    $endrick = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[13564];

    expect($endrick->probability)->toBe(10)
        ->and($endrick->predictedStarter)->toBeFalse();
});

test('reads where FútbolFantasy draws each probable starter on its pitch', function (): void {
    $players = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'));

    expect([$players[59]->pitchX, $players[59]->pitchY])->toBe([50, 88])
        ->and([$players[6055]->pitchX, $players[6055]->pitchY])->toBe([89, 66])
        ->and([$players[5565]->pitchX, $players[5565]->pitchY])->toBe([20, 18]);
});

test('leaves the pitch spot empty for a bench shirt drawn in px or a shirt without style', function (): void {
    $players = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'));

    expect([$players[13564]->pitchX, $players[13564]->pitchY])->toBe([null, null])
        ->and([$players[17000]->pitchX, $players[17000]->pitchY])->toBe([null, null]);
});

test('leaves the probability empty when FútbolFantasy gives none', function (): void {
    $mastantuono = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[17000];

    expect($mastantuono->probability)->toBeNull()
        ->and($mastantuono->confirmedStarter)->toBeNull()
        ->and($mastantuono->name)->toBe('Mastantuono');
});

test('reads a confirmed lineup as Titular / Suplente with no probability', function (): void {
    $page = parsedFutbolFantasyPage('real-madrid-confirmada');
    $players = parsedPlayersById($page);

    expect($page->weekNumber)->toBe(8)
        ->and($page->confirmed)->toBeTrue()
        ->and($players[59]->confirmedStarter)->toBeTrue()
        ->and($players[59]->probability)->toBeNull()
        ->and($players[5565]->confirmedStarter)->toBeFalse()
        ->and($players[5565]->predictedStarter)->toBeTrue()
        ->and($players[13564]->confirmedStarter)->toBeTrue()
        ->and($players[13564]->predictedStarter)->toBeFalse();
});

test('reads Titular / Suplente whatever their case and spacing', function (): void {
    $html = lineupSectionHtml(
        '<span class="posible">Posible alineación</span><span class="jornada">9</span>',
        '<div class="jugador_1 tipo_lista" data-probabilidad=" titular " data-rival="BAR"></div>'
            .'<div class="jugador_2 tipo_lista" data-probabilidad="SUPLENTE" data-rival="BAR"></div>',
    );

    $players = parsedPlayersById((new FutbolFantasyTeamPageParser)->parse($html));

    expect($players[1]->confirmedStarter)->toBeTrue()
        ->and($players[2]->confirmedStarter)->toBeFalse();
});

test('rejects a page without a jornada in its lineup heading', function (): void {
    (new FutbolFantasyTeamPageParser)->parse(lineupSectionHtml(
        '<span class="posible">Posible alineación</span>',
        '<div class="jugador_1 tipo_lista" data-probabilidad="80%"></div>',
    ));
})->throws(FutbolFantasyPageException::class, 'jornada');

test('rejects a page without a lineup section', function (): void {
    (new FutbolFantasyTeamPageParser)->parse('<!DOCTYPE html><html><body><p>Mantenimiento</p></body></html>');
})->throws(FutbolFantasyPageException::class, 'lineup');

test('rejects a lineup without players', function (): void {
    (new FutbolFantasyTeamPageParser)->parse(lineupSectionHtml(
        '<span class="posible">Posible alineación</span><span class="jornada">8</span>',
        '',
    ));
})->throws(FutbolFantasyPageException::class, 'no players');
