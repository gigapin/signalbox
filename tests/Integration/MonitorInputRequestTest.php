<?php

declare(strict_types=1);

/** Exercise actual Form Requests, global string middleware, authorization and error privacy on PostgreSQL. */

use App\Data\Monitoring\ValidatedMonitorInput;
use App\Exceptions\MissingTenantContext;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\MonitorInputValidator;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorInputFixture;
use Tests\Support\MonitorRequestFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->captured = null;
    $this->patch = null;
    MonitorRequestFixture::routes(function (ValidatedMonitorInput $result, mixed $patch): void {
        $this->captured = $result;
        $this->patch = $patch;
    });
    $this->team = Team::factory()->create();
    $this->monitor = app(TenantContext::class)->run($this->team->id, fn () => Monitor::factory()->keyword()->create());
});

it('uses the real create adapter for every configuration without persisting or returning sensitive values', function (array $payload): void {
    $team = $this->team;
    $before = DB::table('monitors')->get()->toJson();
    $this->actingAs($team->owner)->postJson("/teams/{$team->id}/monitors", $payload)->assertNoContent();
    expect($this->captured)->toBeInstanceOf(ValidatedMonitorInput::class)
        ->and($this->captured->data->configuration->type()->value)->toBe($payload['type'])
        ->and($this->patch)->toBe($this->captured->patch->values)
        ->and(DB::table('monitors')->get()->toJson())->toBe($before);
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
})->with(MonitorInputFixture::cases());

it('preserves whitespace empty header values and empty bodies through the global middleware', function (): void {
    $payload = [...MonitorInputFixture::cases()['keyword'][0], 'body' => '', 'expected_keyword' => ' '];
    $this->actingAs($this->team->owner)->postJson("/teams/{$this->team->id}/monitors", $payload)->assertNoContent();
    $candidate = $this->captured->data->candidate();
    expect($candidate['body'])->toBe('')->and($candidate['expected_keyword'])->toBe(' ')
        ->and($candidate['headers']['authorization'])->toBe(' Bearer synthetic-secret ')
        ->and($candidate['headers']['x.probe'])->toBe('')
        ->and($candidate['name'])->toBe('Synthetic input');
});

it('returns safe JSON field errors without echoing credentials or changing persistence', function (): void {
    $before = DB::table('monitors')->get()->toJson();
    $response = $this->actingAs($this->team->owner)->postJson("/teams/{$this->team->id}/monitors", [
        ...MonitorInputFixture::http(), 'headers' => ['Host' => 'synthetic-secret'],
    ]);
    $response->assertUnprocessable()->assertJsonValidationErrors('headers')->assertDontSee('synthetic-secret', false);
    expect(DB::table('monitors')->get()->toJson())->toBe($before)->and($this->captured)->toBeNull();
});

it('rejects blocked destinations with a generic JSON error and no persistence', function (): void {
    $before = DB::table('monitors')->get()->toJson();
    $response = $this->actingAs($this->team->owner)->postJson("/teams/{$this->team->id}/monitors", [
        ...MonitorInputFixture::http(), 'url' => 'http://169.254.169.254/latest/meta-data/?token=synthetic-secret',
    ]);
    $response->assertUnprocessable()->assertJsonValidationErrors('url')
        ->assertDontSee('169.254', false)->assertDontSee('synthetic-secret', false);
    expect(DB::table('monitors')->get()->toJson())->toBe($before)->and($this->captured)->toBeNull();
});

it('flashes errors but no original monitor input for web validation failures', function (): void {
    $this->actingAs($this->team->owner)->from('/account')
        ->post("/teams/{$this->team->id}/monitors", [...MonitorInputFixture::http(), 'unexpected' => 'synthetic-secret'])
        ->assertRedirect('/account')->assertSessionHasErrors('unexpected')->assertSessionHas('_old_input', []);
    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret');
});

it('denies viewers through the monitor policy for both create and update', function (string $operation): void {
    $viewer = Membership::factory()->for($this->team)->viewer()->create()->user;
    $url = "/teams/{$this->team->id}/monitors";
    $method = 'postJson';

    if ($operation === 'update') {
        $url .= '/'.$this->monitor->id;
        $method = 'patchJson';
    }

    $this->actingAs($viewer)->{$method}($url, MonitorInputFixture::http())->assertForbidden();
    expect($this->captured)->toBeNull();
})->with(['create', 'update']);

