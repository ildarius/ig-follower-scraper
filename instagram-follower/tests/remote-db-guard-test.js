'use strict';
/**
 * Tests for the safety checks in instagram-follower.js: the machine guard
 * (wrongMachineReasons), which stops the script anywhere but Ildar's Windows
 * PC; the SSH tunnel (ensureTunnel); the server identity guard
 * (assertRemoteServer); and the startup refusals in checkDbSettings.
 *
 * Run with:  node tests/remote-db-guard-test.js
 *
 * Unlike tests/db-retry-test.js, this suite does not keep a copy of the code it
 * tests. It reads instagram-follower.js, cuts out the section between the
 * "SSH tunnel" marker and "async function db()", and evaluates that section
 * with stubbed dependencies. A change to the script is therefore tested as it
 * is, with nothing to paste across. If the markers move, the suite fails
 * loudly rather than testing nothing.
 *
 * No MySQL server and no SSH server are needed. MySQL is replaced by a fake
 * pool whose query() returns a chosen server identity, and ssh by a fake child
 * process that opens (or fails to open) the tunnel port.
 */

const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');
const { EventEmitter } = require('events');
const { spawnSync } = require('child_process');

const SCRIPT = path.join(__dirname, '..', 'instagram-follower.js');
const source = fs.readFileSync(SCRIPT, 'utf8');

const START = '// ------------------------------------------------------------- SSH tunnel';
const END = 'async function db() {';
const a = source.indexOf(START);
const b = source.indexOf(END);
if (a < 0 || b < 0 || b < a) {
  console.error('Could not find the SSH tunnel section in instagram-follower.js. Markers moved?');
  process.exit(1);
}
const section = source.slice(a, b);

const M_START = '// ------------------------------------------------------ where the script runs';
const M_END = 'function assertRunningOnPc() {';
const ma = source.indexOf(M_START);
const mb = source.indexOf(M_END);
if (ma < 0 || mb < 0 || mb < ma) {
  console.error('Could not find the machine guard section in instagram-follower.js. Markers moved?');
  process.exit(1);
}
const wrongMachineReasons = new Function(source.slice(ma, mb) + '\nreturn wrongMachineReasons;')();

// Profile detection: findFollowButton and waitForProfile. The wait is cut from
// 15 s to 3 s so the timeout case does not slow the suite down.
const P_START = "const FOLLOW_STATE_LABELS = new Set(";
const P_END = '/**\n * Follow the next queued account.';
const pa = source.indexOf(P_START);
const pb = source.indexOf(P_END);
if (pa < 0 || pb < 0 || pb < pa) {
  console.error('Could not find the profile detection section in instagram-follower.js. Markers moved?');
  process.exit(1);
}
const profileSection = source.slice(pa, pb).replace('const PROFILE_WAIT_MS = 15000;', 'const PROFILE_WAIT_MS = 3000;');
if (!profileSection.includes('const PROFILE_WAIT_MS = 3000;')) {
  console.error('PROFILE_WAIT_MS not found where expected.');
  process.exit(1);
}
const { waitForProfile } = new Function('sleep',
  profileSection + '\nreturn { findFollowButton, waitForProfile };')((ms) => new Promise((r) => setTimeout(r, ms)));

/**
 * A stand-in for a Playwright page. `stages` is a list of { after, buttons },
 * the header button texts present from `after` ms onwards. `missingAfter` is
 * when the "Sorry, this page isn't available." text appears, or null.
 */
function fakeProfilePage(stages, missingAfter = null) {
  const t0 = Date.now();
  const current = () => {
    const el = Date.now() - t0;
    let b = [];
    for (const st of stages) if (el >= st.after) b = st.buttons;
    return b;
  };
  return {
    locator: () => ({
      count: async () => current().length,
      nth: (i) => ({ innerText: async () => current()[i] }),
    }),
    getByText: () => ({
      count: async () => (missingAfter !== null && Date.now() - t0 >= missingAfter ? 1 : 0),
    }),
  };
}

let fails = 0;
function check(name, actual, expected) {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (!ok) fails++;
  console.log(`  ${ok ? 'pass' : 'FAIL'}  ${name}${ok ? '' : `  expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`}`);
}

