<?php
declare(strict_types=1);

/**
 * Orchestrates one Apify run and the resumable import of its dataset.
 *
 * Three steps, each safe to call repeatedly:
 *   1. start()        creates the Apify run and the ig_scrape_runs row
 *   2. refresh()      copies the run's live status into that row
 *   3. importBatch()  pulls one page of dataset items and upserts them
 *
 * importBatch() advances cursor_offset only after its transaction commits, so a
 * request that dies mid page re-imports that page rather than skipping it. The
 * upserts are idempotent, so a repeated page changes nothing.
 */
final class Scraper
{
    private Apify $apify;

    public function __construct(?Apify $apify = null)
    {
        $this->apify = $apify ?? new Apify();
    }

    /**
     * Start a scrape. Returns the ig_scrape_runs row.
     *
     * @param string   $sourceUsername Instagram handle without the @
     * @param string   $dataToScrape   "followers" or "following"
     * @param int|null $resultsLimit   null means every result the actor can reach
     */
    public function start(string $sourceUsername, string $dataToScrape = 'followers', ?int $resultsLimit = null): array
    {
        $sourceUsername = ltrim(trim($sourceUsername), '@');
        if ($sourceUsername === '') {
            throw new InvalidArgumentException('Source username is empty.');
        }
        if (!in_array($dataToScrape, ['followers', 'following'], true)) {
            throw new InvalidArgumentException('dataToScrape must be "followers" or "following".');
        }
        if ($resultsLimit !== null && $resultsLimit < 1) {
            throw new InvalidArgumentException('resultsLimit must be 1 or greater.');
        }

        $actorId = (string) Config::get('actor_id', 'apify/instagram-followers-following-scraper');

        $input = [
            'usernames'    => [$sourceUsername],
            'dataToScrape' => $dataToScrape,
        ];
        if ($resultsLimit !== null) {
            $input['resultsLimit'] = $resultsLimit;
        }

        $run = $this->apify->startRun($actorId, $input);

        Db::run(
            'INSERT INTO ig_scrape_runs
                (actor_id, apify_run_id, dataset_id, source_username, data_to_scrape, results_limit, status, started_at)
             VALUES
                (:actor_id, :apify_run_id, :dataset_id, :source_username, :data_to_scrape, :results_limit, :status, :started_at)',
            [
                'actor_id'        => $actorId,
                'apify_run_id'    => $run['id'] ?? null,
                'dataset_id'      => $run['defaultDatasetId'] ?? null,
                'source_username' => $sourceUsername,
                'data_to_scrape'  => $dataToScrape,
                'results_limit'   => $resultsLimit,
                'status'          => (string) ($run['status'] ?? 'CREATED'),
                'started_at'      => Config::now(),
            ]
        );

        return $this->getRunRow((int) Db::pdo()->lastInsertId());
    }

    /**
     * Copy the live Apify status onto the local row. Returns the updated row.
     */
    public function refresh(int $runRowId): array
    {
        $row = $this->getRunRow($runRowId);
        if ($row['apify_run_id'] === null) {
            return $row;
        }

        $run = $this->apify->getRun((string) $row['apify_run_id']);

        $status   = (string) ($run['status'] ?? $row['status']);
        $finished = isset($run['finishedAt']) && $run['finishedAt']
            ? (new DateTimeImmutable((string) $run['finishedAt']))->setTimezone(Config::timezone())->format('Y-m-d H:i:s')
            : null;

        $datasetId = $run['defaultDatasetId'] ?? $row['dataset_id'];

        // The run object has no item count. Its `stats` carries compute units and
        // timings only, so ask the dataset. Getting this wrong made a finished run
        // with 48 rows in it report "Dataset items: 0".
        $itemsTotal = (int) $row['items_total'];
        if ($datasetId !== null && $datasetId !== '') {
            try {
                $dataset    = $this->apify->getDataset((string) $datasetId);
                $itemsTotal = (int) ($dataset['itemCount'] ?? $itemsTotal);
            } catch (Throwable $e) {
                // Leave the previous count rather than failing a status refresh.
            }
        }

        Db::run(
            'UPDATE ig_scrape_runs
                SET status = :status,
                    dataset_id = COALESCE(:dataset_id, dataset_id),
                    finished_at = :finished_at,
                    items_total = :items_total
              WHERE id = :id',
            [
                'status'      => $status,
                'dataset_id'  => $datasetId,
                'finished_at' => $finished,
                'items_total' => $itemsTotal,
                'id'          => $runRowId,
            ]
        );

        return $this->getRunRow($runRowId);
    }

