import { usePage } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqLed } from '@/components/hq-led';
import { HqTooltip } from '@/components/hq-tooltip';
import { formatMatchDateTime } from '@/lib/format';
import { useCountdown } from '@/lib/use-countdown';
import { useLiveFixtureRefresh } from '@/lib/use-live-fixture-refresh';
import { cn } from '@/lib/utils';
import type {
    Fixture,
    JornadaMatches,
    MarketPlayer,
    Season,
    SeasonManager,
    WeekProgressMap,
} from '@/types/models';
import { JornadaMatchesStrip } from './jornada-matches-strip';

interface NowPanelProps {
    season: Season;
    standings: SeasonManager[];
    weekProgress: WeekProgressMap;
    market: MarketPlayer[];
    /** The season's next kickoff — independent of the jornada browsed below. */
    nextFixture: Fixture | null;
    /** The current jornada's matches with the managers whose lineup plays in each. */
    jornadaMatches: JornadaMatches;
}

const pad = (value: number) => String(value).padStart(2, '0');

function NowTile({
    label,
    accent,
    className,
    children,
}: {
    label: ReactNode;
    /** A CSS colour for the 3px top rule of a tile that has something to say. */
    accent?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            style={accent ? ({ '--acc': accent } as CSSProperties) : undefined}
            className={cn(
                'relative min-w-0 bg-hq-well px-3 pt-3 pb-[13px] md:px-4 md:pt-3.5 md:pb-[15px]',
                accent && 'shadow-[inset_0_3px_0_var(--acc)]',
                className,
            )}
        >
            <div className="mb-2.5 flex items-center gap-1.5 font-mono text-[10px] font-bold tracking-[0.12em] text-hq-moss-dim uppercase">
                {label}
            </div>
            {children}
        </div>
    );
}

function TileValue({
    tone,
    children,
}: {
    tone: 'lime' | 'paper' | 'live' | 'gold' | 'off';
    children: ReactNode;
}) {
    return (
        <HqLed
            tone={tone}
            glow={tone === 'lime'}
            className="block text-[30px] whitespace-nowrap min-[73.75rem]:text-[38px]"
        >
            {children}
        </HqLed>
    );
}

function TileNote({ children }: { children: ReactNode }) {
    return (
        <div className="mt-[9px] flex flex-wrap items-center gap-[5px] font-mono text-[11px] leading-[1.3] text-hq-moss [&_b]:font-bold [&_b]:text-hq-paper">
            {children}
        </div>
    );
}

function WeekProgressBar({
    season,
    weekProgress,
}: {
    season: Season;
    weekProgress: WeekProgressMap;
}) {
    return (
        <div
            className="mt-2.5 grid gap-0.5"
            style={{
                gridTemplateColumns: `repeat(${season.total_weeks}, minmax(0, 1fr))`,
            }}
        >
            {Array.from({ length: season.total_weeks }, (_, index) => {
                const week = index + 1;
                const progress = weekProgress[week];

                return (
                    <i
                        key={week}
                        title={`J${week}`}
                        className={cn(
                            'h-[5px] bg-hq-border-strong',
                            week === season.current_week
                                ? 'bg-hq-paper outline outline-offset-1 outline-hq-paper'
                                : progress === 'all'
                                  ? 'bg-hq-lime'
                                  : progress === 'partial' && 'bg-hq-gold',
                        )}
                    />
                );
            })}
        </div>
    );
}

function JornadaTile({
    season,
    weekProgress,
    inPlay,
}: {
    season: Season;
    weekProgress: WeekProgressMap;
    inPlay: boolean;
}) {
    const finished = weekProgress[season.current_week] === 'all';
    const state = inPlay ? 'En juego' : finished ? 'Terminada' : 'Por empezar';
    const played = season.current_week - 1 + (finished ? 1 : 0);

    return (
        <NowTile label="Jornada" accent="var(--color-hq-lime)">
            <TileValue tone="lime">J{pad(season.current_week)}</TileValue>
            <TileNote>
                <b>{state}</b> · {played} de {season.total_weeks} jugadas
            </TileNote>
            <WeekProgressBar season={season} weekProgress={weekProgress} />
        </NowTile>
    );
}

