# Private account profile

The Account overview replaces Identity/Team access cards with optional first name, last name, phone,
country, company, VAT number and a private avatar. Email is prefilled from the active sign-in identity
and remains required. Existing full names become first_name without guessing surname boundaries;
new ordinary/invited registrations initialize the same field. Profile saves recompute users.name from
first/last name, falling back to Account when both are empty. VAT and phone retain leading zeros.
Countries uses the closed 249-code ISO 3166-1 alpha-2 dataset in Countries.php, with English labels
from Intl.DisplayNames. The code list is the backend authority; labels do not select authorization.

Field wrappers align at the start of each grid row, so upload help text and validation messages do
not stretch adjacent inputs such as VAT number.

## Browser flow

Save changes requires a dirty form and an explicit native confirmation box with the message
“Save changes to your account?”. Cancelling preserves the draft. The native box retains the browser's
focus/keyboard behavior. **Implementation decision:** current-password entry is shown inline only
while email differs from the active address; it precedes the native confirmation rather than appearing
inside a custom dialog. This avoids duplicating a modal implementation but differs from the planned
placement of the password field. Profile drafts use the existing owned-mutation and leave-protection
boundaries. Uncertain results block automatic writes until reload. Pending-email resend/cancel use
empty forms rather than resubmitting profile fields, passwords or selected files.

The header avatar replaces initials only for the current owner; an image load error falls back to
initials. Data URL previews work under the current CSP without enabling blob sources. Avatar upload
supports JPEG/PNG/WebP up to 2 MiB and 512×512 pixels; SVG/GIF are rejected. Files retain their raster
contents and metadata, are stored on the private local disk under generated names, and are served only
by authenticated owner GET /account/avatar with no-store and nosniff. There are no public avatar URLs.
Only a versioned relative avatar URL enters shared identity props; other profile data stays on Account.

## Email changes

Profile POST /account/profile requires auth, verified identity, CSRF, current password for a new email
request and bounded input. Active email remains usable until a signed 60-minute capability is explicitly
confirmed. Pending email is encrypted and registered in RotateProtectedData for legacy adoption/root retirement; token storage is SHA-256 of random 256-bit material. GET
/account/email-change/{token} reviews only; POST with the same signed URL and CSRF changes identity.
Review refreshes the session owner; final confirmation locks the user and rechecks validity and unique
case-insensitive active email. Uniqueness races are validation refusals. Duplicate/expired/cancelled or
superseded capabilities fail without another identity change. The new email remains verified.

POST /account/email-change/resend replaces the capability; DELETE /account/email-change cancels it.
A user-row timestamp and lock enforce a shared one-minute cooldown even across cancellation/recreate.
Other profile saves do not cancel pending email. Mail is sent after the transaction and the old mailbox
receives a change notice after confirmation. Delivery errors are logged without recipient/token and
shown as partial-save feedback; the user may resend. There is no durable email outbox or automatic retry.
Changing the active email does not remove team memberships or reset existing 2FA configuration.

## Storage and deployment

Apply 2026_11_18_000029_add_account_profile before serving new code/assets; verification does not apply
it to development. Rebuild frontend assets and use the normal PHP deployment procedure. The private
local storage must persist and be shared by application replicas. No public storage symlink, dependency,
key or new environment variable is needed. Use the existing mail configuration; no real provider is
exercised by tests. Preserve files in backups with the matching database. Reverting the migration loses
profile/pending-email metadata and does not delete private files automatically.

New files are stored before the profile transaction. Failure removes the new file; replacement/removal
of a previous file occurs only after commit. Cleanup failure is logged and may leave an orphan for
operator review; it never reverses an accepted profile. No user-supplied path selects a file or account.

Actual test outcomes and outstanding browser acceptance are recorded in TASKS. Browser testing must
cover both themes, mobile, actual save/validation, file preview, recovery after uncertain writes and the
end-to-end email flow. No live email delivery, exhaustive concurrent file-failure injection or new 2FA
acceptance is implied by the initial automated results.

## Heading save and synthetic names — 2026-11-20

Save changes is in the account heading action slot, portaled from the removable profile form. Native form association and an internal hidden submit retain keyboard Enter behavior, validation, dirty/pending disabling, confirmation and owned mutations.

The known IdentitySeeder labels now explicitly separate Synthetic / Owner (and the other role labels). Seeder reruns normalize only reserved fixture emails with the exact untouched display label, absent surname and absent/full-label first_name; customized profile fields are preserved. Real legacy names and registration inputs are not heuristically split. The account presenter always uses users.first_name/last_name, never the current team's name. The reported local owner fixture had Synthetic Owner copied wholesale into first_name by the historical migration; its two profile fields were repaired with a conditional, single-row update while preserving its display name, credentials and memberships.
