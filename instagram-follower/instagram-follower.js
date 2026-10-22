#!/usr/bin/env node
/**
 * Instagram warm-up and follow automation for @verifiedbotanicals.
 *
 * Follows accounts from the MySQL queue (sunny_kratom.ig_accounts) on a 3 to 5
 * minute cooldown, and fills the gaps with ordinary browsing so the account is
 * not making follow requests against a background of no other activity.
 *
 * WHY CDP AND NOT A DEDICATED PLAYWRIGHT PROFILE
 *   Meta CAPTCHA-loops fresh automated profiles. This attaches to a Chrome that
 *   is already signed in, the same approach the-record-scheduler uses, against
 *   the same C:\chrome-automation-profile on port 9222. Do not run both at once:
 *   they share the port and will fight over tabs.
 *
 * SELECTORS
 *   Every locator here was verified live on 2026-09-15. See
 *   instagram-selectors-notes.md. Three traps are handled explicitly:
 *     1. "Following" contains "Follow", so the button text is compared exactly.
 *     2. Profile grid links are /<user>/p/<code>/, not /p/<code>/.
 *     3. A post opened by click is a [role="dialog"]; one opened by URL is not.
 *
 * WHERE IT RUNS
 *   On Ildar's Windows PC only, from PowerShell. This folder is part of the
 *   ig-follower-scraper repository, which is deployed to the VPS, so a copy of
 *   this script exists there too. assertRunningOnPc() refuses to start on the
 *   VPS, on any other non-Windows machine, and from WSL.
 *
 * DATABASE
 *   The queue is read from and written to the sunny_kratom database on
 *   seo.bizousoft.com, and nowhere else. The script opens its own SSH tunnel to
 *   the VPS and connects through it. There is no local fallback: the old copy
 *   of the queue in Laragon on this PC is never used, and the run refuses to
 *   start if the MySQL server it reaches turns out to be this PC.
 *
 * USAGE (PowerShell)
 *   node instagram-follower.js --check-db
 *   node instagram-follower.js --dry-run
 *   node instagram-follower.js --follows 5
 *   node instagram-follower.js
 */

'use strict';

const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');
const http = require('http');
const net = require('net');
const os = require('os');
const path = require('path');
const mysql = require('mysql2/promise');

try { require('dotenv').config({ path: path.join(__dirname, '.env') }); } catch (_) { /* optional */ }

// ------------------------------------------------------------------ config

const HERE = __dirname;
const SHOTS_DIR = path.join(HERE, 'run-screenshots');
// IG_FOLLOWER_RUN_LOG exists only so the test suite can point the log elsewhere;
// without it, tests that start the script would write into the real run log.
const RUN_LOG = process.env.IG_FOLLOWER_RUN_LOG || path.join(HERE, 'run-log.jsonl');
// Writes to ig_accounts that could not be completed. See recordAttempt().
const JOURNAL = path.join(HERE, 'pending-writes.jsonl');

const IG_URL = 'https://www.instagram.com/';
const ACCOUNT = 'verifiedbotanicals';
const TZ = 'America/New_York';

const DEFAULT_CDP = 'http://localhost:9222';
// Chrome 136+ refuses --remote-debugging-port on the default profile dir.
const DEFAULT_CHROME_PROFILE = 'C:\\chrome-automation-profile';

// Follow pacing. The cooldown is measured from the attempt, not its outcome.
const COOLDOWN_MIN_S = 180;
const COOLDOWN_MAX_S = 300;

// Filler activity budgets, per hour. Overridden by --likes-per-hour,
// --stories-per-hour and --reads-per-hour (N or N-M, 0 to 60).
const LIKES_PER_HOUR = [5, 10];
const STORIES_PER_HOUR = [5, 15];
const READS_PER_HOUR = [3, 6];
// One story session opens a ring in the home tray and watches this many story
// frames before Escape. --stories-per-session N or N-M, 1 to 20.
const STORIES_PER_SESSION = [1, 3];
// Seconds between scripted advances inside a story session. Without
// --story-seconds the first frame gets 2.5 to 5 s and the rest 2.5 to 6 s.
// Instagram also advances by itself, so this is time between ArrowRight
// presses, not a guaranteed time on one frame. --story-seconds N or N-M, 1 to 60.
const HOURLY_BOUNDS = { min: 0, max: 60 };

// Profile likes (added 2026-10-15, see profile-likes-progress.md): open a
// random account we follow and like one of its recent posts or reels.
const PROFILE_LIKES_PER_HOUR = [1, 2];        // --profile-likes-per-hour N or N-M
const PROFILE_LIKE_MIN_GAP_MS = 20 * 60 * 1000; // between two profile visits
const PROFILE_LIKES_PER_RUN_MAX = 15;
const PROFILE_REVISIT_DAYS = 14;              // also the recheck interval for pending requests
const PROFILE_RECENT_TILES = 6;               // pick among the N most recent grid tiles
const PROFILE_TRIES = 2;                      // posts tried per visit before moving on
const TOTAL_LIKES_PER_HOUR_MAX = 10;          // feed and profile likes combined

// ---------------------------------------------------------------- database
//
// The queue lives ONLY in the sunny_kratom database on seo.bizousoft.com.
// There is deliberately no local fallback. A copy of sunny_kratom still exists
// in Laragon on this PC. It stopped receiving follow results when the queue
// moved to the VPS, so following from it would re-follow accounts the VPS
// already records as followed.
//
// The script opens its own SSH tunnel to the VPS (see ensureTunnel) and
// connects through it, so the database host and port are not configurable:
// MySQL is always reached at 127.0.0.1:<DB_TUNNEL_LOCAL_PORT>, the local end of
// that tunnel. DB_HOST and DB_PORT in .env are ignored. Every new connection
// pool is then checked against the server it actually reached (see
// assertRemoteServer), and the run refuses to continue if that server is this
// PC. The tunnel also encrypts the traffic, so MySQL never has to be exposed to
// the internet.
//
// Settings, all from .env:
//   DB_NAME, DB_USER, DB_PASS   Required. Copy them from the 'db' block of the
//                               VPS copy of ig-follower-scraper/config.php.
//   DB_SSH_HOST                 Default 66.206.23.226, the IP of seo.bizousoft.com.
//                               The IP is used so the tunnel does not depend on DNS.
//   DB_SSH_USER                 Default bizousoft. root login is refused on the VPS.
//   DB_SSH_PORT                 Default 22.
//   DB_SSH_KEY                  Optional private key file, passed as ssh -i.
//   DB_TUNNEL_LOCAL_PORT        Default 3307. 3306 is refused, because that is
//                               the port Laragon's MySQL listens on.
//   DB_REMOTE_MYSQL_PORT        Default 3306, MySQL's port on the VPS.
//   DB_EXPECT_SERVER_HOSTNAME   Optional. When set, the MySQL server's
//                               @@hostname must equal it exactly.
//   SSH_PATH                    Optional. Default ssh, which on Windows is
//                               C:\Windows\System32\OpenSSH\ssh.exe.

const SSH = {
  host: process.env.DB_SSH_HOST || '66.206.23.226',
  user: process.env.DB_SSH_USER || 'bizousoft',
  port: parseInt(process.env.DB_SSH_PORT || '22', 10),
  key: process.env.DB_SSH_KEY || '',
  exe: process.env.SSH_PATH || 'ssh',
};
const TUNNEL_LOCAL_PORT = parseInt(process.env.DB_TUNNEL_LOCAL_PORT || '3307', 10);
const REMOTE_MYSQL_PORT = parseInt(process.env.DB_REMOTE_MYSQL_PORT || '3306', 10);
const EXPECT_SERVER_HOSTNAME = process.env.DB_EXPECT_SERVER_HOSTNAME || '';
const LARAGON_MYSQL_PORT = 3306;
// How long to wait for the tunnel to come up. ssh runs with BatchMode=yes and
// key login only (see ensureTunnel), so there is no prompt to wait for.
const TUNNEL_WAIT_MS = 30000;

const DB = {
  host: '127.0.0.1',
  port: TUNNEL_LOCAL_PORT,
  user: process.env.DB_USER || '',
  password: process.env.DB_PASS || '',
  database: process.env.DB_NAME || '',
};

// ------------------------------------------------------------------- args

const argv = process.argv.slice(2);
const has = (f) => argv.includes(f);
const val = (f, d) => {
  const i = argv.indexOf(f);
  return i >= 0 && argv[i + 1] ? argv[i + 1] : d;
};

const LOGIN = has('--login');
const DRY_RUN = has('--dry-run');
const HEADLESS = has('--headless');
const PAUSE_ON_FAIL = has('--pause-on-fail');
const NO_LAUNCH = has('--no-launch');
const REPLAY_JOURNAL = has('--replay-journal');
const CHECK_DB = has('--check-db');
const FOLLOW_TARGET = parseInt(val('--follows', '100'), 10);
const MIN_POSTS = parseInt(val('--min-posts', '0'), 10);
// Default 50. An account with fewer followers is usually a shell, an abandoned
// account, or someone who will never see the follow. Requires enrichment data,
// so this restricts the queue to accounts present in ig_profile_stats.
const MIN_FOLLOWERS = parseInt(val('--min-followers', '50'), 10);
// Browse for a bit before the first follow. Without this the first thing a run
// does is open a profile and click Follow, which is the least human moment of
// the whole session and defeats the point of the filler actions.
const NO_WARMUP = has('--no-warmup');
const NO_PROFILE_LIKES = has('--no-profile-likes');
// Test mode: one profile visit (and like, unless --dry-run), then stop. No follows.
const PROFILE_LIKE_ONCE = has('--profile-like-once');
const PROFILE_LIKES_RANGE = parseRange(val('--profile-likes-per-hour', ''), PROFILE_LIKES_PER_HOUR, '--profile-likes-per-hour');
// Filler budgets and story pacing (2026-10-21). The likes range still sits
// under TOTAL_LIKES_PER_HOUR_MAX, feed and profile likes together.
const LIKES_RANGE = parseRange(val('--likes-per-hour', ''), LIKES_PER_HOUR, '--likes-per-hour', HOURLY_BOUNDS);
const STORIES_RANGE = parseRange(val('--stories-per-hour', ''), STORIES_PER_HOUR, '--stories-per-hour', HOURLY_BOUNDS);
const READS_RANGE = parseRange(val('--reads-per-hour', ''), READS_PER_HOUR, '--reads-per-hour', HOURLY_BOUNDS);
const STORY_SESSION_RANGE = parseRange(val('--stories-per-session', ''), STORIES_PER_SESSION, '--stories-per-session', { min: 1, max: 20 });
// null = the built-in pacing described above.
const STORY_SECONDS_RANGE = has('--story-seconds')
  ? parseRange(val('--story-seconds', ''), null, '--story-seconds', { min: 1, max: 60 })
  : null;
const MAX_MINUTES = parseInt(val('--max-minutes', '0'), 10);
const ONLY = val('--only', '');
const CDP_URL = val('--cdp', DEFAULT_CDP);
const CHROME_PROFILE = val('--chrome-profile', DEFAULT_CHROME_PROFILE);
const COOLDOWN_MIN = parseInt(val('--cooldown-min', String(COOLDOWN_MIN_S)), 10);
const COOLDOWN_MAX = parseInt(val('--cooldown-max', String(COOLDOWN_MAX_S)), 10);

// --------------------------------------------------------------- utilities

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const rand = (a, b) => a + Math.random() * (b - a);
const randInt = (a, b) => Math.floor(rand(a, b + 1));
const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];

/** MySQL DATETIME string in America/New_York. sv-SE formats as YYYY-MM-DD HH:MM:SS. */
function nyNow() {
  return new Date().toLocaleString('sv-SE', { timeZone: TZ }).replace('T', ' ').slice(0, 19);
}

/** Like nyNow(), for any moment given in epoch milliseconds. */
function nyAt(ms) {
  return new Date(ms).toLocaleString('sv-SE', { timeZone: TZ }).replace('T', ' ').slice(0, 19);
}

function stamp() {
  return new Date().toLocaleTimeString('en-GB', { timeZone: TZ });
}

function log(tag, msg) {
  console.log(`[${stamp()}] ${String(tag).padEnd(6)} ${msg}`);
}

function fail(msg) {
  console.error(`\nERROR: ${msg}\n`);
  process.exit(1);
}

