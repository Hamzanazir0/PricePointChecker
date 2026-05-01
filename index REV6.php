<?php
// Price Checker Was deployed till now Working fine theme switcher issue
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

    <!-- Page Loader -->
    <div id="pageLoader" class="loader-handler fixed inset-0 bg-black/70 flex items-center justify-center z-50">
        <span class="loading loading-ring loading-xl"></span>
    </div>

    <div class="max-w-5xl mx-auto py-10 px-4">
        <div class="bg-gradient-to-br from-black/10 to-black/5 backdrop-blur-[3px] border border-white/20 shadow-md rounded-lg p-10 px-20">

            <!-- Theme Switcher -->
            <div class="fixed top-6 right-6 dropdown dropdown-bottom dropdown-end z-[99999999999]">
                <!-- Main Theme Drop Down -->
                <label tabindex="0" role="button" class="group flex flex-row items-center bg-base-100  border-base-content/10 hover:border-primary-content/10 rounded-md border p-1 px-3 transition-colors cursor-pointer">
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

                <!-- Dropdown Menu -->
                <!-- <ul tabindex="0" class="dropdown-content relative z-[9999] flex flex-col p-2 shadow bg-base-100 rounded-box w-60 "> -->
                <ul tabindex="0" class="relative dropdown-content flex flex-col p-2 mt-2 w-60">

                    <!-- Theme Preview Items -->
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="sunset" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Default - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="wireframe" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Wireframe - Light</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="synthwave" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Synthwave</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="business" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Business - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="pastel" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Pastel - Light</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="halloween" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Halloween - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="bumblebee" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
                            <span>Bumblebee - Dark</span>
                            <div class="flex gap-1">
                                <div class="size-3 bg-primary rounded"></div>
                                <div class="size-3 bg-secondary rounded"></div>
                                <div class="size-3 bg-accent rounded"></div>
                            </div>
                        </div>
                    </li>
                    <li class="theme-item opacity-0 transform translate-y-2 mb-2">
                        <div data-theme="forest" class="theme-preview cursor-pointer rounded-md p-3 flex items-center justify-between hover:shadow hover:bg-base-300 transition mb-2 relative  ">
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
                <div class="badge badge-primary badge-xl py-6 mb-6">
                    <span class="text-xl font-bold">💰 Price Points Checker</span>
                </div>
            </div>

            <!-- Form -->
            <form id="priceForm" method="post" class="card bg-base-100 shadow-lg p-6 space-y-4 border border-primary">
                <div class="space-y-4 space-x-10 flex justify-between ">

                    <fieldset class="fieldset flex-2 relative">
                        <legend class="fieldset-legend text-lg">Price Points</legend>
                        <span class="priceBadgeGroup absolute top-4 right-4 z-[999999999] flex gap-2 flex-col items-end">
                            <span id="priceBadge" class="badge badge-primary badge-xs ">Custom Price Points</span>
                            <span id="priceBadgeCounter" class="badge badge-accent badge-xs ">Prices Counter: <strong class="counter_value ">0</strong> </span>
                        </span>
                        <textarea id="pricePointsInput" class="textarea w-full py-2 px-6" name="prices" rows="6" required><?php echo isset($_POST['prices']) ? htmlspecialchars($_POST['prices']) : "3.79\n4.88\n5.93\n90"; ?></textarea>
                        <div class="flex gap-3 mt-2">
                            <button type="button" id="loadBA" class="btn btn-info flex-1">BA Prices</button>
                            <button type="button" id="loadLMS" class="btn btn-secondary flex-1">LMS Prices</button>
                        </div>
                        <div class="text-warning mt-2"><strong class="badge badge-warning me-1">Notes:</strong> One per line or Space-separated</div>
                    </fieldset>

                    <fieldset class="fieldset flex-3">
                        <legend class="fieldset-legend text-lg">Website URL</legend>
                        <label class="input validator w-full">
                            <svg class="h-[18px] opacity-50" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                                <g
                                    stroke-linejoin="round"
                                    stroke-linecap="round"
                                    stroke-width="2.5"
                                    fill="none"
                                    stroke="currentColor">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                </g>
                            </svg>
                            <!-- Prefix -->
                            <span class="text-primary select-none">https://</span>
                            <input
                                type="text"
                                id="domainInput"
                                name="domain"
                                class="w-full"
                                placeholder="domain.com"
                                title="Must be valid Domain"
                                pattern="^(https?://)?(localhost(?::\d+)?(?:/.*)?|([a-zA-Z0-9]([a-zA-Z0-9\-].*[a-zA-Z0-9])?\.)+[a-zA-Z].*)$"
                                value="<?php echo isset($_POST['url']) ? htmlspecialchars($_POST['domain']) : ''; ?>"
                                required />
                            <!-- Suffix -->
                            <span class="text-primary select-none">/terms.php</span>
                        </label>
                        <p class="validator-hint">Enter domain only (e.g. abc.com)</p>
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
                        <div class="card bg-base-100 shadow-xl/20 p-6 space-y-4 border border-accent">
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

            <!-- Instruction block -->
            <div class="alert alert-info alert-soft my-6">
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

            <div class="mt-8 alert alert-warning alert-soft">
                <p><strong class="badge badge-warning me-1">Notes:</strong> This tool extracts numeric tokens only from <code>div.terms_cond</code> elements (fallback to body if none). Matching is performed after normalization (strip currency symbols & commas; collapse multiple dots; remove trailing fractional zeros) so matching is strict and should not produce false positives like "90" matching "390" or "1.90".</p>
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

        // On load: apply saved theme or default
        const savedTheme = localStorage.getItem("theme") || defaultTheme;
        document.documentElement.setAttribute("data-theme", savedTheme);

        // Select all theme preview items
        const themeItems = document.querySelectorAll(".theme-preview");

        // Attach click event to each
        themeItems.forEach(item => {
            item.addEventListener("click", () => {
                const selectedTheme = item.getAttribute("data-theme") || defaultTheme;

                // Apply theme
                document.documentElement.setAttribute("data-theme", selectedTheme);

                // Save theme in localStorage
                localStorage.setItem("theme", selectedTheme);
            });
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
                }, index * 100));
            });
        }

        function fadeOutItems() {
            clearAnimations();
            themeItems2.forEach((item, index) => {
                animationTimeouts.push(setTimeout(() => {
                    item.classList.remove("show");
                }, (themeItems2.length - 1 - index) * 100));
            });
        }

        // Focus in: fade-in
        dropdown.addEventListener("focusin", () => {
            fadeInItems();
        });

        // Focus out: fade-out if focus leaves dropdown completely
        dropdown.addEventListener("focusout", (e) => {
            if (!dropdown.contains(e.relatedTarget)) {
                fadeOutItems();
            }
        });
    </script>

    <!-- BA LMS Price Loading and Counter -->
    <script>
        // Arrays of price points
        const baPrices = [
            "$3.79",
            "$4.88",
            "$5.93",
            "$7.92",
            "$8.78",
            "$9.87",
            "$10.75",
            "$11.94",
            "$12.65",
            "$13.89",
            "$14.96",
            "$15.78",
            "$17.52",
            "$18.90",
            "$19.77",
            "$19.88",
            "$20.79",
            "$21.98",
            "$23.68",
            "$25.92",
            "$29.95",
            "$34.99",
            "$39.87",
            "$44.96",
            "$49.89",
            "$49.97",
            "$54.95",
            "$56.90",
            "$59.76",
            "$63.81",
            "$66.79",
            "$69.84",
            "$73.50",
            "$75.85",
            "$78.91",
            "$78.91",
            "$82.45",
            "$86.71",
            "$88.59",
            "$90.77",
            "$93.54",
            "$95.99",
            "$97.95",
            "$99.58",
            "$100.68",
            "$105.89",
        ];
        const lmsPrices = [
            "$3.79",
            "$4.88",
            "$5.93",
            "$7.92",
            "$8.78",
            "$9.87",
            "$10.72",
            "$11.94",
            "$12.61",
            "$13.89",
            "$14.25",
            "$18.9",
            "$19.77",
            "$21.98",
            "$23.68",
            "$25.92",
            "$27.42",
            "$29.95",
            "$33.25",
            "$35.22",
            "$36.91",
            "$37.66",
            "$39.87",
            "$40.18",
            "$42.71",
            "$43.02",
            "$44.96",
            "$45.1",
            "$46.82",
            "$49.89",
            "$54.95",
            "$59.76",
            "$64.9",
            "$68.44",
            "$71.27",
            "$74.66",
            "$76.44",
            "$81.33",
        ];

        // --- Helper to count prices using same PHP logic ---
        function countPrices(input) {
            if (!input.trim()) return 0;

            // Same as PHP: split by newline OR whitespace
            const tokens = input
                .split(/\s+|\n+/) // split by any space OR newline
                .map(t => t.trim()) // clean spaces
                .filter(t => t.length > 0); // remove empty entries

            return tokens.length;
        }

        // --- Update counter ---
        function updateCounter() {
            badgeCounter.innerText = countPrices(textarea.value);
        }

        // Elements
        const textarea = document.getElementById("pricePointsInput");
        const badge = document.getElementById("priceBadge");
        const badgeCounter = document.querySelector("#priceBadgeCounter .counter_value");
        const baBtn = document.getElementById("loadBA");
        const lmsBtn = document.getElementById("loadLMS");
        const clearBtn = document.getElementById("clearBtn");

        // Track state
        let isPresetLoaded = false;

        // Helper to set prices
        function loadPrices(prices, label) {
            textarea.value = prices.join("\n");
            badge.innerText = label + " Prices Loaded";
            badgeCounter.innerText = prices.length;
            isPresetLoaded = true;
        }

        // BA button
        baBtn.addEventListener("click", () => {
            loadPrices(baPrices, "BA");
            badge.classList.remove("badge-primary", "badge-info", "badge-secondary");
            badge.classList.add("badge-info");
        });

        // LMS button
        lmsBtn.addEventListener("click", () => {
            loadPrices(lmsPrices, "LMS");
            badge.classList.remove("badge-primary", "badge-info", "badge-secondary");
            badge.classList.add("badge-secondary");
        });

        // Clear button
        clearBtn.addEventListener("click", () => {
            textarea.value = "";
            badge.innerText = "Custom Price Points";
            badge.classList.remove("badge-primary", "badge-info", "badge-secondary");
            badge.classList.add("badge-primary");
            badgeCounter.innerText = 0;
            updateCounter(); // reset counter to 0
            isPresetLoaded = false;
        });

        // Detect manual edits
        textarea.addEventListener("input", () => {
            if (isPresetLoaded) {
                badge.innerText = "Custom Price Points";
                badge.classList.remove("badge-primary", "badge-info", "badge-secondary");
                badge.classList.add("badge-primary");
                isPresetLoaded = false;
            }
            updateCounter(); // keep counter in sync on every keystroke
        });

        // Initial load (in case PHP pre-fills values)
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

            // --- Case 1: User already typed full URL (http/https)
            if (/^https?:\/\//i.test(domain)) {
                if (domain.endsWith("/terms.php")) {
                    fullUrl = domain; // keep as is
                } else {
                    fullUrl = domain.replace(/\/+$/, "") + "/terms.php";
                }
            }
            // --- Case 2: Localhost (with or without path, with or without terms.php)
            else if (domain.startsWith("localhost")) {
                if (domain.endsWith("/terms.php")) {
                    fullUrl = "http://" + domain; // keep as is
                } else {
                    fullUrl = "http://" + domain.replace(/\/+$/, "") + "/terms.php";
                }
            }
            // --- Case 3: Normal domain (without protocol)
            else {
                if (domain.endsWith("/terms.php")) {
                    fullUrl = "https://" + domain; // keep as is
                } else {
                    fullUrl = "https://" + domain.replace(/\/+$/, "") + "/terms.php";
                }
            }

            // Hidden input for PHP
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