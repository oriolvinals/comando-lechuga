import { Link } from '@inertiajs/react';
import { ArrowRight, Trophy } from 'lucide-react';
import { index as prizesIndex } from '@/routes/prizes';

/** The only way into /premios: a wide plain link under the home standings (no menu entry, no teaser). */
export function PrizesLink() {
    return (
        <Link
            href={prizesIndex()}
            className="group grid w-full cursor-pointer grid-cols-[auto_1fr_auto] items-center gap-3 border-t border-hq-border-strong bg-hq-panel py-2.5 pr-4 pl-[18px] transition-colors hover:bg-hq-panel-alt max-sm:gap-2.5 max-sm:px-3.5"
        >
            <span className="grid size-[34px] place-items-center border border-hq-gold/55 text-hq-gold">
                <Trophy className="size-[18px]" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="block font-display text-sm leading-none text-hq-paper uppercase">
                    Premios de fin de temporada
                </span>
                <span className="mt-1 block font-mono text-[11.5px] text-hq-moss">
                    <b className="font-bold text-hq-gold">70 €</b> en 10 premios
                    · el campeón no cobra
                </span>
            </span>
            <span className="inline-flex min-h-8 items-center gap-2 border border-hq-border-bright px-3 font-mono text-[11px] font-bold tracking-[0.08em] text-hq-paper uppercase transition-colors group-hover:border-hq-lime group-hover:bg-hq-lime group-hover:text-hq-ink max-sm:min-h-10 max-sm:w-8 max-sm:justify-center max-sm:border-0 max-sm:px-0 max-sm:group-hover:bg-transparent max-sm:group-hover:text-hq-lime">
                <span className="max-sm:hidden">Ver premios</span>
                <ArrowRight className="size-3.5" aria-hidden="true" />
            </span>
        </Link>
    );
}
