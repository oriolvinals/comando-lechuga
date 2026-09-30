<?php

declare(strict_types=1);

use App\Enums\SeasonPrize;

test('the ten prizes add up to the 70 euro pot in page order', function (): void {
    expect(array_map(fn (SeasonPrize $prize): string => $prize->value, SeasonPrize::cases()))->toBe([
        'noche_magica', 'el_atracador', 'rey_del_domingo', 'banquillo_de_oro',
        'el_criminal', 'la_victima', 'el_pupas', 'matrimonio', 'fichaje_del_pueblo', 'hueco_libre',
    ])
        ->and(array_sum(array_map(fn (SeasonPrize $prize): int => $prize->amount(), SeasonPrize::cases())))->toBe(70)
        ->and(SeasonPrize::NocheMagica->amount())->toBe(10)
        ->and(SeasonPrize::ElCriminal->amount())->toBe(5);
});

test('only the free slot is undecided', function (): void {
    expect(SeasonPrize::HuecoLibre->isDecided())->toBeFalse()
        ->and(SeasonPrize::Matrimonio->isDecided())->toBeTrue()
        ->and(SeasonPrize::FichajeDelPueblo->label())->toBe('El Fichaje del Pueblo');
});
