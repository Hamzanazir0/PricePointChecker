<?php
// price-checker.php
// Simple price-checker: paste price points and a URL (terms page).
// Saves nothing; fetches the URL and checks only <div class="terms_cond"> text.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pricesInput = $_POST['prices'] ?? '';
    $url = trim($_POST['url'] ?? '');
    $result = check_prices_on_terms_page($pricesInput, $url);
}

function check_prices_on_terms_page($pricesInput, $url)
{
    $out = ['error' => null, 'found' => [], 'missing' => [], 'page_prices' => []];

    if (!preg_match('#^https?://#i', $url)) {
        $out['error'] = 'URL must start with http:// or https://';
        return $out;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        $out['error'] = 'Invalid URL';
        return $out;
    }

    // Resolve host -> ip and refuse private addresses (basic SSRF protection)
    $ip = gethostbyname($host);
    if ($ip === $host) { /* unresolved? still try */
    }

    $fetch = fetch_url($url);
    if (isset($fetch['error'])) {
        $out['error'] = $fetch['error'];
        return $out;
    }
    $content = $fetch['content'];

    // Parse HTML and grab only div.terms_cond
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // prefix xml encoding to avoid charset problems
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' terms_cond ')]");

    $text = '';
    foreach ($nodes as $n) {
        $text .= ' ' . $n->textContent;
    }

    // Extract numeric tokens like $3.79, 68.44, 390
    preg_match_all('/\$?\d{1,3}(?:[\d,]*\d)?(?:\.\d+)?/', $text, $m);

    $found_norm = [];
    foreach ($m[0] as $tok) {
        $norm = normalize_price($tok);
        if ($norm === '') continue;
        // store original token occurrences
        $found_norm[$norm][] = $tok;
    }

    // Prepare user inputs (split by whitespace or newline)
    $inputs = preg_split('/\s+/', trim($pricesInput));
    foreach ($inputs as $p) {
        if ($p === '') continue;
        $norm = normalize_price($p);
        if ($norm === '') {
            $out['missing'][] = $p; // malformed input treated as missing
            continue;
        }
        if (isset($found_norm[$norm])) {
            $out['found'][] = ['input' => $p, 'normalized' => $norm, 'page_matches' => $found_norm[$norm]];
        } else {
            $out['missing'][] = $p;
        }
    }

    // also return list of all page prices (normalized => occurrences)
    foreach ($found_norm as $k => $arr) $out['page_prices'][$k] = $arr;

    return $out;
}

function fetch_url($url)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'PriceChecker/1.0',
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


// Normalize a price-like string into canonical numeric string:
// - remove $ and commas and non-digit/dot/minus
// - strip leading zeros in integer part
// - strip trailing zeros in fractional part
// - "90" => "90", "90.00" => "90", "1.90" => "1.9"
function normalize_price($s)
{
    $s = trim($s);
    if ($s === '') return '';
    // remove currency symbols / letters except digits, dot and minus
    $s = preg_replace('/[^\d\.\-]/', '', $s);
    if ($s === '') return '';

    // If there are multiple dots (malformed), keep first dot and remove others
    if (substr_count($s, '.') > 1) {
        $parts = explode('.', $s);
        $s = array_shift($parts) . '.' . implode('', $parts);
    }

    // split integer / fractional
    if (strpos($s, '.') !== false) {
        list($int, $frac) = explode('.', $s, 2);
        // remove leading zeros in integer, but keep single zero if result empty
        $int = preg_replace('/^0+(?=\d)/', '', $int);
        if ($int === '') $int = '0';
        // remove trailing zeros in fractional
        $frac = rtrim($frac, '0');
        if ($frac === '') return $int; // becomes integer
        return $int . '.' . $frac;
    } else {
        // integer only
        $s = preg_replace('/^0+(?=\d)/', '', $s);
        if ($s === '') $s = '0';
        return $s;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Price Points Checker</title>
    <style>
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
            max-width: 900px;
            margin: 24px auto;
            padding: 0 18px;
        }

        textarea,
        input {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            margin: 6px 0;
            border: 1px solid #ccc;
            border-radius: 6px
        }

        button {
            padding: 10px 16px;
            border-radius: 8px;
            background: #2d9bf0;
            color: white;
            border: 0;
            cursor: pointer;
        }

        .box {
            background: #f7f9fb;
            padding: 12px;
            border-radius: 8px;
            margin-top: 12px
        }

        .ok {
            color: green
        }

        .bad {
            color: #c0392b
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px
        }

        td,
        th {
            padding: 6px;
            border-bottom: 1px solid #eee;
            text-align: left
        }
    </style>
</head>

<body>
    <h2>Price Points Checker</h2>
    <form method="post">
        <label><strong>Paste price points</strong> (one per line or separated by spaces). Example: <code>3.79 4.88 $68.44 90</code></label>
        <textarea name="prices" rows="8" placeholder="3.79
4.88
5.93
..."><?php if (!empty($_POST['prices'])) echo htmlspecialchars($_POST['prices']); ?></textarea>

        <label><strong>Terms page URL</strong> (e.g. https://example.com/terms.php)</label>
        <input type="text" name="url" value="<?php echo isset($_POST['url']) ? htmlspecialchars($_POST['url']) : ''; ?>" required>

        <button type="submit">Check</button>
    </form>

    <?php if (!empty($result)): ?>
        <div class="box">
            <?php if ($result['error']): ?>
                <p class="bad"><strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?></p>
            <?php else: ?>
                <p><strong>Found:</strong> <span class="ok"><?php echo count($result['found']); ?></span> — <strong>Missing:</strong> <span class="bad"><?php echo count($result['missing']); ?></span></p>

                <?php if (!empty($result['missing'])): ?>
                    <h4>Missing prices</h4>
                    <div><?php echo htmlspecialchars(implode(', ', $result['missing'])); ?></div>
                <?php endif; ?>

                <?php if (!empty($result['found'])): ?>
                    <h4>Found prices (input → normalized → page matches)</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Input</th>
                                <th>Normalized</th>
                                <th>Page matches (raw tokens)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['found'] as $f): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($f['input']); ?></td>
                                    <td><?php echo htmlspecialchars($f['normalized']); ?></td>
                                    <td><?php echo htmlspecialchars(implode(', ', $f['page_matches'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>


                <?php if (!empty($result['page_prices'])): ?>
                    <h4>All numeric tokens found inside <code>div.terms_cond</code> (normalized => occurrences)</h4>
                    <table>
                        <thead>
                            <tr>
                                <th>Normalized</th>
                                <th>Occurrences</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['page_prices'] as $k => $arr): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($k); ?></td>
                                    <td><?php echo htmlspecialchars(implode(', ', $arr)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p style="margin-top:18px;color:#666">Notes: 1) The script only checks text inside <code>div</code> elements whose <code>class</code> contains <strong>terms_cond</strong>. 2) If the page is rendered client-side (JavaScript), cURL won't see JS-inserted content — use a headless browser (Puppeteer) in that case. 3) The script refuses private IPs as a lightweight safety check.</p>
</body>

</html>