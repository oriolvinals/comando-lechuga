import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import type {
    Fixture,
    MarketPlayer,
    Season,
    SeasonActivity,
    SeasonTeam,
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
    standings: SeasonTeam[];
    market: MarketPlayer[];
    activity: SeasonActivity[];
    [key: string]: unknown;
}

export default function Home({
    season,
    filters,
    fixtures,
    standings,
    market,
    activity,
}: HomeProps) {
    return (
        <>
            <Head title="Inicio" />
            <div className="hq-texture hq-bleed border-y border-hq-border">
                <div className="mx-auto max-w-7xl px-6">
                    <HeroPanel week={filters.week} standings={standings} />
                    <FixturesPanel
                        fixtures={fixtures}
                        season={season}
                        week={filters.week}
                    />
                    <StandingsTable standings={standings} />
                    <MarketPanel market={market} />
                    <ActivityPanel activity={activity} />
                </div>
            </div>
        </>
    );
}

Home.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
