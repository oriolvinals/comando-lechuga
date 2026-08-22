<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('renders the home page', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('home'));
});
