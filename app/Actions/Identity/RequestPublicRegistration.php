<?php

declare(strict_types=1);

/** Resolve valid public registration without returning an identity or revealing whether it existed. */

namespace App\Actions\Identity;

use App\Models\User;
use App\Services\Identity\RegistrationMessages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** Always attempt atomic creation; PostgreSQL settles both pre-existing and concurrent email conflicts. */
final readonly class RequestPublicRegistration
{
    /**
     * @param RegisterUser $register Existing aggregate creator and after-commit registration lifecycle.
     * @param RegistrationMessages $messages Rate-bounded, post-commit existing-owner guidance.
     */
    public function __construct(private RegisterUser $register, private RegistrationMessages $messages)
    {
    }

    /**
     * @param string $name Validated display name, never copied into existing-account notices.
     * @param string $email Validated mailbox, normalized before persistence and conflict lookup.
     * @param string $password Plaintext credential; hashing is performed for every attempted creation.
     * @param string $teamName Validated initial-team name, used only for genuinely new accounts.
     * @throws UniqueConstraintViolationException An unrelated unique constraint failed.
     */
    public function __invoke(string $name, string $email, #[\SensitiveParameter] string $password, string $teamName): void
    {
        $email = Str::lower(trim($email));
        try {
            ($this->register)($name, $email, $password, $teamName);
        } catch (UniqueConstraintViolationException $error) {
            if (!in_array($error->index, ['users_email_unique', 'users_email_lower_unique'], true)) {
                throw $error;
            }

            // The creator has rolled back its failed transaction/savepoint before this read or notification.
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($user !== null) {
                $this->messages->existing($user);
            }
        }
    }
}
