import { Head } from '@inertiajs/react';
import type { ReactElement } from 'react';
import AppLayout from '@/layouts/app-layout';
import type { Team } from '@/types/models';

interface TeamShowProps {
    team: Team;
    [key: string]: unknown;
}

export default function TeamShow({ team }: TeamShowProps) {
    return (
        <div className="hq-texture hq-bleed flex-1 border-y border-hq-border">
            <div className="mx-auto max-w-7xl px-6 py-9">
                <Head title={team.main_name} />

                <div className="flex items-center gap-3">
                    <img
                        src={team.logo}
                        alt={team.main_name}
                        className="h-12 w-12 object-contain"
                    />
                    <h1 className="font-display text-3xl text-hq-paper uppercase">
                        {team.main_name}
                    </h1>
                </div>
            </div>
        </div>
    );
}

TeamShow.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;
