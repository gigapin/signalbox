/** Adapt native Echo/Pusher to a disposable private subscription with session/CSRF authorization. */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { csrfHeader } from '../components/csrf';
import { isRecord, TEAM_EVENTS, type TeamStreamFactory } from './types';

/** Own every socket, binding and pending grant within one page/team/account lifetime. */
export const openTeamStream: TeamStreamFactory = (configuration, teamId, callbacks) => {
    let closed = false;
    // Every connection transition invalidates grants bound to the previous socket lifetime.
    let authorizationGeneration = 0;
    const pending = new Map<AbortController, ReturnType<typeof setTimeout>>();

    /** Abort old socket grants and their deadlines without completing stale Pusher callbacks. */
    function cancelAuthorizations(): void {
        for (const [controller, timeout] of pending) {
            clearTimeout(timeout);
            controller.abort();
        }
        pending.clear();
    }
    const client = new Pusher(configuration.key, {
        cluster: 'mt1',
        wsHost: configuration.host,
        wsPort: configuration.port,
        wssPort: configuration.port,
        forceTLS: configuration.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        enableStats: false,
        channelAuthorization: {
            /** Sign only this connection's own private team channel using the current CSRF cookie. */
            customHandler(params, callback) {
                if (closed || params.channelName !== `private-team.${teamId}`) {
                    callback(new Error('Live updates authorization refused.'), null);
                    return;
                }

                const controller = new AbortController();
                const generation = authorizationGeneration;
                const active = (): boolean => !closed && generation === authorizationGeneration;
                pending.set(
                    controller,
                    setTimeout(() => controller.abort(), 10_000),
                );

                /** Complete a local grant without allowing late responses to revive a disposed subscription. */
                async function authorize(): Promise<void> {
                    try {
                        const response = await fetch('/broadcasting/auth', {
                            method: 'POST',
                            credentials: 'same-origin',
                            cache: 'no-store',
                            redirect: 'error',
                            signal: controller.signal,
                            headers: {
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                                'X-XSRF-TOKEN': csrfHeader(
                                    'Reload the page to restore your session.',
                                ),
                            },
                            body: JSON.stringify({
                                socket_id: params.socketId,
                                channel_name: params.channelName,
                            }),
                        });

                        if (!active()) return;
                        if ([401, 403, 419].includes(response.status)) {
                            callback(new Error('Live updates authorization refused.'), null);
                            callbacks.denied();
                            return;
                        }
                        if (!response.ok)
                            throw new Error('Live updates authorization unavailable.');
                        const data: unknown = await response.json();
                        if (!isRecord(data) || typeof data.auth !== 'string')
                            throw new Error('Invalid live updates grant.');
                        if (active()) callback(null, { auth: data.auth });
                    } catch {
                        if (active()) {
                            callback(new Error('Live updates authorization unavailable.'), null);
                            callbacks.state('error');
                        }
                    } finally {
                        clearTimeout(pending.get(controller));
                        pending.delete(controller);
                    }
                }

                void authorize();
            },
        },
    });
    const echo = new Echo<'reverb'>({ broadcaster: 'reverb', client, withoutInterceptors: true });
    const channel = echo.private(`team.${teamId}`);

    /** Mirror connection loss without confusing a transport handshake with private admission. */
    function changed(change: { current: string }): void {
        if (closed) return;
        ++authorizationGeneration;
        cancelAuthorizations();
        const state = change.current;
        if (state === 'connecting' || state === 'connected') callbacks.state('connecting');
        else if (state === 'failed') callbacks.state('error');
        else callbacks.state('disconnected');
    }

    client.connection.bind('state_change', changed);
    channel.subscribed(() => {
        if (!closed) callbacks.subscribed();
    });
    channel.error(() => {
        if (!closed) callbacks.state('error');
    });
    for (const event of TEAM_EVENTS) {
        channel.listen(`.${event}`, (payload: unknown) => {
            if (!closed) callbacks.event(event, payload);
        });
    }

    return () => {
        if (closed) return;
        closed = true;
        ++authorizationGeneration;
        cancelAuthorizations();
        client.connection.unbind('state_change', changed);
        echo.leave(`team.${teamId}`);
        echo.disconnect();
    };
};
