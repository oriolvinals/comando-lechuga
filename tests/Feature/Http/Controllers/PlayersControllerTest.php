<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the players index page', function (): void {
    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('players/index'));
});