    /**
     * Import one page of dataset items.
     *
     * @return array{imported:int, skipped:int, fetched:int, done:bool, cursor:int, errors:array<int,string>}
     */
    public function importBatch(int $runRowId, ?int $batchSize = null): array
    {
        $row = $this->getRunRow($runRowId);
        if ($row['dataset_id'] === null) {
            throw new RuntimeException('Run ' . $runRowId . ' has no dataset id yet. Refresh it first.');
        }

        $batchSize = $batchSize ?? (int) Config::get('import_batch_size', 500);
        $offset    = (int) $row['cursor_offset'];

        $items = $this->apify->getDatasetItems((string) $row['dataset_id'], $offset, $batchSize);

        $fetched  = count($items);
        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $now      = Config::now();

        if ($fetched === 0) {
            return [
                'imported' => 0,
                'skipped'  => 0,
                'fetched'  => 0,
                'done'     => true,
                'cursor'   => $offset,
                'errors'   => [],
            ];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $upsertAccount = $pdo->prepare(
                'INSERT INTO ig_accounts
                    (ig_user_id, username, full_name, profile_pic_url, is_verified, is_private, first_seen_at, last_seen_at)
                 VALUES
                    (:ig_user_id, :username, :full_name, :profile_pic_url, :is_verified, :is_private, :first_seen, :last_seen)
                 ON DUPLICATE KEY UPDATE
                    username        = VALUES(username),
                    full_name       = VALUES(full_name),
                    profile_pic_url = VALUES(profile_pic_url),
                    is_verified     = VALUES(is_verified),
                    is_private      = VALUES(is_private),
                    last_seen_at    = VALUES(last_seen_at)'
            );

            $insertRaw = $pdo->prepare(
                'INSERT INTO ig_raw_items
                    (run_id, ig_user_id, payload, imported_at)
                 VALUES
                    (:run_id, :ig_user_id, :payload, :imported_at)
                 ON DUPLICATE KEY UPDATE
                    payload     = VALUES(payload),
                    imported_at = VALUES(imported_at)'
            );

            $upsertRelation = $pdo->prepare(
                'INSERT INTO ig_relations
                    (source_username, relation, ig_user_id, scraped_at)
                 VALUES
                    (:source_username, :relation, :ig_user_id, :scraped_at)
                 ON DUPLICATE KEY UPDATE
                    scraped_at = VALUES(scraped_at)'
            );

            foreach ($items as $item) {
                // Store the payload verbatim before interpreting any of it, so a
                // mapping bug or a field we ignore today is still recoverable.
                $insertRaw->execute([
                    'run_id'      => $runRowId,
                    'ig_user_id'  => isset($item['userId']) && $item['userId'] !== '' ? (string) $item['userId'] : null,
                    'payload'     => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'imported_at' => $now,
                ]);

                // The actor emits a single {"error": "no_items"} object when a
                // profile is private, empty or missing, instead of a result list.
                if (isset($item['error'])) {
                    $errors[] = (string) $item['error'];
                    $skipped++;
                    continue;
                }

                $userId   = isset($item['userId']) ? (string) $item['userId'] : '';
                $username = isset($item['username']) ? (string) $item['username'] : '';

                if ($userId === '' || $username === '') {
                    $skipped++;
                    continue;
                }

                $upsertAccount->execute([
                    'ig_user_id'      => $userId,
                    'username'        => $username,
                    'full_name'       => isset($item['fullName']) ? (string) $item['fullName'] : null,
                    'profile_pic_url' => isset($item['profilePicUrl']) ? (string) $item['profilePicUrl'] : null,
                    'is_verified'     => !empty($item['isVerified']) ? 1 : 0,
                    'is_private'      => !empty($item['isPrivate']) ? 1 : 0,
                    // Two distinct placeholder names on purpose. With
                    // PDO::ATTR_EMULATE_PREPARES off, a native prepared statement
                    // rejects the same named placeholder used twice with
                    // "SQLSTATE[HY093]: Invalid parameter number".
                    'first_seen'      => $now,
                    'last_seen'       => $now,
                ]);

                $relation = strtoupper((string) ($item['type'] ?? ''));
                if (!in_array($relation, ['FOLLOWER', 'FOLLOWING'], true)) {
                    $relation = $row['data_to_scrape'] === 'following' ? 'FOLLOWING' : 'FOLLOWER';
                }

                $upsertRelation->execute([
                    'source_username' => isset($item['sourceUsername']) && $item['sourceUsername'] !== ''
                        ? (string) $item['sourceUsername']
                        : (string) $row['source_username'],
                    'relation'   => $relation,
                    'ig_user_id' => $userId,
                    'scraped_at' => $now,
                ]);

                $imported++;
            }

            Db::run(
                'UPDATE ig_scrape_runs
                    SET cursor_offset = :cursor,
                        items_imported = items_imported + :imported
                  WHERE id = :id',
                [
                    'cursor'   => $offset + $fetched,
                    'imported' => $imported,
                    'id'       => $runRowId,
                ]
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'fetched'  => $fetched,
            'done'     => $fetched < $batchSize,
            'cursor'   => $offset + $fetched,
            'errors'   => array_values(array_unique($errors)),
        ];
    }

    /**
     * Import every remaining page. Only safe from the CLI, where there is no
     * request timeout. Route each batch by run kind, just like the web endpoint.
     *
     * @return array{imported:int, skipped:int, batches:int, errors:array<int,string>}
     */
    public function importAll(int $runRowId, ?callable $onBatch = null): array
    {
        $imported = 0;
        $skipped  = 0;
        $batches  = 0;
        $errors   = [];

        while (true) {
            $result = $this->importAny($runRowId);
            $imported += $result['imported'];
            $skipped  += $result['skipped'];
            $errors    = array_values(array_unique(array_merge($errors, $result['errors'])));
            $batches++;

            if ($onBatch !== null) {
                $onBatch($result, $batches);
            }

            if ($result['done']) {
                break;
            }
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'batches'  => $batches,
            'errors'   => $errors,
        ];
    }

    /**
     * Start a profile-enrichment run over accounts that have no stats yet.
     *
     * Uses a different actor from the follower scrape: the followers actor
     * returns list membership only, with no profile statistics.
     *
     * @param int  $limit      how many accounts to enrich in this run
     * @param bool $publicOnly skip private accounts, which are usually not follow targets
     */
    public function startEnrich(int $limit, bool $publicOnly = false): array
    {
        $limit = max(1, min(5000, $limit));

        $sql = 'SELECT a.username
                  FROM ig_accounts a
                  LEFT JOIN ig_profile_stats p ON p.ig_user_id = a.ig_user_id
                 WHERE p.id IS NULL';
        if ($publicOnly) {
            $sql .= ' AND a.is_private = 0';
        }
        $sql .= ' ORDER BY a.id LIMIT ' . $limit;

        $usernames = array_column(Db::all($sql), 'username');
        if ($usernames === []) {
            throw new RuntimeException('Nothing left to enrich: every account already has profile stats.');
        }

        $actorId = (string) Config::get('enrich_actor_id', 'memo23/instagram-followers-count-scraper');

        $run = $this->apify->startRun($actorId, ['usernames' => $usernames]);

        Db::run(
            'INSERT INTO ig_scrape_runs
                (actor_id, kind, apify_run_id, dataset_id, source_username, data_to_scrape, results_limit, status, started_at)
             VALUES
                (:actor_id, :kind, :apify_run_id, :dataset_id, :source_username, :data_to_scrape, :results_limit, :status, :started_at)',
            [
                'actor_id'        => $actorId,
                'kind'            => 'profiles',
                'apify_run_id'    => $run['id'] ?? null,
                'dataset_id'      => $run['defaultDatasetId'] ?? null,
                'source_username' => 'enrich',
                'data_to_scrape'  => 'profiles',
                'results_limit'   => count($usernames),
                'status'          => (string) ($run['status'] ?? 'CREATED'),
                'started_at'      => Config::now(),
            ]
        );

        return $this->getRunRow((int) Db::pdo()->lastInsertId());
    }

    /**
     * Import one page of a profile-enrichment dataset into ig_profile_stats.
     * Same resumable cursor contract as importBatch().
     *
     * @return array{imported:int, skipped:int, fetched:int, done:bool, cursor:int, errors:array<int,string>}
     */
    public function importProfileBatch(int $runRowId, ?int $batchSize = null): array
    {
        $row = $this->getRunRow($runRowId);
        if ($row['dataset_id'] === null) {
            throw new RuntimeException('Run ' . $runRowId . ' has no dataset id yet. Refresh it first.');
        }

        $batchSize = $batchSize ?? (int) Config::get('import_batch_size', 500);
        $offset    = (int) $row['cursor_offset'];

        $items   = $this->apify->getDatasetItems((string) $row['dataset_id'], $offset, $batchSize);
        $fetched = count($items);

        if ($fetched === 0) {
            return ['imported' => 0, 'skipped' => 0, 'fetched' => 0, 'done' => true, 'cursor' => $offset, 'errors' => []];
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $now      = Config::now();

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $insertRaw = $pdo->prepare(
                'INSERT INTO ig_raw_items (run_id, ig_user_id, payload, imported_at)
                 VALUES (:run_id, :ig_user_id, :payload, :imported_at)
                 ON DUPLICATE KEY UPDATE payload = VALUES(payload), imported_at = VALUES(imported_at)'
            );

            $upsert = $pdo->prepare(
                'INSERT INTO ig_profile_stats
                    (ig_user_id, username, user_full_name, followers_count, follows_count, posts_count,
                     is_private, is_verified, is_business, biography, external_url, public_email,
                     public_phone, category, fb_id, location_id, account_type, profile_pic, user_url,
                     scraped_at, run_id, enriched_at)
                 VALUES
                    (:ig_user_id, :username, :user_full_name, :followers_count, :follows_count, :posts_count,
                     :is_private, :is_verified, :is_business, :biography, :external_url, :public_email,
                     :public_phone, :category, :fb_id, :location_id, :account_type, :profile_pic, :user_url,
                     :scraped_at, :run_id, :enriched_at)
                 ON DUPLICATE KEY UPDATE
                    username        = VALUES(username),
                    user_full_name  = VALUES(user_full_name),
                    followers_count = VALUES(followers_count),
                    follows_count   = VALUES(follows_count),
                    posts_count     = VALUES(posts_count),
                    is_private      = VALUES(is_private),
                    is_verified     = VALUES(is_verified),
                    is_business     = VALUES(is_business),
                    biography       = VALUES(biography),
                    external_url    = VALUES(external_url),
                    public_email    = VALUES(public_email),
                    public_phone    = VALUES(public_phone),
                    category        = VALUES(category),
                    fb_id           = VALUES(fb_id),
                    location_id     = VALUES(location_id),
                    account_type    = VALUES(account_type),
                    profile_pic     = VALUES(profile_pic),
                    user_url        = VALUES(user_url),
                    scraped_at      = VALUES(scraped_at),
                    run_id          = VALUES(run_id),
                    enriched_at     = VALUES(enriched_at)'
            );

            foreach ($items as $item) {
                $userId = isset($item['userId']) ? (string) $item['userId'] : '';

                $insertRaw->execute([
                    'run_id'      => $runRowId,
                    'ig_user_id'  => $userId !== '' ? $userId : null,
                    'payload'     => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'imported_at' => $now,
                ]);

                if (isset($item['error'])) {
                    $errors[] = (string) $item['error'];
                    $skipped++;
                    continue;
                }

                $username = isset($item['userName']) ? (string) $item['userName'] : '';
                if ($userId === '' || $username === '') {
                    $skipped++;
                    continue;
                }

                // scrapedAt arrives as ISO 8601 UTC. Store it in the configured
                // timezone so it lines up with every other timestamp in the DB.
                $scrapedAt = null;
                if (!empty($item['scrapedAt'])) {
                    try {
                        $scrapedAt = (new DateTimeImmutable((string) $item['scrapedAt']))
                            ->setTimezone(Config::timezone())
                            ->format('Y-m-d H:i:s');
                    } catch (Throwable $e) {
                        $scrapedAt = null;
                    }
                }

                $upsert->execute([
                    'ig_user_id'      => $userId,
                    'username'        => $username,
                    'user_full_name'  => isset($item['userFullName']) ? (string) $item['userFullName'] : null,
                    'followers_count' => isset($item['followersCount']) ? (int) $item['followersCount'] : null,
                    'follows_count'   => isset($item['followsCount']) ? (int) $item['followsCount'] : null,
                    'posts_count'     => isset($item['postsCount']) ? (int) $item['postsCount'] : null,
                    'is_private'      => isset($item['isPrivate']) ? (int) (bool) $item['isPrivate'] : null,
                    'is_verified'     => isset($item['isVerified']) ? (int) (bool) $item['isVerified'] : null,
                    'is_business'     => isset($item['isBusiness']) ? (int) (bool) $item['isBusiness'] : null,
                    'biography'       => $item['biography'] ?? null,
                    'external_url'    => $item['externalUrl'] ?? null,
                    'public_email'    => $item['publicEmail'] ?? null,
                    'public_phone'    => $item['publicPhone'] ?? null,
                    'category'        => $item['category'] ?? null,
                    'fb_id'           => isset($item['fbId']) ? (string) $item['fbId'] : null,
                    'location_id'     => isset($item['locationId']) ? (string) $item['locationId'] : null,
                    'account_type'    => isset($item['accountType']) ? (int) $item['accountType'] : null,
                    'profile_pic'     => $item['profilePic'] ?? null,
                    'user_url'        => $item['userUrl'] ?? null,
                    'scraped_at'      => $scrapedAt,
                    'run_id'          => $runRowId,
                    'enriched_at'     => $now,
                ]);

                $imported++;
            }

            Db::run(
                'UPDATE ig_scrape_runs
                    SET cursor_offset = :cursor, items_imported = items_imported + :imported
                  WHERE id = :id',
                ['cursor' => $offset + $fetched, 'imported' => $imported, 'id' => $runRowId]
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'fetched'  => $fetched,
            'done'     => $fetched < $batchSize,
            'cursor'   => $offset + $fetched,
            'errors'   => array_values(array_unique($errors)),
        ];
    }

    /**
     * Route to the right importer for the run's kind.
     */
    public function importAny(int $runRowId, ?int $batchSize = null): array
    {
        $row = $this->getRunRow($runRowId);
        return ($row['kind'] ?? 'followers') === 'profiles'
            ? $this->importProfileBatch($runRowId, $batchSize)
            : $this->importBatch($runRowId, $batchSize);
    }

    public function getRunRow(int $runRowId): array
    {
        $row = Db::one('SELECT * FROM ig_scrape_runs WHERE id = :id', ['id' => $runRowId]);
        if ($row === null) {
            throw new RuntimeException('No ig_scrape_runs row with id ' . $runRowId . '.');
        }
        return $row;
    }

    public function latestRunRow(): ?array
    {
        return Db::one('SELECT * FROM ig_scrape_runs ORDER BY id DESC LIMIT 1');
    }

    /**
     * Counts for the dashboard.
     */
    public function stats(): array
    {
        return [
            'accounts_total'   => (int) Db::scalar('SELECT COUNT(*) FROM ig_accounts'),
            'accounts_private' => (int) Db::scalar('SELECT COUNT(*) FROM ig_accounts WHERE is_private = 1'),
            'relations_total'  => (int) Db::scalar('SELECT COUNT(*) FROM ig_relations'),
            'by_follow_status' => Db::all(
                'SELECT follow_status, COUNT(*) AS n FROM ig_accounts GROUP BY follow_status ORDER BY n DESC'
            ),
            'by_source' => Db::all(
                'SELECT source_username, relation, COUNT(*) AS n
                   FROM ig_relations
                  GROUP BY source_username, relation
                  ORDER BY n DESC'
            ),
        ];
    }
}
