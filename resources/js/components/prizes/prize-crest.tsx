import { Shield } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { crestTintStyle } from '@/lib/season-manager-colors';
import { cn } from '@/lib/utils';
import type { PrizeManager } from '@/types/prizes';

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
