<?php

declare(strict_types=1);

namespace App\Http\Filters;

use App\Enums\SeasonActivityType;
use Illuminate\Http\Request;

final class ActivityFilter extends BaseRequestFilter
{
    /** @var int[] */
    private readonly array $managers;

    /** @var SeasonActivityType[] */
    private readonly array $types;

    public function __construct(Request $request)
    {
        $this->managers = $this->parseIntList($request->string('manager')->toString());
        $this->types = $this->parseEnumList(SeasonActivityType::class, $request->string('type')->toString());
    }

    /**
     * @return int[]
     */
    public function getManagers(): array
    {
        return $this->managers;
    }

    /**
     * @return SeasonActivityType[]
     */
    public function getTypes(): array
    {
        return $this->types;
    }
}
