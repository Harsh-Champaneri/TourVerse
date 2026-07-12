<?php

/**
 * =============================================================================
 * includes/pdf-builder.php — Shared PDF Generation Logic (Dompdf 3.x)
 * =============================================================================
 * This file contains all functions needed to generate the professional B&W
 * travel itinerary PDF using Dompdf 3.x.
 *
 * Used by:
 *   - api/generate-pdf.php  (download endpoint)
 *   - api/send-email.php    (email attachment generator)
 *
 * The PDF uses DejaVu Sans for Unicode support (₹ • symbols), table-based
 * layout, and page-break controls for clean printing.
 * =============================================================================
 */

// =============================================================================
// TEXT HELPERS
// =============================================================================

/**
 * Strip Markdown syntax and return clean plain text.
 * Removes code fences, bold/italic markers, headings, links, blockquotes.
 */
function stripMarkdown(string $text): string
{
    $text = preg_replace('/```[\s\S]*?```/', '', $text);
    $text = preg_replace('/`([^`]+)`/',       '$1', $text);
    $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '$1', $text);
    $text = preg_replace('/\*\*(.+?)\*\*/',    '$1', $text);
    $text = preg_replace('/\*(.+?)\*/',        '$1', $text);
    $text = preg_replace('/___(.+?)___/',       '$1', $text);
    $text = preg_replace('/__(.+?)__/',         '$1', $text);
    $text = preg_replace('/_(.+?)_/',           '$1', $text);
    $text = preg_replace('/^#{1,6}\s+/m',       '', $text);
    $text = preg_replace('/!\[.*?\]\(.*?\)/',   '', $text);
    $text = preg_replace('/\[(.+?)\]\(.+?\)/',  '$1', $text);
    $text = preg_replace('/^>\s*/m',            '', $text);
    $text = preg_replace('/\*+/',               '', $text);
    $text = preg_replace('/_{2,}/',             '', $text);
    $text = preg_replace('/[ \t]+/',            ' ', $text);
    $text = preg_replace('/\n{3,}/',            "\n\n", $text);
    return trim($text);
}

/** HTML-escape a value for safe output. */
function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Parse a raw bullet/numbered list into an array of clean HTML-escaped strings.
 * Handles "- item", "* item", "• item", "1. item" prefixes.
 */
function parseBullets(string $raw, int $max = 20): array
{
    $items = [];
    foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
        if (
            preg_match('/^[-*•]\s+(.+)$/', $line, $m)
            || preg_match('/^\d+\.\s+(.+)$/', $line, $m)
        ) {
            $items[] = esc(stripMarkdown($m[1]));
        } elseif (!preg_match('/^[#*]/', $line)) {
            $c = esc(stripMarkdown($line));
            if ($c !== '') $items[] = $c;
        }
        if (count($items) >= $max) break;
    }
    return $items;
}

// =============================================================================
// SECTION PARSER — day headings checked BEFORE generic headings (bug-fix)
// =============================================================================

/**
 * Parse raw AI markdown into structured sections.
 *
 * BUG-FIX NOTE: Day heading regex (/^#{1,4}\s*day\s*\d+/i) is tested FIRST,
 * before the generic heading block. Previously "### Day 1: …" matched the
 * generic block, fell to section='other', and no days were ever captured.
 */
