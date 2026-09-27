import { formatCurrency, formatShortDay } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PlayerCapitalGain } from '@/types/models';

function signedCurrency(amount: number): string {
    const sign = amount > 0 ? '+' : amount < 0 ? '−' : '';

    return `${sign}${formatCurrency(Math.abs(amount))}`;
}

/**
 * The owner's paper gain on the player (under the Propiedad card): current
 * value minus what they paid in their latest signing or buyout. Without a
 * recorded purchase the owner already had the player on joining the league.
 */
export function CapitalGain({ gain }: { gain: PlayerCapitalGain | null }) {
    return (
        <div className="mt-2.5 border border-hq-border-strong px-[11px] py-2.5">
            <p className="hq-label">Plusvalía</p>
            {gain ? (
                <>
                    <span
                        className={cn(
                            'mt-2 block font-mono text-base leading-none font-bold whitespace-nowrap tabular-nums',
                            gain.amount >= 0 ? 'text-hq-lime' : 'text-hq-neg',
                        )}
                    >
                        {signedCurrency(gain.amount)}
                    </span>
                    <span className="mt-1.5 block font-mono text-[11px] leading-[1.35] text-hq-moss-dim">
                        {gain.type === 'buyout' ? 'Cláusula' : 'Fichado'} el{' '}
                        {formatShortDay(gain.occurred_at)} por{' '}
                        <b className="font-semibold text-hq-paper">
                            {formatCurrency(gain.paid)}
                        </b>
                    </span>
                </>
            ) : (
                <>
                    <span className="mt-2 block font-mono text-base leading-none font-bold text-hq-moss-dim">
                        —
                    </span>
                    <span className="mt-1.5 block font-mono text-[11px] leading-[1.35] text-hq-moss-dim">
                        ya lo tenía al entrar en la liga
                    </span>
                </>
            )}
        </div>
    );
}
