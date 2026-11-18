<?php

/** Save session-owned appearance without accepting user or team identifiers. */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appearance\UpdateThemeRequest;
use App\Models\User;
use App\Services\Appearance\UiTheme;
use Illuminate\Http\JsonResponse;

/** Persist the selected theme and acknowledge the browser preference together. */
final class ThemeController extends Controller
{
    /**
     * @param UpdateThemeRequest $request Validated session choice.
     * @param UiTheme $themes Cookie adapter.
     * @return JsonResponse Confirmed theme and persistent cookie.
     */
    public function __invoke(UpdateThemeRequest $request, UiTheme $themes): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $theme = $request->validated('theme');
        assert(is_string($theme));
        $actor->theme_preference = $theme;
        $actor->save();

        return response()->json(['theme' => $theme])->withCookie($themes->cookie($theme, $request));
    }
}
