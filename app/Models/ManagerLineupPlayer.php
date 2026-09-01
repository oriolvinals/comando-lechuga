<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\ManagerLineupPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int $id
 * @property-read int $manager_lineup_id
 * @property-read int $player_id
 * @property-read int|null $fixture_id
 * @property int|null $points Set by SyncCurrentSeasonManagerLineups from the Fantasy API's own per-week
 *                            score at sync time — a fallback for when this player never resolves a `fixture_id` (and so has no
 *                            `FixtureLineup` to derive points from). SeasonManagersController::attachLineupPlayerScores()
 *                            overwrites this with the more authoritative `FixtureLineup.fantasy_points` whenever one exists.
 * @property-read PlayerPosition $position
 * @property bool $match_finished Computed at query time by SeasonManagersController; not a database column.
 * @property array<string, mixed>|null $stats Computed at query time by SeasonManagersController from the linked FixtureLineup; not a database column.
 * @property Fixture|null $fixture Computed at query time by SeasonManagersController's AttachesLineupFixtures, resolved by
 *                                 player team + lineup week (like `match_finished`) rather than via `fixture_id`, which isn't
 *                                 always set — see `AttachesLineupPlayerScores`. Shadows the lazy `fixture()` relation below.
 */
#[UseFactory(ManagerLineupPlayerFactory::class)]
#[Table(name: 'manager_lineup_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['manager_lineup_id', 'player_id', 'fixture_id', 'points', 'position'])]
class ManagerLineupPlayer extends Model
{
    /** @use HasFactory<ManagerLineupPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<ManagerLineup, $this> */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(ManagerLineup::class, 'manager_lineup_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return HasOne<FixtureLineup, $this> */
    public function fixtureLineup(): HasOne
    {
        // Not ->whereColumn(): that compares two columns within the SAME
        // query, but this relation's lazy-load query only ever selects FROM
        // fixture_lineups — manager_lineup_players is never joined in, so a
        // whereColumn() reference to it fails with "no such column". This is
        // a genuine per-instance value comparison instead: $this->fixture_id
        // is evaluated once, at call time, into a literal binding. When it's
        // null, Laravel's query builder converts a null-valued ->where(...)
        // into ->whereNull(...) automatically — which correctly matches zero
        // rows here, since fixture_lineups.fixture_id is itself never
        // nullable, giving the desired "no match" result for free.
        //
        // This also means the relation can only be used per-instance
        // (lazy-loaded) — it CANNOT be eager-loaded via ->with(), since
        // eager loading builds one shared query template from the first
        // model in a collection and would incorrectly bake in only that
        // model's fixture_id for every row in the batch. Any code that needs
        // this for many ManagerLineupPlayers at once must do a manual bulk
        // lookup instead.
        return $this->hasOne(FixtureLineup::class, 'player_id', 'player_id')
            ->where('fixture_lineups.fixture_id', $this->fixture_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'manager_lineup_id' => 'int',
            'player_id' => 'int',
            'fixture_id' => 'int',
            'points' => 'int',
            'position' => PlayerPosition::class,
        ];
    }
}
