<?php

/** Verify private profile ownership, optional data and one-use email verification. */
use App\Models\User;
use App\Services\Account\AccountProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL.');
    }
    $this->withoutVite();
    Storage::fake('local');
    Mail::fake();
});
it('saves optional data only for the verified owner and keeps string identifiers', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user)->post('/account/profile', ['email' => $user->email,'first_name' => 'Giga','last_name' => 'Prog','phone' => '+39 0123','vat_number' => '00123','country' => 'IT','user_id' => $other->id])->assertRedirect('/account');
    expect($user->fresh()->name)->toBe('Giga Prog')->and($user->fresh()->vat_number)->toBe('00123')->and($other->fresh()->first_name)->toBeNull();
    $this->post('/account/profile', ['email' => $user->email])->assertRedirect('/account');
    expect($user->fresh()->name)->toBe('Account');
});
it('validates required email country and image content', function (): void {
    $this->actingAs(User::factory()->create())->post('/account/profile', ['email' => '','country' => 'XX','avatar' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')])->assertSessionHasErrors(['email','country','avatar']);
});
it('serves only the owners private image and removes it explicitly', function (): void {
    $user = User::factory()->create();
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $this->actingAs($user)->post('/account/profile', ['email' => $user->email,'avatar' => UploadedFile::fake()->createWithContent('avatar.png', $png)])->assertSessionHasNoErrors();
    $path = $user->fresh()->avatar_path;
    expect($path)->not->toBeNull();
    $this->get('/account/avatar')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(User::factory()->create())->get('/account/avatar')->assertNotFound();
    $this->actingAs($user->fresh())->post('/account/profile', ['email' => $user->email,'remove_avatar' => true])->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($path);
});
it('keeps the old login active until explicit signed confirmation and consumes the capability', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/account/profile', ['email' => 'new@example.test'])->assertSessionHasErrors('current_password');
    $this->post('/account/profile', ['email' => 'new@example.test','current_password' => 'password'])->assertSessionHasNoErrors();
    expect($user->fresh()->email)->toBe($user->email)->and($user->fresh()->pending_email)->toBe('new@example.test');
    $profiles = app(AccountProfile::class);
    $this->travel(61)->seconds();
    // Obtain a known capability without relying on an external mail server.
    $token = str_repeat('a', 64);
    $stored = $user->fresh();
    $stored->email_change_token_hash = hash('sha256', $token);
    $stored->save();
    $url = URL::temporarySignedRoute('account.email.review', now()->addHour(), ['token' => $token]);
    $this->get($url)->assertOk();
    expect($user->fresh()->email)->toBe($user->email);
    $this->post($url)->assertRedirect('/account');
    expect($user->fresh()->email)->toBe('new@example.test')->and($user->fresh()->pending_email)->toBeNull();
    $this->post($url)->assertForbidden();
});

it('retains the send cooldown after cancelling and rejects expired capabilities', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/account/profile', ['email' => 'first@example.test','current_password' => 'password'])->assertSessionHasNoErrors();
    $this->delete('/account/email-change')->assertRedirect('/account');
    $this->post('/account/profile', ['email' => 'second@example.test','current_password' => 'password'])->assertSessionHasErrors('email');
    $this->travel(61)->seconds();
    $this->post('/account/profile', ['email' => 'second@example.test','current_password' => 'password'])->assertSessionHasNoErrors();
    $token = str_repeat('b', 64);
    $stored = $user->fresh();
    $stored->email_change_token_hash = hash('sha256', $token);
    $stored->email_change_expires_at = now();
    $stored->save();
    expect(app(AccountProfile::class)->valid($stored, $token))->toBeFalse();
});

it('rejects guest and unverified profile mutations', function (): void {
    $this->post('/account/profile', ['email' => 'guest@example.test'])->assertRedirect('/login');
    $this->actingAs(User::factory()->unverified()->create())->post('/account/profile', ['email' => 'guest@example.test'])->assertRedirect('/email/verify');
});

/**
 * Build a real compressed RGB PNG without requiring GD in the test container.
 *
 * @param int $width Image width in pixels.
 * @param int $height Image height in pixels.
 * @return UploadedFile Raster whose actual dimensions exercise the server validator.
 */
function profileDimensionImage(int $width, int $height): UploadedFile
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $rows = str_repeat("\0".str_repeat("\0", $width * 3), $height);
    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress($rows))
        .$chunk('IEND', '');

    return UploadedFile::fake()->createWithContent('dimensions.png', $png);
}

it('accepts avatar dimensions at the 512 pixel boundary', function (int $width, int $height): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/account/profile', [
        'email' => $user->email,
        'avatar' => profileDimensionImage($width, $height),
    ])->assertSessionHasNoErrors()->assertRedirect('/account');

    expect($user->fresh()->avatar_path)->not->toBeNull();
})->with([[512, 512], [512, 1], [1, 512]]);

it('rejects oversized avatar dimensions before saving fields or files', function (int $width, int $height): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/account/profile', [
        'email' => $user->email,
        'first_name' => 'Unsaved change',
        'avatar' => profileDimensionImage($width, $height),
    ])->assertSessionHasErrors('avatar');

    expect($user->fresh()->avatar_path)->toBeNull()
        ->and($user->fresh()->first_name)->toBeNull()
        ->and(Storage::disk('local')->allFiles('account-avatars'))->toBe([]);
})->with([[513, 512], [512, 513], [513, 513], [4096, 1], [1, 4096]]);

it('seeds separate synthetic profile names and preserves customized profiles on reruns', function (): void {
    $legacy = User::factory()->create(['email' => 'owner@signalbox.test', 'name' => 'Synthetic Owner', 'first_name' => 'Synthetic Owner']);
    $this->seed(\Database\Seeders\IdentitySeeder::class);
    expect($legacy->fresh()->first_name)->toBe('Synthetic')->and($legacy->fresh()->last_name)->toBe('Owner')->and($legacy->fresh()->name)->toBe('Synthetic Owner');
    $this->actingAs($legacy->refresh())->get('/account')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->where('profile.first_name', 'Synthetic')->where('profile.last_name', 'Owner'));
    $legacy->forceFill(['first_name' => 'Ada', 'last_name' => 'Lovelace'])->save();
    $this->seed(\Database\Seeders\IdentitySeeder::class);
    expect($legacy->fresh()->first_name)->toBe('Ada')->and($legacy->fresh()->last_name)->toBe('Lovelace');
    expect(User::query()->where('email', 'admin@signalbox.test')->sole()->first_name)->toBe('Synthetic');
});
