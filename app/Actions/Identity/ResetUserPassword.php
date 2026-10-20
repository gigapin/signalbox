<?php

/** Apply the shared local password policy to Fortify password resets. */

namespace App\Actions\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/** Change only the password; Fortify rotates remember tokens and emits PasswordReset afterward. */
final class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * @param User $user Broker-verified identity.
     * @param array<string, mixed> $input Password and confirmation from the reset request.
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, ['password' => ['required', 'string', 'min:12', 'max:128', 'confirmed']])->validate();
        $user->forceFill(['password' => $input['password']])->save();
    }
}