function parseSections(string $raw): array
{
    $sections = [
        'overview'    => '',
        'days'        => [],   // [[dayTitle, dayContent], ...]
        'hotels'      => '',
        'attractions' => '',
        'food'        => '',
        'checklist'   => '',
        'budget'      => '',
        'tips'        => '',
        'safety'      => '',
        'eco'         => '',
    ];

    $lines         = explode("\n", $raw);
    $section       = 'other';
    $curDay        = null;
    $curDayContent = '';

    foreach ($lines as $line) {
        $trim  = trim($line);
        $lower = strtolower($trim);

        // 1. Day heading — MUST be tested BEFORE the generic heading block
        //    Matches: "### Day 1:", "#### Day 2 –", "**Day 3:**", "## Day 1"
        if (
            preg_match('/^#{1,4}\s*day\s*\d+/i', $trim)
            || preg_match('/^\*\*\s*day\s*\d+/i',   $trim)
        ) {
            if ($curDay !== null) {
                $sections['days'][] = [$curDay, $curDayContent];
            }
            $curDay        = stripMarkdown($trim);
            $curDayContent = '';
            $section       = 'itinerary';
            continue;
        }

        // 2. Generic section heading (# / ## / ### that is NOT a Day)
        if (preg_match('/^#{1,6}\s/', $trim)) {
            if ($curDay !== null) {
                $sections['days'][] = [$curDay, $curDayContent];
                $curDay             = null;
                $curDayContent      = '';
            }
            // Strip emoji / symbol characters so keyword matching is clean
            $head = trim(preg_replace('/[#*_\p{So}\p{Sm}]/u', '', $lower));

            if (str_contains($head, 'overview')   || str_contains($head, 'about')) {
                $section = 'overview';
            } elseif (str_contains($head, 'day-wise')   || str_contains($head, 'itinerary') || str_contains($head, 'schedule')) {
                $section = 'itinerary';
            } elseif (str_contains($head, 'hotel')      || str_contains($head, 'accommodation') || str_contains($head, 'recommend')) {
                $section = 'hotels';
            } elseif (str_contains($head, 'attract')    || str_contains($head, 'tourist')   || str_contains($head, 'landmark')) {
                $section = 'attractions';
            } elseif (str_contains($head, 'food')       || str_contains($head, 'cuisine')   || str_contains($head, 'eat')) {
                $section = 'food';
            } elseif (str_contains($head, 'pack')       || str_contains($head, 'checklist') || str_contains($head, 'bring')) {
                $section = 'checklist';
            } elseif (str_contains($head, 'budget')     || str_contains($head, 'cost')      || str_contains($head, 'expense')) {
                $section = 'budget';
            } elseif (str_contains($head, 'tip')        || str_contains($head, 'advice')) {
                $section = 'tips';
            } elseif (str_contains($head, 'safety')     || str_contains($head, 'safe')      || str_contains($head, 'emergency')) {
                $section = 'safety';
            } elseif (str_contains($head, 'eco')        || str_contains($head, 'green')     || str_contains($head, 'sustain')) {
                $section = 'eco';
            } else {
                $section = 'other';
            }
            continue;
        }

        if ($trim === '') continue;

        // 3. Accumulate content into the current section
        switch ($section) {
            case 'overview':
                $sections['overview']    .= $trim . "\n";
                break;
            case 'hotels':
                $sections['hotels']      .= $trim . "\n";
                break;
            case 'attractions':
                $sections['attractions'] .= $trim . "\n";
                break;
            case 'food':
                $sections['food']        .= $trim . "\n";
                break;
            case 'checklist':
                $sections['checklist']   .= $trim . "\n";
                break;
            case 'budget':
                $sections['budget']      .= $trim . "\n";
                break;
            case 'tips':
                $sections['tips']        .= $trim . "\n";
                break;
            case 'safety':
                $sections['safety']      .= $trim . "\n";
                break;
            case 'eco':
                $sections['eco']         .= $trim . "\n";
                break;
            case 'itinerary':
                if ($curDay !== null) {
                    $curDayContent .= $trim . "\n";
                }
                break;
        }
    }

    // Save last open day
    if ($curDay !== null) {
        $sections['days'][] = [$curDay, $curDayContent];
    }
    return $sections;
}

// =============================================================================
// LAYOUT HELPERS
// =============================================================================

/**
 * Section heading: 15px bold, bottom border, 32px top margin.
 * page-break-after:avoid keeps the heading with the first row of its table.
 */
function secHead(string $label): string
{
    return '<table width="100%" cellpadding="0" cellspacing="0"'
        . ' style="margin-top:32px;margin-bottom:8px;'
        . 'page-break-before:auto;page-break-after:avoid;page-break-inside:avoid;">'
        . '<tr><td style="padding:6px 0 5px 0;border-bottom:2px solid #000000;">'
        . '<span style="font-size:15px;font-weight:bold;color:#000000;letter-spacing:0.3px;">'
        . esc($label)
        . '</span>'
        . '</td></tr></table>';
}

/**
 * Render a <ul><li> bullet list from an array of pre-escaped strings.
 * Available as a utility for external callers.
 */
function bulletList(array $items, string $extraUlStyle = ''): string
{
    if (empty($items)) {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">'
            . 'Not available.</p>';
    }
    $html = '<ul style="margin:4px 0 0 0;padding-left:20px;' . $extraUlStyle . '">';
    foreach ($items as $item) {
        $html .= '<li style="font-size:11px;line-height:1.65;margin-bottom:3px;">'
            . $item . '</li>';
    }
    $html .= '</ul>';
    return $html;
}

// =============================================================================
// DATA PARSERS
// =============================================================================

