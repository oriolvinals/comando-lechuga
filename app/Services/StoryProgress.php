<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Step-by-step progress of a story publication: one line per step, followed by its duration when the next step
 * starts (or on finish), plus loose notes (Remotion progress, Instagram status…). Every line is also logged at info
 * level so a production run can be followed from the log.
 */
final class StoryProgress
{
    private ?string $currentStep = null;

    private float $stepStartedAt;

    /**
     * @param  (Closure(string): void)|null  $writer  Where the lines go besides the log (the console).
     */
    public function __construct(private readonly ?Closure $writer = null)
    {
        $this->stepStartedAt = microtime(true);
    }

    /**
     * Closes the current step (printing its duration) and starts a new one. The first step counts from construction.
     */
    public function step(string $message): void
    {
        if ($this->currentStep !== null) {
            $this->finish();
            $this->stepStartedAt = microtime(true);
        }

        $this->currentStep = $message;
        $this->write($message);
    }

    public function note(string $message): void
    {
        $this->write('  '.$message);
    }

    public function finish(): void
    {
        if ($this->currentStep === null) {
            return;
        }

        $this->write('  ↳ '.number_format(microtime(true) - $this->stepStartedAt, 1, ',', '').' s');
        $this->currentStep = null;
        $this->stepStartedAt = microtime(true);
    }

    private function write(string $line): void
    {
        Log::info('[stories] '.trim($line));

        if ($this->writer !== null) {
            ($this->writer)($line);
        }
    }
}
