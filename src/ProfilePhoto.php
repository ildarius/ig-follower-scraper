<?php
declare(strict_types=1);

/**
 * Profile photos for the review screen, fetched on demand and kept on disk.
 *
 * The picture URLs the scrapers stored are signed Instagram CDN links that
 * expire after a few weeks; by 2026-10-16 every one for a pending account
 * returned 403. Fetching a fresh link straight from Instagram without a login
 * is refused on the first request, even through a residential proxy. So the
 * Fetch photo button asks HikerAPI for the profile (one paid lookup), downloads
 * the picture from Instagram's CDN, and saves it as data/photos/<ig_user_id>.img.
 * The saved file never expires, and once it exists no lookup is made again.
 *
 * Spending is bounded four ways: only pending accounts can be fetched; a saved
 * photo is never fetched twice; one lock serialises fetches, so two taps on the
 * same card cannot both pay; a failed fetch is not retried for
 * FAIL_COOLDOWN_SECONDS; and a daily budget (config hikerapi.daily_photo_budget)
 * caps paid lookups across all users.
 *
 * data/ is blocked from the web by the root .htaccess. Photos are served only
 * through api.php?action=review-photo, behind the same login as everything else.
 * The web server runs PHP as a different user, so data/photos must be writable
 * by it (0777 on the VPS).
 *
 * $lookup and $download can be replaced by tests; null means the real HikerAPI
 * call and the real HTTP download.
 */
