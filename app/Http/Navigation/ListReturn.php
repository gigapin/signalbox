<?php

declare(strict_types=1);

/** Normalize bounded local list navigation independently of the selected domain filters. */

namespace App\Http\Navigation;

use Closure;
use Illuminate\Http\Request;

/** A navigation hint never grants access, carries form data, or acts as a raw redirect destination. */
final readonly class ListReturn
{
    /** Maximum raw navigation hint bytes, bounding query parsing and generated link size. */
    private const int MAX_BYTES = 2048;

    /**
     * @param Request $request Read request containing optional untrusted returnTo.
     * @param string $base Application-built list path inside the authorized team.
     * @param Closure(array<array-key, mixed>): array<string, int|string|null> $normalize Domain filter normalization.
     * @param string $parameter Navigation query key selected by application code.
     * @return string Canonical same-team list URL, falling back to its unfiltered first page.
     */
    public function resolve(Request $request, string $base, Closure $normalize, string $parameter = 'returnTo'): string
    {
        $outer = $request->server('QUERY_STRING', '');
        if (!is_string($outer)) {
            $outer = '';
        }
        if (in_array($parameter, $this->duplicateKeys($outer), true)) {
            return $base;
        }
        $value = $request->query($parameter);
        if (!is_string($value) || strlen($value) > self::MAX_BYTES || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return $base;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['path'] ?? null) !== $base
            || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return $base;
        }
        $query = $parts['query'] ?? '';
        parse_str($query, $input);
        foreach ($this->duplicateKeys($query) as $key) {
            unset($input[$key]);
        }
        $accepted = array_filter($normalize($input), static fn (mixed $item): bool => $item !== null && $item !== '');
        $search = http_build_query($accepted, '', '&', PHP_QUERY_RFC3986);

        return $search === '' ? $base : $base.'?'.$search;
    }

    /**
     * @param string $query Raw query text, before PHP discards duplicate scalar keys.
     * @return list<string> Repeated decoded keys; no duplicate can choose the returned filter value.
     */
    private function duplicateKeys(string $query): array
    {
        $keys = [];
        foreach (explode('&', $query) as $pair) {
            $key = explode('[', urldecode(explode('=', $pair, 2)[0]), 2)[0];
            $keys[$key] = ($keys[$key] ?? 0) + 1;
        }

        return array_map(static fn (int|string $key): string => (string) $key, array_keys(array_filter($keys, static fn (int $count): bool => $count > 1)));
    }
}
