<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

/**
 * Renders the «Compras del mercado» stories with the external Remotion project (config services.remotion):
 *
 *  1. writes the export to <path>/data/compras-<date>.json;
 *  2. `npm run build:compras -- <date>` → <path>/src/generated/compras-<date>.json, {parts: [{frames: [{kind}]}]},
 *     one `player` frame per buy, in the export's order;
 *  3. `npm run render:compras -- <date>` → <path>/out/compras-<date>.mp4 (one part) or compras-<date>-p1.mp4, -p2…
 *
 * and copies every MP4 to the public disk at stories/compras-<date>[-pN].mp4.
 */
final class MarketSigningsStoryRenderer
{
    /**
     * @param  array{buys: list<array<string, mixed>>}&array<string, mixed>  $data  The MarketSigningsExport payload.
     * @return list<array{file: string, activity_ids: list<int>}> Every part, in order: its public-disk path and the activities it shows.
     *
     * @throws JsonException
     */
    public function render(string $date, array $data, StoryProgress $progress = new StoryProgress): array
    {
        $projectPath = $this->projectPath();
        $name = "compras-{$date}";

        file_put_contents($this->projectFile((string) config('services.remotion.data_directory'), "{$name}.json"), MarketSigningsExport::toJson($data));

        foreach (glob($this->projectFile((string) config('services.remotion.output_directory'), "{$name}*.mp4")) ?: [] as $staleVideo) {
            unlink($staleVideo);
        }

        $progress->step('Construyendo los fotogramas…');
        $this->runScript((string) config('services.remotion.build_script'), $date, $projectPath, function (string $line) use ($progress): void {
            $progress->note($line);
        });
        $playerFramesPerPart = $this->playerFramesPerPart($name);
        $partCount = count($playerFramesPerPart);
        $buyCount = count($data['buys']);
        $progress->note(($buyCount === 0 ? '0 fichajes (story «Hoy nadie ha fichado»)' : "{$buyCount} ".($buyCount === 1 ? 'fichaje pendiente' : 'fichajes pendientes'))
            ." → {$partCount} ".($partCount === 1 ? 'parte' : 'partes'));

        $progress->step("Renderizando parte 1/{$partCount}…");
        $this->runScript((string) config('services.remotion.render_script'), $date, $projectPath, $this->renderOutputForwarder($name, $partCount, $progress));

        $progress->step('Subiendo a storage…');
        $activityIds = array_map(fn (array $buy): int => (int) $buy['id'], $data['buys']);
        $activityIdsPerPart = $this->activityIdsPerPart($activityIds, $playerFramesPerPart);
        $parts = [];

        foreach (range(1, $partCount) as $part) {
            $fileName = $partCount === 1 ? "{$name}.mp4" : "{$name}-p{$part}.mp4";
            $video = $this->projectFile((string) config('services.remotion.output_directory'), $fileName);

            if (!is_file($video)) {
                throw new RuntimeException("The Remotion render did not produce {$video}.");
            }

            $stream = fopen($video, 'rb');

            if ($stream === false || !Storage::disk('public')->put("stories/{$fileName}", $stream)) {
                throw new RuntimeException("Could not copy {$video} to the public disk.");
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            $progress->note("stories/{$fileName} (".number_format(filesize($video) / 1048576, 1, ',', '').' MB)');
            unlink($video);
            $parts[] = ['file' => "stories/{$fileName}", 'activity_ids' => $activityIdsPerPart[$part - 1]];
        }

        $progress->finish();

        return $parts;
    }

    private function projectPath(): string
    {
        $projectPath = rtrim((string) config('services.remotion.path'), '/\\');

        if ($projectPath === '' || !is_dir($projectPath)) {
            throw new RuntimeException("The Remotion project was not found at «{$projectPath}»: set REMOTION_PROJECT_PATH.");
        }

        return $projectPath;
    }

    private function projectFile(string $directory, string $fileName): string
    {
        return $this->projectPath().'/'.trim($directory, '/\\').'/'.$fileName;
    }

    /**
     * Runs one npm script, handing every non-empty output line (ANSI codes stripped, carriage-return updates split)
     * to $forward as it streams.
     *
     * @param  callable(string): void  $forward
     */
    private function runScript(string $script, string $date, string $projectPath, callable $forward): void
    {
        $pending = '';
        $streamed = false;
        $forwardLines = function (string $buffer, bool $flush = false) use (&$pending, $forward): void {
            $chunks = preg_split('/[\r\n]+/', $pending.$buffer) ?: [];
            $pending = $flush ? '' : (string) array_pop($chunks);

            foreach ($chunks as $chunk) {
                $line = trim((string) preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $chunk));

                if ($line !== '') {
                    $forward($line);
                }
            }
        };

        $result = Process::path($projectPath)
            ->timeout((int) config('services.remotion.timeout'))
            ->env(['REMOTION_CONCURRENCY' => (string) config('services.remotion.concurrency')])
            ->run($this->lowPriority("npm run {$script} -- {$date}"), function (string $type, string $buffer) use (&$streamed, $forwardLines): void {
                $streamed = true;
                $forwardLines($buffer);
            });

        $forwardLines($streamed ? '' : $result->output(), true);

        if ($result->failed()) {
            throw new RuntimeException("`npm run {$script} -- {$date}` failed (exit {$result->exitCode()}):\n".trim($result->errorOutput()."\n".$result->output()));
        }
    }

