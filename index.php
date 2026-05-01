<?php
// Price Checker
// Strict price matching tool with improved UI (Tailwind) and Daisy UI.
// Place this on your PHP server and open in browser.
// Multi Price Search single site with daisy UI Design and Theme Switcher

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
        'note' => null, // Added to store fallback notices
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

    // Prevent DOMDocument warning if the page returned absolutely no HTML content
    if (trim($content) === '') {
        $out['error'] = 'The provided URL returned an empty page.';
        return $out;
    }

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
        $bodyNode = $xpath->query("//body");
        if ($bodyNode->length) {
            $text = $bodyNode->item(0)->textContent;
            // Fulfilling the developer comment: notifying the user
            $out['note'] = 'Notice: div.terms_cond was not found. Searched against entire page body text instead.';
        }
    }

    // Strict numeric token extraction:
    // - avoid partial matches by ensuring tokens are not adjacent to other digits/dots
    // - match optional currency symbol prefix ($,£,€,Rs, etc) but we only keep numeric content
    preg_match_all('/(?<![\\d\\.])\\$?\\d{1,3}(?:,\\d{3})*(?:\\.\\d+)?(?![\\d\\.])/', $text, $matches);

    $found_norm = []; // normalized => array of raw tokens occurrences
    foreach ($matches[0] as $tok) {
        $norm = normalize_price($tok);
        if ($norm === '') continue;
        if (!isset($found_norm[$norm])) $found_norm[$norm] = [];
        $found_norm[$norm][] = $tok;
    }

    // return tokens_on_page for debug/inspection
    $out['tokens_on_page'] = $found_norm;

    // Prepare user inputs
    $inputs_raw = preg_split('/[\r\n]+|\\s+/', trim($pricesInput));
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
 */
function normalize_price($s)
{
    $s = trim($s);
    if ($s === '') return '';

    // Keep only digits and dots and minus
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
        $int = preg_replace('/^0+(?=\d)/', '', preg_replace('/,/', '', $int));
        if ($int === '') $int = '0';
        $frac = rtrim($frac, '0');
        if ($frac === '') {
            return $int;
        }
        return $int . '.' . $frac;
    } else {
        $s = preg_replace('/^0+(?=\d)/', '', preg_replace('/,/', '', $s));
        if ($s === '') $s = '0';
        return $s;
    }
}
?>
<!doctype html>
<html lang="en" data-theme="retro">

