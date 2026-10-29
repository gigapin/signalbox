/** Read Laravel's CSRF cookie for the few same-origin JSON mutations that bypass Inertia. */

/**
 * @param failure Message raised when the cookie is missing, phrased for the calling feature.
 * @returns URL-decoded XSRF cookie to echo in Laravel's expected `X-XSRF-TOKEN` header.
 * @throws Error When the browser has no current CSRF cookie.
 */
export function csrfHeader(failure: string): string {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    if (!cookie) {
        throw new Error(failure);
    }

    return decodeURIComponent(cookie.substring('XSRF-TOKEN='.length));
}