function jlog(entry) {
  try {
    fs.appendFileSync(RUN_LOG, JSON.stringify({ at: nyNow(), ...entry }) + '\n');
  } catch (_) { /* logging must never break the run */ }
}

async function shot(page, name) {
  try {
    if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });
    const file = path.join(SHOTS_DIR, `${Date.now()}-${name}.png`);
    await page.screenshot({ path: file });
    return file;
  } catch (_) { return null; }
}

// ---------------------------------------------------------------- database

let pool = null;

let dbSettingsChecked = false;

/**
 * Refuse to start with settings that cannot reach the VPS, before any
 * connection is attempted.
 */
function checkDbSettings() {
  if (dbSettingsChecked) return;

  const missing = ['DB_NAME', 'DB_USER', 'DB_PASS'].filter((k) => !process.env[k]);
  if (missing.length) {
    fail(`${missing.join(', ')} ${missing.length === 1 ? 'is' : 'are'} not set in ${path.join(HERE, '.env')}.\n` +
         `Copy the name, user and pass values from the 'db' block of the VPS copy of config.php:\n` +
         `  ssh ${SSH.user}@${SSH.host} "grep -A6 'db' /home/bizousoft/public_html/seo/ig-follower-scraper/config.php"`);
  }

  if (TUNNEL_LOCAL_PORT === LARAGON_MYSQL_PORT) {
    fail(`DB_TUNNEL_LOCAL_PORT is ${LARAGON_MYSQL_PORT}, which is Laragon's MySQL port. ` +
         `Use 3307 or any other free port.`);
  }

  for (const k of ['DB_HOST', 'DB_PORT', 'DB_SSL', 'DB_SSL_CA', 'DB_SSL_REJECT_UNAUTHORIZED']) {
    if (process.env[k] !== undefined && process.env[k] !== '') {
      log('WARN', `${k} is set in .env but ignored. The database is always reached through ` +
                  `the SSH tunnel at 127.0.0.1:${TUNNEL_LOCAL_PORT}.`);
    }
  }

  dbSettingsChecked = true;
}

// ------------------------------------------------------------- SSH tunnel

let tunnel = null;

/** True when something accepts a TCP connection on 127.0.0.1:<port>. */
function portAnswers(port) {
  return new Promise((resolve) => {
    const sock = net.connect({ host: '127.0.0.1', port });
    const done = (ok) => { sock.destroy(); resolve(ok); };
    sock.setTimeout(2000, () => done(false));
    sock.once('connect', () => done(true));
    sock.once('error', () => done(false));
  });
}

function tunnelError(message) {
  const e = new Error(message);
  // Listed in RETRYABLE_DB_ERRORS, so a tunnel that drops mid-run is reopened
  // by withDbRetry rather than ending the run.
  e.code = 'TUNNEL_FAILED';
  return e;
}

/**
 * Make sure 127.0.0.1:<DB_TUNNEL_LOCAL_PORT> forwards to MySQL on the VPS.
 *
 * If the port already answers, it is used as it is. That covers a tunnel
 * opened by hand in another window. Whatever is behind it still has to pass
 * assertRemoteServer before a single query runs, so an unexpected listener on
 * that port cannot quietly stand in for the VPS.
 *
 * Otherwise ssh is started with ExitOnForwardFailure, so it exits instead of
 * staying connected without a forward.
 *
 * ssh runs DETACHED, in its own hidden console, with BatchMode=yes (key login
 * only, never a prompt). Changed 2026-10-14. Until then ssh shared this
 * PowerShell window, and two runs that day ended silently with no error and no
 * summary: one at 13:37 mid-run, one at 14:51 while waiting for the tunnel.
 * A console shares Ctrl+C and close events between every process attached to
 * it, so ssh sharing the window is the most likely cause. Detached, nothing ssh
 * does can reach this process through the console. Its stderr is captured and
 * shown if it fails, and the moment it ends is logged whenever that happens.
 */
async function ensureTunnel() {
  if (tunnel && tunnel.exitCode === null && tunnel.signalCode === null) {
    if (await portAnswers(TUNNEL_LOCAL_PORT)) return;
    log('DB', 'SSH tunnel process is running but not forwarding. Restarting it.');
    closeTunnel();
  }

  if (await portAnswers(TUNNEL_LOCAL_PORT)) {
    if (!tunnel) log('DB', `port ${TUNNEL_LOCAL_PORT} is already listening, using it. The server behind it is checked next.`);
    return;
  }

  const args = [
    '-N',
    '-o', 'BatchMode=yes',
    '-o', 'ExitOnForwardFailure=yes',
    '-o', 'ServerAliveInterval=30',
    '-o', 'ServerAliveCountMax=3',
    '-p', String(SSH.port),
    '-L', `127.0.0.1:${TUNNEL_LOCAL_PORT}:127.0.0.1:${REMOTE_MYSQL_PORT}`,
  ];
  if (SSH.key) args.push('-i', SSH.key);
  args.push(`${SSH.user}@${SSH.host}`);

  log('DB', `opening SSH tunnel 127.0.0.1:${TUNNEL_LOCAL_PORT} -> ${SSH.host}:${REMOTE_MYSQL_PORT} as ${SSH.user}`);
  const child = spawn(SSH.exe, args, {
    stdio: ['ignore', 'ignore', 'pipe'],
    detached: true,
    windowsHide: true,
  });
  let spawnError = null;
  let sshStderr = '';
  child.on('error', (e) => { spawnError = e; });
  if (child.stderr) child.stderr.on('data', (d) => { sshStderr = (sshStderr + d.toString()).slice(-2000); });
  child.on('exit', (code, signal) => {
    log('DB', `ssh tunnel process ended (exit code ${code}, signal ${signal || 'none'})` +
              (sshStderr.trim() ? `: ${sshStderr.trim().split('\n').slice(-3).join(' | ')}` : ''));
    jlog({ action: 'tunnel-exit', code, signal: signal || null, stderr: sshStderr.trim().slice(-500) || null });
  });
  tunnel = child;

  const deadline = Date.now() + TUNNEL_WAIT_MS;
  while (Date.now() < deadline) {
    if (spawnError) {
      tunnel = null;
      throw tunnelError(`could not start ${SSH.exe}: ${spawnError.message}`);
    }
    if (child.exitCode !== null || child.signalCode !== null) {
      tunnel = null;
      throw tunnelError(`ssh exited (code ${child.exitCode}) before the tunnel was up` +
                        (sshStderr.trim() ? `. ssh said: ${sshStderr.trim()}` : ''));
    }
    if (await portAnswers(TUNNEL_LOCAL_PORT)) {
      log('DB', 'SSH tunnel is up');
      jlog({ action: 'tunnel-up', ssh: `${SSH.user}@${SSH.host}`, localPort: TUNNEL_LOCAL_PORT });
      return;
    }
    await sleep(500);
  }

  closeTunnel();
  throw tunnelError(`the SSH tunnel did not come up within ${TUNNEL_WAIT_MS / 1000} seconds`);
}

function closeTunnel() {
  if (tunnel && tunnel.exitCode === null && tunnel.signalCode === null) {
    try { tunnel.kill(); } catch (_) { /* already gone */ }
  }
  tunnel = null;
}

// fail() and every normal exit go through process.exit, so this closes the
// tunnel on all of them. ssh runs detached, so if this process is killed
// outright the tunnel can outlive it; the next run finds the port answering
// and reuses it, after the server identity check.
process.on('exit', closeTunnel);

// ------------------------------------------------------ server identity guard

let serverInfo = null;

/**
 * Refuse any MySQL server that is not the VPS.
 *
 * Four independent signals, any one of which is enough to refuse:
 *   1. @@version_compile_os starts with "Win". The VPS runs Linux, and
 *      Laragon's MySQL is a Windows build.
 *   2. @@datadir is a Windows drive path or contains "laragon".
 *   3. @@hostname equals this PC's hostname.
 *   4. DB_EXPECT_SERVER_HOSTNAME is set and @@hostname differs from it.
 *
 * The error code LOCAL_DB_REFUSED is not retryable, so the run stops at once.
 */
async function assertRemoteServer(p) {
  const [rows] = await p.query(
    `SELECT @@hostname AS hostname, @@version_compile_os AS compile_os,
            @@datadir AS datadir, DATABASE() AS db, VERSION() AS version`
  );
  const s = rows[0];
  const host = String(s.hostname || '');
  const compileOs = String(s.compile_os || '');
  const datadir = String(s.datadir || '');
  const reasons = [];

  if (/^win/i.test(compileOs)) {
    reasons.push(`it is a Windows build of MySQL (${compileOs}), and the VPS runs Linux`);
  }
  if (/^[a-z]:[\\/]/i.test(datadir) || /laragon/i.test(datadir)) {
    reasons.push(`its data directory is ${datadir}`);
  }
  if (host.toLowerCase() === os.hostname().toLowerCase()) {
    reasons.push(`its hostname, ${host}, is this PC's hostname`);
  }
  if (EXPECT_SERVER_HOSTNAME && host !== EXPECT_SERVER_HOSTNAME) {
    reasons.push(`its hostname is ${host}, but DB_EXPECT_SERVER_HOSTNAME is ${EXPECT_SERVER_HOSTNAME}`);
  }

  if (reasons.length) {
    const e = new Error(`refusing to use the MySQL server on 127.0.0.1:${TUNNEL_LOCAL_PORT}, ` +
                        `because ${reasons.join('; and ')}. That is a local database, not seo.bizousoft.com (66.206.23.226).`);
    e.code = 'LOCAL_DB_REFUSED';
    throw e;
  }

  if (!serverInfo) {
    serverInfo = { hostname: host, compileOs, db: s.db, version: s.version };
    log('DB', `connected to MySQL ${s.version} on ${host} (${compileOs}), database ${s.db}`);
    jlog({ action: 'db-server', ...serverInfo });
  }
  return s;
}

async function db() {
  if (!pool) {
    checkDbSettings();
    await ensureTunnel();
    const p = mysql.createPool({
      ...DB,
      waitForConnections: true,
      connectionLimit: 4,
      connectTimeout: 15000,
      // The script queries roughly every four minutes, which is usually often
      // enough to keep the connection warm by itself. The keepalive removes the
      // dependence on that timing, so a longer cooldown or a slow run cannot
      // leave the connection idle long enough for a NAT table or MySQL's
      // wait_timeout to drop it.
      enableKeepAlive: true,
      keepAliveInitialDelay: 10000,
    });
    try {
      await assertRemoteServer(p);
    } catch (e) {
      await p.end().catch(() => {});
      throw e;
    }
    pool = p;
  }
  return pool;
}

/** One message for every way the database can fail to come up at startup. */
function dbFailMessage(e) {
  const code = e && e.code;
  let hint;
  if (code === 'LOCAL_DB_REFUSED') {
    hint = `Something on this PC is listening on port ${TUNNEL_LOCAL_PORT}. Close it, or set ` +
           `DB_TUNNEL_LOCAL_PORT in .env to a free port, and rerun.`;
  } else if (code === 'TUNNEL_FAILED') {
    hint = `Check that this prints key-login-ok without asking for a password, in PowerShell:\n` +
           `  ssh -p ${SSH.port} ${SSH.user}@${SSH.host} "echo key-login-ok"\n` +
           `The script logs in with the SSH key only (BatchMode), so a password prompt means the key ` +
           `is not accepted. Set DB_SSH_KEY if the key is not C:\\Users\\ildar\\.ssh\\id_ed25519.`;
  } else if (code === 'ER_ACCESS_DENIED_ERROR') {
    hint = `DB_USER and DB_PASS in .env do not match the VPS. Copy them from the 'db' block of ` +
           `/home/bizousoft/public_html/seo/ig-follower-scraper/config.php on the VPS.`;
  } else if (code === 'ER_BAD_DB_ERROR') {
    hint = `DB_NAME in .env does not exist on the VPS. Copy it from the same config.php.`;
  } else {
    hint = `The tunnel reached the VPS but MySQL did not answer on its port ` +
           `${REMOTE_MYSQL_PORT}. Check DB_REMOTE_MYSQL_PORT.`;
  }
  return `cannot use the remote database ${DB.database} on ${SSH.host}: ${e.code || ''} ${e.message}\n${hint}`;
}

