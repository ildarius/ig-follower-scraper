<?php
/**
 * Localhost-guarded JSON endpoint. Same pattern as the Karkusha app's
 * tests/*.php helpers: the Linux sandbox cannot reach Laragon's MySQL on
 * port 3306, so database work is driven through a browser request to
 * http://127.0.0.1 instead.
 *
 * Actions:
 *   ?action=start&username=american_kratom_assoc&what=followers&limit=500
 *   ?action=status[&run=ID]
 *   ?action=import[&run=ID]      one page per call, resumable
 *   ?action=abort[&run=ID]
 *   ?action=stats
 *   ?action=queue&n=20
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Authentication, then authorisation, then the action.
 *
 * What stood here was a comparison of REMOTE_ADDR against 127.0.0.1. That is
 * correct on the PC and wrong on a remote host, where REMOTE_ADDR is the
 * visitor's address and the check rejects everyone. Auth replaces it, and keeps
 * the original behaviour available as the 'localhost' provider so that nothing
 * changes on the PC.
 *
 * The role check sits here, above the switch, rather than inside the individual
 * cases. An action added to the switch later is therefore denied to the
 * operator role until it is named in Acl, instead of being exposed by having
 * been forgotten.
 */
$authUser = Auth::user();

if ($authUser === null) {
    http_response_code(401);
    echo json_encode([
        'ok'    => false,
        'error' => 'Not signed in.',
        'login' => 'login.php',
    ]);
    exit;
}

$requestedAction = (string) ($_GET['action'] ?? 'stats');

