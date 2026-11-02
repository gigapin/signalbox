{{-- Render escaped, crawler-visible metadata with the same managed keys as the Inertia Head component. --}}
<title inertia>{{ $metadata['title'] }}</title>
<meta inertia="description" name="description" content="{{ $metadata['description'] }}">
<meta inertia="robots" name="robots" content="{{ $metadata['robots'] }}">
@if($metadata['canonicalUrl'] !== null)
    <link inertia="canonical" rel="canonical" href="{{ $metadata['canonicalUrl'] }}">
    <meta inertia="og:title" property="og:title" content="{{ $metadata['title'] }}">
    <meta inertia="og:description" property="og:description" content="{{ $metadata['description'] }}">
    <meta inertia="og:url" property="og:url" content="{{ $metadata['canonicalUrl'] }}">
    <meta inertia="og:type" property="og:type" content="website">
    <meta inertia="og:site_name" property="og:site_name" content="{{ $metadata['siteName'] }}">
    <meta inertia="twitter:card" name="twitter:card" content="summary">
    <meta inertia="twitter:title" name="twitter:title" content="{{ $metadata['title'] }}">
    <meta inertia="twitter:description" name="twitter:description" content="{{ $metadata['description'] }}">
@endif
