import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import type { FixtureLineupEntry } from '@/types/models';

interface HqMatchPitchProps {
    lineups: FixtureLineupEntry[];
    onSelect?: (entry: FixtureLineupEntry) => void;
}

export function HqMatchPitch({ lineups, onSelect }: HqMatchPitchProps) {
    const starters = lineups.filter((entry) => entry.starter && entry.x !== null && entry.y !== null);

    return (
        <div className="relative aspect-[16/9.4] w-full overflow-hidden border border-hq-border-strong bg-hq-pitch bg-[repeating-linear-gradient(90deg,rgba(255,255,255,0.015)_0_40px,transparent_40px_80px)]">
            <div className="pointer-events-none absolute inset-3.5 border-[1.5px] border-hq-pitch-line" />
            <div className="pointer-events-none absolute top-3.5 bottom-3.5 left-1/2 w-[1.5px] -translate-x-1/2 bg-hq-pitch-line" />
            <div className="pointer-events-none absolute top-1/2 left-1/2 aspect-square w-[15%] -translate-x-1/2 -translate-y-1/2 rounded-full border-[1.5px] border-hq-pitch-line" />
            <div className="pointer-events-none absolute top-[26%] bottom-[26%] left-3.5 w-[12%] border-[1.5px] border-l-0 border-hq-pitch-line" />
            <div className="pointer-events-none absolute top-[26%] bottom-[26%] right-3.5 w-[12%] border-[1.5px] border-r-0 border-hq-pitch-line" />

            {starters.map((entry) => (
                <div
                    key={entry.id}
                    className="absolute -translate-x-1/2 -translate-y-1/2"
                    style={{ left: `${entry.x}%`, top: `${entry.y}%` }}
                >
                    <HqLineupPlayerToken entry={entry} variant="pitch" onSelect={onSelect} />
                </div>
            ))}
        </div>
    );
}