if (!Acl::allowsAction($authUser['role'], $requestedAction)) {
    http_response_code(403);
    echo json_encode([
        'ok'    => false,
        'error' => 'The ' . $authUser['role'] . ' role may not call ' . $requestedAction . '.',
    ]);
    exit;
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$action = $requestedAction;

try {
    $scraper = new Scraper();

    /**
     * Resolve which run to act on: the explicit ?run= id, else the newest.
     */
    $resolveRun = static function () use ($scraper): array {
        if (isset($_GET['run'])) {
            return $scraper->getRunRow((int) $_GET['run']);
        }
        $row = $scraper->latestRunRow();
        if ($row === null) {
            throw new RuntimeException('No runs yet. Start one first.');
        }
        return $row;
    };

    switch ($action) {
        case 'accounts-facets':
            respond(['ok' => true] + AccountsBrowser::facets());

        case 'accounts-search':
            respond(['ok' => true] + AccountsBrowser::search($_GET));

        case 'accounts-bulk':
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                respond(['ok' => false, 'error' => 'accounts-bulk requires POST'], 405);
            }
            $body = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($body)) {
                respond(['ok' => false, 'error' => 'request body must be a JSON object'], 400);
            }
            respond(['ok' => true] + AccountsBrowser::bulk($body, Acl::bulkLimits($authUser['role'])));

        case 'start':
            $username = trim((string) ($_GET['username'] ?? ''));
            $what     = (string) ($_GET['what'] ?? 'followers');
            $limit    = isset($_GET['limit']) && $_GET['limit'] !== '' ? (int) $_GET['limit'] : null;

            if ($username === '') {
                respond(['ok' => false, 'error' => 'username is required'], 400);
            }

            $row = $scraper->start($username, $what, $limit);
            respond([
                'ok'  => true,
                'run' => $row,
                'estimated_cost_usd' => $limit === null ? null : round($limit * 0.00115, 2),
            ]);

        case 'status':
            $row = $scraper->refresh((int) $resolveRun()['id']);
            respond(['ok' => true, 'run' => $row]);

        case 'import':
            $row    = $resolveRun();
            $row    = $scraper->refresh((int) $row['id']);
            $result = $scraper->importAny((int) $row['id']);
            respond([
                'ok'     => true,
                'run'    => $scraper->getRunRow((int) $row['id']),
                'batch'  => $result,
            ]);

        case 'import-all':
            // Import repeatedly within a time budget, so a large dataset needs a
            // handful of requests instead of one per 500 rows. The cursor is
            // persisted per batch, so a request that dies mid-loop loses nothing.
            @set_time_limit(0);
            $row      = $scraper->refresh((int) $resolveRun()['id']);
            $budget   = isset($_GET['seconds']) ? max(5, min(120, (int) $_GET['seconds'])) : 25;
            $deadline = microtime(true) + $budget;

            $imported = 0;
            $skipped  = 0;
            $batches  = 0;
            $done     = false;
            $errors   = [];

            while (microtime(true) < $deadline) {
                $r = $scraper->importAny((int) $row['id']);
                $imported += $r['imported'];
                $skipped  += $r['skipped'];
                $batches++;
                $errors = array_values(array_unique(array_merge($errors, $r['errors'])));
                if ($r['done']) {
                    $done = true;
                    break;
                }
            }

            respond([
                'ok'       => true,
                'done'     => $done,
                'imported' => $imported,
                'skipped'  => $skipped,
                'batches'  => $batches,
                'errors'   => $errors,
                'run'      => $scraper->getRunRow((int) $row['id']),
            ]);

        case 'abort':
            $row = $resolveRun();
            if ($row['apify_run_id'] === null) {
                respond(['ok' => false, 'error' => 'Run has no Apify run id.'], 400);
            }
            (new Apify())->abortRun((string) $row['apify_run_id']);
            respond(['ok' => true, 'run' => $scraper->refresh((int) $row['id'])]);

        case 'log':
            $row = $resolveRun();
            if ($row['apify_run_id'] === null) {
                respond(['ok' => false, 'error' => 'Run has no Apify run id.'], 400);
            }
            $log   = (new Apify())->getRunLog((string) $row['apify_run_id']);
            $lines = preg_split('/\r?\n/', trim($log)) ?: [];
            respond([
                'ok'    => true,
                'run'   => (int) $row['id'],
                'lines' => count($lines),
                'log'   => $lines,
            ]);

        case 'account':
            $me = (new Apify())->getMe();
            // Project only plan and usage fields. No email, no profile data.
            respond([
                'ok'      => true,
                'username'=> $me['username'] ?? null,
                'plan'    => $me['plan'] ?? null,
                'usage'   => [
                    'monthlyUsageCycle' => $me['monthlyUsageCycle'] ?? null,
                    'limits'            => $me['limits'] ?? null,
                    'current'           => $me['current'] ?? null,
                ],
            ]);

        case 'enrich':
            // Start a profile-enrichment run over accounts with no stats yet.
            // Billed per profile returned, so the count is explicit and capped.
            $n          = isset($_GET['n']) ? max(1, min(5000, (int) $_GET['n'])) : 100;
            $publicOnly = isset($_GET['public']) && ($_GET['public'] === '1' || $_GET['public'] === 'true');

            $row = $scraper->startEnrich($n, $publicOnly);
            respond([
                'ok'                 => true,
                'run'                => $row,
                'requested'          => (int) $row['results_limit'],
                'public_only'        => $publicOnly,
                'estimated_cost_usd' => round(((int) $row['results_limit']) * 0.0013, 3),
            ]);

        case 'import-followed':
            // Mark accounts that were followed by hand, before this app existed,
            // so the follow script does not spend a cooldown slot on them again.
            // Source: data/already-followed.json, parsed from the Google Sheet
            // follow tracker. Pass &apply=1 to write; the default is a preview.
            $file = APP_ROOT . '/data/already-followed.json';
            if (!is_file($file)) {
                respond(['ok' => false, 'error' => 'missing data/already-followed.json'], 400);
            }
            $records = json_decode((string) file_get_contents($file), true);
            if (!is_array($records)) {
                respond(['ok' => false, 'error' => 'already-followed.json is not valid JSON'], 400);
            }

            $apply = isset($_GET['apply']) && ($_GET['apply'] === '1' || $_GET['apply'] === 'true');
            $note  = 'followed by hand before automation, imported from follow tracker';

            $matched = [];
            $missing = [];
            $changed = 0;
            $alreadyDone = [];

            $find = Db::pdo()->prepare('SELECT id, username, follow_status FROM ig_accounts WHERE username = :u');
            $upd  = Db::pdo()->prepare(
                'UPDATE ig_accounts
                    SET follow_status = :st, follow_attempted_at = :at, follow_note = :note
                  WHERE id = :id'
            );

            foreach ($records as $r) {
                $u = strtolower(trim((string) ($r['username'] ?? '')));
                if ($u === '') { continue; }

                $find->execute(['u' => $u]);
                $row = $find->fetch();

                if (!$row) { $missing[] = $u; continue; }

                if ($row['follow_status'] !== 'pending') {
                    $alreadyDone[] = $u . ' (' . $row['follow_status'] . ')';
                    continue;
                }

                $matched[] = ['username' => $u, 'to' => (string) $r['status'], 'at' => (string) $r['attempted_at']];

                if ($apply) {
                    $upd->execute([
                        'st'   => (string) $r['status'],
                        'at'   => (string) $r['attempted_at'],
                        'note' => $note,
                        'id'   => (int) $row['id'],
                    ]);
                    $changed++;
                }
            }

            respond([
                'ok'              => true,
                'applied'         => $apply,
                'records_in_file' => count($records),
                'would_change'    => count($matched),
                'changed'         => $changed,
                'not_in_queue'    => count($missing),
                'already_done'    => count($alreadyDone),
                'not_in_queue_list'  => array_slice($missing, 0, 200),
                'already_done_list'  => array_slice($alreadyDone, 0, 50),
                'sample'          => array_slice($matched, 0, 10),
            ]);

        case 'queue-row':
            // Inspect one ig_accounts row, including its follow state.
            $u = trim((string) ($_GET['username'] ?? ''));
            if ($u === '') {
                respond(['ok' => false, 'error' => 'username is required'], 400);
            }
            respond([
                'ok'   => true,
                'rows' => Db::all(
                    'SELECT a.*, s.followers_count, s.posts_count
                       FROM ig_accounts a
                       LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
                      WHERE a.username = :u',
                    ['u' => $u]
                ),
            ]);

        case 'recent-attempts':
            // The most recent follow attempts, newest first. For checking what a
            // run actually did, and what any error rows were.
            $n = isset($_GET['n']) ? max(1, min(200, (int) $_GET['n'])) : 20;
            respond([
                'ok'   => true,
                'rows' => Db::all(
                    'SELECT id, username, follow_status, follow_attempted_at, follow_note, is_private
                       FROM ig_accounts
                      WHERE follow_attempted_at IS NOT NULL
                      ORDER BY follow_attempted_at DESC, id DESC
                      LIMIT ' . $n
                ),
                'errors' => Db::all(
                    "SELECT id, username, follow_status, follow_attempted_at, follow_note
                       FROM ig_accounts WHERE follow_status = 'error'"
                ),
            ]);

        case 'facets':
            // Distinct values for the fields that will become dropdowns in the
            // accounts browser, plus keyword hit counts for the exclusion terms
            // that matter to this project.
            $terms = ['kava', 'kratom', 'smoke', 'vape', 'botanical', 'hemp', 'cbd', 'delta', 'dispensary', 'wholesale', 'distro'];
            $hits = [];
            foreach ($terms as $t) {
                $hits[$t] = (int) Db::scalar(
                    'SELECT COUNT(*) FROM ig_profile_stats WHERE biography LIKE :q',
                    ['q' => '%' . $t . '%']
                );
            }
            respond([
                'ok' => true,
                'follow_status' => Db::all(
                    'SELECT follow_status, COUNT(*) AS n FROM ig_accounts GROUP BY follow_status ORDER BY n DESC'
                ),
                'categories' => Db::all(
                    "SELECT category, COUNT(*) AS n
                       FROM ig_profile_stats
                      WHERE category IS NOT NULL AND category <> ''
                      GROUP BY category ORDER BY n DESC LIMIT 40"
                ),
                'category_null' => (int) Db::scalar(
                    "SELECT COUNT(*) FROM ig_profile_stats WHERE category IS NULL OR category = ''"
                ),
                'is_business' => Db::all(
                    'SELECT is_business, COUNT(*) AS n FROM ig_profile_stats GROUP BY is_business'
                ),
                'bio_empty' => (int) Db::scalar(
                    "SELECT COUNT(*) FROM ig_profile_stats WHERE biography IS NULL OR biography = ''"
                ),
                'keyword_hits' => $hits,
            ]);

        case 'queue-head':
            // The first N rows in queue order (by id), whatever their status.
            // Shows how much of the top of the queue is already handled, which
            // ORDER BY id alone hides.
            $n = isset($_GET['n']) ? max(1, min(200, (int) $_GET['n'])) : 30;
            respond([
                'ok'   => true,
                'rows' => Db::all(
                    'SELECT a.id, a.username, a.follow_status, a.is_private,
                            s.followers_count, s.posts_count
                       FROM ig_accounts a
                       LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
                      ORDER BY a.id
                      LIMIT ' . $n
                ),
                'first_pending_id' => Db::scalar(
                    "SELECT MIN(id) FROM ig_accounts WHERE follow_status = 'pending'"
                ),
                'first_eligible' => Db::one(
                    'SELECT a.id, a.username, s.followers_count
                       FROM ig_accounts a
                       JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
                      WHERE a.follow_status = :st AND s.followers_count >= 50
                      ORDER BY a.id LIMIT 1',
                    ['st' => 'pending']
                ),
            ]);

        case 'queue-stats':
            // How many pending accounts pass the follow script's filters.
            // The script defaults to followers_count >= 50, which requires
            // enrichment, so this is the number that actually governs a run.
            $minF = isset($_GET['min_followers']) ? max(0, (int) $_GET['min_followers']) : 50;
            $minP = isset($_GET['min_posts']) ? max(0, (int) $_GET['min_posts']) : 0;

            $eligible = (int) Db::scalar(
                'SELECT COUNT(*)
                   FROM ig_accounts a
                   JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
                  WHERE a.follow_status = :st
                    AND s.followers_count >= :f
                    AND s.posts_count >= :p',
                ['st' => 'pending', 'f' => $minF, 'p' => $minP]
            );

            $bands = [];
            foreach ([0, 10, 50, 100, 500, 1000, 5000] as $t) {
                $bands[$t] = (int) Db::scalar(
                    'SELECT COUNT(*)
                       FROM ig_accounts a
                       JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
                      WHERE a.follow_status = :st AND s.followers_count >= :f',
                    ['st' => 'pending', 'f' => $t]
                );
            }

            respond([
                'ok'            => true,
                'min_followers' => $minF,
                'min_posts'     => $minP,
                'eligible'      => $eligible,
                'pending_total' => (int) Db::scalar("SELECT COUNT(*) FROM ig_accounts WHERE follow_status = 'pending'"),
                'pending_enriched' => (int) Db::scalar(
                    "SELECT COUNT(*) FROM ig_accounts a
                       JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
                      WHERE a.follow_status = 'pending'"
                ),
                'pending_at_least' => $bands,
                'days_at_100_per_day' => (int) floor($eligible / 100),
            ]);

        case 'enrich-stats':
            respond([
                'ok' => true,
                'stats' => [
                    'accounts_total'   => (int) Db::scalar('SELECT COUNT(*) FROM ig_accounts'),
                    'enriched'         => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats'),
                    'not_enriched'     => (int) Db::scalar(
                        'SELECT COUNT(*) FROM ig_accounts a
                           LEFT JOIN ig_profile_stats p ON p.ig_user_id = a.ig_user_id
                          WHERE p.id IS NULL'
                    ),
                    'zero_posts'       => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats WHERE posts_count = 0'),
                    'zero_followers'   => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats WHERE followers_count = 0'),
                    'business'         => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats WHERE is_business = 1'),
                    'with_email'       => (int) Db::scalar("SELECT COUNT(*) FROM ig_profile_stats WHERE public_email IS NOT NULL AND public_email <> ''"),
                    // AVG, so this is the mean. It was labelled "median" by mistake.
                    // The mean is badly skewed here: one account has 1.49M followers.
                    'followers_mean'   => Db::scalar('SELECT AVG(followers_count) FROM ig_profile_stats'),
                    // MySQL rejects a subquery in OFFSET, so the offset is computed
                    // first and interpolated as an integer.
                    'followers_p50'    => (static function () {
                        $n = (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats WHERE followers_count IS NOT NULL');
                        if ($n === 0) { return null; }
                        $off = intdiv($n, 2);
                        return Db::scalar(
                            'SELECT followers_count FROM ig_profile_stats
                              WHERE followers_count IS NOT NULL
                              ORDER BY followers_count
                              LIMIT 1 OFFSET ' . $off
                        );
                    })(),
                    'followers_max'    => Db::scalar('SELECT MAX(followers_count) FROM ig_profile_stats'),
                ],
                'sample' => Db::all(
                    'SELECT username, followers_count, follows_count, posts_count, is_business, category,
                            LEFT(COALESCE(biography, \'\'), 60) AS bio
                       FROM ig_profile_stats
                      ORDER BY followers_count DESC
                      LIMIT 10'
                ),
            ]);

        case 'actor-pricing':
            // Why a run cost what it cost: the actor's real pricing model and
            // per-event prices, straight from the API.
            $a = trim((string) ($_GET['actor'] ?? ''));
            if ($a === '') {
                respond(['ok' => false, 'error' => 'actor is required'], 400);
            }
            $act = (new Apify())->getActor($a);
            respond([
                'ok'            => true,
                'actor'         => $a,
                'name'          => $act['name'] ?? null,
                'pricing_infos' => $act['pricingInfos'] ?? null,
            ]);

        case 'run-cost':
            // What a run actually cost, from Apify's own accounting rather than
            // from a per-result price quoted on a marketing page.
            $row = $resolveRun();
            if ($row['apify_run_id'] === null) {
                respond(['ok' => false, 'error' => 'Run has no Apify run id.'], 400);
            }
            $r = (new Apify())->getRun((string) $row['apify_run_id']);
            $results = (int) $row['items_total'];
            $cost    = isset($r['usageTotalUsd']) ? (float) $r['usageTotalUsd'] : null;
            respond([
                'ok'              => true,
                'run'             => (int) $row['id'],
                'kind'            => $row['kind'] ?? 'followers',
                'actor'           => $row['actor_id'],
                'results'         => $results,
                'usage_total_usd' => $cost,
                'usd_per_1000'    => ($cost !== null && $results > 0) ? round($cost / $results * 1000, 4) : null,
                'usage_breakdown' => $r['usageUsd'] ?? null,
            ]);

        case 'usage':
            $apify  = new Apify();
            $usage  = [];
            $limits = [];
            try { $usage  = $apify->getMonthlyUsage(); } catch (Throwable $e) { $usage  = ['error' => $e->getMessage()]; }
            try { $limits = $apify->getLimits(); }       catch (Throwable $e) { $limits = ['error' => $e->getMessage()]; }

            $spent = null;
            foreach (['totalUsageCreditsUsdAfterVolumeDiscount', 'totalUsageCreditsUsd'] as $k) {
                if (isset($usage[$k])) { $spent = (float) $usage[$k]; break; }
            }
            $cap = $limits['limits']['maxMonthlyUsageUsd'] ?? $limits['maxMonthlyUsageUsd'] ?? null;

            respond([
                'ok'        => true,
                'spent_usd' => $spent,
                'cap_usd'   => $cap,
                'remaining_usd' => ($spent !== null && $cap !== null) ? round(((float) $cap) - $spent, 4) : null,
                'usage_keys'  => array_keys($usage),
                'limits_keys' => array_keys($limits),
                'usage'  => $usage,
                'limits' => $limits,
            ]);

        case 'profile':
            // Look up one enriched account by username or ig_user_id.
            $u   = trim((string) ($_GET['username'] ?? ''));
            $uid = trim((string) ($_GET['ig_user_id'] ?? ''));
            if ($u === '' && $uid === '') {
                respond(['ok' => false, 'error' => 'username or ig_user_id is required'], 400);
            }
            $found = $u !== ''
                ? Db::one('SELECT * FROM ig_profile_stats WHERE username = :u', ['u' => $u])
                : Db::one('SELECT * FROM ig_profile_stats WHERE ig_user_id = :i', ['i' => $uid]);
            respond(['ok' => true, 'row' => $found]);

        case 'bio-search':
            // Free-text search across enriched bios. Returns matches with the
            // fields that matter for deciding whether an account is a good target.
            $q = trim((string) ($_GET['q'] ?? ''));
            if ($q === '') {
                respond(['ok' => false, 'error' => 'q is required'], 400);
            }
            $lim = isset($_GET['n']) ? max(1, min(200, (int) $_GET['n'])) : 25;
            respond([
                'ok'    => true,
                'query' => $q,
                'rows'  => Db::all(
                    'SELECT id, ig_user_id, username, followers_count, posts_count, biography
                       FROM ig_profile_stats
                      WHERE biography LIKE :q
                      ORDER BY followers_count DESC
                      LIMIT ' . $lim,
                    ['q' => '%' . $q . '%']
                ),
            ]);

        case 'probe':
            // Run an arbitrary actor with arbitrary input, wait for it, and return
            // the raw dataset. For answering "what does this actor actually
            // return" without trusting a docs page. Keep the input tiny: this
            // spends real money per result.
            @set_time_limit(0);
            $actor    = (string) ($_GET['actor'] ?? '');
            $inputRaw = (string) ($_GET['input'] ?? '');
            $wait     = isset($_GET['wait']) ? max(5, min(180, (int) $_GET['wait'])) : 90;

            if ($actor === '' || $inputRaw === '') {
                respond(['ok' => false, 'error' => 'actor and input are required'], 400);
            }

            $input = json_decode($inputRaw, true);
            if (!is_array($input)) {
                respond(['ok' => false, 'error' => 'input must be valid JSON: ' . json_last_error_msg()], 400);
            }

            $apify = new Apify();
            $run   = $apify->startRun($actor, $input);
            $runId = (string) ($run['id'] ?? '');

            $deadline = microtime(true) + $wait;
            $status   = (string) ($run['status'] ?? 'READY');
            while (microtime(true) < $deadline) {
                sleep(3);
                $run    = $apify->getRun($runId);
                $status = (string) ($run['status'] ?? '');
                if (in_array($status, ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT'], true)) {
                    break;
                }
            }

            $items = [];
            $keys  = [];
            if (!empty($run['defaultDatasetId'])) {
                $items = $apify->getDatasetItems((string) $run['defaultDatasetId'], 0, 3, false);
                foreach ($items as $it) {
                    if (is_array($it)) {
                        $keys = array_values(array_unique(array_merge($keys, array_keys($it))));
                    }
                }
            }

            respond([
                'ok'         => true,
                'actor'      => $actor,
                'apify_run'  => $runId,
                'status'     => $status,
                'field_count'=> count($keys),
                'all_keys'   => $keys,
                'items'      => $items,
                'log_tail'   => array_slice(preg_split('/\r?\n/', trim($apify->getRunLog($runId))) ?: [], -6),
            ]);

        case 'dump':
            // Full logical backup of the database, written by PHP because
            // mysqldump is not reachable from every environment this app is
            // driven from. Output is plain SQL, restorable with:
            //   mysql -u user -p dbname < dump.sql
            //
            // &tables=a,b restricts to named tables. &data=0 dumps schema only.
            @set_time_limit(0);

            $dbName   = (string) Config::get('db.name', 'sunny_kratom');
            $withData = !isset($_GET['data']) || ($_GET['data'] !== '0' && $_GET['data'] !== 'false');
            $only     = isset($_GET['tables']) && $_GET['tables'] !== ''
                ? array_filter(array_map('trim', explode(',', (string) $_GET['tables'])))
                : null;

            $all = array_map('current', Db::all('SHOW TABLES'));
            $tables = $only === null ? $all : array_values(array_intersect($all, $only));
            if ($tables === []) {
                respond(['ok' => false, 'error' => 'no matching tables', 'available' => $all], 400);
            }

            $dir = APP_ROOT . '/data';
            if (!is_dir($dir)) { mkdir($dir, 0775, true); }
            $name = 'dump-' . $dbName . '-' . date('Ymd-His') . ($withData ? '' : '-schema') . '.sql';
            $path = $dir . '/' . $name;

            $fh = fopen($path, 'wb');
            if ($fh === false) {
                respond(['ok' => false, 'error' => 'could not open ' . $path . ' for writing'], 500);
            }

            $pdo = Db::pdo();
            $counts = [];

            fwrite($fh, "-- Dump of `{$dbName}` written " . Config::now() . " (" . Config::get('timezone') . ")\n");
            fwrite($fh, "-- Produced by ig-follower-scraper api.php?action=dump\n");
            fwrite($fh, "-- Restore with: mysql -u <user> -p <database> < " . $name . "\n");
            fwrite($fh, "--\n");
            fwrite($fh, "-- The target database must already exist, for example:\n");
            fwrite($fh, "--   CREATE DATABASE `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n\n");
            fwrite($fh, "SET NAMES utf8mb4;\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
            fwrite($fh, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($fh, "SET time_zone = '+00:00';\n\n");

            foreach ($tables as $t) {
                $create = Db::one('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`');
                $ddl = $create['Create Table'] ?? ($create['Create View'] ?? null);
                if ($ddl === null) { continue; }

                fwrite($fh, "\n--\n-- Table `{$t}`\n--\n\n");
                fwrite($fh, "DROP TABLE IF EXISTS `{$t}`;\n");
                fwrite($fh, $ddl . ";\n\n");

                $rows = (int) Db::scalar('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`');
                $counts[$t] = $rows;

                if (!$withData || $rows === 0) { continue; }

                fwrite($fh, "LOCK TABLES `{$t}` WRITE;\n");

                $stmt = $pdo->prepare('SELECT * FROM `' . str_replace('`', '', $t) . '`');
                $stmt->execute();

                $batch = [];
                $batchSize = 200;
                $cols = null;

                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($cols === null) {
                        $cols = '`' . implode('`, `', array_keys($row)) . '`';
                    }
                    $vals = [];
                    foreach ($row as $v) {
                        // Quote everything except NULL. MySQL coerces quoted
                        // numerics, and quoting avoids locale and float issues.
                        $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                    }
                    $batch[] = '(' . implode(',', $vals) . ')';

                    if (count($batch) >= $batchSize) {
                        fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    fwrite($fh, "INSERT INTO `{$t}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }

                fwrite($fh, "UNLOCK TABLES;\n");
            }

            fwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
            fclose($fh);

            respond([
                'ok'          => true,
                'file'        => $name,
                'path'        => $path,
                'bytes'       => filesize($path),
                'with_data'   => $withData,
                'tables'      => $counts,
                'total_rows'  => array_sum($counts),
            ]);

        case 'migrate':
            // Idempotent schema top-up, so a schema change can be applied without
            // opening HeidiSQL. Only ever adds, never drops.
            Db::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `ig_raw_items` (
                  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `run_id`      BIGINT UNSIGNED NOT NULL,
                  `ig_user_id`  VARCHAR(32)         NULL,
                  `payload`     JSON            NOT NULL,
                  `imported_at` DATETIME        NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq_run_user` (`run_id`, `ig_user_id`),
                  KEY `idx_raw_ig_user_id` (`ig_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            Db::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS `ig_profile_stats` (
                  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `ig_user_id`      VARCHAR(32)     NOT NULL,
                  `username`        VARCHAR(255)    NOT NULL,
                  `user_full_name`  VARCHAR(255)        NULL,
                  `followers_count` INT UNSIGNED        NULL,
                  `follows_count`   INT UNSIGNED        NULL,
                  `posts_count`     INT UNSIGNED        NULL,
                  `is_private`      TINYINT(1)          NULL,
                  `is_verified`     TINYINT(1)          NULL,
                  `is_business`     TINYINT(1)          NULL,
                  `biography`       TEXT                NULL,
                  `external_url`    TEXT                NULL,
                  `public_email`    VARCHAR(255)        NULL,
                  `public_phone`    VARCHAR(64)         NULL,
                  `category`        VARCHAR(255)        NULL,
                  `fb_id`           VARCHAR(64)         NULL,
                  `location_id`     VARCHAR(64)         NULL,
                  `account_type`    INT                 NULL,
                  `profile_pic`     TEXT                NULL,
                  `user_url`        VARCHAR(255)        NULL,
                  `scraped_at`      DATETIME            NULL,
                  `run_id`          BIGINT UNSIGNED     NULL,
                  `enriched_at`     DATETIME        NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq_stats_user` (`ig_user_id`),
                  KEY `idx_username` (`username`),
                  KEY `idx_followers` (`followers_count`),
                  KEY `idx_posts` (`posts_count`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            // ADD COLUMN IF NOT EXISTS is not portable, so check first.
            $hasKind = (int) Db::scalar(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'ig_scrape_runs'
                    AND COLUMN_NAME = 'kind'"
            );
            if ($hasKind === 0) {
                Db::pdo()->exec(
                    "ALTER TABLE `ig_scrape_runs`
                       ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT 'followers' AFTER `actor_id`"
                );
            }

            respond([
                'ok'          => true,
                'tables'      => array_map('current', Db::all('SHOW TABLES')),
                'raw_rows'    => (int) Db::scalar('SELECT COUNT(*) FROM ig_raw_items'),
                'stats_rows'  => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats'),
                'kind_column' => $hasKind === 0 ? 'added' : 'already present',
            ]);

        case 'reset-cursor':
            // Rewind a run so its dataset can be re-imported from scratch.
            // Re-reading a dataset is free; only producing results costs money.
            $row = $resolveRun();
            Db::run(
                'UPDATE ig_scrape_runs SET cursor_offset = 0, items_imported = 0 WHERE id = :id',
                ['id' => (int) $row['id']]
            );
            respond(['ok' => true, 'run' => $scraper->getRunRow((int) $row['id'])]);

        case 'raw':
            // Dump raw dataset items straight from Apify, unmapped, so every field
            // the actor actually returns is visible. Use this before assuming a
            // field exists or does not.
            $row = $resolveRun();
            if ($row['dataset_id'] === null) {
                respond(['ok' => false, 'error' => 'Run has no dataset id.'], 400);
            }
            $n     = isset($_GET['n']) ? max(1, min(5, (int) $_GET['n'])) : 2;
            $clean = isset($_GET['clean']) ? ($_GET['clean'] !== '0' && $_GET['clean'] !== 'false') : true;
            $items = (new Apify())->getDatasetItems((string) $row['dataset_id'], 0, $n, $clean);
            $keys  = [];
            foreach ($items as $it) {
                if (is_array($it)) {
                    $keys = array_values(array_unique(array_merge($keys, array_keys($it))));
                }
            }
            respond([
                'ok'         => true,
                'run'        => (int) $row['id'],
                'dataset_id' => $row['dataset_id'],
                'clean'      => $clean,
                'all_keys'   => $keys,
                'items'      => $items,
            ]);

        case 'stats':
            respond([
                'ok'         => true,
                'stats'      => $scraper->stats(),
                'latest_run' => $scraper->latestRunRow(),
            ]);

        case 'queue':
            $n = isset($_GET['n']) ? max(1, min(500, (int) $_GET['n'])) : 20;
            respond([
                'ok'   => true,
                'rows' => Db::all(
                    'SELECT username, full_name, is_private, is_verified, follow_status
                       FROM ig_accounts
                      WHERE follow_status = :s
                      ORDER BY id
                      LIMIT ' . $n,
                    ['s' => 'pending']
                ),
            ]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 400);
    }
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage(), 'type' => $e::class], 500);
}