/**
 * Errors that mean the connection broke, rather than that the query was wrong.
 *
 * Only these are retried. A SQL syntax error or a constraint violation fails
 * identically on every attempt, so retrying one wastes three attempts and hides
 * the real bug behind a slower failure.
 */
const RETRYABLE_DB_ERRORS = new Set([
  'PROTOCOL_CONNECTION_LOST',
  'ECONNRESET',
  'ETIMEDOUT',
  'EPIPE',
  'ECONNREFUSED',
  'EHOSTUNREACH',
  'ENETUNREACH',
  'PROTOCOL_SEQUENCE_TIMEOUT',
  'ER_CON_COUNT_ERROR',
  // Raised by ensureTunnel. Retrying reopens the SSH tunnel.
  'TUNNEL_FAILED',
]);

function isRetryableDbError(e) {
  return RETRYABLE_DB_ERRORS.has(e && (e.code || e.errno));
}

const DB_ATTEMPTS = 3;
const DB_BACKOFF_MS = [1000, 4000];

/**
 * Run a database operation, retrying it if the connection drops.
 *
 * With the database on the PC, any of these failing meant something was
 * seriously wrong. With the database across the internet, a transient network
 * drop, a NAT timeout or a MySQL wait_timeout can make any of them throw. That
 * exception reaches the main loop's catch, which sets stopReason and ends the
 * run, so one network blip would kill a run that is meant to last six to eight
 * hours.
 *
 * On a retryable failure the pool is discarded as well as the attempt, because
 * a pool holding dead connections hands the same dead connection to the next
 * attempt.
 */
async function withDbRetry(label, fn) {
  let lastError = null;

  for (let attempt = 1; attempt <= DB_ATTEMPTS; attempt++) {
    try {
      return await fn();
    } catch (e) {
      lastError = e;

      if (!isRetryableDbError(e)) {
        throw e;
      }

      log('DB', `${label} failed on attempt ${attempt} of ${DB_ATTEMPTS}: ${e.code || e.message}`);
      jlog({ action: 'db-retry', label, attempt, code: e.code || null, message: e.message });

      if (pool) {
        const dead = pool;
        pool = null;
        await dead.end().catch(() => {});
      }

      if (attempt < DB_ATTEMPTS) {
        await sleep(DB_BACKOFF_MS[attempt - 1] || 4000);
      }
    }
  }

  throw lastError;
}

/**
 * Next account to follow.
 *
 * With --min-followers above 0 (the default is 50) this is an INNER JOIN, so
 * only enriched accounts are eligible: an unenriched account has no follower
 * count to test against. With --min-followers 0 it falls back to a LEFT JOIN so
 * unenriched accounts are followable again.
 *
 * Private accounts are deliberately NOT filtered. Decided 2026-09-17: they are
 * followed like anyone else and record 'requested'.
 *
 * --only bypasses both filters, so a named account can always be tested.
 *
 * Accounts marked VALID on the web app's review screen (review_decision =
 * 'valid') go first, then the rest of the queue; within each group the account
 * with the most followers goes first (2026-10-20; before that, lowest id).
 * VALID only reorders: it does not bypass the filters. While VALID accounts are
 * waiting this script stays off the card the reviewer is on.
 */
// A dry run writes nothing back, so without this every pick would return the
// same first pending row. If that account already shows "Following", no
// cooldown is spent and the loop would reload the same profile again at once.
// The dry run therefore remembers what it has opened and excludes it.
const dryRunSeen = new Set();

/** SQL fragment and params that exclude accounts this dry run already opened. */
function dryRunExclusion() {
  if (!DRY_RUN || dryRunSeen.size === 0) return { sql: '', params: [] };
  return { sql: ' AND a.id NOT IN (?)', params: [[...dryRunSeen]] };
}

// Queue order: review-screen VALID accounts first, then everything else; within
// each group most followers first, accounts with no follower count last, then
// id. NULL and 'skip' review decisions both fall into the second group.
const QUEUE_ORDER = `CASE WHEN a.review_decision = 'valid' THEN 0 ELSE 1 END,
          CASE WHEN s.followers_count IS NULL THEN 1 ELSE 0 END,
          s.followers_count DESC, a.id`;

async function nextTargetOnce() {
  const conn = await db();
  const ex = dryRunExclusion();

  if (ONLY) {
    const names = ONLY.split(',').map((s) => s.trim()).filter(Boolean);
    if (!names.length) return null;
    const [rows] = await conn.query(
      `SELECT a.id, a.ig_user_id, a.username, a.is_private
         FROM ig_accounts a
        WHERE a.username IN (?) AND a.follow_status = 'pending'${ex.sql}
        ORDER BY a.id LIMIT 1`,
      [names, ...ex.params]
    );
    return rows[0] || null;
  }

  if (MIN_FOLLOWERS > 0) {
    const [rows] = await conn.query(
      `SELECT a.id, a.ig_user_id, a.username, a.is_private, a.review_decision,
              s.posts_count, s.followers_count
         FROM ig_accounts a
         JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
        WHERE a.follow_status = 'pending'
          AND s.followers_count >= ?
          AND s.posts_count >= ?${ex.sql}
        ORDER BY ${QUEUE_ORDER}
        LIMIT 1`,
      [MIN_FOLLOWERS, MIN_POSTS, ...ex.params]
    );
    return rows[0] || null;
  }

  const [rows] = await conn.query(
    `SELECT a.id, a.ig_user_id, a.username, a.is_private, a.review_decision,
            s.posts_count, s.followers_count
       FROM ig_accounts a
       LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
      WHERE a.follow_status = 'pending'
        AND (s.id IS NULL OR s.posts_count >= ?)${ex.sql}
      ORDER BY ${QUEUE_ORDER}
      LIMIT 1`,
    [MIN_POSTS, ...ex.params]
  );
  return rows[0] || null;
}

/** How many accounts currently satisfy the filters, for the startup banner. */
async function eligibleCountOnce() {
  const conn = await db();
  if (MIN_FOLLOWERS > 0) {
    const [rows] = await conn.query(
      `SELECT COUNT(*) AS n
         FROM ig_accounts a
         JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
        WHERE a.follow_status = 'pending'
          AND s.followers_count >= ?
          AND s.posts_count >= ?`,
      [MIN_FOLLOWERS, MIN_POSTS]
    );
    return Number(rows[0].n);
  }
  const [rows] = await conn.query(
    `SELECT COUNT(*) AS n
       FROM ig_accounts a
       LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
      WHERE a.follow_status = 'pending'
        AND (s.id IS NULL OR s.posts_count >= ?)`,
    [MIN_POSTS]
  );
  return Number(rows[0].n);
}

// Only a row that is still pending is written. Someone on review.php (or the
// accounts browser) may have marked it skipped while this script had the
// profile open, and that decision must not be overwritten. Returns the number
// of rows changed, so the caller can warn when the write was refused.
async function recordAttemptOnce(id, status, note) {
  const conn = await db();
  const [result] = await conn.execute(
    `UPDATE ig_accounts
        SET follow_status = ?, follow_attempted_at = ?, follow_note = ?
      WHERE id = ? AND follow_status = 'pending'`,
    [status, nyNow(), note || null, id]
  );
  return result.affectedRows;
}

/**
 * Is the account still pending? Asked immediately before clicking Follow,
 * because the target was picked several seconds earlier and a reviewer may have
 * marked it skipped on review.php in the meantime.
 */
async function stillPendingOnce(id) {
  const conn = await db();
  const [rows] = await conn.query(
    'SELECT follow_status, follow_note FROM ig_accounts WHERE id = ?',
    [id]
  );
  if (!rows.length) return { pending: false, status: 'gone', note: null };
  return {
    pending: rows[0].follow_status === 'pending',
    status: rows[0].follow_status,
    note: rows[0].follow_note,
  };
}

async function queueCountsOnce() {
  const conn = await db();
  const [rows] = await conn.query(
    `SELECT follow_status, COUNT(*) AS n FROM ig_accounts GROUP BY follow_status`
  );
  return rows.reduce((acc, r) => { acc[r.follow_status] = Number(r.n); return acc; }, {});
}

// ------------------------------------------------- retrying database wrappers
//
// Five functions in this script talk to the database: nextTarget, which pulls
// the next account to follow; eligibleCount, which reports how many accounts
// pass the current filters for the startup banner; recordAttempt, which writes
// the outcome of a follow back to ig_accounts; stillPending, which re-reads the
// target's status just before the Follow click; and queueCounts, which reports
// the queue totals. Each one below wraps the corresponding *Once function above
// in the retry helper, so every existing call site gets the retry without
// changing.

async function nextTarget() {
  try {
    return await withDbRetry('nextTarget', () => nextTargetOnce());
  } catch (e) {
    // The queue orders by review_decision, a column the web app adds. Say how
    // to fix a database that does not have it yet, instead of a bare SQL error.
    if (e && e.code === 'ER_BAD_FIELD_ERROR' && /review_decision/.test(e.message || '')) {
      e.message = 'ig_accounts has no review_decision column yet. Open ?action=migrate in the web app ' +
        'once (or the accounts browser), then start the run again. ' + e.message;
    }
    throw e;
  }
}

async function eligibleCount() {
  return withDbRetry('eligibleCount', () => eligibleCountOnce());
}

async function queueCounts() {
  return withDbRetry('queueCounts', () => queueCountsOnce());
}

async function stillPending(id) {
  return withDbRetry('stillPending', () => stillPendingOnce(id));
}

/**
 * Write the outcome of a follow attempt, and never lose it.
 *
 * This one write is different from the three reads around it. A failed read can
 * be repeated later at no cost. A failed write cannot, because by the time it
 * runs the Follow button has already been clicked: Instagram has recorded the
 * follow and ig_accounts still says pending. The next run then selects the same
 * account and follows it again, which is exactly the repetitive pattern the
 * pacing in this script exists to avoid.
 *
 * So when all three attempts fail, the intended update is appended to
 * pending-writes.jsonl and the run carries on. Replay it with:
 *
 *     node instagram-follower.js --replay-journal
 *
 * The failure is printed, written to the run log, and counted in the summary,
 * because a journal nobody reads is the same as no journal.
 */
let journalledWrites = 0;

async function recordAttempt(id, status, note) {
  if (DRY_RUN) {
    log('DB', `dry run, not writing: id=${id} status=${status}${note ? ' note=' + note : ''}`);
    return;
  }

  try {
    const changed = await withDbRetry('recordAttempt', () => recordAttemptOnce(id, status, note));
    if (changed === 0) {
      // The row stopped being pending between the pick and this write, almost
      // always because a reviewer skipped it. Its status is left as they set
      // it. If this was a real follow, Instagram has it and the database says
      // skipped, so it is printed loudly.
      log('WARN', `id=${id} is no longer pending, so ${status} was not recorded. ` +
        `Someone changed it while this profile was open (review screen or accounts browser).`);
      jlog({ action: 'record-refused', id, status, note: note || null });
    }
    return changed;
  } catch (e) {
    const entry = {
      at: nyNow(),
      id,
      status,
      note: note || null,
      error: e.code || e.message,
    };

    try {
      fs.appendFileSync(JOURNAL, JSON.stringify(entry) + '\n');
      journalledWrites++;
      console.error('');
      console.error(`  !! COULD NOT RECORD THE FOLLOW OF ACCOUNT id=${id} AS ${status}.`);
      console.error(`  !! Instagram has it, the database does not.`);
      console.error(`  !! Written to ${JOURNAL}. Replay with --replay-journal.`);
      console.error('');
      jlog({ action: 'journalled-write', ...entry });
    } catch (writeError) {
      // The journal is the last line of defence. If even that fails there is
      // nowhere left to put this, so it is printed as loudly as possible and
      // the run stops rather than continuing to follow accounts it cannot
      // record.
      console.error('');
      console.error(`  !! CANNOT WRITE THE JOURNAL: ${writeError.message}`);
      console.error(`  !! Lost update: id=${id} status=${status} note=${note || ''}`);
      console.error('');
      throw e;
    }
  }
}

/**
 * Replay pending-writes.jsonl into ig_accounts, then clear it.
 *
 * Each line is applied only if the row is still pending, for the same reason
 * the accounts browser refuses to bulk-tag a non-pending row: a status written
 * since the journal entry was made records something that actually happened and
 * must not be overwritten by a stale entry. Lines that fail to apply are kept
 * in the file so nothing is lost.
 */
