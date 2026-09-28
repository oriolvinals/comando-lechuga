<?php

declare(strict_types=1);

namespace App\Http\Filters;

use App\Enums\ApiPlayerSort;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SortDirection;
use Illuminate\Http\Request;

/**
 * `/api/players` filters. Validation (422) happens in the controller; this
 * only parses already-valid input.
 */
final class ApiPlayerFilter extends BaseRequestFilter
{
    /** @var PlayerPosition[] */
    private readonly array $positions;

    /** @var int[] */
    private readonly array $teams;

    /** @var int[] */
    private readonly array $managers;

    /** @var PlayerStatus[] */
    private readonly array $statuses;

    private readonly ?string $search;

    private readonly ?bool $free;

    private readonly ?int $minValue;

    private readonly ?int $maxValue;

    private readonly ?int $minStartProbability;

    private readonly ApiPlayerSort $sort;

    private readonly SortDirection $direction;

    public function __construct(Request $request)
    {
        $this->positions = $this->parseEnumList(PlayerPosition::class, $request->string('position')->toString());
        $this->teams = $this->parseIntList($request->string('team')->toString());
        $this->managers = $this->parseIntList($request->string('manager')->toString());
        $this->statuses = $this->parseEnumList(PlayerStatus::class, $request->string('status')->toString());
        $this->search = $this->parseString($request->string('search')->toString());
        $this->free = $request->filled('free') ? $request->boolean('free') : null;
        $this->minValue = $request->filled('min_value') ? $request->integer('min_value') : null;
        $this->maxValue = $request->filled('max_value') ? $request->integer('max_value') : null;
        $this->minStartProbability = $request->filled('min_start_probability') ? $request->integer('min_start_probability') : null;
        $this->sort = $this->parseEnum(ApiPlayerSort::class, $request->string('sort')->toString()) ?? ApiPlayerSort::Points;
        $this->direction = $this->parseEnum(SortDirection::class, $request->string('direction')->toString()) ?? SortDirection::Desc;
    }

    /**
     * @return PlayerPosition[]
     */
    public function getPositions(): array
    {
        return $this->positions;
    }

    /**
     * @return int[]
     */
    public function getTeams(): array
    {
        return $this->teams;
    }

    /**
     * @return int[]
     */
    public function getManagers(): array
    {
        return $this->managers;
    }

    /**
     * @return PlayerStatus[]
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    /** True: only unowned players; false: only owned ones; null: both. */
    public function isFree(): ?bool
    {
        return $this->free;
    }

    public function getMinValue(): ?int
    {
        return $this->minValue;
    }

    public function getMaxValue(): ?int
    {
        return $this->maxValue;
    }

    public function getMinStartProbability(): ?int
    {
        return $this->minStartProbability;
    }

    public function getSort(): ApiPlayerSort
    {
        return $this->sort;
    }

    public function getDirection(): SortDirection
    {
        return $this->direction;
    }
}
