# Private status-page, team and account settings

UI.04.3 integrates the existing settings workflows with shared private layouts, semantic controls and
owned mutation feedback. [TASKS.md](TASKS.md) records actual gates and unexecuted browser acceptance.
No domain endpoint, policy, schema, package or public status-page theme changes are introduced.

## Private and public status pages

The Status pages entry is private management. Its instructions and Draft/Published/Password protected
labels distinguish it from visitor content. Only a published row offers Open public page at its existing
public URL; drafts remain unreachable to visitors. There is no new preview endpoint. Public layout,
subscription/unlock flows, SEO and light/dark/system/accent choices remain with their existing code.
The editor still submits the same title, slug, description, theme, publication, password-removal and
ordered component fields, with each monitor chosen only once. Plan usage includes drafts.

Name search is local to the existing page collection and uses hidden rows rather than unmounting their
editors. Filtering does not discard a password/component draft. Create/edit and deletion now use
useOwnedMutation/MutationFeedback: one owned attempt, pending input freeze, distinct refused/uncertain
states, preserved drafts, explicit saved-state review before another manual attempt. Confirmed save
clears the ephemeral password and closes the editor; network recovery never retries publication or a
password change. Existing native delete confirmations remain.

The separate custom-hostname form uses the same lifecycle for claim save, DNS verification and
removal. Removing a hostname requires confirmation and explains loss of that address. Removal errors
are visible. The form is no longer keyed by the saved hostname: pristine input follows a replacement
claim, while dirty input remains local. TXT proof stays explicit public data, not an assertion of deployed
HTTPS. Verification lease times are browser-local time elements; backend ownership and TLS rules
remain in [STATUS-DOMAINS.md](STATUS-DOMAINS.md).

## Team roles, invitations and ownership

Team settings groups Members, Invite a member, Invitations and Team access in named panels, with
local section navigation and a link to Your teams. Member email/role data is private. Roles and
manageable flags come from current server policy; owner/admin/member/viewer rules are unchanged.
Member editors keep a stable identity across role changes, with a separate saved baseline. Pristine
selectors rebase without creating a departure warning; dirty selections survive another accepted
change. A selection removed from the current role choices is shown explicitly and cannot be saved
until the user chooses an admitted role. Loss of manageability remounts the row without the old draft;
loss of invitation-management capability removes the invitation form and its departure guard.

Role changes, removal, invitation creation/resend/revocation and self-departure all use the shared
owned mutation lifecycle. Pending fields are frozen, duplicates are blocked synchronously and uncertain
writes are never replayed. Invitation email is cleared only after acceptance; the existing intended-email
and explicit acceptance/verification requirements remain. Resending rotates the link; this management
page never projects bearer links. Expiry is shown in the browser timezone. Native destructive
confirmations remain. A primary owner is directed to transfer ownership before departure; the transfer
still admits only another existing owner, retains the former owner's membership, and uses the existing
recent-password server confirmation. Expired confirmation does not trigger an automatic transfer replay.

The public/session-backed invitation review and invited registration pages keep AuthLayout and their
existing email-matching, verification, login/switch-account and explicit acceptance steps. No team
creation, membership acceptance or confirmation email is synthesized by the settings UI.

## Account workspace and credential lifetime

Your account presents identity separately from team access, with real links to the selected team's
dashboard, Your teams and a pending invitation when supplied by the server. The team selector's state
now lives below the removable account context. PrivateContextLayout keys its subtree by authenticated
email/workspace, removes private content and identity on explicit logout or access denial, and hides
private heading/description/status. It reuses the same data-free access-loss context as AppLayout;
platform context remains separate and its functional integration stays UI.04.4.

SecurityContent owns all authenticator form state, setup responses and recovery codes below that
boundary. Enrollment flag replacement starts a fresh credential subtree. Setup QR/manual-key and
recovery-code reads remain explicit same-origin no-store AJAX requests, with AbortController ownership
and single-flight admission. Unknown JSON is validated before display; QR SVG stays an isolated image
resource. Neither setup keys nor codes enter Inertia props, remembered state, URLs or browser storage.

Hide recovery codes also cancels an in-flight reveal; even a transport double that ignores abort cannot
show a late response. Departure, account replacement and denial abort pending reads. A failed paired
setup request disposes its sibling. 401/403/404 remove private content; 423/password redirects clear
credentials and follow the existing server password-confirmation path, once for paired responses.
The HTTP/session rules remain in [AUTHENTICATION.md](AUTHENTICATION.md).

Enable, confirmation, regeneration and disable use the shared owned mutation lifecycle and existing
Fortify endpoints/error bag. Confirmation errors preserve the typed code; acceptance clears it.
Refreshing setup display does not erase an unrelated typed confirmation code. Regeneration and
disable/cancel setup require explicit native confirmation. A regeneration with an uncertain response
never fetches or displays a supposedly accepted new code set and never retries automatically. Credential
reads follow confirmed acceptance only; when an enrollment-state change replaces the subtree, the new
page offers explicit Show controls. Hiding/remounting forgets displayed credentials without claiming
that server-side credentials were revoked.

## Verification and limits

SettingsWorkflows.test.tsx exercises actual Inertia pages and forms, state-preserving server snapshot
replacement, duplicate/refused/uncertain writes, permission and role choices, hostname baselines,
credential read cancellation/late response, current identity replacement, explicit logout and sensitive
GET/mutation denial. Existing StatusPages/StatusDomains/Teams/Authentication/AppLayout suites remain
regression coverage. PostgreSQL HTTP suites check the existing status-page public/private/password,
team membership/authorization and authenticator contracts; no live DNS, email or provider is used.

No new query or database performance claim is made. This task changes client presentation/lifecycle;
full browser keyboard/focus, Firefox, VoiceOver, native zoom, real-session/reconnect/provider behavior
and remote CI/PHP 8.5 require their own evidence. UI.06 retains final L acceptance. Rebuild/reload the
normal frontend assets; no migration, environment edit or worker restart is required.
