<?php
declare(strict_types=1);

/**
 * Query and mutation service for the accounts browser.
 *
 * Keeping filter compilation here ensures that a select-all bulk action uses
 * exactly the same WHERE clause as the count and results shown to the user.
 */
final class AccountsBrowser
{
    private const STATUSES = ['pending', 'followed', 'requested', 'already_following', 'error', 'skipped'];
    private const TEXT_FILTERS = [
        'username' => 'a.username',
        'full_name' => 's.user_full_name',
        'biography' => 's.biography',
        'note' => 'a.follow_note',
    ];

    public static function facets(): array
    {
        return [
            'categories' => Db::all(
                "SELECT category, COUNT(*) AS n
                   FROM ig_profile_stats
                  WHERE category IS NOT NULL AND category <> ''
                  GROUP BY category
                  ORDER BY category ASC"
            ),
            'category_empty' => (int) Db::scalar(
                "SELECT COUNT(*) FROM ig_profile_stats WHERE category IS NULL OR category = ''"
            ),
            'follow_status' => Db::all(
                'SELECT follow_status, COUNT(*) AS n
                   FROM ig_accounts
                  GROUP BY follow_status
                  ORDER BY FIELD(follow_status, \'pending\', \'followed\', \'requested\', \'already_following\', \'error\', \'skipped\')'
            ),
            'totals' => [
                'accounts' => (int) Db::scalar('SELECT COUNT(*) FROM ig_accounts'),
                'enriched' => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats'),
            ],
        ];
    }

    public static function search(array $input): array
    {
        [$where, $params] = self::compileFilters($input);
        $sortMap = [
            'id' => 'a.id',
            'username' => 'a.username',
            'followers_count' => 's.followers_count',
            'posts_count' => 's.posts_count',
        ];
        $sort = isset($sortMap[(string) ($input['sort'] ?? '')]) ? (string) $input['sort'] : 'id';
        $dir = strtolower((string) ($input['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $perPage = in_array((int) ($input['per_page'] ?? 100), [50, 100, 200], true)
            ? (int) ($input['per_page'] ?? 100)
            : 100;
        $page = max(1, (int) ($input['page'] ?? 1));

        $from = ' FROM ig_accounts a LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id ';
        $total = (int) Db::scalar('SELECT COUNT(*)' . $from . $where, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $eligibleParams = $params;
        $eligibleParams['eligible_status'] = 'pending';
        $eligible = (int) Db::scalar(
            'SELECT COUNT(*)' . $from . $where .
            ($where === '' ? ' WHERE ' : ' AND ') . 'a.follow_status = :eligible_status',
            $eligibleParams
        );
        $revertParams = $params;
        $revertParams['revert_status'] = 'skipped';
        $revertEligible = (int) Db::scalar(
            'SELECT COUNT(*)' . $from . $where .
            ($where === '' ? ' WHERE ' : ' AND ') . 'a.follow_status = :revert_status',
            $revertParams
        );

        $rows = Db::all(
            'SELECT a.id, a.username, s.user_full_name, s.followers_count, s.posts_count,
                    s.is_business, a.is_private, s.category, s.biography,
                    a.follow_status, a.follow_note' .
            $from . $where .
            ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir . ', a.id ASC' .
            ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'eligible_for_action' => $eligible,
            'eligible_for_revert' => $revertEligible,
            'rows' => $rows,
        ];
    }

    public static function bulk(array $body): array
    {
        $operation = (string) ($body['operation'] ?? '');
        if (!in_array($operation, ['skip', 'revert'], true)) {
            throw new InvalidArgumentException('operation must be skip or revert');
        }

        $note = trim((string) ($body['note'] ?? ''));
        if ($operation === 'skip' && $note === '') {
            throw new InvalidArgumentException('note is required when marking accounts do-not-follow');
        }
        if (mb_strlen($note) > 255) {
            throw new InvalidArgumentException('note must be 255 characters or fewer');
        }

        $selectAll = ($body['select_all'] ?? false) === true;
        $ids = $body['ids'] ?? null;
        if (!$selectAll && !is_array($ids)) {
            throw new InvalidArgumentException('provide ids or set select_all to true');
        }
        if (is_array($ids) && count($ids) > 5000) {
            throw new InvalidArgumentException('ids may contain at most 5,000 entries');
        }

        $params = [];
        $where = '';
        if ($selectAll) {
            if (!isset($body['filters']) || !is_array($body['filters'])) {
                throw new InvalidArgumentException('filters must be supplied with select_all');
            }
            [$where, $params] = self::compileFilters($body['filters']);
        } else {
            $cleanIds = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $ids),
                static fn (int $id): bool => $id > 0
            )));
            if ($cleanIds === []) {
                throw new InvalidArgumentException('ids must contain at least one valid account id');
            }
            $holders = [];
            foreach ($cleanIds as $i => $id) {
                $key = 'bulk_id_' . $i;
                $holders[] = ':' . $key;
                $params[$key] = $id;
            }
            $where = ' WHERE a.id IN (' . implode(', ', $holders) . ')';
        }

        $from = ' FROM ig_accounts a LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id ';
        $requiredStatus = $operation === 'skip' ? 'pending' : 'skipped';

        $writeParams = $params;
        $writeParams['required_status'] = $requiredStatus;
        $writeWhere = $where . ($where === '' ? ' WHERE ' : ' AND ') . 'a.follow_status = :required_status';
        if ($operation === 'skip') {
            $writeParams['new_status'] = 'skipped';
            $writeParams['note_value'] = $note;
            $writeParams['attempted_at'] = Config::now();
            $set = 'a.follow_status = :new_status, a.follow_note = :note_value, a.follow_attempted_at = :attempted_at';
        } else {
            $writeParams['new_status'] = 'pending';
            $set = 'a.follow_status = :new_status, a.follow_note = NULL, a.follow_attempted_at = NULL';
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $matched = (int) Db::scalar('SELECT COUNT(*)' . $from . $where, $params);
            $changed = Db::run('UPDATE ig_accounts a LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id SET ' . $set . $writeWhere, $writeParams);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'operation' => $operation,
            'changed' => $changed,
            'unchanged' => max(0, $matched - $changed),
            'unchanged_reason' => $operation === 'skip' ? 'not pending' : 'not skipped',
            'note' => $operation === 'skip' ? $note : null,
        ];
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function compileFilters(array $input): array
    {
        $conditions = [];
        $params = [];
        $counter = 0;

        foreach (self::TEXT_FILTERS as $name => $column) {
            if (self::isTrue($input[$name . '_empty'] ?? null)) {
                // A NULL from the right side of the LEFT JOIN means "not
                // enriched", not "this enriched field is empty".
                $enriched = str_starts_with($column, 's.') ? 's.id IS NOT NULL AND ' : '';
                $conditions[] = '(' . $enriched . '(' . $column . " IS NULL OR " . $column . " = ''))";
                continue;
            }
            $raw = trim((string) ($input[$name] ?? ''));
            if ($raw === '') {
                continue;
            }
            $likes = [];
            foreach (explode(',', $raw) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $key = 'text_' . $counter++;
                $likes[] = $column . ' LIKE :' . $key;
                $params[$key] = strpbrk($part, '%_') === false ? '%' . $part . '%' : $part;
            }
            if ($likes !== []) {
                $conditions[] = '(' . implode(' OR ', $likes) . ')';
            }
        }

        $category = (string) ($input['category'] ?? '');
        if ($category === '__none__') {
            $conditions[] = "(s.id IS NOT NULL AND (s.category IS NULL OR s.category = ''))";
        } elseif ($category !== '') {
            $conditions[] = 's.category = :category';
            $params['category'] = $category;
        }

        foreach (['is_business' => 's.is_business', 'is_private' => 'a.is_private'] as $name => $column) {
            $value = (string) ($input[$name] ?? '');
            if ($value === '0' || $value === '1') {
                $conditions[] = $column . ' = :' . $name;
                $params[$name] = (int) $value;
            }
        }

        $statuses = array_values(array_intersect(
            self::STATUSES,
            array_filter(array_map('trim', explode(',', (string) ($input['follow_status'] ?? ''))))
        ));
        if ($statuses !== []) {
            $holders = [];
            foreach ($statuses as $status) {
                $key = 'status_' . $counter++;
                $holders[] = ':' . $key;
                $params[$key] = $status;
            }
            $conditions[] = 'a.follow_status IN (' . implode(', ', $holders) . ')';
        }

        foreach ([
            'followers_min' => ['s.followers_count', '>='],
            'followers_max' => ['s.followers_count', '<='],
            'posts_min' => ['s.posts_count', '>='],
            'posts_max' => ['s.posts_count', '<='],
        ] as $name => [$column, $operator]) {
            if (!isset($input[$name]) || $input[$name] === '') {
                continue;
            }
            $value = filter_var($input[$name], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false) {
                throw new InvalidArgumentException($name . ' must be a non-negative integer');
            }
            $conditions[] = $column . ' ' . $operator . ' :' . $name;
            $params[$name] = $value;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
