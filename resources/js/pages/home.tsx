import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { HqCompareTray } from '@/components/compare/tray';
import AppLayout from '@/layouts/app-layout';
import type {
    Fixture,
    JornadaMatches,
    MarketPlayer,
    Season,
    Activity,
    SeasonManager,
    WeekProgressMap,
} from '@/types/models';
import { ActivityPanel } from './home/activity-panel';
import { FixturesPanel } from './home/fixtures-panel';
import { MarketPanel } from './home/market-panel';
import { NowPanel } from './home/now-panel';
import { StandingsTable } from './home/standings-table';

interface HomeProps {
    season: Season;
    filters: { week: number };
    fixtures: Fixture[];
    nextFixture: Fixture | null;
    jornadaMatches: JornadaMatches;
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
    nextFixture,
    jornadaMatches,
    standings,
    weekProgress,
    market,
    activity,
}: HomeProps) {
    return (
        <>
            <Head title="Inicio" />
            <NowPanel
                season={season}
                standings={standings}
                weekProgress={weekProgress}
                market={market}
                nextFixture={nextFixture}
                jornadaMatches={jornadaMatches}
            />
            <StandingsTable season={season} standings={standings} />
            <MarketPanel market={market} />
            <FixturesPanel
                fixtures={fixtures}
                season={season}
                week={filters.week}
                weekProgress={weekProgress}
            />
            <ActivityPanel activity={activity} />
            <HqCompareTray />
        </>
    );
}

Home.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
