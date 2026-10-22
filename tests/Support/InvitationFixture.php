<?php

/** Extract invitation links from fake mail without making a network request. */

namespace Tests\Support;

use App\Actions\Identity\IssueTeamInvitation;
use App\Enums\Identity\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/** Exercise production issuance while keeping raw test tokens confined to assertions. */
final class InvitationFixture
{
    /**
     * @param Team $team Issuing synthetic team.
     * @param string $email Intended fixture recipient.
     * @param TeamRole $role Offered membership role.
     * @return array{invitation: TeamInvitation, url: string} Persisted invitation and fake-mail bearer URL.
     */
    public static function issue(Team $team, string $email = 'invitee@example.test', TeamRole $role = TeamRole::Member): array
    {
        $owner = $team->owner;

        $invitation = app(IssueTeamInvitation::class)($owner, $team->id, $email, $role);

        $notification = Notification::sent(new AnonymousNotifiable(), TeamInvitationNotification::class)->last();

        return [
            'invitation' => $invitation,
            'url' => $notification->toMail(new AnonymousNotifiable())->actionUrl
        ];
    }
}
