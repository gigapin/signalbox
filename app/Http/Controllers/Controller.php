<?php

/**
 * Define the common extension point for application HTTP controllers.
 */

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/** Keep shared controller behavior separate from domain services. */
abstract class Controller
{
    /**
     * Resolve the authenticated application identity without relying on local type annotations.
     *
     * @param Request $request Request protected by authentication middleware.
     * @return User Current application user.
     * @throws AuthenticationException When no application user is authenticated.
     */
    protected function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new AuthenticationException();
        }

        return $user;
    }
}