/** Parse hotel structured data from raw AI text. */
function parseHotels(string $raw): array
{
    $hotels = [];
    $cur    = null;
    foreach (explode("\n", $raw) as $line) {
        $trim = trim($line);
        if ($trim === '') continue;

        // New hotel entry: "- **Hotel Name**"
        if (preg_match('/^[-*•]\s+\*\*(.+?)\*\*/', $trim, $m)) {
            if ($cur) $hotels[] = $cur;
            $cur = [
                'name' => stripMarkdown($m[1]),
                'desc' => '',
                'price' => '',
                'location' => '',
                'website' => '',
                'bestFor' => ''
            ];
            continue;
        }
        if (!$cur) continue;

        $sub = trim(preg_replace('/^[\s]*[-*•]\s*/', '', $trim));
        if (preg_match(
            '/^(Description|Estimated\s*Price|Price|Location|Website\/Map|Website|Map|Best\s*For)\s*[:–\-]\s*(.+)$/i',
            $sub,
            $m
        )) {
            $key = strtolower(preg_replace('/[\s\/]+/', '', $m[1]));
            $val = stripMarkdown($m[2]);
            if (str_starts_with($key, 'desc'))                              $cur['desc']     = $val;
            elseif (str_contains($key, 'price'))                                $cur['price']    = $val;
            elseif (str_starts_with($key, 'loc'))                               $cur['location'] = $val;
            elseif (str_contains($key, 'web') || str_contains($key, 'map'))     $cur['website']  = $val;
            elseif (str_contains($key, 'best') || str_contains($key, 'for'))    $cur['bestFor']  = $val;
        }
    }
    if ($cur) $hotels[] = $cur;
    return $hotels;
}

/**
 * Extract budget amounts from raw AI text.
 * Returns associative array: ['transport'=>N, 'hotel'=>N, ..., 'total'=>N]
 */
function parseBudgetAmounts(string $rawBudget): array
{
    $aliases = [
        'transport' => ['transport', 'flight', 'train', 'bus', 'cab', 'taxi', 'travel'],
        'hotel'     => ['hotel', 'accommod', 'stay', 'hostel', 'resort', 'lodge', 'room'],
        'food'      => ['food', 'meal', 'dining', 'restaurant', 'eat', 'cuisine'],
        'activity'  => ['activit', 'entertain', 'ticket', 'tour', 'excursion', 'sight'],
        'misc'      => ['misc', 'other', 'shopping', 'personal', 'extra', 'sundry'],
        'total'     => ['total', 'grand', 'overall', 'sum'],
    ];
    $found = [];

    foreach (explode("\n", $rawBudget) as $line) {
        $clean = trim($line);
        if ($clean === '') continue;
        $clean = preg_replace('/^[-*•]\s+/', '', $clean);
        $clean = preg_replace('/\*\*([^*]+)\*\*/', '$1', $clean);
        $clean = preg_replace('/\*+/', '', $clean);
        if (!preg_match('/\d/', $clean)) continue;
        $lower = strtolower($clean);

        preg_match('/(?:INR|USD|EUR|GBP|AED|SGD|THB|JPY|AUD|[₹$€£])\s*([\d,]+)/i', $clean, $am)
            || preg_match('/([\d,]+)\s*(?:INR|USD|EUR|GBP|AED|SGD|THB|JPY|AUD|[₹$€£])/i', $clean, $am)
            || preg_match('/([\d,]+)/', $clean, $am);
        $amount = isset($am[1]) ? (int) str_replace(',', '', $am[1]) : 0;
        if ($amount === 0) continue;

        foreach ($aliases as $key => $words) {
            foreach ($words as $w) {
                if (str_contains($lower, $w) && !isset($found[$key])) {
                    $found[$key] = $amount;
                    break 2;
                }
            }
        }
    }

    if (!isset($found['total'])) {
        $sum = array_sum(array_diff_key($found, ['total' => 0]));
        if ($sum > 0) $found['total'] = $sum;
    }
    return $found;
}

// =============================================================================
// SECTION RENDERERS
// =============================================================================

// ---------------------------------------------------------------------------
// RENDER: Day-wise Itinerary
// ---------------------------------------------------------------------------
/**
 * Each day = one bordered table that stays together (page-break-inside:avoid).
 *
 * Layout per day:
 *   ┌──────────────────────────────────────────────────┐
 *   │  DAY 1: ARRIVAL IN PARIS       (dark header)     │
 *   ├────────────┬─────────────────────────────────────┤
 *   │  Morning   │  • Visit Eiffel Tower               │
 *   │  Afternoon │  • Lunch at Café de Flore           │
 *   │  Evening   │  • Seine river cruise               │
 *   └────────────┴─────────────────────────────────────┘
 */