final class ProfilePhoto
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const FAIL_COOLDOWN_SECONDS = 15 * 60;
    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /** @var null|Closure(string): array */
    public static ?Closure $lookup = null;
    /** @var null|Closure(string, string): void */
    public static ?Closure $download = null;

    public static function dir(): string
    {
        return rtrim((string) Config::get('photos_dir', dirname(__DIR__) . '/data/photos'), '/');
    }

    /** Path of the saved photo for an ig_user_id, or null if the id is not numeric. */
    public static function path(string $igUserId): ?string
    {
        return preg_match('/^\d{1,32}$/', $igUserId) === 1 ? self::dir() . '/' . $igUserId . '.img' : null;
    }

    public static function has(string $igUserId): bool
    {
        $path = self::path($igUserId);
        return $path !== null && is_file($path);
    }

    /**
     * Fetch and save the photo for one pending account. Returns cached = true
     * when it was already saved, in which case nothing was spent.
     */
    public static function fetch(int $id): array
    {
        $row = self::eligibleRow($id);
        $igUserId = (string) $row['ig_user_id'];
        $final = self::path($igUserId);
        if ($final === null) {
            throw new RuntimeException('Account ' . $id . ' has no numeric Instagram id.');
        }
        if (is_file($final)) {
            return ['id' => $id, 'cached' => true];
        }

        $dir = self::dir();
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('The photo folder ' . $dir . ' is missing or not writable by the web server.');
        }

        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the photo folder.');
        }
        try {
            // Another request may have saved it while this one waited, or
            // the row may have stopped being pending, so check both again.
            if (is_file($final)) {
                return ['id' => $id, 'cached' => true];
            }
            $row = self::eligibleRow($id);
            if ((string) $row['ig_user_id'] !== $igUserId) {
                throw new RuntimeException('Account ' . $id . ' changed while waiting; try again.');
            }

            $failFile = $dir . '/' . $igUserId . '.fail';
            if (is_file($failFile) && filemtime($failFile) > time() - self::FAIL_COOLDOWN_SECONDS) {
                $failed = json_decode((string) file_get_contents($failFile), true);
                throw new RuntimeException('Fetching this photo failed a few minutes ago ('
                    . (is_array($failed) ? (string) ($failed['error'] ?? 'unknown error') : 'unknown error')
                    . '). Try again in ' . (int) ceil(self::FAIL_COOLDOWN_SECONDS / 60) . ' minutes.');
            }

            self::spendFromBudget($dir);

            $tmp = $dir . '/' . $igUserId . '.tmp-' . bin2hex(random_bytes(6));
            try {
                $user = (self::$lookup ?? static fn (string $u): array => HikerApi::userByUsername($u))((string) $row['username']);
                $pk = (string) ($user['pk'] ?? $user['id'] ?? '');
                if ($pk !== $igUserId) {
                    throw new RuntimeException('HikerAPI returned a different account for @' . $row['username']
                        . ' (it may have been renamed), so its photo was not saved.');
                }
                $url = (string) ($user['profile_pic_url_hd'] ?? '');
                if ($url === '') {
                    $url = (string) ($user['profile_pic_url'] ?? '');
                }
                if (!self::isInstagramCdnUrl($url)) {
                    throw new RuntimeException('HikerAPI returned no usable picture link.');
                }

                (self::$download ?? static fn (string $u, string $p) => self::httpDownload($u, $p))($url, $tmp);

                $info = @getimagesize($tmp);
                if ($info === false || !in_array($info[2], self::ALLOWED_TYPES, true)) {
                    throw new RuntimeException('The downloaded file is not a JPEG, PNG or WebP image.');
                }
                if (!rename($tmp, $final)) {
                    throw new RuntimeException('Could not save the photo.');
                }
                @chmod($final, 0644);
                @unlink($failFile);
            } catch (Throwable $e) {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
                @file_put_contents($failFile, json_encode(['at' => Config::now(), 'error' => $e->getMessage()]));
                throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
            }

            return ['id' => $id, 'cached' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** The account row, if it exists and is still pending; otherwise throws. */
    private static function eligibleRow(int $id): array
    {
        $row = $id > 0 ? Db::one(
            'SELECT id, ig_user_id, username, follow_status FROM ig_accounts WHERE id = :id',
            ['id' => $id]
        ) : null;
        if ($row === null) {
            throw new InvalidArgumentException('No account with id ' . $id . '.');
        }
        if ($row['follow_status'] !== 'pending') {
            throw new InvalidArgumentException('@' . $row['username'] . ' is no longer pending ('
                . str_replace('_', ' ', (string) $row['follow_status']) . '), so no photo is fetched.');
        }
        return $row;
    }

    /** Only https links on Instagram's own picture hosts are downloaded. */
    public static function isInstagramCdnUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        return preg_match('/(^|\.)(cdninstagram\.com|fbcdn\.net)$/i', (string) ($parts['host'] ?? '')) === 1;
    }

    /**
     * Count one paid lookup against today's budget, or refuse. Called inside
     * the folder lock, before the lookup, so a lookup that then fails still
     * counts: HikerAPI bills the request either way.
     */
    private static function spendFromBudget(string $dir): void
    {
        $budget = (int) Config::get('hikerapi.daily_photo_budget', 500);
        $file = $dir . '/.budget.json';
        $today = substr(Config::now(), 0, 10);
        $count = 0;
        if (is_file($file)) {
            // A budget file that cannot be read or parsed stops fetching rather
            // than silently starting the count again from zero.
            $raw = @file_get_contents($file);
            $state = $raw === false ? null : json_decode($raw, true);
            if (!is_array($state) || !isset($state['day'], $state['count'])) {
                throw new RuntimeException('The photo budget file ' . $file . ' is unreadable; fix or delete it.');
            }
            if (!is_string($state['day']) || !is_int($state['count']) || $state['count'] < 0) {
                throw new RuntimeException('The photo budget file ' . $file . ' is unreadable; fix or delete it.');
            }
            $count = $state['day'] === $today ? $state['count'] : 0;
        }
        if ($count >= $budget) {
            throw new RuntimeException('Today\'s photo budget of ' . $budget . ' lookups is used up. '
                . 'Raise hikerapi.daily_photo_budget in config.php or try again tomorrow.');
        }
        // Written to a temporary file and renamed into place, so an interrupted
        // write can never leave a half-written budget behind.
        $json = (string) json_encode(['day' => $today, 'count' => $count + 1]);
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Could not record the photo budget.');
        }
        @chmod($file, 0666);
    }

    /** Download with no redirects, https only, stopping at MAX_BYTES. */
    private static function httpDownload(string $url, string $dest): void
    {
        $out = fopen($dest, 'xb');
        if ($out === false) {
            throw new RuntimeException('Could not create a temporary file for the photo.');
        }
        $written = 0;
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use ($out, &$written, &$tooBig): int {
                $written += strlen($chunk);
                if ($written > self::MAX_BYTES) {
                    $tooBig = true;
                    return -1;
                }
                return (int) fwrite($out, $chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($out);

        if ($tooBig) {
            throw new RuntimeException('The photo is larger than ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }
        if ($ok === false) {
            throw new RuntimeException('The photo could not be downloaded: ' . $error);
        }
        if ($status !== 200) {
            throw new RuntimeException('Instagram\'s server returned HTTP ' . $status . ' for the photo.');
        }
    }
}
