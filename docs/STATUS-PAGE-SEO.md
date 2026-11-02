# Status page documents and SEO

Public passwordless status pages deliver a useful HTML document before JavaScript runs. The title, description, canonical URL, robots directives, Open Graph fields and Twitter summary fields are present in the initial `<head>`. A visible read-only summary contains the public title/introduction, current page/component states, observed uptime over the last 90 days and explicitly published incident entries. It uses the existing visitor projection, including its cache and publication rules; it does not query private models or recalculate availability in Blade.

## Document contract

`StatusPageDocuments` builds the live metadata decision from the published page. `StatusPageMetadata` carries five plain data fields: `title`, `description`, `robots`, nullable `canonicalUrl` and `siteName`. The public title combines the page title and configured application name; a missing introduction receives a generic service-status description. Whitespace is normalized, and both Blade and React escape literal text. No user text becomes HTML, a CSS value or a URL.

The initial Blade head and `StatusPageHead.tsx` use the same managed keys. The installed Inertia 2 adapter recognizes the `inertia` attribute and the React `head-key` property; subsequent navigation replaces existing tags and removes obsolete social/canonical tags. Metadata props use `Inertia::always`, including partial visits requesting other props. The default application description is also managed so it is replaced on entry to a status page.

The summary is a sibling before the native `@inertia` root; the Inertia protocol and JSON mount data remain unchanged. The public React page removes the summary after mounting. Without JavaScript, or when a bundle fails before mounting, the public summary remains readable. There is no user-agent detection, crawler-only body or alternate authorization path. Daily charts and subscription/password/consent forms still require JavaScript. Password pages retain their existing client rendering after authorization.

## Canonical policy

The canonical is always the configured primary `APP_URL` followed by the current `/status/{slug}` path, excluding request query strings. This applies to the primary origin and verified custom domains. A configured deployment prefix/port is retained; `APP_URL` must be an absolute HTTP(S) URL without credentials, query, fragment, control characters or markup. Invalid configuration fails explicitly instead of using request headers as a fallback.

One stable primary canonical consolidates duplicate public variants. DNS proof establishes ownership, not functioning TLS; the primary address survives expiry/removal of an alias. Forwarded Host, session, visitor identity, campaign queries and the current alias do not influence the canonical. A rename uses the new current path; existing behavior still returns 404 at the old slug. No new redirect history, sitemap, discovery endpoint or claim of search-engine canonical selection is introduced.

`StatusPageIndexing` emits `X-Robots-Tag: index, follow` and an HTTP `Link: <...>; rel="canonical"` only after a successful GET/HEAD of a published passwordless page. This also covers Inertia JSON/partial responses. Route mutations, errors and email capabilities default to `noindex, nofollow, nosnippet`, without canonical Link. Unknown origins are rejected earlier by the domain guard with 404 and no page document. HTTP responses retain `no-store, private`; SEO metadata does not authorize shared caching.

## Protected pages and mailbox links

Every password-protected page uses a generic title/description and `noindex, nofollow, nosnippet` in the initial HTML and HTTP header, even for an unlocked session. It has no canonical, Open Graph or Twitter tags, and no server summary. Only the existing authorized Inertia projection contains the page contents after unlocking. Visibility/password admission and the metadata policy are read live outside the content cache, so warming a public page cannot retain indexable metadata after a password is added.

Signed verification/unsubscribe documents also use generic metadata with the same noindex policy. Their `<head>` never includes the signed URL, signature, subscriber generation, mailbox or page title. The action URL remains in its authorized form props because the visitor must explicitly submit it. Noindex is crawler guidance; session checks, signed routes, CSRF, encryption and tenant boundaries enforce access.

## Optional React SSR assessment

Full Inertia React SSR remains disabled in `config/inertia.php`. The required public information is available in server-rendered head and body using the existing PHP runtime. This avoids introducing an SSR bundle, Node rendering process and its failure/restart/deployment lifecycle for a read-only summary. It is not full React SSR or hydration: React still mounts normally, and the Blade summary intentionally omits daily chart cells and interactive forms.

Reassess React SSR if the complete interactive layout must be available without JavaScript or if measured rendering/performance requirements justify the additional process. The Blade and React presentations must stay aligned when public fields change; the existing public projection remains the only source of publishable data. Automated HTML/DOM tests cover this boundary, but browser rendering, external crawler execution, social preview retrieval, indexing, ranking and performance have not been measured.

Official guidance consulted on 2026-11-02: [Google JavaScript SEO basics](https://developers.google.com/search/docs/crawling-indexing/javascript/javascript-seo-basics) explains rendering and initial noindex handling; [Google canonical URL guidance](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls) explains duplicate URL signals. The installed Inertia Laravel directive and React Head implementation were inspected for the exact supported initial-document and head-replacement protocol. Canonical and robots are signals, not guarantees of indexing or removal.

## Verification and adoption

```sh
docker compose run --rm -v /var/www/html/public/build php-test vendor/bin/pest tests/Feature/StatusPageDocumentsTest.php tests/Integration/StatusPageSeoTest.php --compact --fail-on-skipped --fail-on-warning
docker compose run --rm node npm test -- tests/frontend/StatusPageMetadata.test.tsx
```

PHP tests parse actual initial head and visible body nodes, independently of the serialized Inertia props. They cover escaping, fallback descriptions, missing and observed uptime, publication filtering, password/cache transitions, HEAD, partial responses, query/forwarded-host handling, canonical aliases and exact domain expiry, signed consent forms and route errors. Feature cases validate canonical configuration without storage. DOM tests exercise the actual Head manager, server-tag replacement, obsolete-tag removal for locked/unlocked/email pages, missing-prop restriction and removal of the server summary after mounting.

Deploy the PHP/views and rebuild the normal client assets. There is no new migration, dependency, environment variable, worker or SSR service. Existing status-page/domain/subscription migrations remain prerequisites. Configure the externally reachable primary `APP_URL` and product `APP_NAME`; neither is inferred from an incoming request. Standard remote CI/PHP 8.5 and real browser/crawler checks remain separate from local test evidence in [TASKS.md](TASKS.md).
