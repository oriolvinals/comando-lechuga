import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import type {
    Fixture,
    MarketPlayer,
    Season,
    Activity,
    SeasonManager,
    WeekProgressMap,
} from '@/types/models';
import { ActivityPanel } from './home/activity-panel';
import { FixturesPanel } from './home/fixtures-panel';
import { HeroPanel } from './home/hero-panel';
import { MarketPanel } from './home/market-panel';
import { StandingsTable } from './home/standings-table';

interface HomeProps {
    season: Season;
    filters: { week: number };
    fixtures: Fixture[];
    standings: SeasonManager[];
    weekProgress: WeekProgressMap;
    market: MarketPlayer[];
    activity: Activity[];
    [key: string]: unknown;
}

export default function Home({
    season,
    filters,
    fixtures,
    standings,
    weekProgress,
    market,
    activity,
}: HomeProps) {
    return (
        <>
            <Head title="Inicio" />
            <HeroPanel
                currentWeek={season.current_week}
                standings={standings}
            />
            <div className="grid grid-cols-1 border-b border-hq-border min-[73.75rem]:grid-cols-[minmax(0,1fr)_440px] [&>*]:min-w-0">
                <StandingsTable season={season} standings={standings} />
                <div className="border-t border-hq-border min-[73.75rem]:border-t-0 min-[73.75rem]:border-l">
                    <MarketPanel market={market} />
                </div>
            </div>
            <FixturesPanel
                fixtures={fixtures}
                season={season}
                week={filters.week}
                weekProgress={weekProgress}
            />
            <ActivityPanel activity={activity} />
        </>
    );
}

Home.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
