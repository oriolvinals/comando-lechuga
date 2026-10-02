<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PublishedStoryType;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\PublishedStory;
use App\Services\InstagramStoryPublisher;
use App\Services\MarketSigningsExport;
use App\Services\MarketSigningsStoryRenderer;
use App\Services\StoryProgress;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * «Compras del mercado» Instagram story. Scheduled from 20:05 to 23:05 Madrid every 15 minutes: each run first asks
 * one cheap question (are there unshared signings today?) and stops when there aren't. When there are, it renders
 * them, publishes every part in order (the PublishedStory registry skips parts already out when a batch resumes)
 * and only then marks the activities shared. At 23:05 (or straight away for a past --date), a day without any
 * signing gets the «Hoy nadie ha fichado» story, once.
 */
#[Signature('stories:publish-market-signings
    {--date= : Madrid day (Y-m-d), today by default; a past day skips the 23:05 rule}
    {--dry-run : Export and render only: publish nothing and mark nothing}
    {--force : Ignore the registry of what was already published}')]
#[Description('Render the day\'s market signings and publish them as Instagram stories')]
class PublishMarketSigningsStory extends Command
{
    /** The last scheduled run (Madrid time): only from then on does today, without signings, get its story. */
    public const string LAST_RUN_AT = '23:05';

    private StoryProgress $progress;

