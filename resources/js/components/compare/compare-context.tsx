import { createContext, useContext } from 'react';
import type { DerivedPlayer } from '@/components/compare/derive';
import type {
    CompareManager,
    ComparedPlayer,
    LeagueCloudRow,
} from '@/types/models';

export interface CompareContextValue {
    players: ComparedPlayer[];
    derived: DerivedPlayer[];
    league: LeagueCloudRow[];
    managersById: Map<number, CompareManager>;
    currentWeek: number;
    now: number;
    /** Adds a player; `focusSelector` is focused once the new props arrive. */
    add: (id: number, focusSelector?: string) => void;
    remove: (index: number) => void;
    /** Opens the picker to add (null) or to replace the player in that slot. */
    openPicker: (replaceIndex: number | null) => void;
    announce: (text: string) => void;
}

export const CompareContext = createContext<CompareContextValue | null>(null);

export function useCompare(): CompareContextValue {
    const value = useContext(CompareContext);

    if (!value) {
        throw new Error('useCompare needs the comparator page.');
    }

    return value;
}
