<?php

/** Verify Sanctum persistence and Scout queries on real PostgreSQL using rollback-only fixtures. */

use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Fixtures\SearchDocument;

beforeEach(
    /** Isolate all fixtures without resetting application tables or requiring a SQLite fallback. */
    function (): void {
        if (getenv('RUN_DOCKER_TESTS') !== '1') {
            return;
        }

        Route::get(
            '/_test/token',
            /**
             * @param Request $request Request authenticated by a bearer token with the required ability.
             * @return array{id: mixed} Test-only principal identifier.
             */
            fn (Request $request): array => ['id' => $request->user()?->getAuthIdentifier()],
        )->middleware(['auth:sanctum', 'abilities:monitors:read']);
    },
);


it(
    'stores only a token hash and enforces bearer abilities',
    /** Verify the actual HTTP authentication middleware against PostgreSQL token ownership. */
    function (): void {
        /** @var User $user Synthetic token owner inside the test transaction. */
        $user = User::factory()->create();
        /** @var \Laravel\Sanctum\NewAccessToken $token Plaintext credential available only at issuance. */
        $token = $user->createToken('probe', ['monitors:read'], now()->addHour());
        expect(PersonalAccessToken::findToken($token->plainTextToken)?->tokenable_id)->toBe($user->id)
            ->and($token->accessToken->token)->toHaveLength(64)
            ->and($token->accessToken->token)->not->toBe($token->plainTextToken);
        $this->withToken($token->plainTextToken)->getJson('/_test/token')->assertOk()->assertJsonPath('id', $user->id);

        /** @var \Laravel\Sanctum\NewAccessToken $restricted Token lacking the read ability. */
        $restricted = $user->createToken('restricted', ['monitors:write']);
        app(AuthManager::class)->forgetGuards();
        $this->withToken($restricted->plainTextToken)->getJson('/_test/token')->assertForbidden();
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires local PostgreSQL with the Sanctum migration applied.');

it(
    'rejects revoked and explicitly expired bearer tokens',
    /** Recreate guards between requests so authentication cannot reuse a previous principal. */
    function (): void {
        /** @var User $user Synthetic account whose tokens are never committed. */
        $user = User::factory()->create();
        /** @var \Laravel\Sanctum\NewAccessToken $revoked Token removed before its next attempted use. */
        $revoked = $user->createToken('revoked', ['monitors:read']);
        $revoked->accessToken->delete();
        $this->withToken($revoked->plainTextToken)->getJson('/_test/token')->assertUnauthorized();

        /** @var \Laravel\Sanctum\NewAccessToken $expired Token with a per-token deadline already in the past. */
        $expired = $user->createToken('expired', ['monitors:read'], now()->subSecond());
        app(AuthManager::class)->forgetGuards();
        $this->withToken($expired->plainTextToken)->getJson('/_test/token')->assertUnauthorized();
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires local PostgreSQL with the Sanctum migration applied.');

it(
    'expires bearer tokens at the configured lifetime boundary',
    /** Confirm a token works just before its global deadline and fails at that deadline without sleeping. */
    function (): void {
        $this->freezeTime();
        /** @var User $user Disposable token owner. */
        $user = User::factory()->create();
        /** @var \Laravel\Sanctum\NewAccessToken $token Token relying on the global 24-hour lifetime. */
        $token = $user->createToken('boundary', ['monitors:read']);
        $this->travel(1439)->minutes();
        $this->withToken($token->plainTextToken)->getJson('/_test/token')->assertOk();
        $this->travel(1)->minutes();
        app(AuthManager::class)->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/_test/token')->assertUnauthorized();
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires local PostgreSQL with the Sanctum migration applied.');

it(
    'searches PostgreSQL with an explicit owner constraint and returns no unrelated documents',
    /** Exercise the real database engine on temporary synthetic rows; domain tenant policy is not yet implemented. */
    function (): void {
        DB::statement('CREATE TEMPORARY TABLE signalbox_search_documents (id bigint PRIMARY KEY, team_id bigint NOT NULL, title text NOT NULL) ON COMMIT DROP');
        DB::table('signalbox_search_documents')->insert([
            ['id' => 1, 'team_id' => 10, 'title' => 'Signalbox search probe'],
            ['id' => 2, 'team_id' => 20, 'title' => 'Signalbox search probe'],
            ['id' => 3, 'team_id' => 10, 'title' => 'Unrelated document'],
        ]);
        expect(SearchDocument::search('Signalbox')->where('team_id', 10)->get()->modelKeys())->toBe([1]);
        expect(SearchDocument::search('missing')->where('team_id', 10)->get())->toBeEmpty();
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires local PostgreSQL.');
