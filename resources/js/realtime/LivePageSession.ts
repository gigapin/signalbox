/** Reconcile a private live page through bounded, cancellable reads of its existing authorized Inertia URL. */
import { openTeamStream } from './teamStream';
import {
    isRecord,
    validTeamEvent,
    type LivePhase,
    type ReverbConfiguration,
    type TeamEventName,
    type TeamStreamFactory,
} from './types';

/** Immutable page identity; changing any scope creates a different store in the React hook. */
export interface LivePageScope<P extends Record<string, unknown>> {
    /** Explicit route team, independent of the selected-team preference. */
    teamId: number;
    /** Current Inertia component used for partial response matching. */
    component: string;
    /** Original URL including filters and pagination; never replaced by event input. */
    url: string;
    /** Current asset version; incompatible deployments require a full browser reload. */
    version: string | null;
    /** Original page props; snapshots replace only explicitly requested roots. */
    props: P;
    /** Prop roots refreshed together, without touching form drafts, flash or validation errors. */
    keys: readonly string[];
    /** Verified browser identity used to reject a response belonging to a changed account. */
    actorEmail: string;
    /** Null disables all socket and snapshot traffic. */
    configuration: ReverbConfiguration | null;
    /** Optional detail-page filter; list pages must invalidate even for rows currently filtered out. */
    monitorId?: number;
}

/** Stable snapshot consumed by useSyncExternalStore. */
export interface LivePageSnapshot<P> {
    /** Server-owned data for the current scope only. */
    props: P;
    /** Connection/recovery feedback; no transport exception content is exposed. */
    phase: LivePhase;
}

/** Coordinate event bursts, duplicates, reconnect recovery and disposal without a periodic refresh timer. */
export class LivePageSession<P extends Record<string, unknown>> {
    /** Current immutable store value, replaced only when data or feedback changes. */
    private snapshot: LivePageSnapshot<P>;
    /** React store subscribers, independent of socket event bindings. */
    private listeners = new Set<() => void>();
    /** Whether this lifecycle has started and not yet been disposed. */
    private running = false;
    /** Whether the private channel has acknowledged this subscription. */
    private ready = false;
    /** Latest transport lifetime; stale callbacks cannot revive a replaced connection. */
    private epoch = 0;
    /** Idempotent cleanup for the current native connection. */
    private closeStream: (() => void) | null = null;
    /** First-event coalescing timer in milliseconds, never a polling interval. */
    private scheduled: ReturnType<typeof setTimeout> | null = null;
    /** Single active snapshot request; identity fences late fetch/json completions. */
    private request: AbortController | null = null;
    /** Bound the active read to ten seconds and cancel the deadline on every lifecycle exit. */
    private deadline: ReturnType<typeof setTimeout> | null = null;
    /** Whether an event arrived after the active read started. */
    private dirty = false;
    /** Bounded FIFO duplicate fence; reconnection always recovers independently of this cache. */
    private seen = new Set<string>();

    /**
     * @param scope Fixed page identity and public configuration.
     * @param connect Disposable socket adapter.
     * @param read Same-origin HTTP transport.
     */
    public constructor(
        private readonly scope: LivePageScope<P>,
        private readonly connect: TeamStreamFactory = openTeamStream,
        private readonly read: typeof fetch = fetch,
    ) {
        this.snapshot = {
            props: scope.props,
            phase: scope.configuration === null ? 'off' : 'connecting',
        };
    }

    /** Return the same reference until the store changes, as required by React's external-store contract. */
    public getSnapshot = (): LivePageSnapshot<P> => this.snapshot;

