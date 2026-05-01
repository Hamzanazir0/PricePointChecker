<?php
// price-checker.php (with daisyUI UI)
// Logic untouched, only UI classes/layout updated

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pricesInput = $_POST['prices'] ?? '';
    $url = trim($_POST['url'] ?? '');
    $result = check_prices_on_terms_page($pricesInput, $url);
}

/* --- All your PHP logic functions (unchanged) --- */
function check_prices_on_terms_page($pricesInput, $url)
{
    // ... (same as your original logic)
}
function fetch_url($url)
{
    // ... (same as your original logic)
}
function normalize_price($s)
{
    // ... (same as your original logic)
}
?>
<!doctype html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="utf-8" />
    <title>Price Points Checker</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <!-- Tailwind + daisyUI -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.10/dist/full.css" rel="stylesheet" type="text/css" />
</head>

<body class="min-h-screen bg-base-200">
    <div class="max-w-5xl mx-auto py-10 px-4">

        <!-- Header -->
        <div class="navbar bg-base-100 rounded-box shadow mb-6">
            <div class="flex-1">
                <span class="text-xl font-bold">💰 Price Points Checker</span>
            </div>
            <!-- Theme switcher -->
            <div class="flex-none">
                <select id="themeSwitcher" class="select select-bordered select-sm">
                    <option disabled selected>Change theme</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                    <option value="cupcake">Cupcake</option>
                    <option value="corporate">Corporate</option>
                    <option value="synthwave">Synthwave</option>
                    <option value="dracula">Dracula</option>
                </select>
            </div>
        </div>

        <!-- Instruction block -->
        <div class="alert alert-info mb-6">
            <div>
                <span class="font-semibold">How this tool matches prices:</span>
                <ul class="list-disc list-inside text-sm mt-2 space-y-1">
                    <li>Extracts numeric tokens from <code>div.terms_cond</code> (or body fallback).</li>
                    <li>Normalizes tokens (removes currency symbols, commas, trailing zeros).</li>
                    <li>Strict matching (90 ≠ 390 or 1.90).</li>
                    <li>Reports number of occurrences on the page.</li>
                </ul>
            </div>
        </div>

        <!-- Form -->
        <form method="post" class="card bg-base-100 shadow-lg p-6 space-y-4">
            <label class="form-control">
                <span class="label-text">Paste price points (one per line or space-separated)</span>
                <textarea name="prices" rows="6" required
                    class="textarea textarea-bordered mt-2"><?php echo isset($_POST['prices']) ? htmlspecialchars($_POST['prices']) : "3.79\n4.88\n5.93\n90"; ?></textarea>
            </label>

            <label class="form-control">
                <span class="label-text">Terms page URL</span>
                <input type="url" name="url" placeholder="https://example.com/terms.php"
                    value="<?php echo isset($_POST['url']) ? htmlspecialchars($_POST['url']) : ''; ?>"
                    required class="input input-bordered mt-2" />
            </label>

            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary">Check Prices</button>
                <button type="button" id="clearBtn" class="btn">Clear</button>
            </div>
        </form>

        <!-- Results -->
        <?php if (!empty($result)): ?>
            <div class="mt-8">
                <?php if ($result['error']): ?>
                    <div class="alert alert-error">
                        <span><strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?></span>
                    </div>
                <?php else: ?>
                    <div class="card bg-base-100 shadow-lg p-6 space-y-4">
                        <div class="flex justify-between items-center">
                            <p>Total matches found:
                                <span class="badge badge-primary"><?php echo (int) $result['total_found_count']; ?></span>
                            </p>
                            <div class="text-sm">
                                <span class="text-success mr-3">Found: <?php echo count(array_filter($result['results'], fn($r) => $r['found'])); ?></span>
                                <span class="text-error">Missing: <?php echo count($result['missing']); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($result['missing'])): ?>
                            <div class="alert alert-warning">
                                <span><strong>Missing values:</strong> <?php echo htmlspecialchars(implode(', ', $result['missing'])); ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="overflow-x-auto">
                            <table class="table table-zebra text-sm">
                                <thead>
                                    <tr>
                                        <th>Input</th>
                                        <th>Normalized</th>
                                        <th>Matched?</th>
                                        <th>Occurrences</th>
                                        <th>Page Matches</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($result['results'] as $r): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($r['input']); ?></td>
                                            <td><?php echo htmlspecialchars($r['normalized']); ?></td>
                                            <td>
                                                <?php if ($r['found']): ?>
                                                    <span class="badge badge-success">Yes</span>
                                                <?php else: ?>
                                                    <span class="badge badge-error">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo (int)$r['count']; ?></td>
                                            <td><?php echo $r['matches'] ? htmlspecialchars(implode(', ', $r['matches'])) : '—'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 text-xs opacity-70">
            <p><strong>Notes:</strong> This tool extracts numeric tokens only from <code>div.terms_cond</code> elements (fallback to body if none). Matching is strict after normalization.</p>
        </div>

    </div>

    <script>
        // Clear form
        document.getElementById('clearBtn').addEventListener('click', function() {
            document.querySelector('textarea[name="prices"]').value = '';
            document.querySelector('input[name="url"]').value = '';
        });

        // Theme switcher
        const themeSwitcher = document.getElementById('themeSwitcher');
        themeSwitcher.addEventListener('change', function() {
            document.documentElement.setAttribute('data-theme', this.value);
        });
    </script>
</body>

</html>