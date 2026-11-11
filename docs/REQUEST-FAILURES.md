# Connection failures and narrow viewports

## Foreground GET ownership — UI.06.1

useReadNavigation now consumes invalid/exception events only when their transport URL matches the
active GET including scalar query controls. Equivalent query ordering/encoding is normalized; another
origin, path, selection or missing attribution cannot deny the current private page. Exceptions must
also pass the existing response-less browser-transport classifier; programming errors are not swallowed
as a filter failure. Existing explicit retry, generation and cleanup behavior remains. Regression proof
and phase-A evidence map: [UI-ACCEPTANCE.md](UI-ACCEPTANCE.md). No mutation replay or polling added.


## Shared incident write lifecycle — UI.04.1

useMonitorMutation was renamed/extracted as useOwnedMutation and the identical MutationFeedback moved to components/ for actual monitor and incident callers. The coordinator remains one implementation; the only added callback permits note cleanup after owned confirmed acceptance. Incident note, severity, cause, responsibility and publication writes use it. No event bus, polling or new retry policy is introduced.

A response-less incident note attempt retains its text and leaves further submission locked until the user checks saved data and explicitly permits another manual attempt. Background read recovery does not acknowledge or replay a write. Provider diagnostic text and mutation payloads never appear in these notices. Existing generic RequestFailureNotice behavior and its response-less classifier remain unchanged.

## Owned monitor mutation feedback — UI.03.7

The existing generic notice uses the same extracted response-less browser failure classifier in resources/js/requestFailures.ts. Its behavior for other pages is unchanged. Monitor editors and state actions additionally own their foreground write with useOwnedMutation, which uses the installed Inertia callback/event protocol. Only failures whose Axios config URL matches the owned mutation are consumed by that hook; unrelated programming exceptions remain observable. onFinish without an accepted/refused response is uncertain, including cancellation after dispatch.

MonitorMutation feedback leaves drafts intact and locks another write on uncertainty, even if the generic notice is dismissed or connectivity returns. Review saved data opens a safe same-team GET in a separate tab; explicit I checked the saved data clears the local lock without making a request. This is user acknowledgement, not automatic reconciliation or proof that the previous mutation did not commit. Server-side idempotency and domain semantics remain unchanged. Explicit 401/403/404 denial removes the private page. The global notice and local outcome can both appear for a response-less failure; local controls retain the stronger uncertainty lock.

BT.08 fixes the offline incident update found in L05/L07. The existing Inertia 2 exception event is cancelable; without a handler its rejected transport promise reaches the browser as an unhandled `Network Error`, while `useForm` still releases its processing state.

## Failure handling

`resources/js/components/RequestFailureNotice.tsx` mounts once alongside the Inertia app in `resources/js/app.tsx`. It needs no page, account, tenant or capability props, so the same notice works on private and public pages.

It handles only Axios-marked `ERR_NETWORK`, `ECONNABORTED` and `ETIMEDOUT` errors associated with a real browser XMLHttpRequest and no HTTP response. It cancels the exception event, shows a fixed, viewport-visible `role="alert"`, and leaves Inertia's normal finish callbacks intact. It never renders the exception, URL, headers or payload. Programming errors, cancellations and HTTP responses retain their existing handling.

The notice does not submit, reload, clear fields or retry requests. A lost response can follow a committed mutation, so it says the outcome could not be confirmed and asks the user to check the latest data before trying again. Reconnection alone does not clear that warning; a successful Inertia visit or explicit dismissal does. Incident drafts remain available and the submit button becomes usable again. Individual pages retain their existing rules for clearing sensitive fields.

The listener subscriptions are removed on unmount. A default browser error handler is not replaced, so unrelated JavaScript failures remain observable.

## Tables and navigation

`TableScrollArea.tsx` keeps monitor, incident and delivery tables in a named, focusable region with local horizontal scrolling. Captions and native table/link semantics remain intact. Keyboard users can Tab to the region and use the browser's horizontal arrow scrolling before continuing to its links. Team navigation wraps onto multiple rows; headings and descriptions can wrap long words. No page-level overflow clipping hides content.

The real-browser regression checks keep all six acceptance screen families at a document width of 390 CSS pixels in both Brave and Firefox. Native browser zoom at 200% and VoiceOver remain separate, unfinished acceptance checks.

## Verification and adoption

`RequestFailureNotice.test.tsx` exercises the installed Inertia/Axios XMLHttpRequest error path, draft preservation, processing release, timeout categories, diagnostic redaction, dismissal, successful recovery and listener cleanup. It checks that unrelated exceptions keep propagating. The monitor and incident DOM suites check keyboard access to the table viewport and the first resource link.

The browser regression additionally forwards an ordinary private timeline update to the real server and then drops its response. The notice appears without a replay; reloading reveals exactly one saved note. See the [case register and evidence](BROWSER-RESULTS-2026-11-09.md#correzione-e-ripetizione-bt08).

Rebuild the normal client assets and fully reload open pages. The assets were already rebuilt during the successful frontend gate for BT.08. No dependency, environment, migration, privilege, queue or backend code change is required.
