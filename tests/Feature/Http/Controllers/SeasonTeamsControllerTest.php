<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the season teams index page', function (): void {
    $response = $this->get(route('season-teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('season-teams/index'));
});
