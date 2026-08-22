<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the activity index page', function (): void {
    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('activity/index'));
});
