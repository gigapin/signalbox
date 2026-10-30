/** Replace only the Pusher socket/authorization boundary while native Echo, the hook and React stay real. */
import type { Options } from 'pusher-js';

/** Simulated connection transition matching the Pusher state-change contract. */
interface ConnectionChange {
    /** Current native transport state, not private admission. */
    current: string;
}

/** Per-client connection emitter, preventing another client's state from reaching this scope. */
class MemoryConnection {
    /** Registered native connection listeners, keyed by event name. */
    private listeners = new Map<string, Set<(change: ConnectionChange) => void>>();

    /** Attach a native Echo/application listener without global socket state. */
    public bind(name: string, listener: (change: ConnectionChange) => void): void {
        const listeners = this.listeners.get(name) ?? new Set();
        listeners.add(listener);
        this.listeners.set(name, listeners);
    }

    /** Remove exactly the disposed listener. */
    public unbind(name: string, listener: (change: ConnectionChange) => void): void {
        this.listeners.get(name)?.delete(listener);
    }

    /** Simulate a transport transition; private subscription success is a different channel event. */
    public change(current: string): void {
        for (const listener of this.listeners.get('state_change') ?? []) listener({ current });
    }
}

/** Native Echo channel bindings isolated by the exact protocol channel name. */
export class MemoryChannel {
    /** Installed callbacks; retained references deliberately allow late-callback disposal tests. */
    private bindings = new Map<string, Set<(payload: unknown) => void>>();

    /** Register a short-name event through the real Echo EventFormatter. */
    public bind(event: string, listener: (payload: unknown) => void): void {
        const listeners = this.bindings.get(event) ?? new Set();
        listeners.add(listener);
        this.bindings.set(event, listeners);
    }

    /** Deliver an inbound event to this channel only. */
    public emit(event: string, payload: unknown): void {
        for (const listener of this.bindings.get(event) ?? []) listener(payload);
    }
}

/** In-memory Pusher API used only in acceptance tests; native Reverb validates actual signatures in PHP tests. */
export class MemoryPusher {
    /** All lifetimes created by the mounted application, for scope/disposal assertions. */
    public static instances: MemoryPusher[] = [];
    /** Per-client connection emitter used by the production adapter. */
    public connection = new MemoryConnection();
    /** SDK channel registry; no public/foreign channel is implicitly created. */
    public channels = new Map<string, MemoryChannel>();
    /** Whether the production adapter disconnected this transport. */
    public disconnected = false;

    /**
     * @param _key Public app key.
     * @param options Production transport and authorizer options.
     */
    public constructor(
        _key: string,
        private readonly options: Options,
    ) {
        MemoryPusher.instances.push(this);
    }

    /** Return the private channel requested by native Echo without acknowledging admission yet. */
    public subscribe(name: string): MemoryChannel {
        const channel = this.channels.get(name) ?? new MemoryChannel();
        this.channels.set(name, channel);
        return channel;
    }

    /** Mirror SDK removal when native Echo leaves its private channel. */
    public unsubscribe(name: string): void {
        this.channels.delete(name);
    }

    /** Record native connection shutdown without automatically discarding retained callback references. */
    public disconnect(): void {
        this.disconnected = true;
    }

    /** Simulate a connected socket and route every private grant through the production custom authorizer. */
    public establish(socketId = '111.222'): void {
        this.connection.change('connected');
        const authorization = this.options.channelAuthorization;
        if (!authorization || !('customHandler' in authorization))
            throw new Error('Missing session authorizer.');
        for (const [name, channel] of this.channels) {
            authorization.customHandler({ socketId, channelName: name }, (error) => {
                channel.emit(
                    error === null ? 'pusher:subscription_succeeded' : 'pusher:subscription_error',
                    error ?? {},
                );
            });
        }
    }

    /** Deliver an actual server envelope through the SDK's decoded-data boundary to the exact channel. */
    public receive(channel: string, event: string, data: unknown): void {
        this.channels.get(channel)?.emit(event, data);
    }
}
