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
        // CURLOPT_SSL_VERIFYPEER => true,
        // CURLOPT_SSL_VERIFYHOST => 2,
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
<html lang="en" data-theme="sunset">

<head>
    <meta charset="utf-8" />
    <title>Price Points Checker</title>
    <link rel="icon" type="image/png" href="check.png" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
</head>

<!-- <body class="min-h-screen bg-base-200"> -->

<body class="min-h-screen bg-[#191a1a] [background-image:linear-gradient(0deg,transparent_24%,rgba(114,114,114,0.3)_25%,rgba(114,114,114,0.3)_26%,transparent_27%,transparent_74%,rgba(114,114,114,0.3)_75%,rgba(114,114,114,0.3)_76%,transparent_77%,transparent),linear-gradient(90deg,transparent_24%,rgba(114,114,114,0.3)_25%,rgba(114,114,114,0.3)_26%,transparent_27%,transparent_74%,rgba(114,114,114,0.3)_75%,rgba(114,114,114,0.3)_76%,transparent_77%,transparent)] [background-size:55px_55px]">

    <!-- <div id="pageLoader" class="loader-handler bg-base-100 fixed inset-0 m-auto z-50 hidden">
        <span class="loading loading-ring loading-xl fixed inset-0 m-auto z-50"></span>
    </div> -->
    <div id="pageLoader" class="loader-handler fixed inset-0 bg-black/70 flex items-center justify-center z-50">
        <span class="loading loading-ring loading-xl"></span>
    </div>

    <div class="max-w-5xl mx-auto py-10 px-4">
        <div class="bg-gradient-to-br from-black/10 to-black/5 backdrop-blur-[3px] border border-white/20 shadow-md rounded-lg p-10 px-20">

            <!-- Header -->
            <div class="flex m-auto justify-center">
                <div class="badge badge-primary badge-xl py-6 mb-6">
                    <span class="text-xl font-bold">💰 Price Points Checker</span>
                </div>
            </div>

            <!-- Instruction block -->
            <div class="alert alert-info alert-soft mb-6">
                <div>
                    <span class="font-semibold">How this tool matches prices (important)</span>
                    <ul class="list-disc list-inside text-sm mt-2 space-y-1">
                        <li>1. The script extracts **numeric tokens** only from elements with <code>class="terms_cond"</code>. If none exist it falls back to the page body.</li>
                        <li>2. Tokens and your inputs are <strong>normalized</strong> the same way: currency symbols & commas are removed, leading zeros removed, trailing fractional zeros removed. Examples: <code>$90.00 → 90</code>, <code>1.90 → 1.9</code>.</li>
                        <li>3. Matching is <strong>exact on normalized values</strong>. So <code>90</code> will <em>not</em> match <code>1.90</code> or <code>390</code>. This prevents false positives.</li>
                        <li>4. The tool reports how many times a normalized price appears in the terms page (occurrence counter).</li>
                    </ul>
                </div>
            </div>

            <!-- Form -->
            <form id="priceForm" method="post" class="card bg-base-100 shadow-lg p-6 space-y-4">
                <div class="space-y-4 space-x-10 flex justify-between ">

                    <fieldset class="fieldset flex-2">
                        <legend class="fieldset-legend text-lg">Price Points</legend>
                        <textarea class="textarea w-full" name="prices" rows="6" required><?php echo isset($_POST['prices']) ? htmlspecialchars($_POST['prices']) : "3.79\n4.88\n5.93\n90"; ?></textarea>
                        <div class="text-warning mt-2"><strong class="badge badge-warning me-1">Notes:</strong> One per line or Space-separated</div>
                    </fieldset>

                    <fieldset class="fieldset flex-3">
                        <legend class="fieldset-legend text-lg">Terms Page URL</legend>
                        <input type="text" name="url" class="input w-full" placeholder="https://example.com/terms.php" value="<?php echo isset($_POST['url']) ? htmlspecialchars($_POST['url']) : ''; ?>" required />
                        <div class="text-warning mt-2"><strong class="badge badge-warning me-1">Notes:</strong> Localhost searching not available on live server</div>
                    </fieldset>

                </div>

                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary flex-6">Check Prices</button>
                    <button type="button" id="clearBtn" class="btn btn-error flex-1">Clear</button>
                </div>
            </form>

            <!-- Results -->
            <?php if (!empty($result)): ?>
                <div class="mt-8">
                    <?php if ($result['error']): ?>
                        <div class="alert alert-error">
                            <strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?>
                        </div>
                    <?php else: ?>
                        <div class="card bg-base-100 shadow-lg p-6 space-y-4">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="">Total matching price occurrences found: <span class="badge badge-primary"><?php echo (int) $result['total_found_count']; ?></span></p>
                                </div>
                                <div class="text-sm">
                                    <span class="text-success mr-3">Found: <?php echo count(array_filter($result['results'], fn($r) => $r['found'])); ?></span>
                                    <span class="text-error">Missing: <?php echo count($result['missing']); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($result['missing'])): ?>
                                <div class="alert alert-warning alert-soft">
                                    <strong>Missing values:</strong>
                                    <div class=""><?php echo htmlspecialchars(implode(', ', $result['missing'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <!-- Found / missing table -->
                            <div class="overflow-x-auto rounded-lg border border-white/20">
                                <table class="table table-zebra">
                                    <thead>
                                        <tr class="">
                                            <th class="">Input</th>
                                            <th class="">Normalized</th>
                                            <th class="">Matched?</th>
                                            <th class="">Occurrences</th>
                                            <th class="">Page matches (raw tokens)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="">
                                        <?php foreach ($result['results'] as $r): ?>
                                            <tr class="<?= !$r['found'] ? "!bg-red-900/10" : "" ?> ">
                                                <td class=""><?php echo htmlspecialchars($r['input']); ?></td>
                                                <td class=""><?php echo htmlspecialchars($r['normalized']); ?></td>
                                                <td class="">
                                                    <?php if ($r['found']): ?>
                                                        <span class="badge badge-success">Yes</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-error">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="">
                                                    <?php if ((int)$r['count'] > 1): ?>
                                                        <span class="badge badge-primary "><?php echo (int)$r['count']; ?></span>
                                                    <?php elseif ((int)$r['count'] == 0): ?>
                                                        <span class="badge badge-error "><?php echo (int)$r['count']; ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-neutral "><?php echo (int)$r['count']; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class=""><?php echo $r['matches'] ? htmlspecialchars(implode(', ', $r['matches'])) : '<span class="text-gray-400">—</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="mt-8 alert alert-info alert-soft">
                <p><strong class="badge badge-info me-1">Notes:</strong> This tool extracts numeric tokens only from <code>div.terms_cond</code> elements (fallback to body if none). Matching is performed after normalization (strip currency symbols & commas; collapse multiple dots; remove trailing fractional zeros) so matching is strict and should not produce false positives like "90" matching "390" or "1.90".</p>
            </div>
        </div>
    </div>

    <!-- All Scrips -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script>
        // small UI helpers
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.querySelector('textarea[name="prices"]').value = '';
            document.querySelector('input[name="url"]').value = '';
        });
    </script>

    <!-- Page Loader -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const loader = document.getElementById("pageLoader");
            const form = document.getElementById("priceForm");

            if (form) {
                form.addEventListener("submit", function() {
                    // Show loader immediately
                    loader.classList.remove("hidden");
                });
            }

            // Optional: hide loader after page fully loads
            window.addEventListener("load", function() {
                loader.classList.add("hidden");
            });
        });
    </script>


</body>

</html>