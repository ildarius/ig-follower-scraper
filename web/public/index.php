<?php
/**
 * Small dashboard. Everything it does goes through api.php, which authenticates
 * every request and checks the caller's role. Start a run, watch it, then
 * import the dataset one page at a time until the import reports done.
 *
 * This page is admin only. It exposes the actions that spend money at Apify and
 * the ones that change importer state, so Acl does not grant it to the operator
 * role and the guard below returns 403 rather than redirecting.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
$authUser = require __DIR__ . '/page-guard.php';

$bootError = null;
$stats     = null;
$latest    = null;

$enrich = null;
$staleWarning = null;

try {
    if (!APP_CONFIGURED) {
        throw new RuntimeException('Application setup is incomplete: config.php is missing.');
    }
    $scraper = new Scraper();
    $stats   = $scraper->stats();
    $latest  = $scraper->latestRunRow();

    // A run's status is only as fresh as the last time something asked Apify.
    // Without this, a run that finished after the last status check keeps
    // displaying RUNNING forever. Only non-terminal runs are refreshed, so a
    // finished run costs no API call on page load.
    if ($latest !== null
        && !in_array((string) $latest['status'], ['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT'], true)) {
        try {
            $latest = $scraper->refresh((int) $latest['id']);
        } catch (Throwable $e) {
            $staleWarning = 'Could not refresh run status from Apify: ' . $e->getMessage();
        }
    }

    $enrich  = [
        'enriched'     => (int) Db::scalar('SELECT COUNT(*) FROM ig_profile_stats'),
        'not_enriched' => (int) Db::scalar(
            'SELECT COUNT(*) FROM ig_accounts a
               LEFT JOIN ig_profile_stats p ON p.ig_user_id = a.ig_user_id
              WHERE p.id IS NULL'
        ),
    ];
} catch (Throwable $e) {
    $bootError = $e->getMessage();
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>IG Follower Scraper</title>
<style>
  :root {
    --bg: #ffffff;
    --fg: #1b1f24;
    --muted: #5c6771;
    --line: #dfe3e8;
    --accent: #2f6f4f;
    --warn: #8a4b12;
    --err: #9b2226;
    --panel: #f6f7f9;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #14171a; --fg: #e8eaed; --muted: #9aa4af; --line: #2c3238;
      --accent: #6cc39a; --warn: #e0a463; --err: #e08585; --panel: #1c2024;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 24px 16px; background: var(--bg); color: var(--fg);
    font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .wrap { max-width: 900px; margin: 0 auto; }
  .page-header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 28px 0 10px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
  .sub { color: var(--muted); margin: 0 0 20px; }
  .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 14px 16px; }
  label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 4px; }
  input, select {
    width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px;
    background: var(--bg); color: var(--fg); font: inherit;
  }
  .row { display: flex; gap: 12px; flex-wrap: wrap; }
  .row > div { flex: 1 1 150px; }
  button {
    padding: 9px 14px; border: 1px solid var(--line); border-radius: 6px; cursor: pointer;
    background: var(--bg); color: var(--fg); font: inherit;
  }
  button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  button:disabled { opacity: .5; cursor: not-allowed; }
  .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
  table { border-collapse: collapse; width: 100%; }
  th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); }
  th { color: var(--muted); font-weight: 600; font-size: 13px; }
  pre {
    background: var(--panel); border: 1px solid var(--line); border-radius: 8px;
    padding: 12px; overflow: auto; max-height: 340px; font-size: 13px; margin: 12px 0 0;
  }
  .err { color: var(--err); }
  .note { color: var(--muted); font-size: 13px; }
  code { background: var(--panel); padding: 1px 5px; border-radius: 4px; }
  .sign-out { padding: 8px 12px; border: 1px solid var(--line); border-radius: 6px; color: var(--accent); text-decoration: none; white-space: nowrap; }
  .sign-out:hover { border-color: var(--accent); }
  .enrich-progress[hidden] { display: none; }
  .enrich-progress {
    position: fixed; inset: 0; z-index: 10; display: grid; place-items: center;
    padding: 24px; background: color-mix(in srgb, var(--bg) 88%, transparent);
  }
  .enrich-progress-card {
    width: min(100%, 420px); padding: 28px; text-align: center;
    background: var(--panel); border: 1px solid var(--line); border-radius: 10px;
    box-shadow: 0 16px 48px rgb(0 0 0 / .18);
  }
  .spinner {
    width: 42px; height: 42px; margin: 0 auto 16px; border: 4px solid var(--line);
    border-top-color: var(--accent); border-radius: 50%; animation: spin .8s linear infinite;
  }
  .enrich-progress h2 { margin: 0 0 8px; color: var(--fg); font-size: 18px; text-transform: none; letter-spacing: normal; }
  .enrich-progress p { margin: 0; }
  @keyframes spin { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
</style>
</head>
<body>
<div class="enrich-progress" id="enrich-progress" hidden role="status" aria-live="assertive" aria-atomic="true">
  <div class="enrich-progress-card">
    <div class="spinner" aria-hidden="true"></div>
    <h2>Enrichment in progress</h2>
    <p id="enrich-progress-message">Keep this tab open while Apify runs and profiles are imported.</p>
  </div>
</div>
<div class="wrap">

  <header class="page-header">
    <div>
      <h1>IG Follower Scraper</h1>
      <p class="sub">Apify <code>instagram-followers-following-scraper</code> into MySQL <code><?= h((string) Config::get('db.name')) ?></code>. <a href="accounts.php">Browse accounts</a></p>
    </div>
    <a class="sign-out" href="login.php?logout=1">Sign out</a>
  </header>

<?php if ($bootError !== null): ?>
  <div class="panel">
    <p class="err"><strong>Cannot reach the database or config.</strong></p>
    <p><?= h($bootError) ?></p>
    <p class="note">Check that Laragon's MySQL is running, that <code>config.php</code> exists, and that <code>sql/schema.sql</code> has been executed.</p>
  </div>
<?php else: ?>

  <h2>Start a run</h2>
  <div class="panel">
    <div class="row">
      <div>
        <label for="username">Instagram username</label>
        <input id="username" value="american_kratom_assoc" spellcheck="false">
      </div>
      <div>
        <label for="what">Direction</label>
        <select id="what">
          <option value="followers">followers</option>
          <option value="following">following</option>
        </select>
      </div>
      <div>
        <label for="limit">Results limit (blank = all)</label>
        <input id="limit" value="500" inputmode="numeric">
      </div>
    </div>
    <div class="actions">
      <button class="primary" id="btn-start">Start run</button>
      <button id="btn-status">Refresh status</button>
      <button id="btn-import">Import one page</button>
      <button id="btn-import-all">Import until done</button>
      <button id="btn-stats">Stats</button>
      <button id="btn-abort">Abort run</button>
    </div>
    <p class="note" id="cost">At $1.15 per 1,000 profiles, 500 costs about $0.58 and 12,400 costs about $14.26.</p>
  </div>

  <h2>Enrich profiles</h2>
  <div class="panel">
    <p class="note" style="margin-top:0">
      Follower counts, post counts, bios and business flags for accounts already in the queue.
      Uses <code><?= h((string) Config::get('enrich_actor_id', 'memo23/instagram-followers-count-scraper')) ?></code>,
      a different actor from the follower scrape, billed per profile returned.
<?php if ($enrich !== null): ?>
      <br><strong><?= h((string) $enrich['enriched']) ?></strong> enriched,
      <strong><?= h((string) $enrich['not_enriched']) ?></strong> still to do.
<?php endif; ?>
    </p>
    <div class="row">
      <div>
        <label for="enrich-n">How many profiles</label>
        <input id="enrich-n" value="100" inputmode="numeric">
      </div>
      <div>
        <label for="enrich-public">Skip private accounts</label>
        <select id="enrich-public">
          <option value="0">no, enrich everything</option>
          <option value="1">yes, public only</option>
        </select>
      </div>
    </div>
    <div class="actions">
      <button class="primary" id="btn-enrich">Enrich &amp; import</button>
      <button id="btn-enrich-import">Resume latest import</button>
      <button id="btn-enrich-stats">Enrichment stats</button>
    </div>
    <p class="note">Keeps this page open while Apify runs. If the browser closes or a request fails, use Resume latest import; completed pages are saved.</p>
    <p class="note" id="enrich-cost">About $1.30 per 1,000 profiles, so 100 costs about $0.13 and all 10,857 about $14.11.</p>
  </div>

  <h2>Latest run</h2>
  <div class="panel">
<?php if ($staleWarning !== null): ?>
    <p class="note err"><?= h($staleWarning) ?> The status below may be out of date.</p>
<?php endif; ?>
<?php if ($latest === null): ?>
    <p class="note">No runs yet.</p>
<?php else: ?>
    <table>
      <tr><th>Local id</th><td><?= h((string) $latest['id']) ?></td></tr>
      <tr><th>Kind</th><td><?= h((string) ($latest['kind'] ?? 'followers')) ?></td></tr>
      <tr><th>Source</th><td>@<?= h((string) $latest['source_username']) ?> (<?= h((string) $latest['data_to_scrape']) ?>)</td></tr>
      <tr><th>Apify run</th><td><?= h((string) $latest['apify_run_id']) ?></td></tr>
      <tr><th>Status</th><td><?= h((string) $latest['status']) ?></td></tr>
      <tr><th>Items in dataset</th><td><?= h((string) $latest['items_total']) ?></td></tr>
      <tr><th>Imported</th><td><?= h((string) $latest['items_imported']) ?></td></tr>
      <tr><th>Cursor offset</th><td><?= h((string) $latest['cursor_offset']) ?></td></tr>
      <tr><th>Started</th><td><?= h((string) $latest['started_at']) ?></td></tr>
      <tr><th>Finished</th><td><?= h((string) ($latest['finished_at'] ?? '-')) ?></td></tr>
    </table>
<?php endif; ?>
  </div>

  <h2>Database</h2>
  <div class="panel">
    <table>
      <tr><th>Accounts</th><td><?= h((string) $stats['accounts_total']) ?> (<?= h((string) $stats['accounts_private']) ?> private)</td></tr>
      <tr><th>Relations</th><td><?= h((string) $stats['relations_total']) ?></td></tr>
<?php foreach ($stats['by_follow_status'] as $r): ?>
      <tr><th><?= h((string) $r['follow_status']) ?></th><td><?= h((string) $r['n']) ?></td></tr>
<?php endforeach; ?>
    </table>
  </div>

  <h2>Output</h2>
  <pre id="out">Ready.</pre>

<?php endif; ?>
</div>

<script>
const out = document.getElementById('out');
const enrichProgress = document.getElementById('enrich-progress');
const enrichProgressMessage = document.getElementById('enrich-progress-message');

function log(text) {
  const stamp = new Date().toLocaleTimeString();
  out.textContent = '[' + stamp + '] ' + text + '\n' + out.textContent;
}

async function call(params) {
  const qs = new URLSearchParams(params).toString();
  const res = await fetch('api.php?' + qs, { headers: { 'Accept': 'application/json' } });
  const body = await res.json();
  if (res.status === 401) window.location.assign(body.login || 'login.php');
  if (!body.ok) {
    throw new Error(body.error || 'Request failed');
  }
  return body;
}

function busy(state) {
  // An actor can keep billing while this page waits, so Abort must stay usable.
  document.querySelectorAll('button').forEach(b => {
    if (b.id !== 'btn-abort') { b.disabled = state; }
  });
}

function setEnrichProgress(visible, message) {
  enrichProgress.hidden = !visible;
  if (message) { enrichProgressMessage.textContent = message; }
}

const TERMINAL_RUN_STATUSES = new Set(['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT']);

function pause(ms) {
  return new Promise(resolve => window.setTimeout(resolve, ms));
}

// Apify runs asynchronously. Keep that boundary in the API so imports remain
// resumable, while presenting one normal "enrich and import" action in the UI.
async function waitForRun(runId) {
  for (;;) {
    const body = await call({ action: 'status', run: runId });
    const run = body.run;
    log('Enrichment run ' + run.id + ' is ' + run.status + '.');
    setEnrichProgress(true, 'Apify is ' + run.status + '. Keep this tab open.');
    if (!TERMINAL_RUN_STATUSES.has(run.status)) {
      await pause(5000);
      continue;
    }
    if (run.status !== 'SUCCEEDED') {
      throw new Error('Enrichment run ended as ' + run.status + '. Nothing was imported.');
    }
    return run;
  }
}

async function importRunUntilDone(runId) {
  let total = 0;
  for (;;) {
    const params = { action: 'import-all', seconds: 60 };
    if (runId !== undefined) { params.run = runId; }
    const body = await call(params);
    total += body.imported;
    log('Imported +' + body.imported + ' (running total ' + total +
        ', cursor ' + body.run.cursor_offset + ').');
    setEnrichProgress(true, 'Importing profiles: ' + total + ' imported in this session. Keep this tab open.');
    if (body.errors.length) { log('Actor reported: ' + body.errors.join(', ')); }
    if (body.done) {
      log('Import finished. ' + total + ' rows imported.');
      return body;
    }
  }
}

function startParams() {
  const limit = document.getElementById('limit').value.trim();
  const params = {
    action: 'start',
    username: document.getElementById('username').value.trim(),
    what: document.getElementById('what').value
  };
  if (limit !== '') { params.limit = limit; }
  return params;
}

document.getElementById('btn-start').addEventListener('click', async () => {
  const params = startParams();
  const label = params.limit ? params.limit + ' profiles' : 'every profile, uncapped';
  if (!confirm('Start a paid Apify run for @' + params.username + ' (' + label + ')?')) { return; }
  busy(true);
  try {
    const body = await call(params);
    log('Started. Local run ' + body.run.id + ', Apify run ' + body.run.apify_run_id +
        (body.estimated_cost_usd !== null ? ', about $' + body.estimated_cost_usd : ''));
  } catch (e) {
    log('ERROR ' + e.message);
  } finally {
    busy(false);
  }
});

document.getElementById('btn-status').addEventListener('click', async () => {
  busy(true);
  try {
    const body = await call({ action: 'status' });
    log('Run ' + body.run.id + ' is ' + body.run.status + '. Dataset items: ' + body.run.items_total +
        ', imported: ' + body.run.items_imported + ', cursor: ' + body.run.cursor_offset);
  } catch (e) {
    log('ERROR ' + e.message);
  } finally {
    busy(false);
  }
});

document.getElementById('btn-import').addEventListener('click', async () => {
  busy(true);
  try {
    const body = await call({ action: 'import' });
    log('Page imported: fetched ' + body.batch.fetched + ', imported ' + body.batch.imported +
        ', skipped ' + body.batch.skipped + ', cursor ' + body.batch.cursor + (body.batch.done ? ' (done)' : ''));
    if (body.batch.errors.length) { log('Actor reported: ' + body.batch.errors.join(', ')); }
  } catch (e) {
    log('ERROR ' + e.message);
  } finally {
    busy(false);
  }
});

document.getElementById('btn-import-all').addEventListener('click', async () => {
  busy(true);
  let total = 0, pages = 0;
  try {
    for (;;) {
      const body = await call({ action: 'import' });
      total += body.batch.imported;
      pages += 1;
      log('Page ' + pages + ': +' + body.batch.imported + ' (running total ' + total + ', cursor ' + body.batch.cursor + ')');
      if (body.batch.done) { break; }
    }
    log('Import finished. ' + total + ' rows over ' + pages + ' pages.');
  } catch (e) {
    log('ERROR ' + e.message + ' — the cursor is saved, press this again to resume.');
  } finally {
    busy(false);
  }
});

document.getElementById('btn-enrich').addEventListener('click', async () => {
  const n = document.getElementById('enrich-n').value.trim();
  const pub = document.getElementById('enrich-public').value;
  const cost = (parseInt(n, 10) || 0) * 0.0013;
  if (!confirm('Enrich ' + n + ' profiles? Roughly $' + cost.toFixed(2) + '.')) { return; }
  busy(true);
  setEnrichProgress(true, 'Starting Apify. Keep this tab open while it runs and profiles are imported.');
  try {
    const body = await call({ action: 'enrich', n: n, public: pub });
    log('Enrichment run ' + body.run.id + ' started (Apify ' + body.run.apify_run_id + '), ' +
        body.requested + ' profiles, about $' + body.estimated_cost_usd +
        '. Waiting for Apify, then importing automatically.');
    await waitForRun(body.run.id);
    await importRunUntilDone(body.run.id);
  } catch (e) {
    log('ERROR ' + e.message + ' — use Resume latest import after the run succeeds.');
  } finally {
    setEnrichProgress(false);
    busy(false);
  }
});

document.getElementById('btn-enrich-import').addEventListener('click', async () => {
  busy(true);
  try {
    await importRunUntilDone();
  } catch (e) {
    log('ERROR ' + e.message + ' — the cursor is saved, press Resume latest import again.');
  } finally {
    busy(false);
  }
});

document.getElementById('btn-enrich-stats').addEventListener('click', async () => {
  busy(true);
  try {
    const body = await call({ action: 'enrich-stats' });
    log(JSON.stringify(body.stats, null, 2));
    log('Top accounts by followers:\n' + body.sample.map(r =>
      '  @' + r.username + '  ' + r.followers_count + ' followers, ' + r.posts_count + ' posts'
    ).join('\n'));
  } catch (e) {
    log('ERROR ' + e.message);
  } finally {
    busy(false);
  }
});

document.getElementById('btn-stats').addEventListener('click', async () => {
  busy(true);
  try {
    const body = await call({ action: 'stats' });
    log(JSON.stringify(body.stats, null, 2));
  } catch (e) {
    log('ERROR ' + e.message);
  } finally {
    busy(false);
  }
});

document.getElementById('btn-abort').addEventListener('click', async () => {
  if (!confirm('Abort the latest Apify run?')) { return; }
  busy(true);
  try {
    const body = await call({ action: 'abort' });
    log('Aborted. Status is now ' + body.run.status + '.');
  } catch (e) {
    log('ERROR ' + e.message);
  } finally {
    busy(false);
  }
});
</script>
</body>
</html>
