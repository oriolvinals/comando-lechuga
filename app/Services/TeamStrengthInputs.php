<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A team's aggregates at one date, gathered once so `TeamStrength::fromInputs()`
 * can be replayed in the backtest with other parameters. Every performance
 * figure is a per-match mean over that team's finished matches before the
 * date; it is null when the team has no such match (or, for key passes, no
 * match with that data).
 *
 * @phpstan-type TeamStrengthInputsArray array{teamId: int, matches: int, logValue: float|null, goalDifference: float|null, shotsOnTargetDifference: float|null, keyPassesFor: float|null, goalsFor: float|null, shotsOnTargetFor: float|null, failedToScoreRate: float|null, goalsAgainst: float|null, shotsOnTargetAgainst: float|null, keyPassesAgainst: float|null}
 */
final readonly class TeamStrengthInputs
{
    public function __construct(
        public int $teamId,
        public int $matches,
        /** ln(sum of the top `topPlayers` squad values); null when the team has no market values. */
        public ?float $logValue,
        public ?float $goalDifference,
        public ?float $shotsOnTargetDifference,
        public ?float $keyPassesFor,
        public ?float $goalsFor,
        public ?float $shotsOnTargetFor,
        public ?float $failedToScoreRate,
        public ?float $goalsAgainst,
        public ?float $shotsOnTargetAgainst,
        public ?float $keyPassesAgainst,
    ) {}

    /**
     * Plain values, so the inputs can be cached without serializing objects.
     *
     * @return TeamStrengthInputsArray
     */
    public function toArray(): array
    {
        return [
            'teamId' => $this->teamId,
            'matches' => $this->matches,
            'logValue' => $this->logValue,
            'goalDifference' => $this->goalDifference,
            'shotsOnTargetDifference' => $this->shotsOnTargetDifference,
            'keyPassesFor' => $this->keyPassesFor,
            'goalsFor' => $this->goalsFor,
            'shotsOnTargetFor' => $this->shotsOnTargetFor,
            'failedToScoreRate' => $this->failedToScoreRate,
            'goalsAgainst' => $this->goalsAgainst,
            'shotsOnTargetAgainst' => $this->shotsOnTargetAgainst,
            'keyPassesAgainst' => $this->keyPassesAgainst,
        ];
    }

    /**
     * @param  TeamStrengthInputsArray  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(...$values);
    }
}
