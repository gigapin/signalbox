<?php

/**
 * Define the baseline authenticatable user before team membership is added in M1.
 */

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Store user identity while hiding authentication secrets from serialization.
 *
 * @property int $id Unique user identifier.
 * @property string $name Display name.
 * @property string $email Unique authentication email address.
 * @property string $password Hashed authentication password.
 * @property string|null $remember_token Optional persistent-session token.
 * @property \Illuminate\Support\Carbon|null $email_verified_at Email verification timestamp.
 * @property \Illuminate\Support\Carbon|null $created_at Creation timestamp in UTC.
 * @property \Illuminate\Support\Carbon|null $updated_at Last modification timestamp in UTC.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> Generate synthetic users through the dedicated factory. */
    use HasFactory;

    use Notifiable;

    /**
     * Cast timestamps and hash passwords assigned through the model.
     *
     * @return array<string, string> Attribute names mapped to framework cast types.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