it('checks update authority before decrypting current secrets', function (): void {
    DB::table('monitors')->where('id', $this->monitor->id)->update(['url' => 'invalid-ciphertext']);
    $viewer = Membership::factory()->for($this->team)->viewer()->create()->user;
    $this->actingAs($viewer)->patchJson("/teams/{$this->team->id}/monitors/{$this->monitor->id}", ['name' => 'Rename'])->assertForbidden();
    expect($this->captured)->toBeNull();
});

it('merges authorized partial updates without exposing omitted secrets or persisting a stale snapshot', function (string $method): void {
    $before = DB::table('monitors')->get()->toJson();
    $this->actingAs($this->team->owner)->{$method}("/teams/{$this->team->id}/monitors/{$this->monitor->id}", ['name' => ' Renamed '])->assertNoContent();
    expect($this->patch)->toBe(['name' => 'Renamed'])
        ->and($this->captured->data->candidate()['url'])->toBe('https://example.invalid/health')
        ->and(DB::table('monitors')->get()->toJson())->toBe($before);
})->with(['patchJson', 'putJson']);

it('preserves explicit null and replacement arrays on authorized updates', function (): void {
    $this->actingAs($this->team->owner)->patchJson("/teams/{$this->team->id}/monitors/{$this->monitor->id}", ['body' => null, 'headers' => []])->assertNoContent();
    expect($this->patch)->toBe(['headers' => [], 'body' => null]);
});

it('rejects foreign bindings even for a managing owner', function (): void {
    $other = Team::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn () => Monitor::factory()->create());
    $this->actingAs($this->team->owner)->patchJson("/teams/{$this->team->id}/monitors/{$foreign->id}", ['name' => 'forged'])->assertNotFound();
    expect($this->captured)->toBeNull();
});

it('gates monitor request fixtures behind authentication verification and current membership', function (string $state): void {

    if ($state !== 'guest') {
        $actor = $this->team->owner;

        if ($state === 'unverified') {
            $actor->forceFill(['email_verified_at' => null])->save();
        }

        if ($state === 'revoked') {
            $this->team->users()->detach($actor->id);
        }

        $this->actingAs($actor);
    }

    $response = $this->post("/teams/{$this->team->id}/monitors", MonitorInputFixture::http());

    if ($state === 'revoked') {
        $response->assertNotFound();
    } else {
        $response->assertRedirect($state === 'guest' ? '/login' : '/email/verify');
    }

    expect($this->captured)->toBeNull();
})->with(['guest', 'unverified', 'revoked']);

it('leaves ordinary authentication normalization unchanged', function (): void {
    $this->post('/register', ['name' => '   ', 'team_name' => '   ', 'email' => '  ', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors(['name', 'team_name', 'email', 'password']);
});


it('never echoes or flashes decrypted existing secrets when a different update field fails', function (string $transport): void {
    $configuration = app(MonitorInputValidator::class)->validate(MonitorInputFixture::http())->data->configuration;
    app(TenantContext::class)->run($this->team->id, function () use ($configuration): void {
        $this->monitor->applyConfiguration($configuration);
        $this->monitor->save();
    });
    $before = DB::table('monitors')->get()->toJson();
    $this->actingAs($this->team->owner);
    $url = "/teams/{$this->team->id}/monitors/{$this->monitor->id}";

    if ($transport === 'json') {
        $this->patchJson($url, ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name')->assertDontSee('synthetic-secret', false);
    } else {
        $this->from('/account')->patch($url, ['name' => ''])->assertRedirect('/account')->assertSessionHasErrors('name')->assertSessionHas('_old_input', []);
        expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret');
    }

    expect(DB::table('monitors')->get()->toJson())->toBe($before);
})->with(['json', 'web']);

it('preserves monitor bytes for alternate numeric route representations too', function (): void {
    $this->actingAs($this->team->owner)->postJson('/teams/00'.$this->team->id.'/monitors', [...MonitorInputFixture::cases()['keyword'][0], 'expected_keyword' => ' '])->assertNoContent();
    expect($this->captured->data->candidate()['expected_keyword'])->toBe(' ');
});
