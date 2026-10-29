<?php

declare(strict_types=1);

/** Accept only a dedicated Authorization credential; request payload and query parameters grant no authority. */

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;

/** Bearer validation produces the same generic controller response for every missing or malformed credential. */
final class HeartbeatRequest extends FormRequest
{
    /** @return bool Credential authorization occurs inside the locked receiving action, independently of web users. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> No payload fields are accepted as receipt times or tenant identifiers. */
    public function rules(): array
    {
        return [];
    }

    /** @return string|null Strict dedicated token from a single Bearer header, never from URL or body. */
    public function credential(): ?string
    {
        $header = $this->header('Authorization', '');

        return preg_match('/\A(?i:Bearer) ([a-f0-9]{64})\z/D', $header, $matches) === 1 ? $matches[1] : null;
    }
}
