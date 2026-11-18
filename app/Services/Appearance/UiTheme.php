<?php

/** Resolve the account theme independently of tenant and published status-page appearance. */

namespace App\Services\Appearance;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** Closed theme values and persistent browser preference shared by HTML and JSON responses. */
final class UiTheme
{
    /** Browser preference remains available after session logout. */
    public const COOKIE = 'signalbox_theme';

    /** @return list<string> Accepted appearance values. */
    public static function values(): array
    {
        return array_column(\App\Enums\Appearance\UiThemeMode::cases(), 'value');
    }

    /**
     * @param Request $request Current primary-origin request.
     * @return string Account choice, anonymous cookie, or dark.
     */
    public function resolve(Request $request): string
    {
        $actor = $request->user();
        $value = $actor instanceof User ? $actor->theme_preference : $request->cookie(self::COOKIE);

        return in_array($value, self::values(), true) ? $value : 'dark';
    }

    /**
     * @param string $theme Validated preference.
     * @param Request $request Determines transport security.
     * @return Cookie Encrypted by Laravel's cookie middleware.
     */
    public function cookie(string $theme, Request $request): Cookie
    {
        return cookie(self::COOKIE, $theme, 525600, '/', null, $request->isSecure() || app()->environment('production'), true, false, 'lax');
    }
}
