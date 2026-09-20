<?php
/**
 * Command line entry point. Run from PowerShell with Laragon's PHP on PATH:
 *
 *   php cli.php start american_kratom_assoc followers 500
 *   php cli.php status
 *   php cli.php import
 *   php cli.php stats
 *   php cli.php queue 20
 *   php cli.php abort
 *
 * The CLI has no request timeout, so "import" pulls every remaining page in one
 * go. The browser endpoint imports one page per request instead.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli.php is for the command line only.\n");
}

require __DIR__ . '/bootstrap.php';

$argv    = $_SERVER['argv'];
$command = $argv[1] ?? 'help';

function out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

function fail(string $line): never
{
    fwrite(STDERR, $line . PHP_EOL);
    exit(1);
}

try {
    $scraper = new Scraper();

    switch ($command) {
        case 'start':
            $source = $argv[2] ?? null;
            $what   = $argv[3] ?? 'followers';
            $limit  = isset($argv[4]) ? (int) $argv[4] : null;

            if ($source === null) {
                fail('Usage: php cli.php start <username> [followers|following] [resultsLimit]');
            }

            $costNote = $limit === null
                ? 'uncapped, billed per profile returned'
                : sprintf('capped at %d profiles, about $%.2f at $1.15 per 1,000', $limit, $limit * 0.00115);

            out(sprintf('Starting %s scrape of @%s (%s).', $what, $source, $costNote));

            $row = $scraper->start($source, $what, $limit);

            out('');
            out('  local run id : ' . $row['id']);
            out('  apify run id : ' . (string) $row['apify_run_id']);
            out('  dataset id   : ' . (string) $row['dataset_id']);
            out('  status       : ' . $row['status']);
            out('');
            out('Now poll it:  php cli.php status');
            break;

        case 'status':
            $row = isset($argv[2]) ? $scraper->getRunRow((int) $argv[2]) : $scraper->latestRunRow();
            if ($row === null) {
                fail('No runs yet. Start one with: php cli.php start <username>');
            }

            $row = $scraper->refresh((int) $row['id']);

            out(sprintf('Run %d  @%s  %s', $row['id'], $row['source_username'], $row['data_to_scrape']));
            out('  apify run id  : ' . (string) $row['apify_run_id']);
            out('  status        : ' . $row['status']);
            out('  started       : ' . (string) $row['started_at']);
            out('  finished      : ' . (string) ($row['finished_at'] ?? '-'));
            out('  items in set  : ' . $row['items_total']);
            out('  imported      : ' . $row['items_imported']);
            out('  cursor offset : ' . $row['cursor_offset']);
            if ($row['error']) {
                out('  error         : ' . $row['error']);
            }

            if ($row['status'] === 'SUCCEEDED') {
                out('');
                out('Run finished. Import it:  php cli.php import');
            }
            break;

        case 'import':
            $row = isset($argv[2]) ? $scraper->getRunRow((int) $argv[2]) : $scraper->latestRunRow();
            if ($row === null) {
                fail('No runs yet. Start one with: php cli.php start <username>');
            }

            $row = $scraper->refresh((int) $row['id']);

            if (!in_array($row['status'], ['SUCCEEDED', 'ABORTED', 'TIMED-OUT'], true)) {
                out(sprintf('Run %d is %s, not finished. Import anyway to take what is in the dataset so far.', $row['id'], $row['status']));
            }

            out(sprintf('Importing dataset %s from offset %d.', (string) $row['dataset_id'], (int) $row['cursor_offset']));

            $summary = $scraper->importAll((int) $row['id'], static function (array $result, int $batch): void {
                out(sprintf(
                    '  batch %d: fetched %d, imported %d, skipped %d, cursor now %d',
                    $batch,
                    $result['fetched'],
                    $result['imported'],
                    $result['skipped'],
                    $result['cursor']
                ));
            });

            out('');
            out(sprintf('Done. %d imported, %d skipped, across %d batches.', $summary['imported'], $summary['skipped'], $summary['batches']));
            if ($summary['errors'] !== []) {
                out('Actor reported: ' . implode(', ', $summary['errors']));
            }
            break;

        case 'abort':
            $row = isset($argv[2]) ? $scraper->getRunRow((int) $argv[2]) : $scraper->latestRunRow();
            if ($row === null || $row['apify_run_id'] === null) {
                fail('No run to abort.');
            }
            (new Apify())->abortRun((string) $row['apify_run_id']);
            $row = $scraper->refresh((int) $row['id']);
            out('Aborted. Status is now ' . $row['status'] . '.');
            break;

        case 'stats':
            $stats = $scraper->stats();
            out('Accounts total   : ' . $stats['accounts_total']);
            out('  of which private: ' . $stats['accounts_private']);
            out('Relations total  : ' . $stats['relations_total']);
            out('');
            out('By follow status:');
            foreach ($stats['by_follow_status'] as $r) {
                out(sprintf('  %-18s %d', $r['follow_status'], $r['n']));
            }
            out('');
            out('By source:');
            foreach ($stats['by_source'] as $r) {
                out(sprintf('  @%-28s %-9s %d', $r['source_username'], $r['relation'], $r['n']));
            }
            break;

        case 'queue':
            $n    = isset($argv[2]) ? max(1, (int) $argv[2]) : 20;
            $rows = Db::all(
                'SELECT username, full_name, is_private, is_verified
                   FROM ig_accounts
                  WHERE follow_status = :s
                  ORDER BY id
                  LIMIT ' . $n,
                ['s' => 'pending']
            );
            out(sprintf('Next %d pending targets:', count($rows)));
            foreach ($rows as $r) {
                out(sprintf(
                    '  @%-30s %s%s%s',
                    $r['username'],
                    (string) ($r['full_name'] ?? ''),
                    $r['is_private'] ? '  [private]' : '',
                    $r['is_verified'] ? '  [verified]' : ''
                ));
            }
            break;

        case 'help':
        default:
            out('ig-follower-scraper');
            out('');
            out('  php cli.php start <username> [followers|following] [resultsLimit]');
            out('  php cli.php status [runId]');
            out('  php cli.php import [runId]');
            out('  php cli.php abort  [runId]');
            out('  php cli.php stats');
            out('  php cli.php queue [n]');
            break;
    }
} catch (Throwable $e) {
    fail('ERROR: ' . $e->getMessage());
}