    /**
     * On the small production server the render shares two cores with syncs that run every 10–20 s, so it runs
     * niced there (REMOTION_NICE=true; Windows has no `nice`).
     */
    private function lowPriority(string $command): string
    {
        return config('services.remotion.nice') ? "nice -n 10 {$command}" : $command;
    }

    /**
     * Turns the Remotion render output into progress: frame counters every 10 %, and a new step when a part's MP4
     * is written. Other lines (size, faststart check…) pass through.
     *
     * @return callable(string): void
     */
    private function renderOutputForwarder(string $name, int $partCount, StoryProgress $progress): callable
    {
        $currentPart = 1;
        $lastDecile = -1;

        return function (string $line) use ($name, $partCount, $progress, &$currentPart, &$lastDecile): void {
            if (preg_match('/(\d+)\s*\/\s*(\d+)/', $line, $frames) === 1 && (int) $frames[2] >= 10 && (int) $frames[1] <= (int) $frames[2]) {
                $decile = intdiv(intdiv(100 * (int) $frames[1], (int) $frames[2]), 10) * 10;

                if ($decile !== $lastDecile) {
                    $lastDecile = $decile;
                    $progress->note("{$line} ({$decile} %)");
                }

                return;
            }

            $progress->note($line);

            if (preg_match('/'.preg_quote($name, '/').'(?:-p(\d+))?\.mp4/', $line, $video) === 1) {
                $finishedPart = isset($video[1]) ? (int) $video[1] : 1;

                if ($finishedPart === $currentPart && $currentPart < $partCount) {
                    $currentPart++;
                    $lastDecile = -1;
                    $progress->step("Renderizando parte {$currentPart}/{$partCount}…");
                }
            }
        };
    }

    /**
     * How many signing screens each part of the built story shows.
     *
     * @return non-empty-list<int>
     *
     * @throws JsonException
     */
    private function playerFramesPerPart(string $name): array
    {
        $specFile = $this->projectFile((string) config('services.remotion.generated_directory'), "{$name}.json");

        if (!is_file($specFile)) {
            throw new RuntimeException("The Remotion build did not produce {$specFile}.");
        }

        $spec = json_decode((string) file_get_contents($specFile), true, 512, JSON_THROW_ON_ERROR);
        $parts = is_array($spec) && is_array($spec['parts'] ?? null) ? array_values($spec['parts']) : [];

        if ($parts === []) {
            throw new RuntimeException("{$specFile} has no parts.");
        }

        return array_map(
            fn (mixed $part): int => count(array_filter(
                is_array($part) && is_array($part['frames'] ?? null) ? $part['frames'] : [],
                fn (mixed $frame): bool => is_array($frame) && ($frame['kind'] ?? null) === 'player',
            )),
            $parts,
        );
    }

    /**
     * Splits the buys (in order) over the parts by their signing screens. If the counts don't add up, every part
     * gets every activity (so every buyer is still mentioned).
     *
     * @param  list<int>  $activityIds
     * @param  non-empty-list<int>  $playerFramesPerPart
     * @return non-empty-list<list<int>>
     */
    private function activityIdsPerPart(array $activityIds, array $playerFramesPerPart): array
    {
        if (array_sum($playerFramesPerPart) !== count($activityIds)) {
            Log::warning('The Remotion parts do not match the signings; every part mentions every buyer.', [
                'signings' => count($activityIds),
                'player_frames_per_part' => $playerFramesPerPart,
            ]);

            return array_map(fn (): array => $activityIds, $playerFramesPerPart);
        }

        $offset = 0;
        $perPart = [];

        foreach ($playerFramesPerPart as $playerFrames) {
            $perPart[] = array_slice($activityIds, $offset, $playerFrames);
            $offset += $playerFrames;
        }

        return $perPart;
    }
}
