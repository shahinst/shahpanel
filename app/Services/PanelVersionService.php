<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The panel's own version and whether GitHub has a newer one.
 *
 * The installed version is the VERSION file of this checkout; the latest one is
 * the VERSION file on the repository's main branch, and what changed is read
 * from changelog.json there (notes in every supported language). Commits that
 * arrive without a version bump are still noticed through GitHub's compare API,
 * so every push to the branch shows up as an update.
 *
 * Remote state is cached: pages only ever read the cache, and the cache is
 * refreshed by the scheduler (panel:check-update) or the Update page.
 */
class PanelVersionService
{
    public const CACHE_KEY = 'panel_update_state';

    public function current(): string
    {
        $file = base_path('VERSION');
        $version = is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return $version !== '' ? $version : '0.0.0';
    }

    /**
     * Commit this checkout is on, read straight from .git (no git binary, so it
     * works as the web user and on hosts where exec is disabled).
     */
    public function currentCommit(): ?string
    {
        $git = base_path('.git');
        $head = @file_get_contents($git.'/HEAD');

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (preg_match('/^[0-9a-f]{40}$/', $head)) {
            return $head;
        }

        if (! preg_match('#^ref:\s*(refs/\S+)$#', $head, $m)) {
            return null;
        }

        $ref = @file_get_contents($git.'/'.$m[1]);

        if ($ref !== false && preg_match('/^[0-9a-f]{40}/', trim($ref))) {
            return substr(trim($ref), 0, 40);
        }

        $packed = @file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($packed as $line) {
            if (str_ends_with($line, ' '.$m[1]) && preg_match('/^([0-9a-f]{40}) /', $line, $p)) {
                return $p[1];
            }
        }

        return null;
    }

