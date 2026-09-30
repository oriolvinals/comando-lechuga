import { Shield, User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { crestTintStyle } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type { PrizeManager, PrizePlayer } from '@/types/prizes';

/** A manager crest on its tinted square, as in the home standings. */
export function ManagerCrest({
    manager,
    className = 'size-[22px]',
}: {
    manager: PrizeManager;
    className?: string;
}) {
    return (
        <EntityImage
            src={manager.logo}
            alt={manager.name}
            fallback={Shield}
            shape="square"
            style={crestTintStyle(manager.primary_color)}
            className={cn(
                'shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt p-[2px] text-hq-khaki',
                className,
            )}
        />
    );
}

/** A player's photo on a square frame, head in view. */
export function PlayerPortrait({
    player,
    className = 'size-9',
}: {
    player: PrizePlayer;
    className?: string;
}) {
    return (
        <EntityImage
            src={player.image}
            alt={player.nickname}
            fallback={User}
            shape="square"
            className={cn(
                'shrink-0 rounded-none border border-hq-border-strong bg-hq-panel-alt object-cover object-top text-hq-moss',
                className,
            )}
        />
    );
}
