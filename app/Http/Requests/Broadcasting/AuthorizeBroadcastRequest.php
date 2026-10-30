<?php

declare(strict_types=1);

/** Validate the single supported private subscription format before native Reverb authorization. */

namespace App\Http\Requests\Broadcasting;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** POST/session/verified/CSRF gates are on the route; unknown channel families and JSONP are refused here. */
final class AuthorizeBroadcastRequest extends FormRequest
{
    /** @return bool Whether the route resolved an application identity through the web session guard. */
    public function authorize(): bool
    {
        return $this->user('web') instanceof User;
    }

    /** @return array<string, mixed> Bounded Pusher socket identity, canonical team channel and no JSONP callback. */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/\A[0-9]{1,20}\.[0-9]{1,20}\z/'],
            'channel_name' => ['required', 'string', 'regex:/\Aprivate-team\.[1-9][0-9]{0,18}\z/'],
            'callback' => ['prohibited'],
        ];
    }
}
