<?php

/** Declare independent expected access for every currently available private team route. */

namespace Tests\Support;

/** Keep expected permissions explicit rather than deriving test outcomes from production policies. */
final class TeamRouteMatrix
{
    /** @var array<string, array{method: string, status: int, roles: list<string>}> Route contract and successful actor roles; owner fixtures are primary owners. */
    public const array PRIVATE_ROUTES = [
        'teams.show' => ['method' => 'GET', 'status' => 200, 'roles' => ['owner', 'admin', 'member', 'viewer']],
        'teams.select' => ['method' => 'POST', 'status' => 302, 'roles' => ['owner', 'admin', 'member', 'viewer']],
        'teams.members.update' => ['method' => 'PATCH', 'status' => 302, 'roles' => ['owner', 'admin']],
        'teams.members.destroy' => ['method' => 'DELETE', 'status' => 302, 'roles' => ['owner', 'admin']],
        'teams.ownership' => ['method' => 'GET', 'status' => 200, 'roles' => ['owner']],
        'teams.ownership.store' => ['method' => 'POST', 'status' => 302, 'roles' => ['owner']],
        'teams.invitations.store' => ['method' => 'POST', 'status' => 302, 'roles' => ['owner', 'admin']],
        'teams.invitations.resend' => ['method' => 'POST', 'status' => 302, 'roles' => ['owner', 'admin']],
        'teams.invitations.destroy' => ['method' => 'DELETE', 'status' => 302, 'roles' => ['owner', 'admin']],
    ];

    /** @var array<string, string> Cross-team listing and capability/account entrypoints intentionally outside route-team context. */
    public const array OTHER_ROUTES = [
        'teams.index' => 'GET',
        'invitations.open' => 'GET',
        'invitations.review' => 'GET',
        'invitations.switch-account' => 'POST',
        'invitations.register' => 'GET',
        'invitations.register.store' => 'POST',
        'invitations.accept' => 'POST',
        'account' => 'GET',
        'account.security' => 'GET',
    ];

    /** @return array<string, array{string, string, string}> Route/role/access scenarios with readable Pest dataset names. */
    public static function accessCases(): array
    {
        $cases = [];

        foreach (array_keys(self::PRIVATE_ROUTES) as $route) {
            foreach (['owner', 'admin', 'member', 'viewer'] as $role) {
                foreach (['member', 'foreign', 'revoked'] as $access) {
                    $cases[$route.' '.$role.' '.$access] = [$route, $role, $access];
                }
            }
        }

        return $cases;
    }

    /** @return array<string, array{string, string}> Every private route tested against both authentication gates. */
    public static function authenticationCases(): array
    {
        $cases = [];

        foreach (array_keys(self::PRIVATE_ROUTES) as $route) {
            foreach (['guest', 'unverified'] as $identity) {
                $cases[$route.' '.$identity] = [$route, $identity];
            }
        }

        return $cases;
    }

    /** @return array<string, array{string, string, string}> Full actor/target hierarchy for member transitions and invitation lifecycles. */
    public static function roleCases(): array
    {
        $cases = [];

        foreach (['teams.members.update', 'teams.members.destroy', 'teams.invitations.store', 'teams.invitations.resend', 'teams.invitations.destroy'] as $route) {
            foreach (['owner', 'admin', 'member', 'viewer'] as $actor) {
                foreach (['owner', 'admin', 'member', 'viewer'] as $target) {
                    $cases[$route.' actor '.$actor.' target '.$target] = [$route, $actor, $target];
                }
            }
        }

        return $cases;
    }

}