/** Evaluate the section with the given stubs and return its functions. */
function load(opts) {
  const fakeProcess = { on: () => {} };   // keep the exit hook out of the test process
  const factory = new Function(
    'net', 'os', 'spawn', 'log', 'jlog', 'sleep', 'process',
    'SSH', 'TUNNEL_LOCAL_PORT', 'REMOTE_MYSQL_PORT', 'TUNNEL_WAIT_MS', 'EXPECT_SERVER_HOSTNAME',
    section + '\nreturn { ensureTunnel, closeTunnel, assertRemoteServer, portAnswers, ' +
              'getTunnel: () => tunnel, getServerInfo: () => serverInfo };'
  );
  return factory(
    net, os, opts.spawn || (() => { throw new Error('spawn not expected'); }),
    () => {}, () => {}, (ms) => new Promise((r) => setTimeout(r, ms)), fakeProcess,
    { host: '66.206.23.226', user: 'root', port: 22, key: opts.key || '', exe: 'ssh' },
    opts.port, 3306, opts.waitMs || 5000, opts.expect || ''
  );
}

function fakePool(row) {
  return { query: async () => [[row]] };
}

function freePort() {
  return new Promise((resolve) => {
    const srv = net.createServer();
    srv.listen(0, '127.0.0.1', () => { const p = srv.address().port; srv.close(() => resolve(p)); });
  });
}

function listen(port) {
  return new Promise((resolve) => {
    const srv = net.createServer((sock) => sock.destroy());
    srv.listen(port, '127.0.0.1', () => resolve(srv));
  });
}

async function codeOf(promise) {
  try { await promise; return 'resolved'; } catch (e) { return e.code || e.message; }
}

const linux = { hostname: 'vps-bizousoft', compile_os: 'Linux', datadir: '/var/lib/mysql/', db: 'bizousoft_sunny_kratom', version: '8.0.39' };

