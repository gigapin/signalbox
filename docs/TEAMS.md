# Invitations and team membership management

M1.03 adds verified browser flows for invitations, membership management, primary ownership transfer and team switching. It builds on [IDENTITY.md](IDENTITY.md) and [AUTHENTICATION.md](AUTHENTICATION.md). M1.04 adds TeamScope, explicit tenant context and policies for this surface; see [TENANCY.md](TENANCY.md). The routes enforce fresh team membership and the permissions below. See [TASKS.md](TASKS.md) for actual checks and limitations.

## Permissions and ownership

| Actor | Invitation and membership management |
|---|---|
| Owner | Invite all four roles; change/remove other members, subject to primary/last-owner protection. |
| Admin | Invite member/viewer; change roles only when both existing and requested roles are member/viewer; remove member/viewer. |
| Member / viewer | View their team's member directory and select an accessible team; no invitation-management data or management permissions. |
| Any member | Leave voluntarily, subject to ownership invariants. |
| Primary owner | Transfer primary ownership to another existing owner after recent password confirmation. |

Multiple owners remain supported. `teams.owner_id` identifies the primary owner, who cannot leave, be removed or be demoted until ownership is explicitly transferred. Transfers do not promote a nonowner and do not remove/demote the previous primary owner. Losing the last owner is rejected. An admin's own departure is permitted but self-promotion is not. These are domain action checks, not merely hidden UI controls.

`TeamController` separates team, member and invitation projections into documented methods. The common HTTP controller resolves a real authenticated User with an explicit type check rather than local PHPDoc assertions. `TeamAccess` centralizes the membership hierarchy; `ManageTeamMembers`, `SwitchTeam`, `IssueTeamInvitation` and `AcceptTeamInvitation` recheck fresh state inside database transactions. All existing-team mutations lock the Team row first. Acceptance, switching and removal then lock the affected User row, so a concurrent revocation cannot leave a newly selected inaccessible team. Existing `CreateTeam` locks its user and creates a new aggregate rather than acquiring an existing-team lock. Direct pivot writes are trusted fixture/persistence operations; they must not be used as production alternatives to these actions.

Composite membership keys are always explicit in standalone update/delete queries; a Pivot loaded outside a belongsToMany relation does not carry that relation's key metadata. No speculative repository or generic scope bypass is added. TeamPolicy and TeamInvitationPolicy reuse these rules in M1.04; action checks remain authoritative under the existing locks.

## Invitation lifecycle and storage

The incremental `2026_10_21_000005` migration creates `team_invitations` with team, nullable sender, normalized email, enum role, SHA-256 token digest, exclusive `expires_at`, nullable acceptance/revocation timestamps and audit timestamps. PostgreSQL enforces unique `(team_id, email)`, role vocabulary, normalized email and mutually exclusive accepted/revoked states. Team deletion cascades invitations; sender deletion only nulls provenance. Earlier users and teams are unchanged.

One lifecycle row is retained per team/email, including expired or revoked invitations. A new pending duplicate is rejected with an instruction to resend. A fresh explicit invitation may renew an expired/revoked lifecycle or a used invitation after the member has left. Existing members cannot be reinvited. Resend retains the stored role/email, rotates the capability and starts a new seven-day window; management checks include the existing invitation role, preventing admins from taking over privileged invitations. An accepted invitation cannot be revoked; manage its member instead.

Expiry is exactly seven UTC days after issuance, including across DST boundaries. At `now == expires_at` it is invalid. Tokens use 64 cryptographically random alphanumeric characters; only the digest is persisted and it is hidden from model serialization. Issuance/revocation/acceptance share the Team lock. First acceptance writes membership, acceptance timestamp and selected team atomically. Existing memberships retain their current roles. Replays have no additional effect, do not switch preferences again, and cannot restore a membership removed after acceptance.

Email is sent synchronously after commit, using an English Laravel notification and configured mail transport. It is not queued with a raw token. Rollbacks send nothing. Delivery failure reports that the invitation was saved and asks for a resend; the database lifecycle remains intact. Automated tests fake all notifications. No real mail delivery is claimed for M1.03. Local development may log mail under the existing mailer; invitation mail/logs contain a bearer link and must remain private, like password-reset mail.

## Browser interfaces and continuations