    /** Register a React listener and return its cleanup without opening an extra socket. */
    public subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };

    /** Start one lifecycle; supports StrictMode's setup/cleanup/setup sequence. */
    public start = (): (() => void) => {
        this.running = true;
        if (this.scope.configuration !== null) {
            this.open();
            window.addEventListener('online', this.wake);
            document.addEventListener('visibilitychange', this.wake);
        }
        return this.stop;
    };

    /** Retry deliberately after transport/read failure; authorization and recovery are checked again. */
    public retry = (): void => {
        if (!this.running || this.scope.configuration === null || this.snapshot.phase === 'off')
            return;
        this.disposeConnection();
        this.open();
    };

    /** Cancel all owned work; unrelated Inertia requests and other stores are untouched. */
    public stop = (): void => {
        this.running = false;
        this.disposeConnection();
        window.removeEventListener('online', this.wake);
        document.removeEventListener('visibilitychange', this.wake);
    };

    /** Recover on browser wake/online events, with no activity while the page stays idle. */
    private wake = (): void => {
        if (
            !this.running ||
            document.visibilityState === 'hidden' ||
            this.snapshot.phase === 'denied' ||
            this.snapshot.phase === 'off'
        )
            return;
        if (this.ready) this.invalidate();
        else this.retry();
    };

    /** Establish fresh callbacks fenced against disposal, team/account changes and manual reconnects. */
    private open(): void {
        if (this.scope.configuration === null) return;
        const epoch = ++this.epoch;
        this.publish('connecting');
        const active = (): boolean => this.running && this.epoch === epoch;
        try {
            const close = this.connect(this.scope.configuration, this.scope.teamId, {
                subscribed: () => {
                    if (!active()) return;
                    this.ready = true;
                    this.seen.clear();
                    this.invalidate(true);
                },
                state: (state) => {
                    if (!active()) return;
                    this.ready = false;
                    this.cancelRead();
                    this.publish(state);
                },
                event: (name, payload) => {
                    if (active()) this.receive(name, payload);
                },
                denied: () => {
                    if (!active()) return;
                    this.disposeConnection();
                    this.publish('denied');
                },
            });
            if (active()) this.closeStream = close;
            else close();
        } catch {
            this.publish('error');
        }
    }

    /** Validate and deduplicate facts, treating even old incident names as invalidations rather than current state. */
    private receive(name: TeamEventName, payload: unknown): void {
        if (!this.ready || !validTeamEvent(name, payload)) return;
        if (this.scope.monitorId !== undefined && payload.monitor_id !== this.scope.monitorId)
            return;
        if (name === 'CheckCompleted') {
            const identity = String(payload.execution_id);
            if (this.seen.has(identity)) return;
            this.seen.add(identity);
            if (this.seen.size > 512) {
                const oldest = this.seen.values().next().value;
                if (oldest !== undefined) this.seen.delete(oldest);
            }
        }
        this.invalidate();
    }

    /** Coalesce from the first event, so a sustained burst cannot postpone recovery indefinitely. */
    private invalidate(immediate = false): void {
        if (!this.running || !this.ready) return;
        this.dirty = true;
        if (this.request !== null || this.scheduled !== null) return;
        this.scheduled = setTimeout(
            () => {
                this.scheduled = null;
                void this.refresh();
            },
            immediate ? 0 : 250,
        );
    }

    /** Read the exact page URL, validate scope and replace only whitelisted roots; failures wait for an event or explicit retry. */
    private async refresh(): Promise<void> {
        if (!this.running || !this.ready || !this.dirty) return;
        this.dirty = false;
        const controller = new AbortController();
        this.request = controller;
        this.deadline = setTimeout(() => controller.abort(), 10_000);
        // Keep healthy background updates quiet for screen readers; announce recovery after connection/error states.
        if (this.snapshot.phase !== 'live') this.publish('syncing');
        try {
            if (new URL(this.scope.url, window.location.href).origin !== window.location.origin)
                throw new Error('Invalid page origin.');
            const response = await this.read(this.scope.url, {
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Inertia': 'true',
                    'X-Inertia-Partial-Component': this.scope.component,
                    'X-Inertia-Partial-Data': [...this.scope.keys, 'team', 'auth', 'realtime'].join(
                        ',',
                    ),
                    ...(this.scope.version === null
                        ? {}
                        : { 'X-Inertia-Version': this.scope.version }),
                },
            });
            if (this.request !== controller) return;
            if ([401, 403, 404, 419].includes(response.status)) {
                this.disposeConnection();
                this.publish('denied');
                return;
            }
            if (!response.ok || response.headers.get('X-Inertia') !== 'true')
                throw new Error('Snapshot unavailable.');
            const body: unknown = await response.json();
            if (this.request !== controller || controller.signal.aborted) return;
            if (!isRecord(body) || body.component !== this.scope.component || !isRecord(body.props))
                throw new Error('Invalid page.');
            const props = body.props;
            if (
                !isRecord(props.team) ||
                props.team.id !== this.scope.teamId ||
                !isRecord(props.auth) ||
                !isRecord(props.auth.user) ||
                props.auth.user.email !== this.scope.actorEmail
            ) {
                this.disposeConnection();
                this.publish('denied');
                return;
            }
            const update: Record<string, unknown> = {};
            for (const key of this.scope.keys) {
                if (!(key in props)) throw new Error('Incomplete snapshot.');
                update[key] = props[key];
            }
            if (props.realtime === null) {
                this.disposeConnection();
                this.publish('off', { ...this.snapshot.props, ...update });
                return;
            }
            this.publish('live', { ...this.snapshot.props, ...update });
        } catch {
            if (this.running && this.request === controller) this.publish('error');
        } finally {
            if (this.request === controller) {
                if (this.deadline !== null) clearTimeout(this.deadline);
                this.deadline = null;
                this.request = null;
                if (this.dirty) this.invalidate();
            }
        }
    }

    /** Release a read and its coalescing timer; the request identity fence also covers an already resolving JSON body. */
    private cancelRead(): void {
        if (this.scheduled !== null) clearTimeout(this.scheduled);
        if (this.deadline !== null) clearTimeout(this.deadline);
        this.deadline = null;
        this.scheduled = null;
        this.request?.abort();
        this.request = null;
        this.dirty = false;
    }

    /** Invalidate callback generations before native teardown can emit connection events. */
    private disposeConnection(): void {
        ++this.epoch;
        this.ready = false;
        this.cancelRead();
        this.closeStream?.();
        this.closeStream = null;
    }

    /** Notify React with a fresh immutable snapshot; frame data is never merged directly into page resources. */
    private publish(phase: LivePhase, props = this.snapshot.props): void {
        this.snapshot = { props, phase };
        for (const listener of this.listeners) listener();
    }
}
