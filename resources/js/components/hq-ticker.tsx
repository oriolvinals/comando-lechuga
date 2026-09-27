import { Link, usePage } from '@inertiajs/react';
import { Shield } from 'lucide-react';
import type { ReactNode } from 'react';
import { TYPE_COLORS, TYPE_LABELS } from '@/components/activity-helpers';
import { EntityImage } from '@/components/entity-image';
import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';
import { formatMillions } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as fixturesShow } from '@/routes/fixtures';
import { show as playersShow } from '@/routes/players';
import type { Ticker, TickerFixture, TickerTeam } from '@/types/models';

const ITEM_CLASSES = 'inline-flex shrink-0 items-center gap-[7px] lg:gap-2';
const LINK_CLASSES = cn(ITEM_CLASSES, 'hover:text-hq-paper');

function Crest({ team }: { team: TickerTeam }) {
    return (
        <EntityImage
            src={team.logo}
            alt=""
            fallback={Shield}
            shape="square"
            className="size-3.5 rounded-none bg-transparent text-hq-moss-dim"
        />
    );
}

function Scoreline({ fixture }: { fixture: TickerFixture }) {
    return (
        <>
            <Crest team={fixture.local_team} />
            <b className="font-semibold text-hq-paper tabular-nums">
                {fixture.local_team.short_name} {fixture.local_score ?? 0}–
                {fixture.guest_score ?? 0} {fixture.guest_team.short_name}
            </b>
            <Crest team={fixture.guest_team} />
        </>
    );
}

function Label({ children }: { children: ReactNode }) {
    return (
        <span className="shrink-0 font-semibold tracking-[0.05em] text-hq-moss-dim">
            {children}
        </span>
    );
}

/** Every item of the strip, in reading order. */
function tickerItems(ticker: Ticker): ReactNode[] {
    const items: ReactNode[] = [];

    ticker.live.forEach((fixture) => {
        items.push(
            <Link
                key={`live-${fixture.id}`}
                href={fixturesShow(fixture.id).url}
                className={LINK_CLASSES}
            >
                <Scoreline fixture={fixture} />
                <span className="text-hq-neg">
                    {fixture.state === 'half_time'
                        ? 'DESCANSO'
                        : fixture.display_clock}
                </span>
            </Link>,
        );
    });

    if (ticker.finished_week !== null && ticker.results.length > 0) {
        items.push(<Label key="results-label">J{ticker.finished_week}</Label>);

        ticker.results.forEach((fixture) => {
            items.push(
                <Link
                    key={`result-${fixture.id}`}
                    href={fixturesShow(fixture.id).url}
                    className={LINK_CLASSES}
                >
                    <Scoreline fixture={fixture} />
                </Link>,
            );
        });
    }

    if (ticker.market.length > 0) {
        items.push(<Label key="market-label">MERCADO</Label>);

        ticker.market.forEach((listing) => {
            items.push(
                <Link
                    key={`listing-${listing.id}`}
                    href={playersShow(listing.player_id).url}
                    className={LINK_CLASSES}
                >
                    <b className="font-semibold text-hq-paper">
                        {listing.nickname}
                    </b>
                    <span className="tabular-nums">
                        {formatMillions(listing.value)}
                    </span>
                    <HqMarketValueDifference
                        difference={listing.market_value_difference}
                        trend={listing.market_trend}
                        className="text-[11px] lg:text-xs"
                    />
                    {listing.bids > 0 && (
                        <span className="font-semibold text-hq-ember">
                            {listing.bids}{' '}
                            {listing.bids === 1 ? 'PUJA' : 'PUJAS'}
                        </span>
                    )}
                </Link>,
            );
        });
    }

    if (ticker.activities.length > 0) {
        items.push(<Label key="activity-label">ÚLTIMOS MOVIMIENTOS</Label>);

        ticker.activities.forEach((activity) => {
            items.push(
                <span key={`activity-${activity.id}`} className={ITEM_CLASSES}>
                    <span
                        className={cn(
                            'font-semibold',
                            TYPE_COLORS[activity.type],
                        )}
                    >
                        {TYPE_LABELS[activity.type].toUpperCase()}
                    </span>
                    <span>
                        {activity.manager_name}
                        {activity.player_nickname && (
                            <>
                                {' · '}
                                <b className="font-semibold text-hq-paper">
                                    {activity.player_nickname}
                                </b>
                            </>
                        )}
                        {activity.amount !== null &&
                            activity.amount > 0 &&
                            ` · ${formatMillions(activity.amount)}`}
                    </span>
                </span>,
            );
        });
    }

    return items;
}

/**
 * One full pass of the strip: the items split by slashes, with a trailing
 * slash and gap so two passes back to back loop seamlessly.
 */
function TickerRun({
    items,
    hidden = false,
}: {
    items: ReactNode[];
    hidden?: boolean;
}) {
    return (
        <div
            aria-hidden={hidden || undefined}
            inert={hidden}
            className="flex shrink-0 items-center gap-[26px] pr-[26px] lg:gap-[34px] lg:pr-[34px]"
        >
            {items.map((item, index) => (
                <span key={index} className="contents">
                    {index > 0 && (
                        <span
                            aria-hidden="true"
                            className="text-hq-border-bright"
                        >
                            /
                        </span>
                    )}
                    {item}
                </span>
            ))}
            <span aria-hidden="true" className="text-hq-border-bright">
                /
            </span>
        </div>
    );
}

/**
 * The "Teletipo" above the shell header: a scrolling strip with the matches
 * being played (red "EN DIRECTO" lead, lime "CUARTEL" otherwise), the last
 * finished jornada's results (under a "J{n}" label), the current daily market listings (value,
 * daily move and bids) and the latest transfer-market moves. It pauses on hover or
 * keyboard focus and stands still under reduced motion.
 */
export function HqTicker() {
    const { ticker } = usePage().props;

    if (!ticker) {
        return null;
    }

    const items = tickerItems(ticker);

    if (items.length === 0) {
        return null;
    }

    const isLive = ticker.live.length > 0;
    const lead = isLive ? '● EN DIRECTO' : 'CUARTEL';

    return (
        <section
            aria-label="Teletipo"
            className="h-[26px] overflow-hidden border-b border-hq-border bg-hq-well lg:h-[30px]"
        >
            <div className="mx-auto flex h-full w-full max-w-[1440px] items-center font-mono text-[11px] leading-none font-medium whitespace-nowrap text-hq-moss min-[1441px]:border-x min-[1441px]:border-hq-border lg:text-xs">
                <span
                    className={cn(
                        'z-10 flex h-full shrink-0 items-center px-[9px] font-bold tracking-[0.05em] lg:px-3',
                        isLive
                            ? 'bg-hq-live text-white'
                            : 'bg-hq-lime text-hq-ink',
                    )}
                >
                    {lead}
                </span>
                <div className="group flex h-full min-w-0 flex-1 items-center overflow-hidden pl-[18px] lg:pl-[22px]">
                    <div className="flex w-max animate-hq-ticker group-focus-within:[animation-play-state:paused] group-hover:[animation-play-state:paused]">
                        <TickerRun items={items} />
                        <TickerRun items={items} hidden />
                    </div>
                </div>
            </div>
        </section>
    );
}
