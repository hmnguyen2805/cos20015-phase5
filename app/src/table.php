<?php
declare(strict_types=1);

/*
 * Sorting and filter helpers shared by list screens (and later the reports).
 * Column names can't be bound as SQL parameters, so the sort key from the URL
 * is looked up in a whitelist and only the whitelisted SQL text is used.
 */

/** Returns [key, dir, 'ORDER BY ...'] for the current request. */
function sort_spec(array $allowed, string $defaultKey, string $defaultDir = 'asc'): array
{
    $key = get_str('sort', $defaultKey);
    if (!isset($allowed[$key])) {
        $key = $defaultKey;
    }
    $dir = strtolower(get_str('dir', $defaultDir)) === 'desc' ? 'desc' : 'asc';
    // A sort expression may list several columns; the direction applies to the first.
    $parts = explode(',', $allowed[$key], 2);
    $order = trim($parts[0]) . ' ' . strtoupper($dir) . (isset($parts[1]) ? ', ' . trim($parts[1]) : '');
    return [$key, $dir, 'ORDER BY ' . $order];
}

/** Clickable column header that flips direction and keeps the current filters. */
function sort_link(string $label, string $key, array $spec): string
{
    [$cur, $dir] = $spec;
    $next = ($cur === $key && $dir === 'asc') ? 'desc' : 'asc';
    $q = array_merge($_GET, ['sort' => $key, 'dir' => $next]);
    $arrow = $cur === $key ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    return '<a class="sort" href="?' . h(http_build_query($q)) . '">' . h($label) . $arrow . '</a>';
}

/** <select> for a filter form; $options is [value => label]. */
function filter_select(string $name, array $options, string $current, string $allLabel = 'All'): string
{
    $html = '<select name="' . h($name) . '"><option value="">' . h($allLabel) . '</option>';
    foreach ($options as $value => $label) {
        $sel = ((string)$value === $current) ? ' selected' : '';
        $html .= '<option value="' . h($value) . '"' . $sel . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

/** Option list from a one-column query, e.g. distinct site names. */
function distinct_options(string $sql): array
{
    $out = [];
    foreach (q($sql) as $row) {
        $v = (string)array_values($row)[0];
        $out[$v] = $v;
    }
    return $out;
}

/** Lab flag / severity badge. */
function badge(string $text, string $kind = ''): string
{
    return '<span class="badge ' . h($kind) . '">' . h($text) . '</span>';
}

function flag_badge(?string $flag): string
{
    return match ($flag) {
        'H' => badge('H', 'bad'),
        'L' => badge('L', 'warn'),
        default => badge('N', 'ok'),
    };
}