function renderDays(array $days): string
{
    if (empty($days)) {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">'
            . 'Day-wise itinerary not available.</p>';
    }

    $html = '';
    foreach ($days as $idx => [$title, $content]) {
        $num    = $idx + 1;
        $theme  = trim(preg_replace('/^day\s*\d+[:.\-–]?\s*/iu', '', $title));
        $dayLbl = 'DAY ' . $num . ($theme ? ': ' . mb_strtoupper($theme) : '');

        // Parse time-of-day sub-sections
        $segs = ['Morning' => '', 'Afternoon' => '', 'Evening' => '', 'Night' => ''];
        $cur  = null;
        foreach (explode("\n", $content) as $ln) {
            $lc = strtolower(trim($ln));
            if (str_contains($lc, 'morning')) {
                $cur = 'Morning';
                continue;
            }
            if (str_contains($lc, 'afternoon')) {
                $cur = 'Afternoon';
                continue;
            }
            if (str_contains($lc, 'evening')) {
                $cur = 'Evening';
                continue;
            }
            if (str_contains($lc, 'night')) {
                $cur = 'Night';
                continue;
            }
            if ($cur !== null && trim($ln) !== '') {
                $segs[$cur] .= trim($ln) . "\n";
            }
        }

        $hasSeg = false;
        foreach ($segs as $v) {
            if (trim($v) !== '') {
                $hasSeg = true;
                break;
            }
        }

        // Outer wrapper — page-break-inside:avoid keeps each day together
        $html .= '<table width="100%" cellpadding="0" cellspacing="0"'
            . ' style="border-collapse:collapse;margin-top:10px;margin-bottom:16px;'
            . 'border:1px solid #cccccc;page-break-inside:avoid;">';

        // Day header row
        $html .= '<tr>'
            . '<td colspan="2"'
            . ' style="background:#1a1a1a;color:#ffffff;font-size:13px;font-weight:bold;'
            . 'padding:8px 12px;letter-spacing:0.4px;">'
            . esc($dayLbl) . '</td></tr>';

        if ($hasSeg) {
            // Sub-header row
            $html .= '<tr style="background:#f0f0f0;">'
                . '<td width="18%" style="border-top:1px solid #cccccc;border-right:1px solid #cccccc;'
                . 'padding:5px 10px;font-size:10px;font-weight:bold;color:#333333;">TIME</td>'
                . '<td style="border-top:1px solid #cccccc;padding:5px 10px;'
                . 'font-size:10px;font-weight:bold;color:#333333;">ACTIVITIES</td>'
                . '</tr>';

            foreach ($segs as $segName => $segContent) {
                if (trim($segContent) === '') continue;

                $bullets = parseBullets($segContent, 8);
                if (!empty($bullets)) {
                    $actHtml = '<ul style="margin:0;padding-left:16px;">';
                    foreach ($bullets as $b) {
                        $actHtml .= '<li style="font-size:11px;line-height:1.65;margin-bottom:2px;">'
                            . $b . '</li>';
                    }
                    $actHtml .= '</ul>';
                } else {
                    $actHtml = '<span style="font-size:11px;line-height:1.65;">'
                        . esc(stripMarkdown(trim($segContent))) . '</span>';
                }

                $html .= '<tr>'
                    . '<td width="18%" valign="top"'
                    . ' style="border-top:1px solid #e0e0e0;border-right:1px solid #cccccc;'
                    . 'padding:8px 10px;font-size:11px;font-weight:bold;'
                    . 'background:#fafafa;vertical-align:top;">'
                    . esc($segName) . '</td>'
                    . '<td valign="top"'
                    . ' style="border-top:1px solid #e0e0e0;padding:8px 10px;">'
                    . $actHtml . '</td>'
                    . '</tr>';
            }
        } else {
            // No time segments — list all bullets spanning both columns
            $bullets = parseBullets($content, 15);
            if (!empty($bullets)) {
                $html .= '<tr><td colspan="2" style="border-top:1px solid #cccccc;padding:8px 12px;">'
                    . '<ul style="margin:0;padding-left:18px;">';
                foreach ($bullets as $b) {
                    $html .= '<li style="font-size:11px;line-height:1.65;margin-bottom:3px;">'
                        . $b . '</li>';
                }
                $html .= '</ul></td></tr>';
            } else {
                $plain = esc(stripMarkdown(trim($content)));
                $html .= '<tr><td colspan="2"'
                    . ' style="border-top:1px solid #cccccc;padding:8px 12px;'
                    . 'font-size:11px;line-height:1.65;">'
                    . $plain . '</td></tr>';
            }
        }

        $html .= '</table>';
    }
    return $html;
}