    public function handle(
        MarketSigningsExport $export,
        MarketSigningsStoryRenderer $renderer,
        InstagramStoryPublisher $publisher,
    ): int {
        $this->progress = new StoryProgress(function (string $line): void {
            $this->line($line);
        });
        $today = now(MarketSigningsExport::TIMEZONE)->toDateString();

        try {
            $date = $this->resolveDate($today);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $daySignings = $this->daySignings($date);
        $isDayClosed = $date < $today || now(MarketSigningsExport::TIMEZONE)->format('H:i') >= self::LAST_RUN_AT;

        try {
            if ($this->option('force')) {
                $signings = (clone $daySignings)->whereNotNull('player_id')->get();

                if ($signings->isEmpty() && !$isDayClosed) {
                    $this->line("Sin fichajes del {$date} todavía: la story «Hoy nadie ha fichado» espera a las ".self::LAST_RUN_AT.'.');

                    return self::SUCCESS;
                }

                $this->progress->step("Comprobando fichajes del {$date} (--force)…");

                return $this->publishBatch($date, $this->nextBatch($date), $signings, $export, $renderer, $publisher);
            }

            if (!(clone $daySignings)->whereNotNull('player_id')->whereNull('shared_at')->exists()) {
                if ($isDayClosed && !(clone $daySignings)->exists() && !$this->publishedStories($date)->exists()) {
                    $this->progress->step("Comprobando fichajes pendientes del {$date}… ninguno en todo el día.");

                    return $this->publishBatch($date, $this->nextBatch($date), new Collection, $export, $renderer, $publisher);
                }

                $this->line("Sin fichajes pendientes del {$date}: nada que publicar.");
                Log::info("[stories] Sin fichajes pendientes del {$date}: nada que publicar.");

                return self::SUCCESS;
            }

            $this->progress->step("Comprobando fichajes pendientes del {$date}…");
            $unfinished = $this->unfinishedBatch($date);

            if ($unfinished !== null) {
                $this->progress->note("Reanudando el lote {$unfinished->batch} (ya hay partes publicadas).");
                $signings = Activity::query()->whereKey($unfinished->activity_ids)->get();

                return $this->publishBatch($date, $unfinished->batch, $signings, $export, $renderer, $publisher);
            }

            $signings = (clone $daySignings)->whereNotNull('player_id')->whereNull('shared_at')->get();

            return $this->publishBatch($date, $this->nextBatch($date), $signings, $export, $renderer, $publisher);
        } catch (Throwable $exception) {
            $this->progress->finish();
            $this->error($exception->getMessage());
            report($exception);

            return self::FAILURE;
        }
    }

    /**
     * Renders the batch, publishes its parts not yet published (in order) and marks its activities shared once every
     * part is out.
     *
     * @param  Collection<int, Activity>  $signings
     */
    private function publishBatch(
        string $date,
        int $batch,
        Collection $signings,
        MarketSigningsExport $export,
        MarketSigningsStoryRenderer $renderer,
        InstagramStoryPublisher $publisher,
    ): int {
        $signings->load('sourceSeasonManager');

        $this->progress->step('Exportando datos…');
        $data = $export->export($date, $signings);
        $parts = $renderer->render($date, $data, $this->progress);
        $partCount = count($parts);

        if ($this->option('dry-run')) {
            foreach ($parts as $index => $part) {
                $this->progress->note('Parte '.($index + 1)."/{$partCount}: ".Storage::disk('public')->url($part['file']));
            }

            $this->progress->note('Simulación (--dry-run): no se publica ni se marca nada.');

            return self::SUCCESS;
        }

        try {
            $published = $this->option('force')
                ? collect()
                : $this->publishedStories($date)->where('batch', $batch)->get()->keyBy('part');

            if ($published->isNotEmpty() && $published->first()->parts !== $partCount) {
                throw new RuntimeException("El lote {$batch} del {$date} se publicó en {$published->first()->parts} partes pero ahora se renderiza en {$partCount}: publica el resto a mano o usa --force.");
            }

            $usernames = $signings->mapWithKeys(fn (Activity $signing): array => [
                $signing->id => (string) $signing->sourceSeasonManager?->instagram_username,
            ]);

            foreach ($parts as $index => $part) {
                $partNumber = $index + 1;

                if ($published->has($partNumber)) {
                    $this->progress->note("Parte {$partNumber}/{$partCount} ya publicada (media_id {$published->get($partNumber)->media_id}); se salta.");

                    continue;
                }

                $mentions = array_values(array_unique(array_filter(array_map(
                    fn (int $activityId): string => (string) $usernames->get($activityId, ''),
                    $part['activity_ids'],
                ), fn (string $username): bool => $username !== '')));
                $mediaId = $publisher->publish(Storage::disk('public')->url($part['file']), $mentions, $this->progress);

                PublishedStory::query()->create([
                    'date' => $date,
                    'type' => PublishedStoryType::MarketSignings,
                    'batch' => $batch,
                    'part' => $partNumber,
                    'parts' => $partCount,
                    'signings_count' => $signings->count(),
                    'activity_ids' => $signings->modelKeys(),
                    'media_id' => $mediaId,
                    'published_at' => now(),
                ]);

                $this->progress->note("Publicada parte {$partNumber}/{$partCount} · media_id {$mediaId}");
            }
        } finally {
            Storage::disk('public')->delete(array_column($parts, 'file'));
        }

        $marked = Activity::query()->whereKey($signings->modelKeys())->whereNull('shared_at')->update(['shared_at' => now()]);
        $this->progress->note($signings->isEmpty()
            ? "Publicada la story «Hoy nadie ha fichado» del {$date}."
            : "Marcadas {$marked} actividades como compartidas.");
        $this->progress->finish();

        return self::SUCCESS;
    }

    private function resolveDate(string $today): string
    {
        $option = $this->option('date');

        if ($option === null || $option === '') {
            return $today;
        }

        $option = (string) $option;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $option, $parts) !== 1 || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new RuntimeException("--date «{$option}» no es una fecha válida: usa Y-m-d.");
        }

        if ($option > $today) {
            throw new RuntimeException("--date {$option} es futura (hoy es {$today} en Madrid).");
        }

        return $option;
    }

    /**
     * @return Builder<Activity>
     */
    private function daySignings(string $date): Builder
    {
        [$start, $end] = MarketSigningsExport::dayBounds($date);

        return Activity::query()
            ->where('type', SeasonActivityType::Signing)
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end);
    }

    /**
     * @return Builder<PublishedStory>
     */
    private function publishedStories(string $date): Builder
    {
        return PublishedStory::query()
            ->whereDate('date', $date)
            ->where('type', PublishedStoryType::MarketSignings);
    }

    private function nextBatch(string $date): int
    {
        return (int) $this->publishedStories($date)->max('batch') + 1;
    }

    /**
     * The day's latest batch when some of its parts are still unpublished.
     */
    private function unfinishedBatch(string $date): ?PublishedStory
    {
        $latest = $this->publishedStories($date)->orderByDesc('batch')->orderByDesc('part')->first();

        if ($latest === null) {
            return null;
        }

        $publishedParts = $this->publishedStories($date)->where('batch', $latest->batch)->count();

        return $publishedParts < $latest->parts ? $latest : null;
    }
}
