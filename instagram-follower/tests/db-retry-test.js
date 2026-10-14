'use strict';
/**
 * Tests for the database retry helper in instagram-follower.js.
 *
 * Run with:  node tests/db-retry-test.js
 *
 * The helper matters because the database moved from this PC to a VPS. Before
 * that move, any database error meant something was seriously wrong and ending
 * the run was the right response. Now a transient network drop can throw, and a
 * run is meant to last six to eight hours, so a blip must not end it.
 *
 * withDbRetry and isRetryableDbError below are a verbatim copy of the versions
 * in instagram-follower.js. Copying rather than importing keeps the script a
 * single standalone file with no module exports, which is how every automation
 * script in this workspace is built. The cost is that a change to the helper in
 * the script must be pasted here as well, or this suite silently tests the old
 * version. The marked block is the only part to copy.
 *
 * sleep is stubbed to return immediately, so the suite finishes in milliseconds
 * rather than spending five seconds of real backoff on each exhausted case.
 */

let slept = [];
let logs = [];
let jlogs = [];
let pool = null;
let poolEnds = 0;

const sleep = async (ms) => { slept.push(ms); };
const log = (tag, msg) => { logs.push(`${tag} ${msg}`); };
const jlog = (e) => { jlogs.push(e); };

// ---- begin verbatim copy from instagram-follower.js -----------------------
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
// ---- end verbatim copy ----------------------------------------------------

let fails = 0;
function check(label, got, want) {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  const ok = g === w;
  if (!ok) fails++;
  console.log(`  [${ok ? 'ok' : 'FAIL'}] ${label.padEnd(52)} got=${g} want=${w}`);
}
let poolsCreated = 0;

// Mirrors db() in the real script: the pool is lazily recreated whenever it is
// null, which is the state withDbRetry leaves it in after discarding a dead
// one. Without this, the harness discards the pool once and then has nothing
// left to discard, which is a property of the harness and not of the code.
function db() {
  if (!pool) {
    poolsCreated++;
    pool = { end: async () => { poolEnds++; } };
  }
  return pool;
}

function reset() {
  slept = []; logs = []; jlogs = []; poolEnds = 0; poolsCreated = 0;
  pool = null;
  db();
}
function dbError(code) { const e = new Error('simulated ' + code); e.code = code; return e; }
function dbErrno(errno) { const e = new Error('simulated errno'); e.errno = errno; return e; }

(async () => {

  console.log('== succeeds on the first attempt');
  reset();
  let calls = 0;
  let out = await withDbRetry('nextTarget', async () => { calls++; return { id: 41 }; });
  check('returns the value', out, { id: 41 });
  check('called once', calls, 1);
  check('did not sleep', slept, []);
  check('pool was not discarded', poolEnds, 0);

  console.log('== recovers after two dropped connections');
  reset();
  calls = 0;
  out = await withDbRetry('nextTarget', async () => {
    db();
    calls++;
    if (calls < 3) throw dbError('ECONNRESET');
    return { id: 42 };
  });
  check('returns the value', out, { id: 42 });
  check('called three times', calls, 3);
  check('backoff was 1s then 4s', slept, [1000, 4000]);
  check('pool discarded on each failure', poolEnds, 2);
  check('pool rebuilt for each retry', poolsCreated, 3);
  check('two retries logged', jlogs.length, 2);

  console.log('== gives up after three dropped connections');
  reset();
  calls = 0;
  let threw = null;
  try {
    await withDbRetry('recordAttempt', async () => { db(); calls++; throw dbError('PROTOCOL_CONNECTION_LOST'); });
  } catch (e) { threw = e.code; }
  check('rethrows the last error', threw, 'PROTOCOL_CONNECTION_LOST');
  check('called three times', calls, 3);
  check('slept only between attempts', slept, [1000, 4000]);
  check('pool discarded on all three failures', poolEnds, 3);

  console.log('== a SQL syntax error is not retried');
  reset();
  calls = 0;
  threw = null;
  try {
    await withDbRetry('nextTarget', async () => { calls++; throw dbError('ER_PARSE_ERROR'); });
  } catch (e) { threw = e.code; }
  check('rethrows immediately', threw, 'ER_PARSE_ERROR');
  check('called exactly once', calls, 1);
  check('did not sleep', slept, []);
  check('pool was not discarded', poolEnds, 0);

  console.log('== a constraint violation is not retried');
  reset();
  calls = 0;
  threw = null;
  try {
    await withDbRetry('recordAttempt', async () => { calls++; throw dbError('ER_DUP_ENTRY'); });
  } catch (e) { threw = e.code; }
  check('rethrows immediately', threw, 'ER_DUP_ENTRY');
  check('called exactly once', calls, 1);

  console.log('== an unknown-column error is not retried');
  reset();
  calls = 0;
  try {
    await withDbRetry('nextTarget', async () => { calls++; throw dbError('ER_BAD_FIELD_ERROR'); });
  } catch (e) { /* expected */ }
  check('called exactly once', calls, 1);

  console.log('== errno is honoured as well as code');
  reset();
  calls = 0;
  out = await withDbRetry('queueCounts', async () => {
    calls++;
    if (calls < 2) throw dbErrno('ETIMEDOUT');
    return { pending: 10660 };
  });
  check('retried on errno', out, { pending: 10660 });
  check('called twice', calls, 2);

  console.log('== every listed code is classified retryable');
  for (const code of RETRYABLE_DB_ERRORS) {
    check(code, isRetryableDbError(dbError(code)), true);
  }

  console.log('== SQL-level errors are classified not retryable');
  for (const code of ['ER_PARSE_ERROR', 'ER_DUP_ENTRY', 'ER_BAD_FIELD_ERROR',
                      'ER_NO_SUCH_TABLE', 'ER_ACCESS_DENIED_ERROR', 'ER_DATA_TOO_LONG',
                      // Raised by assertRemoteServer when the server is this PC.
                      // Retrying would only reach the same local server again.
                      'LOCAL_DB_REFUSED']) {
    check(code, isRetryableDbError(dbError(code)), false);
  }

  console.log('== a null or undefined error does not crash the classifier');
  check('null', isRetryableDbError(null), false);
  check('undefined', isRetryableDbError(undefined), false);
  check('plain Error', isRetryableDbError(new Error('no code')), false);

  console.log('');
  console.log(fails === 0 ? 'ALL PASS' : `${fails} FAILED`);
  process.exit(fails === 0 ? 0 : 1);
})();
