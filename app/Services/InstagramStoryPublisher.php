<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Integrations\Instagram\InstagramConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

/**
 * Publishes one video story: STORIES container (with mentions) → wait until FINISHED → media_publish.
 */
final class InstagramStoryPublisher
{
    public const int POLL_INTERVAL_SECONDS = 5;

    public const int MAX_POLLS = 60;

    public function __construct(private readonly InstagramConnector $connector) {}

    /**
     * @param  list<string>  $mentionedUsernames
     * @return string The published story's media id.
     *
     * @throws InstagramApiException
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function publish(string $videoUrl, array $mentionedUsernames, StoryProgress $progress = new StoryProgress): string
    {
        $progress->step('Creando contenedor en Instagram…');
        $containerId = $this->createContainer($videoUrl, array_values(array_unique($mentionedUsernames)), $progress);
        $progress->step('Esperando a Instagram…');
        $this->waitUntilFinished($containerId, $progress);

        $progress->step('Publicando en Instagram…');
        $response = $this->connector->publishMedia($containerId);
        $progress->finish();

        if ($response->failed() || !is_scalar($response->json('id'))) {
            throw InstagramApiException::fromResponse($response, 'publish the story');
        }

        return (string) $response->json('id');
    }

    /**
     * Instagram rejects the whole container when it can't mention an account (a private one, a typo…): retry without
     * the rejected mention (or, if the error doesn't say which one, without any) and log it.
     *
     * @param  list<string>  $mentionedUsernames
     */
    private function createContainer(string $videoUrl, array $mentionedUsernames, StoryProgress $progress): string
    {
        $progress->note($mentionedUsernames === [] ? 'Sin menciones' : 'Menciones: @'.implode(', @', $mentionedUsernames));

        while (true) {
            $response = $this->connector->createStoryContainer($videoUrl, $mentionedUsernames);

            if ($response->successful() && is_scalar($response->json('id'))) {
                return (string) $response->json('id');
            }

            $exception = InstagramApiException::fromResponse($response, 'create the story container');

            if ($mentionedUsernames === [] || $exception->isExpiredToken() || $response->serverError()) {
                throw $exception;
            }

            $rejected = array_values(array_filter(
                $mentionedUsernames,
                fn (string $username): bool => stripos($exception->getMessage(), $username) !== false,
            ));
            $rejected = $rejected !== [] ? $rejected : $mentionedUsernames;
            $mentionedUsernames = array_values(array_diff($mentionedUsernames, $rejected));

            Log::warning('Instagram rejected story mentions; retrying without them.', [
                'rejected' => $rejected,
                'error' => $exception->getMessage(),
            ]);
            $progress->note('Instagram rechaza la mención de @'.implode(', @', $rejected).'; reintentando sin ella');
        }
    }

    private function waitUntilFinished(string $containerId, StoryProgress $progress): void
    {
        for ($poll = 1; $poll <= self::MAX_POLLS; $poll++) {
            $response = $this->connector->getContainerStatus($containerId);

            if ($response->failed()) {
                throw InstagramApiException::fromResponse($response, 'read the story container status');
            }

            $statusCode = (string) $response->json('status_code');

            if ($statusCode === 'FINISHED') {
                return;
            }

            if (in_array($statusCode, ['ERROR', 'EXPIRED'], true)) {
                throw new InstagramApiException("Instagram could not process the story video (container {$containerId}: {$statusCode}): ".(string) $response->json('status'));
            }

            $progress->note('Esperando a Instagram ('.($statusCode !== '' ? $statusCode : 'sin estado').', '.($poll * self::POLL_INTERVAL_SECONDS).' s)…');
            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }

        throw new InstagramApiException("Instagram did not finish processing the story video (container {$containerId}) within ".(self::MAX_POLLS * self::POLL_INTERVAL_SECONDS).' s.');
    }
}