    /**
     * Cached remote state, or null when it has not been fetched yet.
     *
     * @return array<string, mixed>|null
     */
    public function cachedState(): ?array
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $state : null;
    }

    /**
     * @return array{
     *     checked_at: string,
     *     ok: bool,
     *     error: ?string,
     *     latest_version: ?string,
     *     latest_commit: ?string,
     *     ahead_by: int,
     *     commits: list<array{sha: string, message: string, date: ?string, url: ?string}>,
     *     changelog: list<array<string, mixed>>
     * }
     */
    public function refresh(): array
    {
        $repo = (string) config('shahpanel.update.repository', 'shahinst/shahpanel');
        $branch = (string) config('shahpanel.update.branch', 'master');
        $state = [
            'checked_at' => now()->toIso8601String(),
            'ok' => true,
            'error' => null,
            'latest_version' => null,
            'latest_commit' => null,
            'ahead_by' => 0,
            'commits' => [],
            'changelog' => [],
        ];
        $errors = [];

        // Each source is tried on its own: a host that is filtered or slow from
        // the server's network (raw.githubusercontent.com often is) must not
        // sink the whole check.
        $latestSha = null;

        try {
            $head = $this->http()->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/commits/{$branch}");

            if ($head->successful()) {
                $latestSha = (string) $head->json('sha') ?: null;
            } else {
                $errors[] = 'api.github.com: HTTP '.$head->status();
            }
        } catch (Throwable $exception) {
            $errors[] = 'api.github.com: '.$exception->getMessage();
        }

        $version = $this->fetchRepoFile($repo, $branch, $latestSha, 'VERSION', $errors);
        $state['latest_version'] = $version !== null ? (trim($version) ?: null) : null;

        $changelog = $this->fetchRepoFile($repo, $branch, $latestSha, 'changelog.json', $errors);
        $decoded = $changelog !== null ? json_decode($changelog, true) : null;

        if (is_array($decoded['versions'] ?? null)) {
            $state['changelog'] = array_values(array_filter($decoded['versions'], 'is_array'));
        }

        $local = $this->currentCommit();
        $compared = false;

        if ($local !== null) {
            try {
                $compare = $this->http()->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$repo}/compare/{$local}...{$branch}");

                if ($compare->successful()) {
                    $compared = true;
                    $state['ahead_by'] = (int) $compare->json('ahead_by', 0);
                    $commits = array_reverse((array) $compare->json('commits', []));
                    $state['commits'] = array_map(static fn (array $c): array => [
                        'sha' => substr((string) ($c['sha'] ?? ''), 0, 7),
                        'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n") ?: '',
                        'date' => $c['commit']['committer']['date'] ?? null,
                        'url' => $c['html_url'] ?? null,
                    ], array_slice(array_filter($commits, 'is_array'), 0, 50));
                    $state['latest_commit'] = substr((string) ($commits[0]['sha'] ?? $latestSha ?? $local), 0, 7);
                } else {
                    $errors[] = 'compare: HTTP '.$compare->status();
                }
            } catch (Throwable $exception) {
                $errors[] = 'compare: '.$exception->getMessage();
            }
        }

        // The check counts as answered when GitHub told us anything usable.
        if ($state['latest_version'] === null && $state['changelog'] === [] && ! $compared) {
            $state['ok'] = false;
            $state['error'] = $errors === [] ? 'GitHub did not answer' : implode(' | ', array_slice($errors, 0, 4));
            Log::info('Panel update check failed', ['errors' => $errors]);
        }

        // A failed check is retried sooner than a good one.
        $minutes = $state['ok'] ? max(5, (int) config('shahpanel.update.check_minutes', 60)) : 10;
        Cache::put(self::CACHE_KEY, $state, now()->addMinutes($minutes + 5));

        return $state;
    }

    /**
     * A file from the repository's branch, from the first source that answers:
     * raw.githubusercontent.com, the GitHub contents API, then jsDelivr (pinned
     * to the latest commit when known, so its cache cannot serve an old file).
     *
     * @param  list<string>  $errors
     */
    protected function fetchRepoFile(string $repo, string $branch, ?string $sha, string $path, array &$errors): ?string
    {
        $sources = [
            'raw.githubusercontent.com' => ["https://raw.githubusercontent.com/{$repo}/".($sha ?? $branch)."/{$path}", []],
            'api.github.com/contents' => ["https://api.github.com/repos/{$repo}/contents/{$path}?ref=".($sha ?? $branch), ['Accept' => 'application/vnd.github.raw']],
            'cdn.jsdelivr.net' => ["https://cdn.jsdelivr.net/gh/{$repo}@".($sha ?? $branch)."/{$path}", []],
        ];

        foreach ($sources as $name => [$url, $headers]) {
            try {
                $response = $this->http()->withHeaders($headers)->get($url);

                if ($response->successful() && $response->body() !== '') {
                    return $response->body();
                }

                $errors[] = "{$name} ({$path}): HTTP ".$response->status();
            } catch (Throwable $exception) {
                $errors[] = "{$name} ({$path}): ".$exception->getMessage();
            }
        }

        return null;
    }

    /**
     * HTTP client for the check; PANEL_UPDATE_PROXY routes it through a proxy
     * on servers that cannot reach GitHub directly.
     */
    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout(8)->connectTimeout(5)->withUserAgent('ShahPanel/'.$this->current());
        $proxy = (string) config('shahpanel.update.proxy', '');

        return $proxy !== '' ? $request->withOptions(['proxy' => $proxy]) : $request;
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public function updateAvailable(?array $state = null): bool
    {
        $state ??= $this->cachedState();

        if (! is_array($state) || empty($state['ok'])) {
            return false;
        }

        $latest = (string) ($state['latest_version'] ?? '');

        if ($latest !== '' && version_compare($latest, $this->current(), '>')) {
            return true;
        }

        return (int) ($state['ahead_by'] ?? 0) > 0;
    }

    /**
     * Version shown as "new": the remote version when it is newer, otherwise the
     * current one with the newest commit (changes without a version bump).
     *
     * @param  array<string, mixed>|null  $state
     */
    public function latestLabel(?array $state = null): string
    {
        $state ??= $this->cachedState();
        $latest = (string) ($state['latest_version'] ?? '');

        if ($latest !== '' && version_compare($latest, $this->current(), '>')) {
            return $latest;
        }

        $commit = (string) ($state['latest_commit'] ?? '');

        return $this->current().($commit !== '' ? ' ('.$commit.')' : '');
    }

    /**
     * Release notes of every version newer than the installed one, in the
     * viewer's language (English, then Persian, as fallbacks).
     *
     * @param  array<string, mixed>|null  $state
     * @return list<array{version: string, date: ?string, notes: list<string>}>
     */
    public function newerNotes(?array $state = null, ?string $locale = null): array
    {
        $state ??= $this->cachedState();
        $locale ??= app()->getLocale();
        $current = $this->current();
        $entries = [];

        foreach ((array) ($state['changelog'] ?? []) as $entry) {
            $version = (string) ($entry['version'] ?? '');

            if ($version === '' || ! version_compare($version, $current, '>')) {
                continue;
            }

            $notes = (array) ($entry['notes'] ?? []);
            $list = $notes[$locale] ?? $notes['en'] ?? $notes['fa'] ?? [];

            $entries[] = [
                'version' => $version,
                'date' => $entry['date'] ?? null,
                'notes' => array_values(array_filter(array_map('strval', (array) $list))),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $entries;
    }

    /**
     * Notes of the installed version itself (shown after an update).
     *
     * @return list<string>
     */
    public function currentNotes(?string $locale = null): array
    {
        $file = base_path('changelog.json');
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $locale ??= app()->getLocale();

        foreach ((array) ($data['versions'] ?? []) as $entry) {
            if (($entry['version'] ?? null) === $this->current()) {
                $notes = (array) ($entry['notes'] ?? []);

                return array_values(array_map('strval', (array) ($notes[$locale] ?? $notes['en'] ?? [])));
            }
        }

        return [];
    }
}
