# Application appearance

Signalbox defaults to dark regardless of OS preferences. The dashboard workspace header, including
account/platform contexts, contains the only theme switch: an icon-only sun/moon control immediately before the profile menu,
which remains the final header control. Theme/profile are grouped together; the profile uses a
circular initials avatar. Desktop header targets are compact, while mobile targets retain 44px. The icon retains an accessible action label and native title. Welcome and authentication screens follow
the saved appearance without offering a switch. Public status pages retain their owner-defined theme.

## Preference lifecycle

`users.theme_preference` is nullable and constrained to dark/light; null means dark. Authenticated
accounts always win over the browser cookie, including accounts without an explicit preference.
Anonymous welcome/login use the valid decrypted `signalbox_theme` cookie or dark. Switching themes
requires a verified session and POST `/preferences/theme`, JSON `{ "theme": "light" }` or dark.
The endpoint returns the confirmed theme, saves only the session actor, requires web CSRF and is
throttled to 60 requests per minute. Writes and browser acknowledgement are idempotent.

The cookie lasts 365 days, is encrypted by Laravel, host-only, HttpOnly, SameSite=Lax and Secure on
HTTPS or in production. Account page responses synchronize it; logout preserves it. On another device,
welcome initially uses that browser's cookie/default, then login restores the account preference.
Clearing cookies resets anonymous welcome to dark but does not erase account preference.

The initial HTML contains data-ui-theme; no inline startup script or CSP relaxation is needed.
Inertia always shares uiTheme, null on public status pages. Stable wrapped page identities preserve
Inertia state. The provider applies confirmed writes without page navigation; pending saves disable
the switch and failed acknowledgements retain the visible theme with a retry message. An uncertain
network failure may still have committed; refreshing recovers server truth. There is no automatic
retry or live synchronization across tabs/devices. Root tokens also cover body portals.

## Adoption

Apply `2026_11_18_000028_add_user_theme_preference` before serving the updated code/assets. No package,
key or environment changes. Development migration is not applied by verification. Install/rebuild assets
normally and refresh old browser pages. Preserve existing application processes and public/hot.
Rollback requires reverting code/assets before dropping the column; doing so loses theme choices.
The browser cookie carries only appearance, never identity or tenant data.

## Verification limits

TASKS records actual checks. Contrast tests read both real CSS palettes independently. The synthetic
Firefox screenshot checks compiled light CSS, not an authenticated application flow. Full browser
acceptance across welcome/login, mobile, dialogs, select portals and actual dashboard sessions remains
pending after migration adoption. Chart redesign and library selection remain suspended.