function NextFixtureTile({
    fixture,
    currentWeek,
    inPlay,
}: {
    fixture: Fixture;
    currentWeek: number;
    inPlay: boolean;
}) {
    const countdown = useCountdown(fixture.date);

    return (
        <NowTile label={inPlay ? 'Próximo partido' : 'Primer partido'}>
            <TileValue tone="paper">{countdown}</TileValue>
            <TileNote>
                <EntityImage
                    src={fixture.local_team.logo}
                    alt=""
                    fallback={Shield}
                    shape="square"
                    className="h-[15px] w-[15px] rounded-none"
                />
                <b>{fixture.local_team.short_name}</b> vs{' '}
                <b>{fixture.guest_team.short_name}</b>
                <EntityImage
                    src={fixture.guest_team.logo}
                    alt=""
                    fallback={Shield}
                    shape="square"
                    className="h-[15px] w-[15px] rounded-none"
                />
                <span>
                    · {formatMatchDateTime(fixture.date)}
                    {fixture.week_number !== currentWeek &&
                        ` · J${fixture.week_number}`}
                </span>
            </TileNote>
        </NowTile>
    );
}

function jornadaMatchesLabel({ week, status, matches }: JornadaMatches) {
    const jornada = `J${pad(week)}`;

    if (status === 'live') {
        return (
            <>
                <span className="h-1.5 w-1.5 animate-hq-pulse rounded-full bg-hq-live" />
                En directo · {jornada} · {matches.length}{' '}
                {matches.length === 1 ? 'partido' : 'partidos'} en juego
            </>
        );
    }

    if (status === 'not_started') {
        return (
            <>
                <span className="h-1.5 w-1.5 rounded-full bg-hq-led-off" />
                {jornada} sin empezar · abren la jornada · alineaciones al
                primer pitido
            </>
        );
    }

    return (
        <>
            <span className="h-1.5 w-1.5 rounded-full bg-hq-lime" />
            {status === 'finished'
                ? `${jornada} terminada · últimos resultados`
                : `${jornada} en juego · ahora sin partidos · último y próximos`}
        </>
    );
}

/**
 * The jornada's matches right now — what's live, or else the last result
 * and what comes next — with the managers who have players in each one.
 */
function JornadaMatchesTile({
    jornadaMatches,
}: {
    jornadaMatches: JornadaMatches;
}) {
    return (
        <NowTile
            label={jornadaMatchesLabel(jornadaMatches)}
            accent={
                jornadaMatches.status === 'live'
                    ? 'var(--color-hq-live)'
                    : undefined
            }
            className="col-span-full"
        >
            {jornadaMatches.matches.length > 0 ? (
                <JornadaMatchesStrip matches={jornadaMatches.matches} />
            ) : (
                <TileNote>Sin partidos programados</TileNote>
            )}
        </NowTile>
    );
}

/** Full row under the jornada/kickoff pair until the grid has 3 columns. */
const MARKET_TILE_CLASSES = 'col-span-full min-[73.75rem]:col-span-1';

/** Partial reloads while a match is live: the strip, the live table, and whether it's still live. */
const LIVE_REFRESH_PROPS = ['jornadaMatches', 'standings', 'liveMatchday'];

function MarketClosingTile({ market }: { market: MarketPlayer[] }) {
    // Every listing expires at the same time — see the market's summary card.
    const countdown = useCountdown(
        market[0]?.expires_at ?? new Date().toISOString(),
    );
    const bids = market.reduce((sum, listing) => sum + listing.bids, 0);

    if (market.length === 0) {
        return (
            <NowTile label="Mercado" className={MARKET_TILE_CLASSES}>
                <TileValue tone="off">∅</TileValue>
                <TileNote>Sin ofertas ahora mismo</TileNote>
            </NowTile>
        );
    }

    return (
        <NowTile
            label="Mercado cierra"
            accent="var(--color-hq-gold)"
            className={MARKET_TILE_CLASSES}
        >
            <HqTooltip label="Todas las ofertas vencen a la vez">
                <TileValue tone="gold">{countdown}</TileValue>
            </HqTooltip>
            <TileNote>
                <b>{market.length}</b> en venta ·{' '}
                <b className={cn(bids > 0 && 'text-hq-ember!')}>{bids}</b>{' '}
                {bids === 1 ? 'puja' : 'pujas'}
            </TileNote>
        </NowTile>
    );
}

