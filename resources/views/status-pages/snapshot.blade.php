{{-- Present only the existing public projection without JavaScript; React removes this sibling after mounting. --}}
<main id="status-page-snapshot" class="mx-auto max-w-3xl px-6 py-12">
    <h1>{{ $page['title'] }}</h1>
    @if($page['description'] !== null)
        <p>{{ $page['description'] }}</p>
    @endif
    <p>Current status: {{ str_replace('_', ' ', $page['status']) }}.</p>
    <section aria-labelledby="snapshot-components-heading">
        <h2 id="snapshot-components-heading">Components</h2>
        @forelse($page['components'] as $component)
            <article>
                <h3>{{ $component['name'] }}</h3>
                <p>Current status: {{ str_replace('_', ' ', $component['status']) }}.</p>
                <p>
                    @if($component['uptimePercent'] === null)
                        No uptime data in the last 90 days.
                    @else
                        {{ $component['uptimePercent'] }}% observed uptime in the last 90 days, excluding time without data.
                    @endif
                </p>
            </article>
        @empty
            <p>This page has no components yet.</p>
        @endforelse
    </section>
    <section aria-labelledby="snapshot-incidents-heading">
        <h2 id="snapshot-incidents-heading">Published incidents</h2>
        @forelse($page['incidents'] as $incident)
            <article>
                <h3>{{ $incident['component'] }}: {{ ucfirst($incident['status']) }}</h3>
                <ol>
                    @foreach($incident['updates'] as $update)
                        <li>
                            <p class="whitespace-pre-line">{{ ucfirst($update['status']) }}: {{ $update['message'] }}</p>
                            <time datetime="{{ $update['publishedAt'] }}">{{ str_replace('T', ' ', substr($update['publishedAt'], 0, 16)) }} UTC</time>
                        </li>
                    @endforeach
                </ol>
            </article>
        @empty
            <p>Nothing published in the last 90 days.</p>
        @endforelse
    </section>
    <p>History days are whole UTC days.</p>
    <noscript>Enable JavaScript for daily history charts and email subscription forms.</noscript>
</main>