(async () => {
  console.log('== machine guard: the PC is allowed');
  {
    const r = wrongMachineReasons({
      platform: 'win32',
      dir: 'C:\\Users\\ildar\\Claude\\All projects\\Sunny Kratom\\ig-follower-scraper\\instagram-follower',
      interfaces: { Ethernet: [{ address: '192.168.2.14' }, { address: 'fe80::1' }] },
    });
    check('no reasons on the PC', r, []);
  }

  console.log('== machine guard: the VPS is refused on every signal');
  {
    const r = wrongMachineReasons({
      platform: 'linux',
      dir: '/home/bizousoft/public_html/seo/ig-follower-scraper/instagram-follower',
      interfaces: { lo: [{ address: '127.0.0.1' }], eth0: [{ address: '66.206.23.226' }] },
    });
    check('three reasons', r.length, 3);
    check('VPS address named', /66\.206\.23\.226/.test(r[0]), true);
    check('deployment path named', /public_html/.test(r[1]), true);
  }

  console.log('== machine guard: each signal is enough on its own');
  {
    check('VPS address alone', wrongMachineReasons({
      platform: 'win32', dir: 'C:\\x', interfaces: { eth0: [{ address: '66.206.23.226' }] },
    }).length, 1);
    check('VPS path alone', wrongMachineReasons({
      platform: 'win32', dir: '/home/bizousoft/anything', interfaces: {},
    }).length, 1);
    check('another public_html deployment', wrongMachineReasons({
      platform: 'win32', dir: '/var/www/public_html/ig-follower-scraper/instagram-follower', interfaces: {},
    }).length, 1);
    const wsl = wrongMachineReasons({
      platform: 'linux',
      dir: '/mnt/c/Users/ildar/Claude/All projects/Sunny Kratom/ig-follower-scraper/instagram-follower',
      interfaces: { eth0: [{ address: '172.20.1.5' }] },
    });
    check('WSL on the PC refused', wsl.length, 1);
    check('WSL told to use PowerShell', /PowerShell/.test(wsl[0]), true);
    check('missing interfaces handled', wrongMachineReasons({ platform: 'win32', dir: 'C:\\x' }), []);
  }

  console.log('== profile detection');
  {
    let r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: ['site.com and 1 more', 'Follow'] }]));
    check('multi-link profile: Follow found after the links button', [r.state, r.found.label], ['ready', 'Follow']);

    r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: [] }, { after: 1500, buttons: ['Following'] }]));
    check('slow load is waited for, not called missing', [r.state, r.found.label], ['ready', 'Following']);

    r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: [] }], 400));
    check('missing profile text is recognised', r.state, 'missing');

    r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: ['linktr.ee/x and 3 more'] }]));
    check('no follow button times out', r.state, 'timeout');
    check('timeout reports the buttons it saw', r.found.seen, ['linktr.ee/x and 3 more']);

    r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: ['Follow back'] }]));
    check('Follow back counts as Follow', r.found.label, 'Follow');

    r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: ['Requested'] }]));
    check('Requested recognised', r.found.label, 'Requested');

    r = await waitForProfile(fakeProfilePage([{ after: 0, buttons: ['Followingg'] }]));
    check('near-miss text is not accepted', r.state, 'timeout');
  }

  console.log('== server guard accepts the VPS');
  {
    const m = load({ port: 1 });
    check('linux server accepted', await codeOf(m.assertRemoteServer(fakePool(linux))), 'resolved');
    check('server identity recorded', m.getServerInfo().hostname, 'vps-bizousoft');
  }

  console.log('== server guard refuses anything that looks like this PC');
  {
    const m = load({ port: 1 });
    check('Windows build refused',
      await codeOf(m.assertRemoteServer(fakePool({ ...linux, compile_os: 'Win64' }))), 'LOCAL_DB_REFUSED');
    check('Laragon data directory refused',
      await codeOf(m.assertRemoteServer(fakePool({ ...linux, datadir: 'C:\\laragon\\data\\mysql-8\\' }))), 'LOCAL_DB_REFUSED');
    check('any Windows drive data directory refused',
      await codeOf(m.assertRemoteServer(fakePool({ ...linux, datadir: 'D:/mysql/data/' }))), 'LOCAL_DB_REFUSED');
    check('this PC hostname refused, case-insensitive',
      await codeOf(m.assertRemoteServer(fakePool({ ...linux, hostname: os.hostname().toUpperCase() }))), 'LOCAL_DB_REFUSED');
    check('nothing recorded after refusals', m.getServerInfo(), null);
  }

  console.log('== DB_EXPECT_SERVER_HOSTNAME pins the server');
  {
    check('mismatch refused',
      await codeOf(load({ port: 1, expect: 'other-host' }).assertRemoteServer(fakePool(linux))), 'LOCAL_DB_REFUSED');
    check('match accepted',
      await codeOf(load({ port: 1, expect: 'vps-bizousoft' }).assertRemoteServer(fakePool(linux))), 'resolved');
  }

  console.log('== tunnel: a port that already answers is reused, ssh is not started');
  {
    const port = await freePort();
    const srv = await listen(port);
    let spawned = 0;
    const m = load({ port, spawn: () => { spawned++; } });
    check('resolved', await codeOf(m.ensureTunnel()), 'resolved');
    check('ssh not started', spawned, 0);
    srv.close();
  }

  console.log('== tunnel: ssh is started with the right arguments and waited for');
  {
    const port = await freePort();
    let args = null;
    let opts = null;
    let srv = null;
    const m = load({
      port,
      key: 'C:\\Users\\ildar\\.ssh\\id_ed25519',
      spawn: (exe, a, o) => {
        args = a;
        opts = o;
        const child = new EventEmitter();
        child.exitCode = null;
        child.signalCode = null;
        child.kill = () => { child.signalCode = 'SIGTERM'; };
        setTimeout(async () => { srv = await listen(port); }, 300);
        return child;
      },
    });
    check('resolved once the port opens', await codeOf(m.ensureTunnel()), 'resolved');
    check('ExitOnForwardFailure set', args.includes('ExitOnForwardFailure=yes'), true);
    check('BatchMode set, so ssh never prompts', args.includes('BatchMode=yes'), true);
    check('ssh detached from this console', opts.detached, true);
    check('ssh window hidden', opts.windowsHide, true);
    check('ssh stdin not shared', opts.stdio[0], 'ignore');
    check('forward bound to 127.0.0.1 only', args[args.indexOf('-L') + 1], `127.0.0.1:${port}:127.0.0.1:3306`);
    check('key passed with -i', args[args.indexOf('-i') + 1], 'C:\\Users\\ildar\\.ssh\\id_ed25519');
    check('destination last', args[args.length - 1], 'root@66.206.23.226');
    check('tunnel tracked', m.getTunnel() !== null, true);
    m.closeTunnel();
    check('tunnel closed', m.getTunnel(), null);
    srv.close();
  }

  console.log('== tunnel: ssh exiting early is TUNNEL_FAILED');
  {
    const port = await freePort();
    const m = load({
      port,
      spawn: () => {
        const child = new EventEmitter();
        child.exitCode = null;
        child.signalCode = null;
        child.kill = () => {};
        setTimeout(() => { child.exitCode = 255; }, 200);
        return child;
      },
    });
    check('rejected', await codeOf(m.ensureTunnel()), 'TUNNEL_FAILED');
    check('tunnel cleared', m.getTunnel(), null);
  }

  console.log('== tunnel: ssh missing is TUNNEL_FAILED');
  {
    const port = await freePort();
    const m = load({
      port,
      spawn: () => {
        const child = new EventEmitter();
        child.exitCode = null;
        child.signalCode = null;
        child.kill = () => {};
        setTimeout(() => child.emit('error', Object.assign(new Error('spawn ssh ENOENT'), { code: 'ENOENT' })), 50);
        return child;
      },
    });
    check('rejected', await codeOf(m.ensureTunnel()), 'TUNNEL_FAILED');
  }

  console.log('== tunnel: never coming up times out as TUNNEL_FAILED');
  {
    const port = await freePort();
    let killed = false;
    const m = load({
      port,
      waitMs: 1500,
      spawn: () => {
        const child = new EventEmitter();
        child.exitCode = null;
        child.signalCode = null;
        child.kill = () => { killed = true; child.signalCode = 'SIGTERM'; };
        return child;
      },
    });
    check('rejected', await codeOf(m.ensureTunnel()), 'TUNNEL_FAILED');
    check('ssh killed', killed, true);
  }

  console.log('== startup');
  {
    const run = (env) => spawnSync(process.execPath, [SCRIPT, '--check-db'], {
      env: {
        ...process.env, DB_NAME: '', DB_USER: '', DB_PASS: '', DB_TUNNEL_LOCAL_PORT: '',
        IG_FOLLOWER_RUN_LOG: path.join(os.tmpdir(), 'ig-follower-test-run-log.jsonl'),
        ...env,
      },
      encoding: 'utf8',
      timeout: 20000,
    });

    if (process.platform !== 'win32') {
      // The machine guard runs first and stops everything off Windows, which is
      // the point. The settings refusals below can only be reached on the PC.
      const r = run({ DB_NAME: 'x', DB_USER: 'x', DB_PASS: 'x' });
      check('non-Windows exit 1', r.status, 1);
      check('non-Windows refused before any setting is read', /STOP\. instagram-follower\.js only runs/.test(r.stderr), true);
      console.log('  (settings refusals are checked when this suite runs on the Windows PC)');
    } else {
      let r = run({});
      check('missing credentials exit 1', r.status, 1);
      check('missing credentials named', /DB_NAME, DB_USER, DB_PASS are not set/.test(r.stderr), true);

      r = run({ DB_NAME: 'x', DB_USER: 'x', DB_PASS: 'x', DB_TUNNEL_LOCAL_PORT: '3306' });
      check('port 3306 exit 1', r.status, 1);
      check('port 3306 explained', /Laragon's MySQL port/.test(r.stderr), true);
    }
  }

  console.log('');
  console.log(fails === 0 ? 'ALL PASS' : `${fails} FAILED`);
  process.exit(fails === 0 ? 0 : 1);
})();
