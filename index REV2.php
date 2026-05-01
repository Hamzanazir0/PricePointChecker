<?php
// price-checker.php
// Strict price matching tool with improved UI (Tailwind).
// Place this on your PHP server and open in browser.

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pricesInput = $_POST['prices'] ?? '';
    $url = trim($_POST['url'] ?? '');
    $result = check_prices_on_terms_page($pricesInput, $url);
}

/**
 * Main checker function
 */
function check_prices_on_terms_page($pricesInput, $url)
{
    $out = [
        'error' => null,
        'results' => [], // array of per-input results
        'missing' => [],
        'total_found_count' => 0,
        'tokens_on_page' => [] // normalized => occurrences (raw tokens)
    ];

    // Basic URL check: must be non-empty
    if ($url === '') {
        $out['error'] = 'Please provide a URL.';
        return $out;
    }

    // Fetch page
    $fetch = fetch_url($url);
    if (isset($fetch['error'])) {
        $out['error'] = $fetch['error'];
        return $out;
    }
    $content = $fetch['content'];

    // Parse HTML, select only div.terms_cond elements
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // convert to HTML-ENTITIES to better handle utf-8 content
    $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' terms_cond ')]");

    $text = '';
    foreach ($nodes as $n) {
        $text .= ' ' . $n->textContent;
    }

    // If no div.terms_cond found, let user know and still attempt matching on whole body text
    if (trim($text) === '') {
        // fallback to whole body text (but inform user)
        $bodyNode = $xpath->query("//body");
        if ($bodyNode->length) {
            $text = $bodyNode->item(0)->textContent;
            // We'll not throw error; matching will happen on body text if terms_cond absent
        }
    }

    // Strict numeric token extraction:
    // - avoid partial matches by ensuring tokens are not adjacent to other digits/dots
    // - match optional currency symbol prefix ($,£,€,Rs, etc) but we only keep numeric content
    // Regex explanation:
    // (?<![\d.])   -> previous char is not digit or dot (or start of string)
    // \$?          -> optional dollar (escaped)
    // \d{1,3}(?:,\d{3})* -> integer part allowing thousands commas
    // (?:\.\d+)?   -> optional fractional part
    // (?![\d.])    -> next char is not digit or dot (or end of string)
    preg_match_all('/(?<![\\d\\.])\\$?\\d{1,3}(?:,\\d{3})*(?:\\.\\d+)?(?![\\d\\.])/', $text, $matches);

    $found_norm = []; // normalized => array of raw tokens occurrences
    foreach ($matches[0] as $tok) {
        $norm = normalize_price($tok);
        if ($norm === '') continue;
        if (!isset($found_norm[$norm])) $found_norm[$norm] = [];
        $found_norm[$norm][] = $tok;
    }

    // return tokens_on_page for debug/inspection (but not the previously-requested all-tokens table)
    $out['tokens_on_page'] = $found_norm;

    // Prepare user inputs
    $inputs_raw = preg_split('/[\r\n]+|\\s+/', trim($pricesInput));
    // keep track of seen normalized inputs so duplicates in input are reported consistently
    $seen_norm = [];

    foreach ($inputs_raw as $input_raw) {
        $input_raw = trim($input_raw);
        if ($input_raw === '') continue;
        $norm = normalize_price($input_raw);
        if ($norm === '') {
            // malformed numeric input
            $out['results'][] = [
                'input' => $input_raw,
                'normalized' => '',
                'found' => false,
                'count' => 0,
                'matches' => [],
                'note' => 'Invalid numeric input after stripping non-numeric chars'
            ];
            $out['missing'][] = $input_raw;
            continue;
        }

        // If we already processed the same normalized input earlier, we still push a repeated result
        // but reuse the information from found_norm
        $matches_on_page = $found_norm[$norm] ?? [];
        $count = count($matches_on_page);
        $found_flag = $count > 0;

        $out['results'][] = [
            'input' => $input_raw,
            'normalized' => $norm,
            'found' => $found_flag,
            'count' => $count,
            'matches' => $matches_on_page
        ];

        if (!$found_flag) $out['missing'][] = $input_raw;
        if ($found_flag) $out['total_found_count'] += $count;

        $seen_norm[$norm] = true;
    }

    return $out;
}