| Method / path | Behavior |
|---|---|
| GET `/teams` | List teams reached through the current user's memberships. |
| GET `/teams/{team}` | Team member directory; invitations only for roles the actor may manage. |
| POST `/teams/{team}/select` | Set only the actor's preference after checking destination membership. |
| PATCH / DELETE `/teams/{team}/members/{member}` | Role transition or removal/self-departure. |
| GET / POST `/teams/{team}/ownership` | Primary-owner transfer form and recent-password-protected transfer. |
| POST `/teams/{team}/invitations` | Issue an invitation with validated email and role. |
| POST `/teams/{team}/invitations/{invitation}/resend` | Rotate a team-local invitation capability. |
| DELETE `/teams/{team}/invitations/{invitation}` | Revoke a team-local, unaccepted invitation. |
| GET `/invitations/{invitation}/{token}` | Prove a bearer capability, store ID/digest in session, redirect to a nonsecret URL. |
| GET / POST `/invitations/accept` | Review for guests/unverified users; POST requires a verified matching identity. |
| GET / POST `/invitations/register` | Guest registration from a valid pending invitation, without a personal team. |
| POST `/invitations/switch-account` | Logout and regenerate the session/CSRF token, retaining only a freshly valid invitation continuation. |

Team IDs are explicit route arguments, not automatically authorized model bindings. Foreign teams and team-local IDs outside the actor's membership return 404; insufficient hierarchy on an accessible team returns 403; domain/input conflicts use validation errors. All writes retain standard CSRF protection. Invitation issuance/resend is limited to 10 requests/minute, original-link visits and acceptance to 30/minute, with the framework's authenticated-user/IP key; invited registration uses the existing route/IP public-auth limit of five/minute. No member quotas are invented from monitor-plan limits.

All these responses are private/no-store and use `Referrer-Policy: no-referrer`. Raw tokens and digests are absent from page props; pending continuation is server-owned session state, never a submitted invitation/team/role authority. Opening a new link replaces any old continuation. Expired, rotated or revoked continuations display an unavailable-link state without invitation metadata. Normal logout clears continuation; the explicit invitation account-switch action retains a freshly validated capability while discarding identity privileges. Shared auth props remain name/email only.

Ordinary registration still creates its first team. Invited registration uses a separate action and Form Request: name/email/password are validated with the existing credential policy, the email must match the proved invitation, and no team, membership or trial is created. It sends normal verification after account commit. Verification and login/2FA preserve the review destination, but never accept the invitation automatically. An account created from an invitation that later expires remains a valid teamless account and needs a new link. `/account` offers a review link for a pending invitation; it never falls back to an unrelated team.

The ownership GET establishes a server-selected return team before password confirmation. The response adapter only returns to that internal ownership page while the actor is still its primary owner and member; otherwise it returns to account security. Arbitrary intended URLs and sensitive JSON endpoints are not accepted. Transfer POSTs independently retain the standard recent-password gate. Removal clears only a preference pointing at the removed team; another accessible preference is preserved and no replacement is automatically selected.

## Verification and local rollout

`TeamMembershipTest` exercises the real HTTP/actions and PostgreSQL, including role hierarchy, foreign identifiers, stale memberships, signed email verification, 2FA continuation, account switching, invitation rotation/revocation, expiry boundaries, rollback, delivery failures, primary-owner constraints and preference removal. Five two-connection probes contend acceptance/acceptance, role/transfer, switch/remove, remove/switch and transfer/remove with a bounded database lock timeout and explicit committed-fixture cleanup. They demonstrate real locking, not parallel-suite or load-test support.

`Teams.test.tsx` mounts actual Inertia forms and intercepts only transport: loading/errors, team switching, invitation submission/acceptance, invited registration, read-only controls, account switching, ownership and destructive confirmation. Full backend/frontend checks run through `./scripts/verify.sh` on isolated services.

Apply the new migration explicitly to the intended development environment when ready, using the existing Docker migration command. Automated checks migrate only the guarded test database; the agent does not migrate development storage, send real invitations, change `.env` or operate Git. No dependency or lockfile changes are required.

## M1.04 follow-up

Private invitation reads and model writes now require explicit tenant context. HTTP establishes an authorized route team before tenant binding; issuance/revocation and capability acceptance also establish context for direct action calls. The capability-only resolver remains the restricted entry for anonymous invitation flows. [TENANCY.md](TENANCY.md) documents policy behavior, reload safeguards and queue/CLI contracts. Existing user-visible flows and ownership invariants remain unchanged.