// ---------------------------------------------------------------------------
// RENDER: Hotel Recommendations
// ---------------------------------------------------------------------------
function renderHotels(array $hotels): string
{
    if (empty($hotels)) {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">'
            . 'Hotel recommendations not available. Please check Booking.com or MakeMyTrip.</p>';
    }

    $html = '<table width="100%" cellpadding="0" cellspacing="0"'
        . ' style="border-collapse:collapse;margin-top:4px;">';

    $html .= '<tr style="background:#1a1a1a;">'
        . '<td width="22%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 9px;border:1px solid #000;">Hotel Name</td>'
        . '<td width="33%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 9px;border:1px solid #000;">Description</td>'
        . '<td width="15%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 9px;border:1px solid #000;">Price / Night</td>'
        . '<td width="18%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 9px;border:1px solid #000;">Location</td>'
        . '<td width="12%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 9px;border:1px solid #000;">Best For</td>'
        . '</tr>';

    foreach ($hotels as $idx => $h) {
        $bg = ($idx % 2 === 0) ? '#ffffff' : '#f8f8f8';
        $q  = urlencode(($h['name'] ?? '') . ' hotel ' . ($h['location'] ?? ''));
        $ws = trim($h['website'] ?? '');
        $href = preg_match('/^https?:\/\//i', $ws) ? esc($ws)
            : 'https://www.google.com/maps/search/?api=1&query=' . $q;

        $mapLink = '<p style="margin:3px 0 0 0;font-size:10px;">'
            . '<a href="' . $href . '" style="color:#000000;text-decoration:underline;">'
            . 'View on Google Maps</a></p>';

        $html .= '<tr style="background:' . $bg . ';page-break-inside:avoid;">'
            . '<td width="22%" valign="top" style="border:1px solid #cccccc;padding:7px 9px;font-size:11px;">'
            . '<p style="margin:0;font-weight:bold;">' . esc($h['name']) . '</p>' . $mapLink . '</td>'
            . '<td width="33%" valign="top" style="border:1px solid #cccccc;padding:7px 9px;font-size:11px;line-height:1.55;">'
            . esc($h['desc'] ?: '—') . '</td>'
            . '<td width="15%" valign="top" style="border:1px solid #cccccc;padding:7px 9px;font-size:11px;">'
            . esc($h['price'] ?: 'N/A') . '</td>'
            . '<td width="18%" valign="top" style="border:1px solid #cccccc;padding:7px 9px;font-size:11px;">'
            . esc($h['location'] ?: '—') . '</td>'
            . '<td width="12%" valign="top" style="border:1px solid #cccccc;padding:7px 9px;font-size:11px;">'
            . esc($h['bestFor'] ?: '—') . '</td>'
            . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

// ---------------------------------------------------------------------------
// RENDER: Numbered two-column table (Attractions / Food)
// ---------------------------------------------------------------------------
function renderItemTable(string $raw, string $col1Label, string $col2Label, int $max = 8): string
{
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    $items = [];
    foreach ($lines as $line) {
        $bullet = '';
        if (
            preg_match('/^[-*•]\s+(.+)$/', $line, $m)
            || preg_match('/^\d+\.\s+(.+)$/', $line, $m)
        ) {
            $bullet = $m[1];
        } elseif (!preg_match('/^[#*]/', $line)) {
            $bullet = $line;
        }
        if ($bullet === '') continue;
        $clean  = stripMarkdown($bullet);
        $parts  = preg_split('/[:\-–]/', $clean, 2);
        $name   = trim($parts[0]);
        $desc   = isset($parts[1]) ? trim($parts[1]) : '';
        if ($name === '') continue;
        $items[] = [$name, $desc];
        if (count($items) >= $max) break;
    }

    if (empty($items)) {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">Not available.</p>';
    }

    $html  = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:4px;">';
    $html .= '<tr style="background:#1a1a1a;">'
        . '<td width="5%" style="color:#fff;font-weight:bold;font-size:10px;padding:6px 8px;border:1px solid #000;text-align:center;">#</td>'
        . '<td width="30%" style="color:#fff;font-weight:bold;font-size:10px;padding:6px 8px;border:1px solid #000;">' . esc($col1Label) . '</td>'
        . '<td style="color:#fff;font-weight:bold;font-size:10px;padding:6px 8px;border:1px solid #000;">' . esc($col2Label) . '</td>'
        . '</tr>';

    foreach ($items as $i => [$name, $desc]) {
        $bg    = ($i % 2 === 0) ? '#ffffff' : '#f8f8f8';
        $html .= '<tr style="background:' . $bg . ';page-break-inside:avoid;">'
            . '<td style="border:1px solid #cccccc;padding:6px 8px;font-size:11px;text-align:center;font-weight:bold;">' . ($i + 1) . '</td>'
            . '<td style="border:1px solid #cccccc;padding:6px 8px;font-size:11px;font-weight:bold;">' . esc($name) . '</td>'
            . '<td style="border:1px solid #cccccc;padding:6px 8px;font-size:11px;line-height:1.55;">' . esc($desc ?: '—') . '</td>'
            . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

// ---------------------------------------------------------------------------
// RENDER: Packing Checklist (2-column, plain "- " dash bullets)
// ---------------------------------------------------------------------------
function renderChecklist(string $raw): string
{
    $items = parseBullets($raw, 30);
    if (empty($items)) {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">Not available.</p>';
    }

    $html   = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:4px;">';
    $chunks = array_chunk($items, (int) ceil(count($items) / 2));
    $col0   = $chunks[0] ?? [];
    $col1   = $chunks[1] ?? [];
    $rows   = max(count($col0), count($col1));

    for ($i = 0; $i < $rows; $i++) {
        $bg    = ($i % 2 === 0) ? '#ffffff' : '#f8f8f8';
        $left  = isset($col0[$i]) ? ('- ' . $col0[$i]) : '&nbsp;';
        $right = isset($col1[$i]) ? ('- ' . $col1[$i]) : '&nbsp;';
        $html .= '<tr style="background:' . $bg . ';">'
            . '<td width="50%" style="border:1px solid #cccccc;padding:5px 10px;font-size:11px;">' . $left  . '</td>'
            . '<td width="50%" style="border:1px solid #cccccc;padding:5px 10px;font-size:11px;">' . $right . '</td>'
            . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

// ---------------------------------------------------------------------------
// RENDER: Budget Breakdown table
// ---------------------------------------------------------------------------
function renderBudget(string $rawBudget, string $currency): string
{
    if (trim($rawBudget) === '') {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">Budget details not available.</p>';
    }

    $labels = [
        'transport' => 'Transport & Flights',
        'hotel'     => 'Accommodation',
        'food'      => 'Food & Dining',
        'activity'  => 'Activities & Entry',
        'misc'      => 'Miscellaneous',
        'total'     => 'TOTAL',
    ];

    $found = parseBudgetAmounts($rawBudget);

    if (empty($found)) {
        return '<pre style="font-size:11px;white-space:pre-wrap;border:1px solid #cccccc;padding:8px 12px;margin:4px 0 0 0;line-height:1.65;">'
            . esc(stripMarkdown($rawBudget)) . '</pre>';
    }

    $currSymbol = ($currency === 'INR') ? '&#x20B9;' : esc($currency);

    $html  = '<table width="55%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:4px;">';
    $html .= '<tr style="background:#1a1a1a;">'
        . '<td width="60%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 10px;border:1px solid #000;">Category</td>'
        . '<td width="40%" style="color:#fff;font-weight:bold;font-size:10px;padding:7px 10px;border:1px solid #000;text-align:right;">Estimated Cost (' . $currSymbol . ')</td>'
        . '</tr>';

    $rowIdx = 0;
    foreach ($labels as $key => $label) {
        if (!isset($found[$key])) continue;
        $isTotal = ($key === 'total');
        $amt     = number_format($found[$key]);
        $amtDisp = $currSymbol . '&nbsp;' . $amt;

        if ($isTotal) {
            $html .= '<tr style="background:#000000;page-break-inside:avoid;">'
                . '<td style="border:1px solid #000000;border-top:2px solid #000000;padding:8px 10px;font-size:12px;font-weight:bold;color:#ffffff;">' . esc($label) . '</td>'
                . '<td style="border:1px solid #000000;border-top:2px solid #000000;padding:8px 10px;font-size:12px;font-weight:bold;color:#ffffff;text-align:right;">' . $amtDisp . '</td>'
                . '</tr>';
        } else {
            $bg    = ($rowIdx % 2 === 0) ? '#ffffff' : '#f8f8f8';
            $html .= '<tr style="background:' . $bg . ';page-break-inside:avoid;">'
                . '<td style="border:1px solid #cccccc;padding:6px 10px;font-size:11px;">' . esc($label) . '</td>'
                . '<td style="border:1px solid #cccccc;padding:6px 10px;font-size:11px;text-align:right;">' . $amtDisp . '</td>'
                . '</tr>';
            $rowIdx++;
        }
    }
    $html .= '</table>';
    return $html;
}

// ---------------------------------------------------------------------------
// RENDER: Numbered bullet list (Tips / Safety / Eco)
// ---------------------------------------------------------------------------
function renderListTable(string $raw, int $max = 8): string
{
    $items = parseBullets($raw, $max);
    if (empty($items)) {
        return '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">Not available.</p>';
    }

    $html = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:4px;">';
    foreach ($items as $i => $item) {
        $bg    = ($i % 2 === 0) ? '#ffffff' : '#f8f8f8';
        $html .= '<tr style="background:' . $bg . ';page-break-inside:avoid;">'
            . '<td width="28" valign="top" style="border:1px solid #cccccc;padding:6px 8px;font-size:11px;font-weight:bold;text-align:center;color:#333333;">' . ($i + 1) . '</td>'
            . '<td valign="top" style="border:1px solid #cccccc;padding:6px 10px;font-size:11px;line-height:1.65;">' . $item . '</td>'
            . '</tr>';
    }
    $html .= '</table>';
    return $html;
}

// =============================================================================
// MAIN HTML BUILDER
// =============================================================================

function buildPdfHtml(
    string $destination,
    string $startDate,
    string $endDate,
    string $travellers,
    string $travelType,
    string $budget,
    string $budgetAmount,
    string $currency,
    string $origin,
    string $itinerary
): string {
    $appName   = APP_NAME;
    $generated = date('F j, Y \a\t h:i A');
    $year      = date('Y');

    // Duration calculation
    $daysCount = 0;
    $daysText  = '';
    if ($startDate && $endDate) {
        $daysCount = (int) ceil((strtotime($endDate) - strtotime($startDate)) / 86400);
        $daysText  = $daysCount . ' Day' . ($daysCount !== 1 ? 's' : '');
    }
    $fmtStart  = $startDate ? date('d M Y', strtotime($startDate)) : 'N/A';
    $fmtEnd    = $endDate   ? date('d M Y', strtotime($endDate))   : 'N/A';

    // Budget display string
    $currSym   = ($currency === 'INR') ? '&#x20B9;' : esc($currency);
    $budgetStr = esc($budget)
        . ($budgetAmount ? ' &mdash; ' . $currSym . '&nbsp;' . esc($budgetAmount) : '');

    // Parse AI output into sections
    $sec = parseSections($itinerary);

    // Render each section
    $overviewHtml = $sec['overview']
        ? '<p style="font-size:11px;line-height:1.8;color:#000000;margin:6px 0 0 0;">'
        . nl2br(esc(stripMarkdown($sec['overview']))) . '</p>'
        : '<p style="font-size:11px;font-style:italic;color:#555555;margin:4px 0 0 0;">Overview not available.</p>';

    $daysHtml        = renderDays($sec['days']);
    $hotels          = parseHotels($sec['hotels']);
    $hotelsHtml      = renderHotels($hotels);
    $attractionsHtml = renderItemTable($sec['attractions'], 'Attraction', 'Details / Why Visit', 8);
    $foodHtml        = renderItemTable($sec['food'],        'Dish / Restaurant', 'Details & Where to Find', 8);
    $checklistHtml   = renderChecklist($sec['checklist']);
    $budgetHtml      = renderBudget($sec['budget'], $currency);
    $tipsHtml        = renderListTable($sec['tips'],   8);
    $safetyHtml      = renderListTable($sec['safety'], 8);
    $ecoHtml         = renderListTable($sec['eco'],    6);

    // Pre-compute section headings (no function calls in heredoc)
    $hOverview    = secHead('1. Destination Overview');
    $hItinerary   = secHead('2. Day-wise Itinerary');
    $hHotels      = secHead('3. Hotel Recommendations');
    $hAttractions = secHead('4. Top Tourist Attractions');
    $hFood        = secHead('5. Local Food &amp; Cuisine Guide');
    $hChecklist   = secHead('6. Packing Checklist');
    $hBudget      = secHead('7. Budget Breakdown');
    $hTips        = secHead('8. Travel Tips');
    $hSafety      = secHead('9. Safety Guidelines');
    $hEco         = secHead('10. Eco-Friendly Suggestions');

    // Origin row — only rendered if origin was provided
    $originRow = $origin
        ? '<tr>'
        . '<td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;'
        . 'font-weight:bold;background:#f5f5f5;width:35%;">Origin / Departing From</td>'
        . '<td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">'
        . esc($origin) . '</td></tr>'
        : '';

    // Hotel section block — omitted entirely if AI returned no hotels
    $hotelBlock = !empty($hotels) ? ($hHotels . $hotelsHtml) : '';

    // Nights count label
    $nightsLabel = $daysCount > 0
        ? esc($daysText) . ' / ' . ($daysCount - 1) . ' Night' . (($daysCount - 1) !== 1 ? 's' : '')
        : 'N/A';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Travel Itinerary &mdash; {$destination}</title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: 'DejaVu Sans', DejaVu Sans, sans-serif;
      font-size: 11px;
      line-height: 1.6;
      color: #000000;
      background: #ffffff;
      margin: 0;
      padding: 0;
    }
    @page { size: A4 portrait; margin: 20mm 20mm 20mm 20mm; }
    a          { color: #000000; text-decoration: underline; }
    p          { margin: 0 0 6px 0; }
    ul         { margin: 0; padding-left: 18px; }
    li         { margin-bottom: 3px; }
    h1,h2,h3   { margin: 0; padding: 0; }
    table      { border-collapse: collapse; }
    pre        { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; }
  </style>
</head>
<body>

<table width="100%" cellpadding="0" cellspacing="0"
       style="margin-bottom:20px;padding-bottom:14px;border-bottom:3px double #000000;
              page-break-after:avoid;page-break-inside:avoid;">
  <tr>
    <td style="text-align:center;">
      <p style="font-size:11px;font-weight:bold;letter-spacing:2px;color:#555555;margin:0 0 6px 0;text-transform:uppercase;">
        AI-Generated Travel Report
      </p>
      <p style="font-size:22px;font-weight:bold;margin:0 0 4px 0;letter-spacing:0.5px;">{$destination}</p>
      <p style="font-size:12px;color:#333333;margin:0 0 2px 0;">
        {$fmtStart} &mdash; {$fmtEnd} &nbsp;&nbsp;&bull;&nbsp;&nbsp; {$nightsLabel}
      </p>
      <p style="font-size:10px;color:#666666;margin:0;">
        Powered by IBM watsonx.ai Granite &nbsp;|&nbsp; {$appName}
      </p>
    </td>
  </tr>
</table>

<table width="65%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse;margin-bottom:8px;page-break-inside:avoid;">
  <tr>
    <td colspan="2" style="background:#1a1a1a;color:#ffffff;font-weight:bold;
        font-size:10px;padding:7px 10px;border:1px solid #000000;letter-spacing:0.5px;">
      TRIP DETAILS
    </td>
  </tr>
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;width:38%;">Destination</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$destination}</td>
  </tr>
  {$originRow}
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;">Travel Dates</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$fmtStart} &mdash; {$fmtEnd}</td>
  </tr>
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;">Duration</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$nightsLabel}</td>
  </tr>
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;">Travellers</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$travellers}</td>
  </tr>
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;">Travel Type</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$travelType}</td>
  </tr>
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;">Budget</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$budgetStr}</td>
  </tr>
  <tr>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;font-weight:bold;background:#f5f5f5;">Currency</td>
    <td style="border:1px solid #cccccc;padding:7px 10px;font-size:11px;">{$currency}</td>
  </tr>
</table>

{$hOverview}
{$overviewHtml}

{$hItinerary}
{$daysHtml}

{$hotelBlock}

{$hAttractions}
{$attractionsHtml}

{$hFood}
{$foodHtml}

{$hChecklist}
{$checklistHtml}

{$hBudget}
{$budgetHtml}

{$hTips}
{$tipsHtml}

{$hSafety}
{$safetyHtml}

{$hEco}
{$ecoHtml}

<table width="100%" cellpadding="0" cellspacing="0"
       style="margin-top:40px;border-top:1px solid #cccccc;padding-top:12px;">
  <tr>
    <td style="text-align:center;">
      <p style="font-size:11px;font-weight:bold;margin:0 0 3px 0;">{$appName}</p>
      <p style="font-size:10px;margin:0 0 2px 0;">
        Powered by IBM watsonx.ai Granite &nbsp;&bull;&nbsp; Developed by Harsh Rakeshkumar Champaneri
      </p>
      <p style="font-size:10px;margin:0 0 8px 0;color:#444444;">Generated: {$generated}</p>
      <p style="font-size:9px;color:#666666;margin:0;line-height:1.6;">
        <em>Disclaimer: This itinerary is AI-generated for planning purposes only.
        Please verify all bookings, schedules, hotel availability, weather conditions,
        visa requirements, and travel advisories independently before travelling.</em>
      </p>
      <p style="font-size:9px;color:#666666;margin:4px 0 0 0;">
        &copy; {$year} TourVerse &mdash; All rights reserved.
      </p>
    </td>
  </tr>
</table>

</body>
</html>
HTML; 
}

// =============================================================================
// PDF BINARY GENERATOR — used by api/send-email.php for email attachment
// =============================================================================

/**
 * Generate the travel itinerary as a PDF binary string (in memory only).
 *
 * Both api/generate-pdf.php (download) and api/send-email.php (attachment)
 * call this shared include so the PDF is identical in both cases.
 *
 * @return string|null  Raw PDF bytes on success, null on failure (Dompdf unavailable
 *                      or render error — caller should show an error message).
 */
function generateItineraryPdfBinary(
    string $destination,
    string $startDate,
    string $endDate,
    string $travellers,
    string $travelType,
    string $budget,
    string $budgetAmount,
    string $currency,
    string $origin,
    string $itinerary
): ?string {
    // Build the exact same HTML used by the download endpoint
    $htmlContent = buildPdfHtml(
        $destination,
        $startDate,
        $endDate,
        $travellers,
        $travelType,
        $budget,
        $budgetAmount,
        $currency,
        $origin,
        $itinerary
    );

    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        return null;
    }

    require_once __DIR__ . '/../vendor/autoload.php';

    if (!class_exists('\Dompdf\Dompdf')) {
        return null;
    }

    try {
        $options = new \Dompdf\Options();
        $options->set('defaultFont',             'DejaVu Sans');
        $options->set('isRemoteEnabled',         false);
        $options->set('isHtml5ParserEnabled',    true);

        $options->set('isFontSubsettingEnabled', true);
        $options->set('defaultPaperSize',        'A4');
        $options->set('defaultPaperOrientation', 'portrait');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($htmlContent, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output(); // Binary PDF string — no file written to disk
    } catch (Throwable $e) {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            error_log('PDF generation error: ' . $e->getMessage());
        }
        return null;
    }
}