/**
 * Fetch URL using cURL — returns ['content'=>...,] or ['error'=>...]
 */
function fetch_url($url)
{
    // Basic sanitization: allow http/https only
    if (!preg_match('#^https?://#i', $url)) return ['error' => 'URL must start with http:// or https://'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'PriceChecker/1.0 (+https://yourdomain.example)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) return ['error' => 'Fetch error: ' . ($err ?: 'unknown')];
    if ($http >= 400) return ['error' => "HTTP error: $http"];
    return ['content' => $res];
}

/**
 * Normalize price string:
 * - remove currency symbols / letters except digits and dot and minus
 * - remove commas
 * - keep single dot (if multiple dots, join after first)
 * - remove leading zeros from integer part
 * - remove trailing zeros from fractional part
 * - drop fractional part if it becomes empty
 *
 * Examples:
 *  "$90.00" -> "90"
 *  "1.90" -> "1.9"
 *  "390" -> "390"
 *  "003.900" -> "3.9"
 */
function normalize_price($s)
{
    $s = trim($s);
    if ($s === '') return '';

    // Keep only digits and dots and minus
    // This strips currency symbols like $ € £ Rs.
    $s = preg_replace('/[^0-9\\.\\-]/', '', $s);
    if ($s === '') return '';

    // If multiple dots, keep first dot, remove others
    if (substr_count($s, '.') > 1) {
        $parts = explode('.', $s);
        $s = array_shift($parts) . '.' . implode('', $parts);
    }

    // Split into integer/fraction
    if (strpos($s, '.') !== false) {
        list($int, $frac) = explode('.', $s, 2);
        // Remove any commas leftover (shouldn't be any) and leading zeros but keep single zero
        $int = preg_replace('/^0+(?=\d)/', '', preg_replace('/,/', '', $int));
        if ($int === '') $int = '0';
        // Trim trailing zeros in fractional part
        $frac = rtrim($frac, '0');
        if ($frac === '') {
            return $int;
        }
        return $int . '.' . $frac;
    } else {
        // integer only
        $s = preg_replace('/^0+(?=\d)/', '', preg_replace('/,/', '', $s));
        if ($s === '') $s = '0';
        return $s;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title>Price Points Checker</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-10 px-4">
        <div class="bg-white shadow-md rounded-lg p-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold">Price Points Checker</h1>
                <span class="text-sm text-gray-500">Strict numeric matching inside <code class="bg-gray-100 px-2 py-0.5 rounded">div.terms_cond</code></span>
            </div>

            <!-- Instruction block -->
            <div class="mt-4 p-4 bg-indigo-50 border border-indigo-100 rounded">
                <h3 class="font-medium">How this tool matches prices (important)</h3>
                <ul class="mt-2 text-sm text-gray-700 space-y-1">
                    <li>1. The script extracts **numeric tokens** only from elements with <code>class="terms_cond"</code>. If none exist it falls back to the page body.</li>
                    <li>2. Tokens and your inputs are <strong>normalized</strong> the same way: currency symbols & commas are removed, leading zeros removed, trailing fractional zeros removed. Examples: <code>$90.00 → 90</code>, <code>1.90 → 1.9</code>.</li>
                    <li>3. Matching is <strong>exact on normalized values</strong>. So <code>90</code> will <em>not</em> match <code>1.90</code> or <code>390</code>. This prevents false positives.</li>
                    <li>4. The tool reports how many times a normalized price appears in the terms page (occurrence counter).</li>
                </ul>
            </div>

            <!-- Form -->
            <form method="post" class="mt-6 grid gap-4">
                <label class="block">
                    <div class="text-sm font-medium text-gray-700">Paste price points (one per line or space-separated)</div>
                    <textarea name="prices" rows="6" required class="mt-2 block w-full rounded-md border-gray-200 shadow-sm focus:ring-indigo-500 focus:border-indigo-500"><?php echo isset($_POST['prices']) ? htmlspecialchars($_POST['prices']) : "3.79\n4.88\n5.93\n90"; ?></textarea>
                </label>

                <label class="block">
                    <div class="text-sm font-medium text-gray-700">Terms page URL</div>
                    <input type="url" name="url" placeholder="https://example.com/terms.php" value="<?php echo isset($_POST['url']) ? htmlspecialchars($_POST['url']) : ''; ?>" required class="mt-2 block w-full rounded-md border-gray-200 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" />
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700">Check Prices</button>
                    <button type="button" id="clearBtn" class="inline-flex items-center px-3 py-2 border rounded text-sm text-gray-600 hover:bg-gray-100">Clear</button>
                </div>
            </form>

            <!-- Results -->
            <?php if (!empty($result)): ?>
                <div class="mt-6">
                    <?php if ($result['error']): ?>
                        <div class="p-4 bg-red-50 border border-red-100 text-red-700 rounded">
                            <strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?>
                        </div>
                    <?php else: ?>
                        <div class="p-4 bg-white border rounded shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm text-gray-600">Total matching price occurrences found: <span class="font-semibold text-indigo-600"><?php echo (int) $result['total_found_count']; ?></span></p>
                                </div>
                                <div class="text-sm">
                                    <span class="text-green-600 mr-3">Found: <?php echo count(array_filter($result['results'], fn($r) => $r['found'])); ?></span>
                                    <span class="text-red-600">Missing: <?php echo count($result['missing']); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($result['missing'])): ?>
                                <div class="mt-4 p-3 bg-yellow-50 border rounded text-sm text-yellow-800">
                                    <strong>Missing values:</strong>
                                    <div class="mt-1"><?php echo htmlspecialchars(implode(', ', $result['missing'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <!-- Found / missing table -->
                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-4 py-2 text-left">Input</th>
                                            <th class="px-4 py-2 text-left">Normalized</th>
                                            <th class="px-4 py-2 text-left">Matched?</th>
                                            <th class="px-4 py-2 text-left">Occurrences</th>
                                            <th class="px-4 py-2 text-left">Page matches (raw tokens)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-100">
                                        <?php foreach ($result['results'] as $r): ?>
                                            <tr>
                                                <td class="px-4 py-2"><?php echo htmlspecialchars($r['input']); ?></td>
                                                <td class="px-4 py-2"><?php echo htmlspecialchars($r['normalized']); ?></td>
                                                <td class="px-4 py-2">
                                                    <?php if ($r['found']): ?>
                                                        <span class="text-green-700 font-medium">Yes</span>
                                                    <?php else: ?>
                                                        <span class="text-red-600 font-medium">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-2"><?php echo (int)$r['count']; ?></td>
                                                <td class="px-4 py-2"><?php echo $r['matches'] ? htmlspecialchars(implode(', ', $r['matches'])) : '<span class="text-gray-400">—</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mt-6 text-xs text-gray-500">
                <p><strong>Notes:</strong> This tool extracts numeric tokens only from <code>div.terms_cond</code> elements (fallback to body if none). Matching is performed after normalization (strip currency symbols & commas; collapse multiple dots; remove trailing fractional zeros) so matching is strict and should not produce false positives like "90" matching "390" or "1.90".</p>
            </div>
        </div>
    </div>

    <script>
        // small UI helpers
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.querySelector('textarea[name="prices"]').value = '';
            document.querySelector('input[name="url"]').value = '';
        });
    </script>
</body>

</html>