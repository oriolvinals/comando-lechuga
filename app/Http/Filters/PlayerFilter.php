<?php

declare(strict_types=1);

namespace App\Http\Filters;

use App\Enums\PlayerPosition;
use App\Enums\PlayerSort;
use App\Enums\PlayerStatus;
use App\Enums\SortDirection;
use Illuminate\Http\Request;

final class PlayerFilter extends BaseRequestFilter
{
    /** @var PlayerPosition[] */
    private readonly array $positions;

    /** @var int[] */
    private readonly array $teams;

    /** @var int[] */
    private readonly array $seasonManagers;

    /** @var PlayerStatus[] */
    private readonly array $statuses;

    private readonly ?string $search;

    private readonly PlayerSort $sort;

    private readonly SortDirection $direction;

    public function __construct(Request $request)
    {
        $this->positions = $this->parseEnumList(PlayerPosition::class, $request->string('position')->toString());
        $this->teams = $this->parseIntList($request->string('team')->toString());
        $this->seasonManagers = $this->parseIntList($request->string('season_manager')->toString());
        $this->statuses = $this->parseEnumList(PlayerStatus::class, $request->string('status')->toString());
        $this->search = $this->parseString($request->string('search')->toString());
        $this->sort = $this->parseEnum(PlayerSort::class, $request->string('sort')->toString()) ?? PlayerSort::Points;
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
    public function getSeasonManagers(): array
    {
        return $this->seasonManagers;
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

    public function getSort(): PlayerSort
    {
        return $this->sort;
    }

    public function getDirection(): SortDirection
    {
        return $this->direction;
    }
}
