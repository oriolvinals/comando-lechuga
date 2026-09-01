<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApiDocsController extends Controller
{
    /**
     * Serves the public API reference as plain text so it renders directly
     * in a browser (or a fetch by the AI agent it's written for) instead of
     * triggering a download — the production web server has no mime type
     * registered for `.md`, so letting it serve the file as a static asset
     * sends `application/octet-stream`.
     */
    public function show(): BinaryFileResponse
    {
        return response()->file(resource_path('docs/api-docs.md'), [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