async function replayJournal() {
  if (!fs.existsSync(JOURNAL)) {
    console.log(`Nothing to replay. ${JOURNAL} does not exist.`);
    return;
  }

  const lines = fs.readFileSync(JOURNAL, 'utf8').split('\n').filter((l) => l.trim() !== '');

  if (lines.length === 0) {
    console.log('Nothing to replay. The journal is empty.');
    return;
  }

  console.log(`Replaying ${lines.length} journalled write(s) into ${DB.database}.`);

  const kept = [];
  let applied = 0;
  let skipped = 0;

  for (const line of lines) {
    let entry;
    try {
      entry = JSON.parse(line);
    } catch (e) {
      console.error(`  unparseable line kept: ${line}`);
      kept.push(line);
      continue;
    }

    try {
      const changed = await withDbRetry('replayJournal', async () => {
        const conn = await db();
        const [result] = await conn.execute(
          `UPDATE ig_accounts
              SET follow_status = ?, follow_attempted_at = ?, follow_note = ?
            WHERE id = ? AND follow_status = 'pending'`,
          [entry.status, entry.at || nyNow(), entry.note || null, entry.id]
        );
        return result.affectedRows;
      });

      if (changed === 1) {
        applied++;
        console.log(`  applied id=${entry.id} -> ${entry.status}`);
      } else {
        skipped++;
        console.log(`  skipped id=${entry.id}, no longer pending`);
      }
    } catch (e) {
      console.error(`  failed id=${entry.id}, kept in the journal: ${e.code || e.message}`);
      kept.push(line);
    }
  }

  if (kept.length === 0) {
    fs.unlinkSync(JOURNAL);
    console.log(`\n${applied} applied, ${skipped} skipped. Journal cleared.`);
  } else {
    fs.writeFileSync(JOURNAL, kept.join('\n') + '\n');
    console.log(`\n${applied} applied, ${skipped} skipped, ${kept.length} still in the journal.`);
  }
}

// ----------------------------------------------------------- action blocks

const BLOCK_RE = /Action Blocked|Try Again Later|We restrict certain activity|Please wait a few minutes/i;

async function isActionBlocked(page) {
  try {
    const text = await page.locator('body').innerText({ timeout: 5000 });
    return BLOCK_RE.test(text);
  } catch (_) { return false; }
}

class ActionBlocked extends Error {}

// ------------------------------------------------------------- IG actions

/** Ensure we are on the home feed. */
async function goHome(page) {
  if (!page.url().startsWith(IG_URL) || page.url() !== IG_URL) {
    await page.goto(IG_URL, { waitUntil: 'domcontentloaded' });
    await sleep(rand(1500, 3000));
  }
}

/**
 * Watch 1 to 3 stories from the tray.
 *
 * Tray items are ul > li > div[role="button"] containing a canvas. Once the
 * viewer has been opened they carry aria-label="Story by <user>, seen|not seen",
 * so unseen ones can be preferred without tracking state here.
 */
async function watchStories(page) {
  await goHome(page);

  const unseen = page.locator('li div[role="button"][aria-label*="not seen"]');
  const any = page.locator('ul li div[role="button"]:has(canvas)');

  let target = null;
  if (await unseen.count() > 0) {
    target = unseen.first();
  } else if (await any.count() > 0) {
    target = any.first();
  }
  if (!target) { log('STORY', 'no stories in the tray'); return { opened: false, watched: 0 }; }

  const who = (await target.innerText().catch(() => '')).split('\n')[0] || 'someone';
  // Time on a frame before the next scripted advance; see STORY_SECONDS_RANGE.
  const dwell = (first) => STORY_SECONDS_RANGE
    ? rand(STORY_SECONDS_RANGE[0] * 1000, STORY_SECONDS_RANGE[1] * 1000)
    : rand(2500, first ? 5000 : 6000);
  await target.click();
  await sleep(dwell(true));

  const watch = randInt(STORY_SESSION_RANGE[0], STORY_SESSION_RANGE[1]);
  for (let i = 1; i < watch; i++) {
    // Right arrow advances, into the next account's stories at the end of
    // one. Never click the story Like: that DMs the author.
    await page.keyboard.press('ArrowRight').catch(() => {});
    await sleep(dwell(false));
  }

  await page.keyboard.press('Escape').catch(() => {});
  await sleep(rand(1000, 2000));
  log('STORY', `watched ~${watch} starting with @${who}`);
  jlog({ action: 'story', user: who, count: watch });
  return { opened: true, watched: watch };
}

/** Scroll the home feed in uneven increments, sometimes back up. */
async function scrollFeed(page) {
  await goHome(page);
  const steps = randInt(2, 5);
  for (let i = 0; i < steps; i++) {
    const up = Math.random() < 0.15;
    const dy = up ? -randInt(200, 500) : randInt(500, 1200);
    await page.mouse.wheel(0, dy);
    await sleep(rand(800, 2500));
  }
  log('SCROLL', `${steps} steps`);
  jlog({ action: 'scroll', steps });
}

/**
 * Like one feed post that is not already liked.
 *
 * State lives on the svg, not the button: svg[aria-label="Like"] vs "Unlike".
 * Selecting on "Like" means an already-liked post yields zero matches, so this
 * can never toggle a like off.
 *
 * Skips ads (no /p/ or /reel/ permalink) and our own posts.
 */
