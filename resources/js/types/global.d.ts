import type { Season, Ticker } from '@/types/models';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            season: Season;
            liveMatchday: boolean;
            godMode: boolean;
            ticker: Ticker;
            [key: string]: unknown;
        };
    }
}