/**
 * The "Ahora" block that opens Inicio: the jornada's matches with the
 * managers who have players in them, the jornada and its progress over the
 * season, the countdown to the next kickoff and the market's closing
 * countdown. Refreshes itself while a match is live.
 */
export function NowPanel({
    season,
    standings,
    weekProgress,
    market,
    nextFixture,
    jornadaMatches,
}: NowPanelProps) {
    const { liveMatchday } = usePage().props;
    useLiveFixtureRefresh(
        Boolean(liveMatchday) || jornadaMatches.status === 'live',
        LIVE_REFRESH_PROPS,
    );
    // live_points only reaches the page while the current jornada is in
    // progress, so it also covers the gaps between that jornada's matches.
    const inPlay =
        Boolean(liveMatchday) ||
        standings.some((team) => team.live_points !== null);

    return (
        <section className="grid grid-cols-1 border-b border-hq-border md:grid-cols-[200px_minmax(0,1fr)] min-[73.75rem]:grid-cols-[232px_minmax(0,1fr)]">
            <div className="hq-hud relative my-4 ml-4 hidden items-center justify-center overflow-hidden border border-hq-border-strong bg-hq-well bg-[radial-gradient(ellipse_at_50%_45%,rgba(196,255,61,0.08),transparent_65%)] md:flex">
                <span className="absolute top-2.5 left-3 z-10 hq-label">
                    CAM 01 · CUARTEL
                </span>
                <span
                    aria-hidden="true"
                    className="absolute top-2.5 right-3 z-10 animate-pulse font-mono text-[10.5px] font-bold tracking-[0.1em] text-hq-live"
                >
                    ● REC
                </span>
                <img
                    src="/images/logo.png"
                    alt="Comando Lechuga"
                    className="h-4/5 w-4/5 object-contain drop-shadow-[0_10px_30px_rgba(0,0,0,0.6)]"
                />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 bg-[repeating-linear-gradient(0deg,rgba(255,255,255,0.025)_0_1px,transparent_1px_3px)]"
                />
            </div>
            <div className="min-w-0 px-3.5 py-4 md:py-[18px] md:pr-4 md:pl-[18px]">
                <p className="mb-2 font-mono text-[11px] leading-none font-bold tracking-[0.2em] text-hq-lime">
                    ▸ AHORA — CUARTEL DE OPERACIONES
                </p>
                <h1 className="mb-3.5 text-[28px] leading-[0.95] font-black tracking-[-0.015em] text-hq-paper uppercase md:text-[34px]">
                    1 campeón.{' '}
                    <span className="text-hq-lime">
                        {Math.max(standings.length - 1, 0)} excusas.
                    </span>
                </h1>
                <div className="grid grid-cols-2 gap-px border border-hq-border-strong bg-hq-border min-[73.75rem]:grid-cols-3">
                    <JornadaMatchesTile jornadaMatches={jornadaMatches} />
                    <JornadaTile
                        season={season}
                        weekProgress={weekProgress}
                        inPlay={inPlay}
                    />
                    {nextFixture ? (
                        <NextFixtureTile
                            fixture={nextFixture}
                            currentWeek={season.current_week}
                            inPlay={inPlay}
                        />
                    ) : (
                        <NowTile label="Primer partido">
                            <TileValue tone="off">—</TileValue>
                            <TileNote>Sin partidos programados</TileNote>
                        </NowTile>
                    )}
                    <MarketClosingTile market={market} />
                </div>
            </div>
        </section>
    );
}
