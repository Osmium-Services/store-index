<?php

declare(strict_types=1);

/**
 * Rebuilds index.json: one entry per repo under the owner carrying the `osmium-service` topic,
 * with its latest vX.Y.Z release tag, the commit that tag points at, and the service.json at that commit.
 * Osmium installs read this one file instead of crawling every service repo.
 *
 * Only public reads: the search API (own quota), git's ref listing, and raw.githubusercontent.com -
 * so a run costs a couple of API calls however many services there are.
 *
 * Exits non-zero (leaving index.json untouched) if GitHub fails on anything but a missing file, so a
 * hiccup can never publish a shortened catalogue.
 *
 * Usage: php build-index.php [owner]   (GITHUB_TOKEN is optional; raises the search quota)
 */

const TOPIC = 'osmium-service';
const TAG_PATTERN = '/^v?(\d+\.\d+\.\d+)$/';
const PARALLEL = 20;

$owner = $argv[1] ?? getenv('INDEX_OWNER') ?: 'Osmium-Services';
$token = getenv('GITHUB_TOKEN') ?: null;
$outFile = __DIR__ . '/index.json';

/** @param string[] $urls @return array<int|string, array{body: string, code: int, error: string}> */
function fetchMany(array $urls, ?string $token = null, bool $apiHost = false): array
{
    $results = [];
    foreach (array_chunk($urls, PARALLEL, preserve_keys: true) as $chunk) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($chunk as $key => $url) {
            $headers = ['User-Agent: osmium-store-index', 'Accept: application/vnd.github+json'];
            if ($token !== null && $apiHost) $headers[] = "Authorization: Bearer {$token}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) curl_multi_select($multi, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $key => $ch) {
            $results[$key] = ['body' => (string) curl_multi_getcontent($ch), 'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error' => curl_error($ch)];
            curl_multi_remove_handle($multi, $ch);
        }
        curl_multi_close($multi);
    }

    return $results;
}

function fail(string $message): never
{
    fwrite(STDERR, "build-index: {$message}\n");
    exit(1);
}

/** 404 means "not there" (null); any other failure aborts the run. */
function bodyOrNull(array $response, string $what): ?string
{
    if ($response['error'] !== '') fail("{$what}: {$response['error']}");
    if ($response['code'] === 404) return null;
    if ($response['code'] !== 200) fail("{$what}: HTTP {$response['code']}");

    return $response['body'];
}

// 1. Every repo under the owner with the topic (paged; GitHub search returns at most 1000)
$query = rawurlencode('topic:' . TOPIC . " user:{$owner}");
$repos = [];
for ($page = 1; $page <= 10; $page++) {
    $r = fetchMany(["https://api.github.com/search/repositories?q={$query}&per_page=100&page={$page}"], $token, apiHost: true)[0];
    $json = json_decode(bodyOrNull($r, 'search') ?? fail('search returned 404'), true);
    $items = $json['items'] ?? fail('search returned an unreadable response');
    if (($json['incomplete_results'] ?? false) === true) fail('GitHub search returned incomplete results; not publishing a partial catalogue');
    foreach ($items as $item) $repos[] = $item;
    if (count($items) < 100) break;
}

// 2. Release tags and their commits, from git's own ref listing (an annotated tag's commit is its peeled "^{}" line)
$refUrls = [];
foreach ($repos as $i => $repo) $refUrls[$i] = "https://github.com/{$repo['full_name']}.git/info/refs?service=git-upload-pack";
$refResponses = fetchMany($refUrls);

$releases = [];
foreach ($repos as $i => $repo) {
    $body = bodyOrNull($refResponses[$i], "tags of {$repo['full_name']}");
    $commits = [];
    if ($body !== null) {
        preg_match_all('#([0-9a-f]{40}) refs/tags/([^\s^]+)(\^\{\})?#', $body, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            if (!preg_match(TAG_PATTERN, $m[2])) continue;
            $peeled = ($m[3] ?? '') !== '';
            if ($peeled || !isset($commits[$m[2]])) $commits[$m[2]] = $m[1];
        }
    }
    $best = null;
    foreach ($commits as $name => $sha) {
        preg_match(TAG_PATTERN, (string) $name, $v);
        if ($best === null || version_compare($v[1], $best['version'], '>')) $best = ['tag' => (string) $name, 'version' => $v[1], 'commitSha' => $sha];
    }
    $releases[$i] = $best;
}

// 3. service.json at the released commit (or the default branch if there is no release yet)
$manifestUrls = [];
foreach ($repos as $i => $repo) {
    $ref = $releases[$i]['commitSha'] ?? rawurlencode($repo['default_branch'] ?? 'main');
    $manifestUrls[$i] = "https://raw.githubusercontent.com/{$repo['full_name']}/{$ref}/service.json";
}
$manifestResponses = fetchMany($manifestUrls);

$entries = [];
foreach ($repos as $i => $repo) {
    $body = bodyOrNull($manifestResponses[$i], "service.json of {$repo['full_name']}");
    $manifest = $body === null ? null : json_decode($body, true);
    $usable = is_array($manifest) && isset($manifest['id'], $manifest['version']);
    if (!$usable) {
        fwrite(STDERR, "build-index: skipping {$repo['full_name']} (no usable service.json)\n");
        continue;
    }
    $entries[] = [
        'repo' => $repo['full_name'],
        'tag' => $releases[$i]['tag'] ?? null,
        'commitSha' => $releases[$i]['commitSha'] ?? null,
        'pushedAt' => $repo['pushed_at'] ?? '',
        'manifest' => $manifest,
    ];
}
usort($entries, fn(array $a, array $b) => strcmp($a['repo'], $b['repo']));

// Never replace a catalogue with a drastically smaller one (a wrong owner, a search hiccup, a mass-failure)
$previous = is_file($outFile) ? (json_decode((string) file_get_contents($outFile), true)['services'] ?? []) : [];
$tooFew = count($previous) >= 5 && count($entries) < count($previous) / 2;
if ($entries === [] && $previous !== []) fail('found no services but the current index has some; refusing to publish an empty catalogue');
if ($tooFew) fail('found ' . count($entries) . ' services but the current index has ' . count($previous) . '; refusing to publish a catalogue that much smaller (delete index.json to force it)');

// "generatedWeek" changes weekly so the repo always gets a commit: GitHub disables scheduled workflows
// in a public repo after 60 days without activity.
$index = ['owner' => $owner, 'generatedWeek' => gmdate('o-\WW'), 'services' => $entries];
$encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

if (is_file($outFile) && file_get_contents($outFile) === $encoded) {
    echo 'build-index: unchanged (' . count($entries) . " services)\n";
    exit(0);
}
file_put_contents($outFile, $encoded);
echo 'build-index: wrote ' . count($entries) . " services\n";
