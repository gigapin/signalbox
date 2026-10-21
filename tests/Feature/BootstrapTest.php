<?php

/**
 * Verify the initial HTML shell, Inertia protocol and framework health route.
 */

use Inertia\Testing\AssertableInertia;

it(
    'renders the public HTML shell without database or Redis access',
    /** Verify the mount element, product identity and document metadata. */
    function (): void {
        $this->withoutVite()
            ->get(route('home'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('name="viewport"', false)
            ->assertSee('data-page=', false)
            ->assertSee('Signalbox')
            ->assertInertia(
                /**
                 * Verify that the initial document names an existing React page.
                 *
                 * @param AssertableInertia $page Server-rendered Inertia page assertion builder.
                 */
                fn (AssertableInertia $page): AssertableInertia => $page
                    ->component('Home')
                    ->where('appName', 'Signalbox'),
            );
    },
);

it(
    'returns the Inertia page and only public application props',
    /** Verify the client protocol without disclosing account or operational data. */
    function (): void {
        /** @var array{version: string|null} $initialPage Initial document's asset version and protocol state. */
        $initialPage = $this->withoutVite()->get(route('home'))->viewData('page');

        $this->get(route('home'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $initialPage['version'] ?? '',
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Home')
            ->assertJsonPath('props.appName', 'Signalbox')
            ->assertJsonPath('props.auth.user', null)
            ->assertJsonMissingPath('props.monitors')
            ->assertJsonMissingPath('props.appKey');
    },
);

it(
    'boots the framework health route without infrastructure dependencies',
    /** Verify application liveness; database and queue readiness are added in M11. */
    function (): void {
        $this->get('/up')->assertOk();
    },
);

it(
    'returns a genuine not-found response for unknown routes',
    /** Prevent the application shell from masking missing endpoints. */
    function (): void {
        $this->get('/missing-bootstrap-route')->assertNotFound();
    },
);
