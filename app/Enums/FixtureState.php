<?php

declare(strict_types=1);

namespace App\Enums;

enum FixtureState: string
{
    case Scheduled = 'scheduled';
    case FirstHalf = 'first_half';
    case HalfTime = 'half_time';
    case SecondHalf = 'second_half';
    case Finished = 'finished';

    public static function fromFantasyId(int $stateId): self
    {
        return match ($stateId) {
            2 => self::FirstHalf,
            3 => self::HalfTime,
            4 => self::SecondHalf,
            7 => self::Finished,
            default => self::Scheduled,
        };
    }

    /**
     * Returns null for a status name worldcup26 doesn't document (e.g. a
     * transient value during a state transition) — callers should leave the
     * fixture's existing state untouched rather than overwrite it with a
     * guess.
     */
    public static function fromWorldcup26Name(string $name): ?self
    {
        return match ($name) {
            'STATUS_SCHEDULED' => self::Scheduled,
            'STATUS_FIRST_HALF' => self::FirstHalf,
            'STATUS_HALFTIME' => self::HalfTime,
            'STATUS_SECOND_HALF' => self::SecondHalf,
            'STATUS_FULL_TIME' => self::Finished,
            default => null,
        };
    }

    /**
     * Spanish display label for the public API — unlike the frontend's
     * FIXTURE_STATE_LABELS (resources/js/lib/fixture-state.ts), Scheduled
     * gets a real label here instead of an empty string, since the frontend
     * substitutes the kickoff date/time for that case but an API consumer
     * has no such fallback.
     */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Programado',
            self::FirstHalf => '1ª parte',
            self::HalfTime => 'Descanso',
            self::SecondHalf => '2ª parte',
            self::Finished => 'Finalizado',
        };
    }
}
