<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Season;
use App\Services\ManualClauseRaise;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * God-only CRUD of the clause raises the user knows about. They are
 * authoritative: the raise detector takes them over its own reading.
 * PRIVATE: never mirrored in /api.
 */
class GodClauseRaiseController extends Controller
{
    public function store(Request $request, ManualClauseRaise $manualClauseRaise): RedirectResponse
    {
        ManagerPlayerClauseSnapshot::query()->create($this->attributes($request, $manualClauseRaise));

        return to_route('god.radar');
    }

    public function update(Request $request, ManagerPlayerClauseSnapshot $snapshot, ManualClauseRaise $manualClauseRaise): RedirectResponse
    {
        abort_unless($snapshot->source === ClauseSnapshotSource::Manual, 404);

        $snapshot->update($this->attributes($request, $manualClauseRaise, $snapshot->id));

        return to_route('god.radar');
    }

    public function destroy(ManagerPlayerClauseSnapshot $snapshot): RedirectResponse
    {
        abort_unless($snapshot->source === ClauseSnapshotSource::Manual, 404);

        $snapshot->delete();

        return to_route('god.radar');
    }

    /**
     * Validates the entry (exactly one of the new clause or the amount paid,
     * for a manager of the current season) and derives the row to store.
     * A manual row has no real lock to record: `buyout_clause_locked_until`
     * is the raise moment (a raise happens with the clause open) and
     * `market_value` is 0, as neither is read from manual rows.
     *
     * @return array{season_manager_id: int, player_id: int, buyout_clause: int, buyout_clause_locked_until: CarbonImmutable, market_value: int, captured_at: CarbonImmutable, source: ClauseSnapshotSource, raise_amount: int, note: string}
     */
    private function attributes(Request $request, ManualClauseRaise $manualClauseRaise, ?int $ignoreId = null): array
    {
        /** @var array{season_manager_id: int|string, player_id: int|string, captured_at: string, new_clause?: int|string|null, paid?: int|string|null, note?: string|null} $validated */
        $validated = $request->validate([
            'season_manager_id' => ['required', 'integer', Rule::exists('season_managers', 'id')->where('season_id', Season::current()->id)],
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'captured_at' => ['required', 'date'],
            'new_clause' => ['nullable', 'integer', 'min:1', 'required_without:paid', 'prohibits:paid'],
            'paid' => ['nullable', 'integer', 'min:1', 'required_without:new_clause'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $managerId = (int) $validated['season_manager_id'];
        $playerId = (int) $validated['player_id'];
        $at = CarbonImmutable::parse($validated['captured_at']);
        $newClause = isset($validated['new_clause']) ? (int) $validated['new_clause'] : null;
        $paid = isset($validated['paid']) ? (int) $validated['paid'] : null;
        $derived = $manualClauseRaise->derive($managerId, $playerId, $at, $newClause, $paid, $ignoreId);

        if ($derived['raise'] <= 0) {
            throw ValidationException::withMessages([
                'new_clause' => 'La nueva cláusula debe superar la anterior ('.number_format($derived['previous'], 0, ',', '.').' €).',
            ]);
        }

        return [
            'season_manager_id' => $managerId,
            'player_id' => $playerId,
            'buyout_clause' => $derived['clause'],
            'buyout_clause_locked_until' => $at,
            'market_value' => 0,
            'captured_at' => $at,
            'source' => ClauseSnapshotSource::Manual,
            'raise_amount' => $derived['raise'],
            'note' => (string) ($validated['note'] ?? ''),
        ];
    }
}
