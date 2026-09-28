<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerPosition;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Reads the lineup block of a FútbolFantasy team page
 * (`/laliga/equipos/{slug}`, server-rendered, ~2.4 MB) with PHP's HTML5
 * parser. Pure: HTML in, FutbolFantasyTeamPage out.
 *
 * - The lineup is the first `section.alineacion_wrapper` that isn't
 *   `.observaciones` ("Once tipo y mapa rotacional").
 * - The jornada is its `span.jornada`; the state is its `span.posible`
 *   ("Posible alineación" / "Alineación confirmada") — the hidden
 *   `span.pasada` always reads "Alineación confirmada", so it's ignored.
 * - Every element with `data-probabilidad` is a player block; its wrapper
 *   is the closest `jugador_{id}` element. FF prints each player twice
 *   (pitch + list), merged by id.
 * - A probable-XI shirt's pitch wrapper also carries its spot on FF's
 *   pitch (`style="left: X%; top: Y%"`) — see pitchCoordinates().
 */
class FutbolFantasyTeamPageParser
{
    /**
     * @throws FutbolFantasyPageException
     */
    public function parse(string $html): FutbolFantasyTeamPage
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $section = $this->lineupSection($document);

        if ($section === null) {
            throw new FutbolFantasyPageException('no lineup section on the page');
        }

        $weekNumber = $this->weekNumber($section);

        if ($weekNumber === null) {
            throw new FutbolFantasyPageException('no "J{n}" jornada in the lineup heading');
        }

        $players = $this->players($section);

        if ($players === []) {
            throw new FutbolFantasyPageException("no players in the J{$weekNumber} lineup");
        }

        $heading = mb_strtolower(trim((string) $section->querySelector('.posible')?->textContent));
        $anyConfirmed = array_filter($players, fn (FutbolFantasyPlayer $player): bool => $player->confirmedStarter !== null) !== [];

        return new FutbolFantasyTeamPage($weekNumber, str_contains($heading, 'confirmada') || $anyConfirmed, $players);
    }

    private function lineupSection(HTMLDocument $document): ?Element
    {
        foreach ($document->querySelectorAll('section.alineacion_wrapper') as $section) {
            if (!$section->classList->contains('observaciones')) {
                return $section;
            }
        }

        return null;
    }

    private function weekNumber(Element $section): ?int
    {
        $text = trim((string) $section->querySelector('.jornada')?->textContent);

        return ctype_digit($text) ? (int) $text : null;
    }

    /**
     * @return list<FutbolFantasyPlayer>
     */
    private function players(Element $section): array
    {
        /** @var array<int, FutbolFantasyPlayer> $byId */
        $byId = [];

        foreach ($section->querySelectorAll('[data-probabilidad]') as $block) {
            $wrapper = $block->closest('[class*="jugador_"]');

            if ($wrapper === null || preg_match('/(?:^|\s)jugador_(\d+)(?:\s|$)/', (string) $wrapper->getAttribute('class'), $matches) !== 1) {
                continue;
            }

            $player = $this->player((int) $matches[1], $block, $wrapper);
            $byId[$player->futbolfantasyId] = isset($byId[$player->futbolfantasyId])
                ? $byId[$player->futbolfantasyId]->withFallback($player)
                : $player;
        }

        return array_values($byId);
    }

    /**
     * @param  Element  $block  the element carrying the data-* attributes (`a.camiseta` or the list div)
     * @param  Element  $wrapper  the `jugador_{id}` element around it (the block itself for a list div)
     */
    private function player(int $futbolfantasyId, Element $block, Element $wrapper): FutbolFantasyPlayer
    {
        $value = trim((string) $block->getAttribute('data-probabilidad'));
        [$pitchX, $pitchY] = $this->pitchCoordinates($wrapper);

        return new FutbolFantasyPlayer(
            futbolfantasyId: $futbolfantasyId,
            name: trim((string) $wrapper->querySelector('.truncate-name')?->textContent),
            slug: $this->slug($block, $wrapper),
            probability: preg_match('/^(\d{1,3})\s*%$/', $value, $matches) === 1 ? min(100, (int) $matches[1]) : null,
            confirmedStarter: match (mb_strtolower($value)) {
                'titular' => true,
                'suplente' => false,
                default => null,
            },
            predictedStarter: $wrapper->hasAttribute('data-onceff')
                ? $wrapper->getAttribute('data-onceff') === 'titular'
                : !$wrapper->classList->contains('isSuplente'),
            rivalCode: mb_strtoupper(trim((string) $block->getAttribute('data-rival'))),
            marketValue: (int) $block->getAttribute('data-valor-laliga-fantasy'),
            totalPoints: (int) round((float) $block->getAttribute('data-puntos-totales-laliga-fantasy')),
            position: match ($wrapper->getAttribute('data-posicionlaligafantasy')) {
                'Portero' => PlayerPosition::Goalkeeper,
                'Defensa' => PlayerPosition::Defender,
                'Mediocampista' => PlayerPosition::Midfield,
                'Delantero' => PlayerPosition::Striker,
                default => null,
            },
            pitchX: $pitchX,
            pitchY: $pitchY,
        );
    }

    /**
     * Where FF draws the shirt on its pitch: the wrapper's inline
     * `style="left: X%; top: Y%"`, FF attacking up (goalkeeper ~87 %,
     * striker ~18 %). Only the probable XI sits on the pitch in %; the
     * bench rows below it use px (`top: 44px`) and the list blocks carry no
     * style, so both read as null.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function pitchCoordinates(Element $wrapper): array
    {
        $style = (string) $wrapper->getAttribute('style');

        if (preg_match('/(?:^|[;\s])left:\s*(\d+(?:\.\d+)?)%/', $style, $left) !== 1
            || preg_match('/(?:^|[;\s])top:\s*(\d+(?:\.\d+)?)%/', $style, $top) !== 1) {
            return [null, null];
        }

        return [min(100, (int) round((float) $left[1])), min(100, (int) round((float) $top[1]))];
    }

    /**
     * The shirt usually links the player; some link "#" and list the slot's
     * alternatives underneath, the first (`pos-0`) being the player himself.
     */
    private function slug(Element $block, Element $wrapper): string
    {
        $href = (string) $block->getAttribute('href');

        if (!str_contains($href, '/jugadores/')) {
            $href = (string) $wrapper->querySelector('a[href*="/jugadores/"]')?->getAttribute('href');
        }

        return preg_match('~/jugadores/([^/?#]+)~', $href, $matches) === 1 ? $matches[1] : '';
    }
}