<head>
    <meta charset="utf-8" />
    <title>Price Points Checker</title>
    <link rel="icon" type="image/png" href="check.png" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5" rel="stylesheet" type="text/css" />
    <link href="https://cdn.jsdelivr.net/npm/daisyui@5/themes.css" rel="stylesheet" type="text/css" />
    <style>
        .group:hover .dot-base-content {
            background-color: var(--color-primary);
        }

        .group:hover .dot-primary {
            background-color: var(--color-primary);
        }

        .group:hover .dot-secondary {
            background-color: var(--color-primary);
        }

        .group:hover .dot-accent {
            background-color: var(--color-primary);
        }

        .theme-item {
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .theme-item.show {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>

<body class="min-h-screen bg-base [background-image:linear-gradient(0deg,transparent_24%,rgba(114,114,114,0.3)_25%,rgba(114,114,114,0.3)_26%,transparent_27%,transparent_74%,rgba(114,114,114,0.3)_75%,rgba(114,114,114,0.3)_76%,transparent_77%,transparent),linear-gradient(90deg,transparent_24%,rgba(114,114,114,0.3)_25%,rgba(114,114,114,0.3)_26%,transparent_27%,transparent_74%,rgba(114,114,114,0.3)_75%,rgba(114,114,114,0.3)_76%,transparent_77%,transparent)] [background-size:55px_55px]">

    <!-- Page Loader (Hidden by default to prevent UI flashing) -->
    <div id="pageLoader" class="loader-handler hidden fixed inset-0 bg-black/70 flex items-center justify-center z-50">
        <span class="loading loading-ring loading-xl text-primary"></span>
    </div>

    <div class="max-w-5xl mx-auto py-10 px-4">
        <div class="bg-gradient-to-br from-black/10 to-black/5 backdrop-blur-[3px] border border-white/20 shadow-md rounded-lg p-10 px-20">

            <!-- Theme Switcher -->
            <div class="fixed top-6 right-6 dropdown dropdown-bottom dropdown-end z-[99999999999]">
                <label tabindex="0" role="button" class="group flex flex-row items-center bg-base-100 border-base-content/10 hover:border-primary-content/10 rounded-md border p-1 px-3 transition-colors cursor-pointer">
                    <div class="grid shrink-0 grid-cols-2 grid-rows-2 gap-1 w-4 h-4 p-1 transition-colors mr-1.5">
                        <div class="bg-base-content dot-base-content size-1 rounded-full"></div>
                        <div class="bg-primary dot-primary size-1 rounded-full"></div>
                        <div class="bg-secondary dot-secondary size-1 rounded-full"></div>
                        <div class="bg-accent dot-accent size-1 rounded-full"></div>
                    </div>
                    <div class="select-none mr-1.5">Change Theme</div>
                    <svg width="12px" height="12px" class="mt-px hidden size-2 fill-current opacity-60 sm:inline-block" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2048 2048">
                        <path d="M1799 349l242 241-1017 1017L7 590l242-241 775 775 775-775z"></path>
                    </svg>
                </label>

                <ul tabindex="0" class="relative dropdown-content flex flex-col p-2 mt-2 w-60">
                    <!-- Theme Items -->
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="sunset" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Default - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="wireframe" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Wireframe - Light</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="synthwave" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Synthwave</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="business" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Business - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="pastel" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Pastel - Light</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="halloween" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Halloween - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="bumblebee" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Bumblebee - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="forest" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative">
                            <span>Forest - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>

            <!-- Header -->
            <div class="flex m-auto justify-center">
                <div class="badge badge-primary badge-xl py-6 mb-6 shadow-md">
                    <span class="text-xl font-bold">💰 Price Points Checker</span>
                </div>
            </div>

            <!-- Form -->
            <form id="priceForm" method="post" class="card bg-base-100 shadow-lg p-6 space-y-4 border border-primary">
                <div class="space-y-4 space-x-10 flex justify-between">

                    <!-- Fixed flex classes from flex-2 to flex-[2] -->
                    <fieldset class="fieldset flex-[2] relative">
                        <legend class="fieldset-legend text-lg font-semibold">Price Points</legend>
                        <span class="priceBadgeGroup absolute top-4 right-4 z-[99999] flex gap-2 flex-col items-end">
                            <span id="priceBadge" class="badge badge-primary badge-xs shadow-sm">Custom Price Points</span>
                            <span id="priceBadgeCounter" class="badge badge-accent badge-xs shadow-sm">Prices Counter: <strong class="counter_value ml-1">0</strong> </span>
                        </span>
                        <textarea id="pricePointsInput" class="textarea w-full py-2 px-6 shadow-inner" name="prices" rows="6" required><?php echo isset($_POST['prices']) ? htmlspecialchars($_POST['prices']) : "3.79\n4.88\n5.93\n90"; ?></textarea>
                        <div class="flex gap-3 mt-2">
                            <button type="button" id="loadBA" class="btn btn-info flex-1">BA Prices</button>
                            <button type="button" id="loadLMS" class="btn btn-secondary flex-1">LMS Prices</button>
                        </div>
                        <div class="text-warning mt-2 text-sm"><strong class="badge badge-warning me-1">Notes:</strong> One per line or Space-separated</div>
                    </fieldset>

                    <!-- Fixed flex classes from flex-3 to flex-[3] -->
                    <fieldset class="fieldset flex-[3]">
                        <legend class="fieldset-legend text-lg font-semibold">Website URL</legend>
                        <label class="input validator w-full shadow-inner">
                            <svg class="h-[18px] opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                <g stroke-linejoin="round" stroke-linecap="round" stroke-width="2.5" fill="none" stroke="currentColor">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                </g>
                            </svg>
                            <span class="text-primary select-none font-medium">https://</span>
                            <!-- Fixed Undefined Key Error: Changed isset($_POST['url']) to isset($_POST['domain']) -->
                            <input
                                type="text"
                                id="domainInput"
                                name="domain"
                                class="w-full"
                                placeholder="domain.com"
                                title="Must be valid Domain"
                                pattern="^(https?://)?(localhost(?::\d+)?(?:/.*)?|([a-zA-Z0-9]([a-zA-Z0-9\-].*[a-zA-Z0-9])?\.)+[a-zA-Z].*)$"
                                value="<?php echo isset($_POST['domain']) ? htmlspecialchars($_POST['domain']) : ''; ?>"
                                required />
                            <span class="text-primary select-none font-medium">/terms.php</span>
                        </label>
                        <p class="validator-hint">Enter domain only (e.g. abc.com)</p>
                        <div class="text-warning mt-2 text-sm"><strong class="badge badge-warning me-1">Notes:</strong> Localhost searching not available on live server</div>
                    </fieldset>

                </div>

                <div class="flex gap-3 pt-2">
                    <!-- Fixed flex classes -->
                    <button type="submit" class="btn btn-primary flex-[6] text-lg">Check Prices</button>
                    <button type="button" id="clearBtn" class="btn btn-error flex-1">Clear</button>
                </div>
            </form>

            <!-- Results -->
            <?php if (!empty($result)): ?>
                <div class="mt-8">
                    <?php if ($result['error']): ?>
                        <div class="alert alert-error shadow-lg">
                            <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span><strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="card bg-base-100 shadow-xl/20 p-6 space-y-4 border border-accent">

                            <!-- Notice Display if Fallback happened -->
                            <?php if (!empty($result['note'])): ?>
                                <div class="alert alert-info alert-soft shadow-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-current shrink-0 w-6 h-6">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span><?php echo htmlspecialchars($result['note']); ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="flex justify-between items-center bg-base-200 p-4 rounded-lg">
                                <div>
                                    <p class="font-medium">Total matching price occurrences: <span class="badge badge-primary badge-lg shadow-sm"><?php echo (int) $result['total_found_count']; ?></span></p>
                                </div>
                                <div class="text-sm font-semibold">
                                    <span class="text-success mr-4 flex-inline items-center gap-1">Found: <?php echo count(array_filter($result['results'], fn($r) => $r['found'])); ?></span>
                                    <span class="text-error flex-inline items-center gap-1">Missing: <?php echo count($result['missing']); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($result['missing'])): ?>
                                <div class="alert alert-warning alert-soft shadow-sm">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    <div>
                                        <strong>Missing values:</strong>
                                        <div class="font-mono mt-1 text-xs"><?php echo htmlspecialchars(implode(', ', $result['missing'])); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Found / missing table -->
                            <div class="overflow-x-auto rounded-lg border border-white/10 shadow-sm">
                                <table class="table table-zebra w-full">
                                    <thead class="bg-base-200 text-base-content">
                                        <tr>
                                            <th>Input</th>
                                            <th>Normalized</th>
                                            <th>Matched?</th>
                                            <th>Occurrences</th>
                                            <th>Page matches (raw tokens)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($result['results'] as $r): ?>
                                            <tr class="<?= !$r['found'] ? "!bg-error/10 hover:!bg-error/20" : "hover:bg-base-200" ?> transition-colors">
                                                <td class="font-mono"><?php echo htmlspecialchars($r['input']); ?></td>
                                                <td class="font-mono text-primary"><?php echo htmlspecialchars($r['normalized']); ?></td>
                                                <td>
                                                    <?php if ($r['found']): ?>
                                                        <span class="badge badge-success badge-sm shadow-sm">Yes</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-error badge-sm shadow-sm">No</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$r['count'] > 1): ?>
                                                        <span class="badge badge-primary badge-sm shadow-sm"><?php echo (int)$r['count']; ?></span>
                                                    <?php elseif ((int)$r['count'] == 0): ?>
                                                        <span class="badge badge-error badge-sm shadow-sm"><?php echo (int)$r['count']; ?></span>
                                                    <?php else: ?>
                                                        <span class="badge badge-neutral badge-sm shadow-sm"><?php echo (int)$r['count']; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-sm opacity-80"><?php echo $r['matches'] ? htmlspecialchars(implode(', ', $r['matches'])) : '<span class="opacity-50">—</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Instruction block -->
            <div class="alert alert-info alert-soft my-6 shadow-sm">
                <div>
                    <span class="font-bold text-lg">How this tool matches prices (Important)</span>
                    <ul class="list-disc list-inside text-sm mt-3 space-y-1.5 ml-1 opacity-90">
                        <li>1. The script extracts <strong>numeric tokens</strong> only from elements with <code>class="terms_cond"</code>. If none exist it falls back to the page body.</li>
                        <li>2. Tokens and your inputs are <strong>normalized</strong> the same way: currency symbols & commas are removed, leading zeros removed, trailing fractional zeros removed. Examples: <code>$90.00 → 90</code>, <code>1.90 → 1.9</code>.</li>
                        <li>3. Matching is <strong>exact on normalized values</strong>. So <code>90</code> will <em>not</em> match <code>1.90</code> or <code>390</code>. This prevents false positives.</li>
                        <li>4. The tool reports how many times a normalized price appears in the terms page (occurrence counter).</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>

    <!-- All Scrips -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <script>
        // small UI helpers
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.querySelector('textarea[name="prices"]').value = '';
            document.querySelector('input[name="domain"]').value = '';
        });

        // Theme switcher
        const defaultTheme = "sunset";
        const savedTheme = localStorage.getItem("theme") || defaultTheme;
        document.documentElement.setAttribute("data-theme", savedTheme);

        const themeItems = document.querySelectorAll(".theme-preview");
        themeItems.forEach(item => {
            item.addEventListener("click", () => {
                const selectedTheme = item.getAttribute("data-theme") || defaultTheme;
                document.documentElement.setAttribute("data-theme", selectedTheme);
                localStorage.setItem("theme", selectedTheme);
            });
        });
    </script>

    <!-- Page Loader Logic -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const loader = document.getElementById("pageLoader");
            const form = document.getElementById("priceForm");

            if (form) {
                form.addEventListener("submit", function() {
                    // Show loader only when request actually processes
                    loader.classList.remove("hidden");
                });
            }
        });
    </script>

    <!-- Items Easing for dropdown -->
    <script>
        const dropdown = document.querySelector(".dropdown");
        const themeItems2 = dropdown.querySelectorAll(".theme-item");
        let animationTimeouts = [];

        function clearAnimations() {
            animationTimeouts.forEach(id => clearTimeout(id));
            animationTimeouts = [];
        }

        function fadeInItems() {
            clearAnimations();
            themeItems2.forEach((item, index) => {
                animationTimeouts.push(setTimeout(() => {
                    item.classList.add("show");
                }, index * 80)); // Slightly sped up
            });
        }

        function fadeOutItems() {
            clearAnimations();
            themeItems2.forEach((item, index) => {
                animationTimeouts.push(setTimeout(() => {
                    item.classList.remove("show");
                }, (themeItems2.length - 1 - index) * 60));
            });
        }

        dropdown.addEventListener("focusin", fadeInItems);
        dropdown.addEventListener("focusout", (e) => {
            if (!dropdown.contains(e.relatedTarget)) {
                fadeOutItems();
            }
        });
    </script>

    <!-- BA LMS Price Loading and Counter -->
    <script>
        const baPrices = ["$3.79", "$4.88", "$5.93", "$7.92", "$8.78", "$9.87", "$10.75", "$11.94", "$12.65", "$13.89", "$14.96", "$15.78", "$17.52", "$18.90", "$19.77", "$19.88", "$20.79", "$21.98", "$23.68", "$25.92", "$29.95", "$34.99", "$39.87", "$44.96", "$49.89", "$49.97", "$54.95", "$56.90", "$59.76", "$63.81", "$66.79", "$69.84", "$73.50", "$75.85", "$78.91", "$78.91", "$82.45", "$86.71", "$88.59", "$90.77", "$93.54", "$95.99", "$97.95", "$99.58", "$100.68", "$105.89"];
        const lmsPrices = ["$3.79", "$4.88", "$5.93", "$7.92", "$8.78", "$9.87", "$10.72", "$11.94", "$12.61", "$13.89", "$14.25", "$18.9", "$19.77", "$21.98", "$23.68", "$25.92", "$27.42", "$29.95", "$33.25", "$35.22", "$36.91", "$37.66", "$39.87", "$40.18", "$42.71", "$43.02", "$44.96", "$45.1", "$46.82", "$49.89", "$54.95", "$59.76", "$64.9", "$68.44", "$71.27", "$74.66", "$76.44", "$81.33"];

        function countPrices(input) {
            if (!input.trim()) return 0;
            const tokens = input.split(/\s+|\n+/).map(t => t.trim()).filter(t => t.length > 0);
            return tokens.length;
        }

        function updateCounter() {
            badgeCounter.innerText = countPrices(textarea.value);
        }

        const textarea = document.getElementById("pricePointsInput");
        const badge = document.getElementById("priceBadge");
        const badgeCounter = document.querySelector("#priceBadgeCounter .counter_value");
        const baBtn = document.getElementById("loadBA");
        const lmsBtn = document.getElementById("loadLMS");
        const clearBtnApp = document.getElementById("clearBtn");
        let isPresetLoaded = false;

        function loadPrices(prices, label) {
            textarea.value = prices.join("\n");
            badge.innerText = label + " Prices Loaded";
            badgeCounter.innerText = prices.length;
            isPresetLoaded = true;
        }

        baBtn.addEventListener("click", () => {
            loadPrices(baPrices, "BA");
            badge.className = "badge badge-info badge-xs shadow-sm";
        });

        lmsBtn.addEventListener("click", () => {
            loadPrices(lmsPrices, "LMS");
            badge.className = "badge badge-secondary badge-xs shadow-sm";
        });

        clearBtnApp.addEventListener("click", () => {
            textarea.value = "";
            badge.innerText = "Custom Price Points";
            badge.className = "badge badge-primary badge-xs shadow-sm";
            badgeCounter.innerText = 0;
            updateCounter();
            isPresetLoaded = false;
        });

        textarea.addEventListener("input", () => {
            if (isPresetLoaded) {
                badge.innerText = "Custom Price Points";
                badge.className = "badge badge-primary badge-xs shadow-sm";
                isPresetLoaded = false;
            }
            updateCounter();
        });

        updateCounter();
    </script>

    <!-- URL Input Prefix (https://) and Suffix (/terms.php) handling -->
    <script>
        document.getElementById("priceForm").addEventListener("submit", (e) => {
            let domain = document.getElementById("domainInput").value.trim();

            if (!domain) {
                e.preventDefault();
                alert("Please enter a domain");
                return;
            }

            let fullUrl = "";

            if (/^https?:\/\//i.test(domain)) {
                if (domain.endsWith("/terms.php")) {
                    fullUrl = domain;
                } else {
                    fullUrl = domain.replace(/\/+$/, "") + "/terms.php";
                }
            } else if (domain.startsWith("localhost")) {
                if (domain.endsWith("/terms.php")) {
                    fullUrl = "http://" + domain;
                } else {
                    fullUrl = "http://" + domain.replace(/\/+$/, "") + "/terms.php";
                }
            } else {
                if (domain.endsWith("/terms.php")) {
                    fullUrl = "https://" + domain;
                } else {
                    fullUrl = "https://" + domain.replace(/\/+$/, "") + "/terms.php";
                }
            }

            let hidden = document.getElementById("fullUrlInput");
            if (!hidden) {
                hidden = document.createElement("input");
                hidden.type = "hidden";
                hidden.name = "url";
                hidden.id = "fullUrlInput";
                e.target.appendChild(hidden);
            }
            hidden.value = fullUrl;
        });
    </script>
</body>

</html>