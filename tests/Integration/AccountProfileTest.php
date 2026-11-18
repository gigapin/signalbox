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