async function likePost(page) {
  await goHome(page);

  const candidates = await page.evaluate((me) => {
    const out = [];
    for (const a of document.querySelectorAll('article')) {
      const perma = a.querySelector('a[href^="/p/"], a[href^="/reel/"]');
      if (!perma) continue;                      // sponsored: no permalink
      const author = a.querySelector('a[href^="/"]');
      const who = author ? author.getAttribute('href').split('?')[0] : '';
      if (who === `/${me}/`) continue;           // our own post
      const svg = a.querySelector('svg[aria-label="Like"]');
      if (!svg) continue;                        // already liked, or no button
      const r = svg.getBoundingClientRect();
      if (r.top < 0 || r.bottom > window.innerHeight) continue;  // off screen
      out.push({ href: perma.getAttribute('href'), who });
    }
    return out;
  }, ACCOUNT);

  if (!candidates.length) {
    await page.mouse.wheel(0, randInt(600, 1200));
    await sleep(rand(1200, 2500));
    log('LIKE', 'nothing likeable in view, scrolled instead');
    return false;
  }

  const target = pick(candidates);
  const sel = `article:has(a[href="${target.href}"]) div[role="button"]:has(svg[aria-label="Like"])`;

  if (DRY_RUN) {
    log('LIKE', `dry run, would like ${target.href} by ${target.who}`);
    return false;
  }

  await page.locator(sel).first().click();
  await sleep(rand(1200, 2500));

  const nowUnlike = await page.locator(
    `article:has(a[href="${target.href}"]) svg[aria-label="Unlike"]`
  ).count();

  if (nowUnlike > 0) {
    log('LIKE', `liked ${target.href} by ${target.who}`);
    jlog({ action: 'like', post: target.href, author: target.who, ok: true });
    // Best effort: a feed like also goes in ig_post_likes, so the profile-likes
    // action never opens a post the feed action already liked.
    const code = postCodeFromHref(target.href);
    if (code) await recordPostLike(code, String(target.who || '').replace(/\//g, ''), 'feed');
    return true;
  }
  log('LIKE', `click did not register on ${target.href}`);
  jlog({ action: 'like', post: target.href, ok: false });
  if (await isActionBlocked(page)) throw new ActionBlocked('blocked while liking');
  return false;
}

/**
 * Open a profile from the feed, open one of its posts, scroll the comments.
 * Dwell time only, nothing is stored.
 *
 * A post opened by clicking a grid tile is a [role="dialog"] whose comments sit
 * in a scrollable ul. Element.scrollBy did not move that list when tested, so
 * this uses a real wheel event over it.
 */
async function readComments(page) {
  await goHome(page);

  const authors = await page.evaluate((me) => {
    const set = new Set();
    for (const a of document.querySelectorAll('article')) {
      if (!a.querySelector('a[href^="/p/"], a[href^="/reel/"]')) continue;
      const author = a.querySelector('a[href^="/"]');
      if (!author) continue;
      const href = author.getAttribute('href').split('?')[0];
      if (href && href !== `/${me}/` && /^\/[^/]+\/$/.test(href)) set.add(href);
    }
    return [...set];
  }, ACCOUNT);

  if (!authors.length) { log('READ', 'no profiles in the feed'); return; }

  const who = pick(authors);
  await page.goto(`https://www.instagram.com${who}`, { waitUntil: 'domcontentloaded' });
  await sleep(rand(2000, 4000));

  // Profile grid links are /<user>/p/<code>/, NOT /p/<code>/. The grid lazy
  // loads, so nudge the page before counting.
  await page.mouse.wheel(0, randInt(400, 900));
  await sleep(rand(1500, 2500));

  const tiles = page.locator('a[href*="/p/"], a[href*="/reel/"]');
  const n = await tiles.count();
  if (!n) { log('READ', `no posts on ${who}`); await goHome(page); return; }

  await tiles.nth(randInt(0, Math.min(n, 6) - 1)).click();
  await sleep(rand(2000, 3500));

  const dialog = page.locator('[role="dialog"]').first();
  if (await dialog.count()) {
    const list = dialog.locator('ul').first();
    const box = await list.boundingBox().catch(() => null);
    if (box) {
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      for (let i = 0; i < randInt(2, 5); i++) {
        await page.mouse.wheel(0, randInt(200, 450));
        await sleep(rand(1200, 2800));
      }
    } else {
      await sleep(rand(3000, 6000));
    }
  } else {
    // Opened as a full page rather than a modal: scroll the window instead.
    for (let i = 0; i < randInt(2, 4); i++) {
      await page.mouse.wheel(0, randInt(400, 800));
      await sleep(rand(1200, 2500));
    }
  }

  await page.keyboard.press('Escape').catch(() => {});
  await sleep(rand(1000, 2000));
  await goHome(page);

  log('READ', `read comments on a post by ${who}`);
  jlog({ action: 'read', profile: who });
}

/**
 * The profile header's follow-state button, and its label.
 *
 * The header can hold more than one button[type="button"]. A profile with
 * several bio links shows a links button such as "site.com and 1 more", and it
 * comes BEFORE the Follow button (verified live on /augustinafinejewelry/,
 * 2026-10-14). Taking .first() therefore read the links button and recorded
 * those profiles as errors. This walks every header button and returns the one
 * whose whole text is a follow state.
 *
 * Labels are compared exactly, never as substrings: "Following" contains
 * "Follow", and clicking a Following button opens the unfollow menu.
 * "Follow Back" (the account already follows us) is normalised to "Follow",
 * since clicking it sends the same follow. That label is not yet verified live.
 *
 * Returns { btn, label } or { btn: null, label: null, seen: [texts] }.
 */
const FOLLOW_STATE_LABELS = new Set(['Follow', 'Following', 'Requested', 'Follow Back']);

async function findFollowButton(page) {
  const all = page.locator('header button[type="button"]');
  const n = await all.count();
  const seen = [];
  for (let i = 0; i < n; i++) {
    const b = all.nth(i);
    const raw = (await b.innerText().catch(() => '')).trim();
    seen.push(raw);
    const text = /^follow back$/i.test(raw) ? 'Follow Back' : raw;
    if (FOLLOW_STATE_LABELS.has(text)) {
      return { btn: b, label: text === 'Follow Back' ? 'Follow' : text };
    }
  }
  return { btn: null, label: null, seen };
}

/**
 * Wait for the profile page to settle, up to PROFILE_WAIT_MS.
 *
 * Until 2026-10-14 the script waited a fixed 2.5 to 4.5 seconds and then called
 * a page with no <header> a missing profile. A slow load looked the same, so
 * @westonerz was "profile not found" in the morning dry run and "already
 * following" that afternoon. Now it polls for whichever comes first:
 *
 *   ready    a header button whose text is a follow state (findFollowButton)
 *   missing  Instagram's "Sorry, this page isn't available." text
 *   timeout  neither within PROFILE_WAIT_MS; the caller records an error with
 *            the header buttons it did see, plus a screenshot
 *
 * The missing-page text is the one recorded in instagram-selectors-notes.md.
 * The apostrophe is matched with "." because Instagram may use ' or a curly one.
 */
const PROFILE_WAIT_MS = 15000;
const PROFILE_MISSING_RE = /Sorry, this page isn.t available/i;

async function waitForProfile(page) {
  const deadline = Date.now() + PROFILE_WAIT_MS;
  let found = { btn: null, label: null, seen: [] };
  while (Date.now() < deadline) {
    found = await findFollowButton(page);
    if (found.btn) return { state: 'ready', found };
    const missing = await page.getByText(PROFILE_MISSING_RE).count().catch(() => 0);
    if (missing) return { state: 'missing' };
    await sleep(500);
  }
  return { state: 'timeout', found };
}

/**
 * Follow the next queued account.
 *
 * The button is found by findFollowButton(), which picks the header button
 * whose text is exactly a follow state.
 *
 * Returns true if an attempt consumed the cooldown, false if it did not (an
 * already-followed account sends no request, so it should not cost a slot).
 */
async function followNext(page) {
  const target = await nextTarget();
  if (!target) { log('FOLLOW', 'queue is empty'); return { attempted: false, exhausted: true }; }
  if (DRY_RUN) dryRunSeen.add(target.id);

  const url = `https://www.instagram.com/${target.username}/`;
  log('FOLLOW', `opening @${target.username}${target.is_private ? ' (private)' : ''}` +
    `${target.review_decision === 'valid' ? ' (validated on the review screen)' : ''}`);
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await sleep(rand(2500, 4500));

  const profile = await waitForProfile(page);

  if (profile.state === 'missing') {
    log('FOLLOW', `@${target.username}: profile not found`);
    await recordAttempt(target.id, 'error', 'profile not found');
    jlog({ action: 'follow', user: target.username, status: 'error', note: 'profile not found' });
    return { attempted: false };
  }

  if (profile.state === 'timeout') {
    const f = await shot(page, `follow-nobutton-${target.username}`);
    const seen = profile.found.seen;
    const seenText = seen.length ? seen.map((t) => `"${t.slice(0, 40)}"`).join(', ') : 'none';
    log('FOLLOW', `@${target.username}: no follow button after ${PROFILE_WAIT_MS / 1000}s, header buttons ${seenText}, screenshot ${f}`);
    await recordAttempt(target.id, 'error',
      `no follow button after ${PROFILE_WAIT_MS / 1000}s; header buttons: ${seenText}`.slice(0, 250));
    jlog({ action: 'follow', user: target.username, status: 'error', note: 'no follow button', seen });
    return { attempted: false };
  }

  const found = profile.found;
  const btn = found.btn;
  const label = found.label;

  if (label === 'Following') {
    log('FOLLOW', `@${target.username}: already following`);
    await recordAttempt(target.id, 'already_following', null);
    await addOwnFollowing(target, 'follow_script');
    jlog({ action: 'follow', user: target.username, status: 'already_following' });
    return { attempted: false };       // no request sent, so no cooldown
  }
  if (label === 'Requested') {
    log('FOLLOW', `@${target.username}: request already pending`);
    await recordAttempt(target.id, 'requested', 'was already requested');
    jlog({ action: 'follow', user: target.username, status: 'requested' });
    return { attempted: false };
  }
  if (label !== 'Follow') {
    log('FOLLOW', `@${target.username}: unexpected button text "${label}"`);
    await recordAttempt(target.id, 'error', `unexpected button text: ${label}`);
    return { attempted: false };
  }

  if (DRY_RUN) {
    log('FOLLOW', `dry run, would follow @${target.username}`);
    return { attempted: true, dry: true };
  }

  // Last check before the click. The target was picked several seconds ago,
  // and a reviewer working through review.php on a phone starts at the same
  // lowest pending id this script does, so a skip landing in that window is
  // likely rather than rare. Skipped here, nothing was sent to Instagram, so
  // no cooldown is spent and nothing is written.
  const check = await stillPending(target.id);
  if (!check.pending) {
    log('FOLLOW', `@${target.username}: no longer pending (${check.status}` +
      `${check.note ? ', ' + check.note : ''}), not following`);
    jlog({ action: 'follow', user: target.username, status: 'not-pending', current: check.status, note: check.note });
    return { attempted: false };
  }

  await btn.click();
  await sleep(rand(3000, 5000));

  if (await isActionBlocked(page)) {
    await shot(page, `follow-blocked-${target.username}`);
    await recordAttempt(target.id, 'error', 'action blocked');
    throw new ActionBlocked(`blocked while following @${target.username}`);
  }

  const after = (await findFollowButton(page)).label || '';

  if (after === 'Following') {
    log('FOLLOW', `@${target.username}: followed`);
    await recordAttempt(target.id, 'followed', null);
    await addOwnFollowing(target, 'follow_script');
    jlog({ action: 'follow', user: target.username, status: 'followed' });
  } else if (after === 'Requested') {
    log('FOLLOW', `@${target.username}: requested (private account)`);
    await recordAttempt(target.id, 'requested', null);
    jlog({ action: 'follow', user: target.username, status: 'requested' });
  } else if (after === 'Follow') {
    // Reverting within seconds is what a silent throttle looks like.
    await shot(page, `follow-reverted-${target.username}`);
    await recordAttempt(target.id, 'error', 'action blocked (button reverted)');
    throw new ActionBlocked(`button reverted to Follow on @${target.username}`);
  } else {
    log('FOLLOW', `@${target.username}: unclear state "${after}"`);
    await recordAttempt(target.id, 'error', `unclear state after click: ${after}`);
  }

  return { attempted: true };
}

// -------------------------------------------------------- chrome plumbing

function chromePath() {
  const candidates = [
    process.env.CHROME_PATH,
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    path.join(process.env.LOCALAPPDATA || '', 'Google\\Chrome\\Application\\chrome.exe'),
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
  ].filter(Boolean);
  return candidates.find((p) => { try { return fs.existsSync(p); } catch (_) { return false; } });
}

async function tryAttach(url) {
  try { return await chromium.connectOverCDP(url); } catch (_) { return null; }
}

function cdpAlive(url) {
  return new Promise((resolve) => {
    const u = new URL('/json/version', url);
    const req = http.get(u, (res) => { res.resume(); resolve(res.statusCode === 200); });
    req.on('error', () => resolve(false));
    req.setTimeout(1500, () => { req.destroy(); resolve(false); });
  });
}

async function waitForCdp(url, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (await cdpAlive(url)) {
      const b = await tryAttach(url);
      if (b) return b;
    }
    await sleep(1000);
  }
  return null;
}

function launchChrome() {
  const exe = chromePath();
  if (!exe) fail('could not find chrome.exe. Set CHROME_PATH, or start Chrome by hand (see README).');
  const port = new URL(CDP_URL).port || '9222';
  log('INFO', `launching ${exe}`);
  log('INFO', `profile ${CHROME_PROFILE}, port ${port}`);
  const args = [
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${CHROME_PROFILE}`,
    '--no-first-run',
    '--no-default-browser-check',
    IG_URL,
  ];
  if (HEADLESS) args.unshift('--headless=new');
  const child = spawn(exe, args, { detached: true, stdio: 'ignore' });
  child.unref();
}

async function connect() {
  log('INFO', `attaching to Chrome at ${CDP_URL}`);
  let browser = await tryAttach(CDP_URL);

  if (!browser && !NO_LAUNCH) {
    log('INFO', 'nothing listening, starting Chrome with remote debugging');
    const freshProfile = !fs.existsSync(CHROME_PROFILE);
    launchChrome();
    browser = await waitForCdp(CDP_URL, 40000);
    if (browser && freshProfile) {
      log('WARN', `${CHROME_PROFILE} was just created, so it is a brand new profile.`);
      log('WARN', `Sign in to Instagram as @${ACCOUNT} in that window, then rerun.`);
      log('WARN', 'To carry an existing session over instead, close Chrome and run:');
      log('WARN', `  Copy-Item -Recurse "$env:LOCALAPPDATA\\Google\\Chrome\\User Data" "${CHROME_PROFILE}"`);
    }
  }

  if (!browser) {
    fail(`could not attach to or start Chrome at ${CDP_URL}.\n` +
         `Start it by hand:\n` +
         `  & "${chromePath() || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'}" ` +
         `--remote-debugging-port=${new URL(CDP_URL).port} --user-data-dir="${CHROME_PROFILE}"`);
  }

  const ctx = browser.contexts()[0];
  const page = ctx.pages().find((p) => p.url().includes('instagram.com')) || ctx.pages()[0] || (await ctx.newPage());
  // Attached to a browser we did not own: detach, never close it.
  return { ctx, page, close: () => browser.close() };
}

/** Confirm the attached browser is signed in as the expected account. */
async function checkSignedIn(page) {
  await page.goto(IG_URL, { waitUntil: 'domcontentloaded' });
  await sleep(rand(2000, 3500));
  const loginForm = await page.locator('input[name="username"]').count();
  if (loginForm) {
    fail(`Chrome at ${CDP_URL} is not signed in to Instagram.\n` +
         `Run with --login, sign in as @${ACCOUNT} in that window, then rerun.`);
  }
}

// ------------------------------------------------ profile likes: pure helpers
//
// Added 2026-10-15. Plan and decisions: profile-likes-progress.md. Selectors:
// section 6 of instagram-selectors-notes.md. Nothing in this section touches the
// browser or the database, so tests/profile-likes-test.js loads it directly.

/**
 * "2" or "1-2" into [min, max]. Empty text gives the default; with a null
 * default the value is required. Exits on bad input, and on a value outside
 * bounds {min, max} when bounds are given.
 */
function parseRange(text, dflt, flagName, bounds) {
  if (text === undefined || text === null || String(text).trim() === '') {
    if (dflt === null) {
      console.error(`\nERROR: ${flagName} needs a number or a range such as 5-12.\n`);
      process.exit(1);
    }
    return dflt.slice();
  }
  const m = /^\s*(\d+)\s*(?:-\s*(\d+)\s*)?$/.exec(String(text));
  if (!m) {
    console.error(`\nERROR: ${flagName} must be a number or a range such as 1-2, not "${text}".\n`);
    process.exit(1);
  }
  const a = parseInt(m[1], 10);
  const b = m[2] !== undefined ? parseInt(m[2], 10) : a;
  const range = a <= b ? [a, b] : [b, a];
  if (bounds && (range[0] < bounds.min || range[1] > bounds.max)) {
    console.error(`\nERROR: ${flagName} must be between ${bounds.min} and ${bounds.max}, not "${text}".\n`);
    process.exit(1);
  }
  return range;
}

/**
 * What the cooldown loop may pick from, with its weights: scroll twice, every
 * other action once and only while its budget lasts. likeOk and plikeOk carry
 * the checks that live outside the budget (the hourly likes total, the profile
 * like gate).
 */
function fillerOptions(budget, likeOk, plikeOk) {
  const options = [];
  if (budget.likes > 0 && likeOk) options.push('like');
  if (plikeOk) options.push('plike');
  if (budget.stories > 0) options.push('story');
  if (budget.reads > 0) options.push('read');
  options.push('scroll', 'scroll');      // scrolling is always plausible
  return options;
}

/** Warm-up choices: scroll twice, a story and a like once each if budgeted. */
function warmupOptions(budget) {
  const options = ['scroll', 'scroll'];
  if (budget.stories > 0) options.push('story');
  if (budget.likes > 0) options.push('like');
  return options;
}

/** The <code> of /<user>/p/<code>/, /<user>/reel/<code>/ or /p/<code>/, or null. */
function postCodeFromHref(href) {
  const m = /\/(?:p|reel)\/([A-Za-z0-9_-]+)/.exec(String(href || ''));
  return m ? m[1] : null;
}

/**
 * What to do on a profile, from how we came to pick it and what its follow
 * button says.
 *
 *   kind 'own'        the account is in ig_own_following (we follow it)
 *   kind 'requested'  ig_accounts says requested; this visit checks acceptance
 *   label             findFollowButton()'s label, or null when there is none
 *
 * Returns one of:
 *   'like'          we follow it: go on and like a post
 *   'accepted'      the pending request was accepted: record that, then like
 *   'still_pending' the request is still pending: note the check, like nothing
 *   'request_gone'  the button says Follow: the request was declined or
 *                   withdrawn; note the check, like nothing, never click Follow
 *   'not_following' we no longer follow an account we used to; note, like nothing
 *   'no_button'     no follow-state button; note, like nothing
 */
function visitDecision(kind, label) {
  if (!label) return 'no_button';
  if (kind === 'requested') {
    if (label === 'Following') return 'accepted';
    if (label === 'Requested') return 'still_pending';
    return 'request_gone';
  }
  return label === 'Following' ? 'like' : 'not_following';
}

/**
 * May a profile-likes visit start now? Pure: the caller passes the counters.
 *
 *   st.disabled       reason string when the action is off for this run, else null
 *   st.budget         likes allowed this hour (drawn from the per-hour range)
 *   st.thisHour       profile likes this hour
 *   st.thisRun        profile likes this run
 *   st.lastAttemptAt  epoch ms of the last visit, liked or not (0 if none)
 *   totalLikesHour    feed and profile likes this hour
 */
function profileLikeGate(st, totalLikesHour, now, limits) {
  if (st.disabled) return { ok: false, reason: st.disabled };
  if (st.thisRun >= limits.perRunMax) return { ok: false, reason: 'run maximum reached' };
  if (st.thisHour >= st.budget) return { ok: false, reason: 'hourly budget used' };
  if (totalLikesHour >= limits.totalPerHourMax) return { ok: false, reason: 'total likes this hour at maximum' };
  if (st.lastAttemptAt && now - st.lastAttemptAt < limits.minGapMs) return { ok: false, reason: 'too soon after the last visit' };
  return { ok: true, reason: null };
}

/** Fisher-Yates shuffle of a copy. */
function shuffled(arr) {
  const a = arr.slice();
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]];
  }
  return a;
}

// ---------------------------------------- profile likes: browser and database

const PROFILE_TILE_SEL = 'main a[href*="/p/"], main a[href*="/reel/"]';
// The post's action bar inside the dialog: the innermost section holding a
// Comment icon and a like icon. Excludes the 12 px like buttons on comments.
const POST_BAR_SEL = '[role="dialog"] section:not(:has(section)):has(svg[aria-label="Comment"])' +
  ':has(svg[aria-label="Like"], svg[aria-label="Unlike"])';

let profileLikesReady = null;          // null: not checked yet
const profileVisitedThisRun = new Set();

/** Do ig_own_following, ig_post_likes and ig_accounts.request_checked_at exist? */
async function checkProfileLikesSchema() {
  if (profileLikesReady !== null) return profileLikesReady;
  const [[r]] = await (await db()).query(
    `SELECT
       (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('ig_own_following', 'ig_post_likes')) AS tables_n,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ig_accounts' AND COLUMN_NAME = 'request_checked_at') AS col_n`);
  profileLikesReady = Number(r.tables_n) === 2 && Number(r.col_n) === 1;
  return profileLikesReady;
}

/**
 * A random account due for a visit: one we follow that was not visited in the
 * last PROFILE_REVISIT_DAYS days, or a pending request not checked in that time.
 */
async function pickProfileTarget() {
  const cutoff = nyAt(Date.now() - PROFILE_REVISIT_DAYS * 24 * 3600 * 1000);
  const exclude = [...profileVisitedThisRun];
  const notIn = exclude.length ? ' AND x.ig_user_id NOT IN (?)' : '';
  return withDbRetry('pickProfileTarget', async () => {
    const [rows] = await (await db()).query(
      `SELECT x.* FROM (
         SELECT 'own' AS kind, o.ig_user_id, o.username, NULL AS account_id, o.is_private
           FROM ig_own_following o
          WHERE o.last_visited_at IS NULL OR o.last_visited_at < ?
         UNION ALL
         SELECT 'requested' AS kind, a.ig_user_id, a.username, a.id AS account_id, a.is_private
           FROM ig_accounts a
          WHERE a.follow_status = 'requested'
            AND COALESCE(a.request_checked_at, a.follow_attempted_at, '2000-01-01 00:00:00') < ?
            AND NOT EXISTS (SELECT 1 FROM ig_own_following o2 WHERE o2.ig_user_id = a.ig_user_id)
       ) x
       WHERE x.username <> ?${notIn}
       ORDER BY RAND()
       LIMIT 1`,
      exclude.length ? [cutoff, cutoff, ACCOUNT, exclude] : [cutoff, cutoff, ACCOUNT]);
    return rows[0] || null;
  });
}

/** How many accounts are due, for the banner and --check-db. */
async function profileTargetsDue() {
  const cutoff = nyAt(Date.now() - PROFILE_REVISIT_DAYS * 24 * 3600 * 1000);
  return withDbRetry('profileTargetsDue', async () => {
    const [[r]] = await (await db()).query(
      `SELECT
         (SELECT COUNT(*) FROM ig_own_following) AS own_total,
         (SELECT COUNT(*) FROM ig_own_following WHERE last_visited_at IS NULL OR last_visited_at < ?) AS own_due,
         (SELECT COUNT(*) FROM ig_accounts a
           WHERE a.follow_status = 'requested'
             AND COALESCE(a.request_checked_at, a.follow_attempted_at, '2000-01-01 00:00:00') < ?
             AND NOT EXISTS (SELECT 1 FROM ig_own_following o WHERE o.ig_user_id = a.ig_user_id)) AS requests_due,
         (SELECT COUNT(*) FROM ig_post_likes) AS likes_total,
         (SELECT COUNT(*) FROM ig_post_likes WHERE liked_at >= ?) AS likes_today`,
      [cutoff, cutoff, nyNow().slice(0, 10) + ' 00:00:00']);
    return r;
  });
}

/** Post codes among `codes` that ig_post_likes already holds. */
async function alreadyLikedCodes(codes) {
  if (!codes.length) return new Set();
  return withDbRetry('alreadyLikedCodes', async () => {
    const [rows] = await (await db()).query(
      'SELECT post_code FROM ig_post_likes WHERE post_code IN (?)', [codes]);
    return new Set(rows.map((r) => r.post_code));
  });
}

/** Best effort: a failed write is logged, never fatal. Nothing is written in a dry run. */
async function bestEffortWrite(label, fn) {
  if (DRY_RUN || !profileLikesReady) return;
  try {
    await withDbRetry(label, fn);
  } catch (e) {
    log('DB', `${label} not recorded: ${e.code || e.message}`);
    jlog({ action: 'db-write-failed', label, error: e.code || e.message });
  }
}

async function recordPostLike(code, owner, source) {
  await bestEffortWrite('recordPostLike', async () => {
    await (await db()).query(
      'INSERT IGNORE INTO ig_post_likes (post_code, owner_username, source, liked_at) VALUES (?, ?, ?, ?)',
      [code, owner || null, source, nyNow()]);
  });
}

/** Add an account the script follows (or found followed) to ig_own_following. */
async function addOwnFollowing(target, source) {
  if (!target || !target.ig_user_id) return;
  await bestEffortWrite('addOwnFollowing', async () => {
    const now = nyNow();
    await (await db()).query(
      `INSERT INTO ig_own_following
         (ig_user_id, username, is_private, source, first_seen_at, last_seen_at)
       VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE username = VALUES(username), last_seen_at = VALUES(last_seen_at)`,
      [String(target.ig_user_id), target.username, target.is_private ? 1 : 0, source, now, now]);
  });
}

/** Record a visit to an account we follow: when, why nothing was liked, and a like. */
async function noteOwnVisit(t, note, liked) {
  await bestEffortWrite('noteOwnVisit', async () => {
    const now = nyNow();
    await (await db()).query(
      `UPDATE ig_own_following
          SET last_visited_at = ?, visit_note = ?, last_liked_at = IF(?, ?, last_liked_at)
        WHERE ig_user_id = ?`,
      [now, note, liked ? 1 : 0, now, String(t.ig_user_id)]);
  });
}

/** A pending request is still pending (or gone): check again in PROFILE_REVISIT_DAYS days. */
async function noteRequestChecked(t) {
  await bestEffortWrite('noteRequestChecked', async () => {
    await (await db()).query('UPDATE ig_accounts SET request_checked_at = ? WHERE id = ?', [nyNow(), t.account_id]);
  });
}

/** A pending request was accepted: the queue row becomes followed, the account joins ig_own_following. */
async function recordRequestAccepted(t) {
  await bestEffortWrite('recordRequestAccepted', async () => {
    await (await db()).query(
      `UPDATE ig_accounts
          SET follow_status = 'followed', follow_note = ?, request_checked_at = ?
        WHERE id = ? AND follow_status = 'requested'`,
      [`request accepted, seen ${nyNow()}`, nyNow(), t.account_id]);
  });
  await addOwnFollowing({ ig_user_id: t.ig_user_id, username: t.username, is_private: 1 }, 'request_accepted');
}

/** Close an open post dialog; fall back to reloading the profile. */
async function closePost(page, username) {
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(rand(800, 1600));
  if (await page.locator('[role="dialog"]').count()) {
    await page.goto(`https://www.instagram.com/${username}/`, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await sleep(rand(1500, 2500));
  }
}

/**
 * Open a random account we follow and like one of its recent posts or reels.
 *
 * Returns { liked: boolean, failed?: boolean }. failed means a Like click did
 * not register: the caller turns the action off for the rest of the run.
 * Throws ActionBlocked on Instagram's block message, like every other action.
 */
async function likeFromProfile(page) {
  const t = await pickProfileTarget();
  if (!t) {
    log('PLIKE', 'no followed account is due for a visit');
    return { liked: false };
  }
  profileVisitedThisRun.add(String(t.ig_user_id));

  log('PLIKE', `opening @${t.username}${t.kind === 'requested' ? ' (checking a pending follow request)' : ''}`);
  await page.goto(`https://www.instagram.com/${t.username}/`, { waitUntil: 'domcontentloaded' });
  await sleep(rand(2500, 4500));

  const profile = await waitForProfile(page);
  if (profile.state === 'missing') {
    log('PLIKE', `@${t.username}: profile not found`);
    if (t.kind === 'own') await noteOwnVisit(t, 'profile not found', false);
    else await noteRequestChecked(t);
    return { liked: false };
  }

  const decision = visitDecision(t.kind, profile.state === 'ready' && profile.found ? profile.found.label : null);
  jlog({ action: 'profile-visit', user: t.username, kind: t.kind, decision });

  if (decision === 'still_pending' || decision === 'request_gone') {
    log('PLIKE', decision === 'still_pending'
      ? `@${t.username}: request still pending, checking again in ${PROFILE_REVISIT_DAYS} days`
      : `@${t.username}: request no longer pending (button says Follow), not clicking it`);
    await noteRequestChecked(t);
    return { liked: false };
  }
  if (decision === 'not_following' || decision === 'no_button') {
    log('PLIKE', `@${t.username}: ${decision === 'not_following' ? 'we no longer follow this account' : 'no follow button'}`);
    if (t.kind === 'own') await noteOwnVisit(t, decision === 'not_following' ? 'not followed any more' : 'no follow button', false);
    else await noteRequestChecked(t);
    return { liked: false };
  }
  if (decision === 'accepted') {
    log('PLIKE', `@${t.username}: follow request was accepted, recording it as followed`);
    await recordRequestAccepted(t);
    t.kind = 'own';
  }

  // Look at the profile for a moment before opening anything.
  await page.mouse.wheel(0, randInt(150, 450));
  await sleep(rand(1500, 3500));

  const hrefs = await page.locator(PROFILE_TILE_SEL).evaluateAll(
    (as, n) => as.slice(0, n).map((a) => a.getAttribute('href')), PROFILE_RECENT_TILES);
  if (!hrefs.length) {
    log('PLIKE', `@${t.username}: no posts visible`);
    await noteOwnVisit(t, 'no posts visible', false);
    return { liked: false };
  }

  const liked = await alreadyLikedCodes(hrefs.map(postCodeFromHref).filter(Boolean)).catch(() => new Set());
  const candidates = shuffled(hrefs).filter((h) => !liked.has(postCodeFromHref(h)));

  let tries = 0;
  for (const href of candidates) {
    if (tries >= PROFILE_TRIES) break;
    tries++;
    const code = postCodeFromHref(href);

    await page.locator(`main a[href="${href}"]`).first().click();
    try {
      await page.locator(POST_BAR_SEL).first().waitFor({ state: 'visible', timeout: 10000 });
    } catch (_) {
      log('PLIKE', `@${t.username}: post ${code} did not open`);
      await closePost(page, t.username);
      continue;
    }
    await sleep(rand(2500, 6000));     // look at the post

    if (await page.locator(`${POST_BAR_SEL} svg[aria-label="Unlike"]`).count()) {
      log('PLIKE', `@${t.username}: post ${code} already liked, trying another`);
      await closePost(page, t.username);
      continue;
    }
    const btn = page.locator(`${POST_BAR_SEL} div[role="button"]:has(svg[aria-label="Like"])`).first();
    if (!(await btn.count())) {
      log('PLIKE', `@${t.username}: no like button on post ${code}`);
      await closePost(page, t.username);
      continue;
    }

    if (DRY_RUN) {
      log('PLIKE', `dry run, would like post ${code} by @${t.username}`);
      await closePost(page, t.username);
      return { liked: false };
    }

    await btn.click();
    await sleep(rand(1200, 2500));
    if (await isActionBlocked(page)) {
      await shot(page, `plike-blocked-${t.username}`);
      throw new ActionBlocked(`blocked while liking a post by @${t.username}`);
    }
    if (!(await page.locator(`${POST_BAR_SEL} svg[aria-label="Unlike"]`).count())) {
      const f = await shot(page, `plike-not-registered-${t.username}`);
      log('PLIKE', `@${t.username}: like on post ${code} did not register, screenshot ${f}`);
      jlog({ action: 'profile-like', user: t.username, post: code, ok: false });
      await noteOwnVisit(t, 'like did not register', false);
      await closePost(page, t.username);
      return { liked: false, failed: true };
    }

    log('PLIKE', `liked post ${code} by @${t.username}`);
    jlog({ action: 'profile-like', user: t.username, post: code, ok: true });
    await recordPostLike(code, t.username, 'profile');
    await noteOwnVisit(t, null, true);
    await sleep(rand(1500, 4000));
    await closePost(page, t.username);
    return { liked: true };
  }

  log('PLIKE', `@${t.username}: nothing to like among the ${hrefs.length} most recent posts`);
  await noteOwnVisit(t, 'recent posts already liked or would not open', false);
  return { liked: false };
}

// ------------------------------------------------------ where the script runs
//
// This script must only run on Ildar's Windows PC. Two reasons, both about the
// Instagram account rather than about the code:
//
//   1. Instagram has seen @verifiedbotanicals operate from the PC's residential
//      Quebec address. A session from a datacenter address, which is what the
//      VPS has, is one of the most common triggers for a checkpoint.
//   2. It drives the signed-in Chrome profile at C:\chrome-automation-profile,
//      which exists only on the PC.
//
// The folder is part of the ig-follower-scraper repository, and that repository
// is deployed to the VPS at /home/bizousoft/public_html/seo/ig-follower-scraper,
// so this file exists there too. Nothing in this folder is meant to run there.

const VPS_IP = '66.206.23.226';
const VPS_PATH_RE = /^\/home\/bizousoft\/|\/public_html\//;

/**
 * Reasons this machine is not the PC. An empty list means it is.
 *
 * Any one reason is enough to refuse. There is deliberately no flag or setting
 * that overrides this: the cost of a wrong start is the account.
 */
function wrongMachineReasons(env) {
  const reasons = [];
  const addresses = Object.values(env.interfaces || {})
    .flat()
    .filter(Boolean)
    .map((a) => a.address);

  if (addresses.includes(VPS_IP)) {
    reasons.push(`this machine has the VPS address ${VPS_IP}, so this is seo.bizousoft.com`);
  }
  if (VPS_PATH_RE.test(env.dir)) {
    reasons.push(`the script is running from ${env.dir}, which is the web deployment on the VPS`);
  }
  if (env.platform !== 'win32') {
    reasons.push(`this is ${env.platform}, not Windows. If this is Ubuntu (WSL) on the PC, open ` +
                 `PowerShell instead: the script drives the Windows Chrome profile at ` +
                 `C:\\chrome-automation-profile and opens the SSH tunnel with Windows ssh.exe`);
  }
  return reasons;
}

function assertRunningOnPc() {
  const reasons = wrongMachineReasons({
    interfaces: os.networkInterfaces(),
    dir: HERE,
    platform: process.platform,
  });
  if (reasons.length === 0) return;

  console.error('');
  console.error('  STOP. instagram-follower.js only runs on Ildar\'s Windows PC, from PowerShell.');
  console.error('');
  console.error('  It follows Instagram accounts as @verifiedbotanicals. Instagram knows that account');
  console.error('  from the PC\'s home internet address and its signed-in Chrome profile. Starting it');
  console.error('  from the VPS, a datacenter address, risks a checkpoint on the account.');
  console.error('');
  console.error('  Refused because:');
  for (const r of reasons) console.error(`    - ${r}`);
  console.error('');
  console.error('  On the VPS, only the PHP app (the scraper and the accounts browser) runs. Nothing');
  console.error('  in this instagram-follower folder is meant to run there. Nothing was opened,');
  console.error('  clicked or written.');
  console.error('');
  process.exit(1);
}

// --------------------------------------------------------------- check-db

/**
 * --check-db: open the tunnel, pass the server guard, print what the follow
 * run would see, and exit. Opens no browser and writes nothing.
 */
async function checkDb() {
  await db().catch((e) => fail(dbFailMessage(e)));
  const counts = await queueCounts().catch((e) => fail(dbFailMessage(e)));
  const eligible = await eligibleCount().catch((e) => fail(dbFailMessage(e)));
  const conn = await db();
  const [rows] = await conn.query('SELECT MAX(follow_attempted_at) AS last FROM ig_accounts');
  // What the most recent following-list import did. Read-only. Added 2026-10-14.
  const [[marked]] = await conn.query(
    `SELECT COUNT(*) AS n, MIN(follow_attempted_at) AS first_at, MAX(follow_attempted_at) AS last_at
       FROM ig_accounts
      WHERE follow_status = 'already_following'
        AND follow_note LIKE 'in @% following list, Apify run %'`);
  const [[ownFollowing]] = await conn.query(
    `SELECT COUNT(*) AS n FROM ig_relations
      WHERE source_username = ? AND relation = 'FOLLOWING'`, [ACCOUNT]);
  const [runs] = await conn.query(
    `SELECT id, source_username, data_to_scrape, status, items_total, items_imported, started_at
       FROM ig_scrape_runs ORDER BY id DESC LIMIT 1`);
  // Every account in our stored following list, by its status in the queue.
  // "not in queue" means we follow it but it was never a follow target.
  const [followingByStatus] = await conn.query(
    `SELECT COALESCE(a.follow_status, 'not in queue') AS status, COUNT(*) AS n
       FROM ig_relations r
       LEFT JOIN ig_accounts a ON a.ig_user_id = r.ig_user_id
      WHERE r.source_username = ? AND r.relation = 'FOLLOWING'
      GROUP BY status ORDER BY n DESC`, [ACCOUNT]);
  // Queue rows recorded as followed that are NOT in that list: unfollowed since,
  // or the follow never took. Only meaningful once a following list is stored.
  const [[followedMissing]] = await conn.query(
    `SELECT COUNT(*) AS n
       FROM ig_accounts a
      WHERE a.follow_status = 'followed'
        AND NOT EXISTS (SELECT 1 FROM ig_relations r
                         WHERE r.source_username = ? AND r.relation = 'FOLLOWING'
                           AND r.ig_user_id = a.ig_user_id)`, [ACCOUNT]);

  console.log('');
  console.log(`Server:    ${serverInfo.hostname} (${serverInfo.compileOs}), MySQL ${serverInfo.version}`);
  console.log(`This PC:   ${os.hostname()}`);
  console.log(`Database:  ${serverInfo.db}, via SSH tunnel to ${SSH.user}@${SSH.host}`);
  console.log('Queue by status:');
  for (const [status, n] of Object.entries(counts).sort((a, b) => b[1] - a[1])) {
    console.log(`  ${String(status).padEnd(18)} ${n}`);
  }
  console.log(`Eligible:  ${eligible} accounts pass the current filter (--min-followers ${MIN_FOLLOWERS})`);
  console.log(`Last follow attempt recorded: ${rows[0].last || 'none'}`);
  console.log(`Marked already_following from a following list: ${marked.n}` +
              (marked.n ? ` (between ${marked.first_at} and ${marked.last_at})` : ''));
  console.log(`@${ACCOUNT} following list rows stored: ${ownFollowing.n}`);
  if (ownFollowing.n > 0) {
    console.log(`  of which, by queue status:`);
    for (const f of followingByStatus) console.log(`    ${String(f.status).padEnd(18)} ${f.n}`);
    console.log(`  queue rows marked followed but not in the stored list: ${followedMissing.n}`);
  }
  if (runs[0]) {
    const r = runs[0];
    console.log(`Latest Apify run: #${r.id} @${r.source_username} ${r.data_to_scrape}, ${r.status}, ` +
                `${r.items_imported} of ${r.items_total} items imported, started ${r.started_at}`);
  }
  // Profile likes (added 2026-10-15).
  const plReady = await checkProfileLikesSchema().catch(() => false);
  if (!plReady) {
    console.log('Profile likes: tables missing, open ?action=migrate in the web app once');
  } else {
    const d = await profileTargetsDue();
    console.log(`Profile likes: ${d.own_total} followed accounts stored, ${d.own_due} due for a visit, ` +
                `${d.requests_due} pending requests due for a check`);
    console.log(`  posts liked: ${d.likes_total} recorded, ${d.likes_today} today`);
  }
  console.log('');
  console.log('OK: this is the remote database. The follow run will use it.');
}

// ------------------------------------------------------------------- main

async function main() {
  // First, before any mode, including --login, --check-db and --replay-journal.
  assertRunningOnPc();

  if (!fs.existsSync(SHOTS_DIR)) fs.mkdirSync(SHOTS_DIR, { recursive: true });

  if (LOGIN) {
    log('INFO', 'opening Chrome for a manual sign-in. Sign in, then close this with Ctrl+C.');
    await connect();
    await new Promise(() => {});
    return;
  }

  // Replaying the journal touches the database and nothing else, so it runs
  // before the browser is opened and exits without starting a follow run.
  if (CHECK_DB) {
    await checkDb();
    if (pool) await pool.end().catch(() => {});
    process.exit(0);
  }

  if (REPLAY_JOURNAL) {
    await db().catch((e) => fail(dbFailMessage(e)));
    await replayJournal();
    if (pool) await pool.end().catch(() => {});
    process.exit(0);
  }

  // Connect first, so a tunnel or server problem is reported on its own rather
  // than as a failed query.
  await db().catch((e) => fail(dbFailMessage(e)));
  const before = await queueCounts().catch((e) => fail(dbFailMessage(e)));

  console.log('');
  console.log(`Account:   @${ACCOUNT}${DRY_RUN ? '   (DRY RUN, nothing will be clicked or written)' : ''}`);
  console.log(`Queue:     ${before.pending || 0} pending, ${before.followed || 0} followed, ` +
              `${before.requested || 0} requested, ${before.error || 0} error`);
  console.log(`Database:  ${serverInfo.db} on ${serverInfo.hostname}, via SSH tunnel to ${SSH.host}`);
  console.log(`Target:    ${FOLLOW_TARGET} follows, cooldown ${COOLDOWN_MIN}-${COOLDOWN_MAX}s`);
  const eligible = await eligibleCount().catch(() => null);

  if (MIN_FOLLOWERS > 0) {
    console.log(`Filter:    followers_count >= ${MIN_FOLLOWERS}` +
                (MIN_POSTS > 0 ? `, posts_count >= ${MIN_POSTS}` : '') +
                `  (enriched accounts only)`);
  } else {
    console.log(`Filter:    none on followers` +
                (MIN_POSTS > 0 ? `, posts_count >= ${MIN_POSTS}` : '') +
                `  (unenriched accounts eligible)`);
  }
  if (eligible !== null) console.log(`Eligible:  ${eligible} accounts match right now`);
  if (MAX_MINUTES > 0) console.log(`Stop after ${MAX_MINUTES} minutes, whichever comes first`);
  console.log(`Warm-up:   ${NO_WARMUP ? 'disabled (--no-warmup)' : '2 to 4 browsing actions before the first follow'}`);
  console.log(`Stories:   up to ${STORIES_RANGE[0]}-${STORIES_RANGE[1]} sessions/hour, ` +
              `${STORY_SESSION_RANGE[0]}-${STORY_SESSION_RANGE[1]} per session, ` +
              (STORY_SECONDS_RANGE ? `${STORY_SECONDS_RANGE[0]}-${STORY_SECONDS_RANGE[1]} s each` : '2.5-6 s each'));
  console.log(`Fillers:   up to ${LIKES_RANGE[0]}-${LIKES_RANGE[1]} likes and ${READS_RANGE[0]}-${READS_RANGE[1]} comment reads per hour`);
  // Profile likes need the tables the web app's migrate action creates.
  // Checked even with --no-profile-likes: feed likes and new follows record into
  // the same tables.
  const plTables = await checkProfileLikesSchema().catch(() => false);
  let plikeOff = NO_PROFILE_LIKES ? 'disabled (--no-profile-likes)' : null;
  if (!plikeOff && !plTables) plikeOff = 'off: tables missing, open ?action=migrate in the web app once';
  if (plikeOff) {
    console.log(`Profile likes: ${plikeOff}`);
  } else {
    const due = await profileTargetsDue().catch(() => null);
    console.log(`Profile likes: ${PROFILE_LIKES_RANGE[0]}-${PROFILE_LIKES_RANGE[1]} an hour, at least ` +
                `${PROFILE_LIKE_MIN_GAP_MS / 60000} min apart, at most ${PROFILE_LIKES_PER_RUN_MAX} this run` +
                (due ? `; ${due.own_due} of ${due.own_total} followed accounts and ${due.requests_due} pending requests due` : ''));
  }
  console.log(`Likes cap: ${TOTAL_LIKES_PER_HOUR_MAX} an hour, feed and profile likes together`);
  console.log('');

  if (eligible !== null && eligible < FOLLOW_TARGET) {
    log('WARN', `only ${eligible} accounts match the filter but the target is ${FOLLOW_TARGET}.`);
    log('WARN', `The run will stop early with "queue empty". Enrich more accounts, or lower --min-followers.`);
  }

  const { page, close } = await connect();
  await checkSignedIn(page);

  if (PROFILE_LIKE_ONCE) {
    if (plikeOff) fail(`--profile-like-once: profile likes are ${plikeOff}`);
    let code = 0;
    try {
      const r = await likeFromProfile(page);
      log('PLIKE', r.liked ? 'done, one post liked' : `done, nothing liked${r.failed ? ' (the like did not register)' : ''}`);
      jlog({ action: 'summary', mode: 'profile-like-once', liked: !!r.liked, failed: !!r.failed });
      if (r.failed) code = 1;
    } catch (e) {
      if (e instanceof ActionBlocked) { log('STOP', e.message); code = 2; }
      else { log('FAIL', e.message); await shot(page, 'plike-once-fatal'); code = 1; }
    }
    if (pool) await pool.end().catch(() => {});
    await close().catch(() => {});
    process.exit(code);
  }

  const started = Date.now();
  let follows = 0;
  let nextFollowAt = 0;          // set below, after any warm-up
  let likes = 0, stories = 0, reads = 0;
  let stopReason = 'target reached';

  const budget = {
    likes: randInt(LIKES_RANGE[0], LIKES_RANGE[1]),
    stories: randInt(STORIES_RANGE[0], STORIES_RANGE[1]),
    reads: randInt(READS_RANGE[0], READS_RANGE[1]),
  };
  let budgetResetAt = Date.now() + 3600 * 1000;

  // Profile likes (added 2026-10-15). totalLikesHour counts real likes only,
  // feed and profile together, against TOTAL_LIKES_PER_HOUR_MAX.
  const plike = {
    budget: randInt(PROFILE_LIKES_RANGE[0], PROFILE_LIKES_RANGE[1]),
    thisHour: 0,
    thisRun: 0,
    visits: 0,
    lastAttemptAt: 0,
    disabled: plikeOff,
  };
  const plikeLimits = {
    perRunMax: PROFILE_LIKES_PER_RUN_MAX,
    totalPerHourMax: TOTAL_LIKES_PER_HOUR_MAX,
    minGapMs: PROFILE_LIKE_MIN_GAP_MS,
  };
  let totalLikesHour = 0;
  let profileLikes = 0;

  try {
    // Warm-up: behave like someone who just opened Instagram before doing
    // anything that touches another account. Skipped with --no-warmup, which is
    // useful when testing and the wait is just in the way.
    if (!NO_WARMUP) {
      const steps = randInt(2, 4);
      log('INFO', `warming up with ${steps} actions before the first follow`);
      for (let i = 0; i < steps; i++) {
        const opener = pick(warmupOptions(budget));
        try {
          // A story attempt uses budget even on an empty tray, so an empty
          // tray cannot be retried every cycle; only an opened ring counts.
          if (opener === 'story') { budget.stories--; if ((await watchStories(page)).opened) stories++; }
          else if (opener === 'like') {
            budget.likes--;
            if (await likePost(page)) { likes++; totalLikesHour++; }
          }
          else { await scrollFeed(page); }
        } catch (e) {
          if (e instanceof ActionBlocked) throw e;
          log('WARN', `warm-up ${opener} failed: ${e.message}`);
        }
        await sleep(rand(4000, 12000));
      }
    }

    while (follows < FOLLOW_TARGET) {
      if (MAX_MINUTES > 0 && Date.now() - started > MAX_MINUTES * 60 * 1000) {
        stopReason = `--max-minutes ${MAX_MINUTES} elapsed`;
        break;
      }

      if (Date.now() > budgetResetAt) {
        budget.likes = randInt(LIKES_RANGE[0], LIKES_RANGE[1]);
        budget.stories = randInt(STORIES_RANGE[0], STORIES_RANGE[1]);
        budget.reads = randInt(READS_RANGE[0], READS_RANGE[1]);
        plike.budget = randInt(PROFILE_LIKES_RANGE[0], PROFILE_LIKES_RANGE[1]);
        plike.thisHour = 0;
        totalLikesHour = 0;
        budgetResetAt = Date.now() + 3600 * 1000;
        log('INFO', `hourly budgets reset: ${budget.likes} likes, ${budget.stories} stories, ${budget.reads} reads, ` +
                    `${plike.disabled ? 'no' : plike.budget} profile likes`);
      }

      if (Date.now() >= nextFollowAt) {
        const res = await followNext(page);
        if (res.exhausted) { stopReason = 'queue empty'; break; }
        if (res.attempted) {
          follows++;
          const wait = randInt(COOLDOWN_MIN, COOLDOWN_MAX);
          nextFollowAt = Date.now() + wait * 1000;
          log('INFO', `${follows}/${FOLLOW_TARGET} follows. Next in ${Math.round(wait / 60 * 10) / 10} min.`);
        }
        await sleep(rand(2000, 5000));
        continue;
      }

      // Cooldown: do something a person would be doing.
      const options = fillerOptions(budget,
        totalLikesHour < TOTAL_LIKES_PER_HOUR_MAX,
        profileLikeGate(plike, totalLikesHour, Date.now(), plikeLimits).ok);

      const action = pick(options);
      try {
        if (action === 'like') {
          budget.likes--;
          if (await likePost(page)) { likes++; totalLikesHour++; }
        }
        else if (action === 'plike') {
          plike.lastAttemptAt = Date.now();
          plike.visits++;
          const r = await likeFromProfile(page);
          if (r.liked) { plike.thisHour++; plike.thisRun++; profileLikes++; totalLikesHour++; }
          if (r.failed) {
            plike.disabled = 'off for this run: a like did not register';
            log('WARN', 'profile likes turned off for the rest of this run, a like did not register');
          }
        }
        else if (action === 'story') { budget.stories--; if ((await watchStories(page)).opened) stories++; }
        else if (action === 'read') { await readComments(page); budget.reads--; reads++; }
        else { await scrollFeed(page); }
      } catch (e) {
        if (e instanceof ActionBlocked) throw e;
        log('WARN', `${action} failed: ${e.message}`);
        await shot(page, `${action}-fail`);
        await goHome(page).catch(() => {});
      }

      const left = Math.max(0, nextFollowAt - Date.now());
      await sleep(Math.min(left, rand(8000, 25000)));
    }
  } catch (e) {
    if (e instanceof ActionBlocked) {
      stopReason = 'ACTION BLOCKED';
      log('STOP', e.message);
      log('STOP', 'Stopping immediately. Continuing past a block extends it.');
      jlog({ action: 'halt', reason: 'action_blocked', message: e.message });
    } else {
      stopReason = `error: ${e.message}`;
      log('FAIL', e.message);
      await shot(page, 'fatal');
      if (PAUSE_ON_FAIL) { log('PAUSE', 'Browser left open. Ctrl+C when done inspecting.'); await new Promise(() => {}); }
    }
  }

  const after = await queueCounts().catch(() => ({}));
  const mins = Math.round((Date.now() - started) / 60000);

  console.log('');
  console.log(`Stopped:   ${stopReason}`);
  console.log(`Ran for:   ${mins} min`);
  console.log(`Follows:   ${follows} attempted this run`);
  console.log(`Filler:    ${likes} feed likes, ${stories} story sets, ${reads} comment reads`);
  console.log(`Profiles:  ${plike.visits} visited, ${profileLikes} posts liked` +
              (plike.disabled ? ` (${plike.disabled})` : ''));
  console.log(`Queue now: ${after.pending || 0} pending, ${after.followed || 0} followed, ` +
              `${after.requested || 0} requested, ${after.error || 0} error`);
  console.log('');

  if (journalledWrites > 0) {
    console.log('');
    console.log(`  !! ${journalledWrites} follow(s) could not be written to the database and are`);
    console.log(`  !! in ${JOURNAL}.`);
    console.log(`  !! Run: node instagram-follower.js --replay-journal`);
    console.log(`  !! Until that is done, those accounts are still pending and will be`);
    console.log(`  !! followed a second time.`);
    console.log('');
  }

  jlog({ action: 'summary', stopReason, minutes: mins, follows, likes, profileLikes,
         profileVisits: plike.visits, stories, reads, journalledWrites });

  if (pool) await pool.end().catch(() => {});
  await close().catch(() => {});
  process.exit(stopReason === 'ACTION BLOCKED' ? 2 : 0);
}

// ------------------------------------------------------- exit diagnostics
//
// Added 2026-10-14 after two runs ended with no error and no summary. Every way
// this process can end now leaves a line on screen and in run-log.jsonl, so a
// silent stop can be told apart from Ctrl+C, a closed window, or a crash.

let exitAnnounced = false;

function announceExit(reason, code) {
  if (exitAnnounced) return;
  exitAnnounced = true;
  try { console.error(`[${stamp()}] EXIT   ${reason} (exit code ${code})`); } catch (_) { /* console gone */ }
  jlog({ action: 'exit', reason, code });
}

for (const sig of ['SIGINT', 'SIGBREAK', 'SIGHUP', 'SIGTERM']) {
  process.on(sig, () => {
    const what = sig === 'SIGINT' ? 'Ctrl+C (SIGINT)'
      : sig === 'SIGBREAK' ? 'Ctrl+Break (SIGBREAK)'
      : sig === 'SIGHUP' ? 'console window closed (SIGHUP)'
      : 'terminated (SIGTERM)';
    announceExit(`stopped by ${what}`, 130);
    process.exit(130);
  });
}

process.on('uncaughtException', (e) => {
  console.error(`\nUNCAUGHT EXCEPTION: ${e && e.stack ? e.stack : e}\n`);
  announceExit(`uncaught exception: ${e && e.message ? e.message : e}`, 1);
  process.exit(1);
});

process.on('unhandledRejection', (e) => {
  console.error(`\nUNHANDLED REJECTION: ${e && e.stack ? e.stack : e}\n`);
  announceExit(`unhandled rejection: ${e && e.message ? e.message : e}`, 1);
  process.exit(1);
});

process.on('exit', (code) => {
  announceExit(code === 0 ? 'finished' : code === 2 ? 'action blocked' : 'stopped', code);
});

main().catch((e) => fail(e.stack || e.message));
