'use strict';
/**
 * Tests for the profile-likes action in instagram-follower.js (added 2026-10-15).
 *
 * Run with:  node tests/profile-likes-test.js
 *
 * Like tests/remote-db-guard-test.js, this suite keeps no copy of the code it
 * tests. It cuts two sections out of instagram-follower.js by their marker
 * comments and evaluates them with stubbed dependencies:
 *
 *   "profile likes: pure helpers"           parseRange, postCodeFromHref,
 *                                           visitDecision, profileLikeGate, shuffled
 *   "profile likes: browser and database"   pickProfileTarget, likeFromProfile
 *                                           and the database writes
 *
 * No browser, no MySQL and no network are needed. The page is a fake that
 * models a profile grid, the post dialog and its Like button; the database is
 * a fake pool that records every query.
 */

const fs = require('fs');
const path = require('path');

const SCRIPT = path.join(__dirname, '..', 'instagram-follower.js');
const source = fs.readFileSync(SCRIPT, 'utf8');

function cut(startMarker, endMarker, what) {
  const a = source.indexOf(startMarker);
  const b = source.indexOf(endMarker);
  if (a < 0 || b < 0 || b < a) {
    console.error(`Could not find the ${what} section in instagram-follower.js. Markers moved?`);
    process.exit(1);
  }
  return source.slice(a, b);
}

const PURE = cut('// ------------------------------------------------ profile likes: pure helpers',
                 '// ---------------------------------------- profile likes: browser and database', 'pure helpers');
const BROWSER = cut('// ---------------------------------------- profile likes: browser and database',
                    '// ------------------------------------------------------ where the script runs', 'browser and database');

let pass = 0, failN = 0;
function check(name, cond, detail) {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { failN++; console.log(`  FAIL  ${name}${detail ? `\n          ${detail}` : ''}`); }
}

// ---------------------------------------------------------------- pure helpers

let exitCode = null;
const fakeProcess = { exit: (c) => { exitCode = c; throw new Error('exit'); } };
const quietConsole = { error: () => {}, log: () => {} };
const pure = new Function('process', 'console',
  PURE + '\nreturn { parseRange, postCodeFromHref, visitDecision, profileLikeGate, shuffled };')(fakeProcess, quietConsole);

console.log('\nparseRange');
{
  const d = [1, 2];
  const r = pure.parseRange('', d, '--x');
  check('empty text gives the default', r[0] === 1 && r[1] === 2);
  check('the default is copied, not shared', r !== d);
  check('undefined gives the default', pure.parseRange(undefined, d, '--x').join() === '1,2');
  check('"2" gives [2, 2]', pure.parseRange('2', d, '--x').join() === '2,2');
  check('"1-3" gives [1, 3]', pure.parseRange('1-3', d, '--x').join() === '1,3');
  check('"3-1" is put in order', pure.parseRange('3-1', d, '--x').join() === '1,3');
  check('spaces are allowed', pure.parseRange(' 0 - 2 ', d, '--x').join() === '0,2');
  for (const bad of ['abc', '1-', '-2', '1.5', '1-2-3']) {
    exitCode = null;
    try { pure.parseRange(bad, d, '--x'); } catch (_) {}
    check(`"${bad}" exits with code 1`, exitCode === 1);
  }
}

console.log('\npostCodeFromHref');
{
  const p = pure.postCodeFromHref;
  check('/user/p/<code>/', p('/somebody/p/DPx_a-12/') === 'DPx_a-12');
  check('/user/reel/<code>/', p('/somebody/reel/DQ9zz/') === 'DQ9zz');
  check('/p/<code>/ (dialog URL)', p('/p/ABC123/') === 'ABC123');
  check('query string is dropped', p('/somebody/reel/XYZ/?img_index=1') === 'XYZ');
  check('full URL', p('https://www.instagram.com/p/Q1w/') === 'Q1w');
  check('profile URL gives null', p('/somebody/') === null);
  check('null gives null', p(null) === null);
}

console.log('\nvisitDecision');
{
  const v = pure.visitDecision;
  const cases = [
    ['own', 'Following', 'like'],
    ['own', 'Follow', 'not_following'],
    ['own', 'Requested', 'not_following'],
    ['own', null, 'no_button'],
    ['requested', 'Following', 'accepted'],
    ['requested', 'Requested', 'still_pending'],
    ['requested', 'Follow', 'request_gone'],
    ['requested', null, 'no_button'],
  ];
  for (const [kind, label, want] of cases) {
    const got = v(kind, label);
    check(`${kind} + ${label} -> ${want}`, got === want, `got ${got}`);
  }
}

console.log('\nprofileLikeGate');
{
  const L = { perRunMax: 15, totalPerHourMax: 10, minGapMs: 20 * 60000 };
  const now = 10 * 3600000;
  const base = { disabled: null, budget: 2, thisHour: 0, thisRun: 0, lastAttemptAt: 0 };
  const g = (over, total = 0) => pure.profileLikeGate({ ...base, ...over }, total, now, L);
  check('fresh state is allowed', g({}).ok === true);
  check('disabled blocks, with its reason', g({ disabled: 'x' }).ok === false && g({ disabled: 'x' }).reason === 'x');
  check('run maximum blocks', g({ thisRun: 15 }).ok === false);
  check('one under the run maximum is allowed', g({ thisRun: 14 }).ok === true);
  check('hourly budget used blocks', g({ thisHour: 2 }).ok === false);
  check('budget 0 blocks', g({ budget: 0 }).ok === false);
  check('total likes at 10 blocks', g({}, 10).ok === false);
  check('total likes at 9 is allowed', g({}, 9).ok === true);
  check('19 min after the last visit blocks', g({ lastAttemptAt: now - 19 * 60000 }).ok === false);
  check('20 min after the last visit is allowed', g({ lastAttemptAt: now - 20 * 60000 }).ok === true);
}

console.log('\nshuffled');
{
  const a = [1, 2, 3, 4, 5, 6];
  const s = pure.shuffled(a);
  check('same elements', s.slice().sort().join() === a.join());
  check('input not changed', a.join() === '1,2,3,4,5,6');
  let moved = false;
  for (let i = 0; i < 50 && !moved; i++) moved = pure.shuffled(a).join() !== a.join();
  check('order changes', moved);
}

console.log('\npacing over 12 simulated hours (gate as the main loop uses it)');
{
  const L = { perRunMax: 15, totalPerHourMax: 10, minGapMs: 20 * 60000 };
  const st = { disabled: null, budget: 0, thisHour: 0, thisRun: 0, lastAttemptAt: 0 };
  const likesAt = [];
  let perHour = [];
  for (let h = 0; h < 12; h++) {
    st.budget = 1 + Math.floor(Math.random() * 2); st.thisHour = 0;
    let n = 0;
    for (let t = h * 3600000; t < (h + 1) * 3600000; t += 15000) {   // a filler slot every 15 s
      if (pure.profileLikeGate(st, 0, t, L).ok && Math.random() < 0.25) {
        st.lastAttemptAt = t; st.thisHour++; st.thisRun++; n++; likesAt.push(t);
      }
    }
    perHour.push(n);
  }
  let minGap = Infinity;
  for (let i = 1; i < likesAt.length; i++) minGap = Math.min(minGap, likesAt[i] - likesAt[i - 1]);
  check('never more than 2 in an hour', perHour.every((n) => n <= 2), perHour.join(','));
  check('at least 20 min between likes', minGap >= 20 * 60000, `${minGap / 60000} min`);
  check('stops at 15 for the run', likesAt.length === 15, `${likesAt.length}`);
}

// ------------------------------------------------------- browser and database

const POST_BAR_TOKEN = 'section:not(:has(section))';

/** A fake Instagram profile page. */
function fakePage(opts) {
  const st = {
    url: null,
    tiles: opts.tiles || [],
    open: null,                                 // href of the open post, or null
    igLiked: new Set(opts.igLiked || []),       // codes Instagram shows as liked
    registers: opts.registers !== false,
    blockAfterLike: !!opts.blockAfterLike,
    blocked: false,
    clicks: [],
    gotos: [],
  };
  const code = (h) => (/\/(?:p|reel)\/([A-Za-z0-9_-]+)/.exec(h) || [])[1];
  function locator(sel) {
    const self = {
      first: () => self,
      async count() {
        if (sel === '[role="dialog"]') return st.open ? 1 : 0;
        if (sel.includes(POST_BAR_TOKEN) && sel.endsWith('svg[aria-label="Unlike"]')) {
          return st.open && st.igLiked.has(code(st.open)) ? 1 : 0;
        }
        if (sel.includes(POST_BAR_TOKEN) && sel.includes('div[role="button"]')) {
          return st.open && !st.igLiked.has(code(st.open)) ? 1 : 0;
        }
        throw new Error(`fake page: unexpected count() selector ${sel}`);
      },
      async click() {
        const m = /^main a\[href="([^"]+)"\]$/.exec(sel);
        if (m) {
          if (!st.tiles.includes(m[1])) throw new Error(`fake page: no tile ${m[1]}`);
          st.clicks.push({ tile: m[1] });
          if (!(opts.wontOpen || []).includes(m[1])) st.open = m[1];
          return;
        }
        if (sel.includes(POST_BAR_TOKEN) && sel.includes('div[role="button"]')) {
          st.clicks.push({ like: code(st.open) });
          if (st.registers) st.igLiked.add(code(st.open));
          if (st.blockAfterLike) st.blocked = true;
          return;
        }
        throw new Error(`fake page: unexpected click() selector ${sel}`);
      },
      async waitFor() {
        if (sel.includes(POST_BAR_TOKEN) && st.open) return;
        throw new Error('timeout');
      },
      async evaluateAll(fn, arg) {
        return fn(st.tiles.map((h) => ({ getAttribute: () => h })), arg);
      },
    };
    return self;
  }
  const page = {
    st,
    locator,
    async goto(url) { st.url = url; st.gotos.push(url); st.open = null; },
    keyboard: { async press(k) { if (k === 'Escape') st.open = null; } },
    mouse: { async wheel() {} },
  };
  return page;
}

/** A fake mysql pool that records queries. */
function fakeDb(opts) {
  const q = [];
  const conn = {
    async query(sql, params) {
      q.push({ sql, params });
      if (opts.failWrites && /^\s*(INSERT|UPDATE)/.test(sql)) {
        const e = new Error('write failed'); e.code = 'ER_FAKE'; throw e;
      }
      if (sql.includes('information_schema')) return [[{ tables_n: 2, col_n: 1 }]];
      if (sql.includes('SELECT x.* FROM')) {
        const t = opts.targets ? opts.targets.shift() : null;
        return [t ? [t] : []];
      }
      if (sql.includes('SELECT post_code FROM ig_post_likes')) {
        return [(opts.dbLiked || []).filter((c) => params[0].includes(c)).map((c) => ({ post_code: c }))];
      }
      return [{ affectedRows: 1 }];
    },
  };
  return { q, db: async () => conn };
}

class ActionBlocked extends Error {}

function load(opts) {
  const page = fakePage(opts);
  const fdb = fakeDb(opts);
  const logs = [];
  const jlogs = [];
  const deps = {
    db: fdb.db,
    withDbRetry: async (label, fn) => fn(),
    nyAt: (ms) => new Date(ms).toISOString().replace('T', ' ').slice(0, 19),
    nyNow: () => '2026-10-15 12:00:00',
    log: (tag, msg) => logs.push(`${tag} ${msg}`),
    jlog: (e) => jlogs.push(e),
    sleep: async () => {},
    rand: (a) => a,
    randInt: (a) => a,
    waitForProfile: async () => opts.profile || { state: 'ready', found: { label: 'Following' } },
    isActionBlocked: async () => page.st.blocked,
    shot: async (p, name) => `${name}.png`,
    ActionBlocked,
    DRY_RUN: !!opts.dryRun,
    ACCOUNT: 'verifiedbotanicals',
    PROFILE_REVISIT_DAYS: 14,
    PROFILE_RECENT_TILES: 6,
    PROFILE_TRIES: 2,
    visitDecision: pure.visitDecision,
    postCodeFromHref: pure.postCodeFromHref,
    shuffled: opts.noShuffle ? (a) => a.slice() : pure.shuffled,
  };
  const names = Object.keys(deps);
  const mod = new Function(...names,
    BROWSER + `
    return { likeFromProfile, pickProfileTarget, checkProfileLikesSchema, recordPostLike,
             addOwnFollowing, profileVisitedThisRun,
             PROFILE_TILE_SEL, POST_BAR_SEL };`)(...names.map((n) => deps[n]));
  return { mod, page, q: fdb.q, logs, jlogs };
}

const writes = (q) => q.filter((x) => /^\s*(INSERT|UPDATE)/.test(x.sql));
const likesInserted = (q) => q.filter((x) => x.sql.includes('INTO ig_post_likes'));
const own = (u, extra = {}) => ({ kind: 'own', ig_user_id: '111', username: u, account_id: null, is_private: 0, ...extra });
const req = (u, extra = {}) => ({ kind: 'requested', ig_user_id: '222', username: u, account_id: 42, is_private: 1, ...extra });
const tiles = (u, n) => Array.from({ length: n }, (_, i) => `/${u}/${i % 3 === 2 ? 'reel' : 'p'}/C${i}/`);

(async () => {
  console.log('\nselectors');
  {
    const { mod } = load({});
    check('tile selector covers posts and reels', mod.PROFILE_TILE_SEL === 'main a[href*="/p/"], main a[href*="/reel/"]');
    check('action bar is scoped to the dialog', mod.POST_BAR_SEL.startsWith('[role="dialog"] section:not(:has(section))'));
    check('action bar needs the Comment icon', mod.POST_BAR_SEL.includes('svg[aria-label="Comment"]'));
  }

  console.log('\nown account, Following: likes one of the 6 most recent, never one already liked');
  {
    const seen = new Set();
    let allOk = true;
    for (let i = 0; i < 40; i++) {
      const t = load({ targets: [own('alice')], tiles: tiles('alice', 9), dbLiked: ['C1', 'C4'] });
      await t.mod.checkProfileLikesSchema();
      const r = await t.mod.likeFromProfile(t.page);
      const liked = t.page.st.clicks.filter((c) => c.like).map((c) => c.like);
      if (!r.liked || liked.length !== 1) { allOk = false; break; }
      seen.add(liked[0]);
      const ins = likesInserted(t.q);
      if (ins.length !== 1 || ins[0].params[0] !== liked[0] || ins[0].params[2] !== 'profile') allOk = false;
    }
    check('each run likes exactly one post and records it as profile', allOk);
    check('never a tile past the 6 most recent', ![...seen].some((c) => ['C6', 'C7', 'C8'].includes(c)), [...seen].join());
    check('never a post ig_post_likes already holds', !seen.has('C1') && !seen.has('C4'), [...seen].join());
    check('the pick varies between runs', seen.size >= 2, [...seen].join());

    const t = load({ targets: [own('alice')], tiles: tiles('alice', 3), noShuffle: true });
    await t.mod.checkProfileLikesSchema();
    await t.mod.likeFromProfile(t.page);
    check('opens the profile URL', t.page.st.gotos[0] === 'https://www.instagram.com/alice/');
    const visit = t.q.find((x) => x.sql.includes('UPDATE ig_own_following'));
    check('records the visit with last_liked_at', visit && visit.params[2] === 1 && visit.params[4] === '111');
    check('post dialog closed at the end', t.page.st.open === null);
    check('jlog has the like', t.jlogs.some((e) => e.action === 'profile-like' && e.ok === true));
  }

  console.log('\npost already liked on Instagram but not in the database');
  {
    const t = load({ targets: [own('bob')], tiles: tiles('bob', 2), igLiked: ['C0'], noShuffle: true });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    const likeClicks = t.page.st.clicks.filter((c) => c.like);
    check('skips it and likes the next one', r.liked && likeClicks.length === 1 && likeClicks[0].like === 'C1');
    check('the skipped post is not clicked Like (no unlike)', !likeClicks.some((c) => c.like === 'C0'));

    const t2 = load({ targets: [own('bob')], tiles: tiles('bob', 4), igLiked: ['C0', 'C1', 'C2', 'C3'] });
    await t2.mod.checkProfileLikesSchema();
    const r2 = await t2.mod.likeFromProfile(t2.page);
    check('all liked: nothing liked, no Like click', !r2.liked && !t2.page.st.clicks.some((c) => c.like));
    check(`stops after ${2} tries`, t2.page.st.clicks.filter((c) => c.tile).length === 2);
    const note = t2.q.find((x) => x.sql.includes('UPDATE ig_own_following'));
    check('visit recorded without a like', note && note.params[2] === 0 && /already liked/.test(note.params[1]));
  }

  console.log('\npost that will not open');
  {
    const t = load({ targets: [own('cy')], tiles: tiles('cy', 2), wontOpen: ['/cy/p/C0/'], noShuffle: true });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    check('moves on to the next post and likes it', r.liked && t.page.st.clicks.some((c) => c.like === 'C1'));
  }

  console.log('\npending request, button Requested');
  {
    const t = load({ targets: [req('dee')], tiles: [], profile: { state: 'ready', found: { label: 'Requested' } } });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    const w = writes(t.q);
    check('nothing liked, nothing clicked', !r.liked && t.page.st.clicks.length === 0);
    check('only request_checked_at is written', w.length === 1 && /SET request_checked_at/.test(w[0].sql) && w[0].params[1] === 42);
  }

  console.log('\npending request, button Follow (declined or withdrawn)');
  {
    const t = load({ targets: [req('eve')], tiles: tiles('eve', 3), profile: { state: 'ready', found: { label: 'Follow' } } });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    const w = writes(t.q);
    check('nothing liked, nothing clicked', !r.liked && t.page.st.clicks.length === 0);
    check('follow_status is not changed', !w.some((x) => /follow_status/.test(x.sql)));
    check('request_checked_at is written', w.length === 1 && /request_checked_at/.test(w[0].sql));
  }

  console.log('\npending request, button Following (accepted)');
  {
    const t = load({ targets: [req('fay')], tiles: tiles('fay', 3), profile: { state: 'ready', found: { label: 'Following' } } });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    const upd = t.q.find((x) => /SET follow_status = 'followed'/.test(x.sql));
    check('queue row set to followed, only if still requested', upd && /follow_status = 'requested'/.test(upd.sql) && upd.params[2] === 42);
    check('note says request accepted', upd && /^request accepted, seen /.test(upd.params[0]));
    const ins = t.q.find((x) => x.sql.includes('INSERT INTO ig_own_following'));
    check('added to ig_own_following as request_accepted, private', ins && ins.params[0] === '222' && ins.params[2] === 1 && ins.params[3] === 'request_accepted');
    check('then likes a post', r.liked && likesInserted(t.q).length === 1);
  }

  console.log('\nown account we no longer follow, and no button');
  {
    const t = load({ targets: [own('gus')], tiles: tiles('gus', 3), profile: { state: 'ready', found: { label: 'Follow' } } });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    const note = t.q.find((x) => x.sql.includes('UPDATE ig_own_following'));
    check('Follow: nothing clicked, never clicks Follow', !r.liked && t.page.st.clicks.length === 0);
    check('Follow: visit noted as not followed', note && note.params[1] === 'not followed any more');

    const noBtn = { state: 'timeout', found: { btn: null, label: null, seen: [] } };
    const t2 = load({ targets: [own('gus')], tiles: tiles('gus', 3), profile: noBtn });
    await t2.mod.checkProfileLikesSchema();
    const r2 = await t2.mod.likeFromProfile(t2.page);
    check('no button: nothing clicked', !r2.liked && t2.page.st.clicks.length === 0);
    const n2 = t2.q.find((x) => x.sql.includes('UPDATE ig_own_following'));
    check('no button: visit noted', n2 && n2.params[1] === 'no follow button');

    const t2b = load({ targets: [req('gil')], tiles: [], profile: noBtn });
    await t2b.mod.checkProfileLikesSchema();
    await t2b.mod.likeFromProfile(t2b.page);
    const w2b = writes(t2b.q);
    check('pending request, no button: check recorded so it is not picked again', w2b.length === 1 && /request_checked_at/.test(w2b[0].sql));

    const t2c = load({ targets: [own('gus')], tiles: tiles('gus', 3), profile: { state: 'ready', found: null } });
    await t2c.mod.checkProfileLikesSchema();
    const r2c = await t2c.mod.likeFromProfile(t2c.page);
    check('ready without a found object does not crash', !r2c.liked);

    const t3 = load({ targets: [own('gus')], tiles: [], profile: { state: 'missing' } });
    await t3.mod.checkProfileLikesSchema();
    await t3.mod.likeFromProfile(t3.page);
    const n3 = t3.q.find((x) => x.sql.includes('UPDATE ig_own_following'));
    check('missing profile: noted as not found', n3 && n3.params[1] === 'profile not found');
  }

  console.log('\nno posts visible');
  {
    const t = load({ targets: [own('hal')], tiles: [] });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    const note = t.q.find((x) => x.sql.includes('UPDATE ig_own_following'));
    check('nothing liked, visit noted', !r.liked && note && note.params[1] === 'no posts visible');
  }

  console.log('\nlike that does not register');
  {
    const t = load({ targets: [own('ivy')], tiles: tiles('ivy', 3), registers: false });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    check('returns failed so the run turns the action off', r.failed === true && !r.liked);
    check('nothing recorded in ig_post_likes', likesInserted(t.q).length === 0);
    check('clicked Like only once (no retry)', t.page.st.clicks.filter((c) => c.like).length === 1);
  }

  console.log('\naction block after the Like click');
  {
    const t = load({ targets: [own('jo')], tiles: tiles('jo', 3), blockAfterLike: true });
    await t.mod.checkProfileLikesSchema();
    let threw = null;
    try { await t.mod.likeFromProfile(t.page); } catch (e) { threw = e; }
    check('throws ActionBlocked', threw instanceof ActionBlocked);
    check('nothing recorded in ig_post_likes', likesInserted(t.q).length === 0);
  }

  console.log('\ndry run');
  {
    const t = load({ dryRun: true, targets: [own('kim')], tiles: tiles('kim', 3) });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    check('opens a post but does not click Like', t.page.st.clicks.some((c) => c.tile) && !t.page.st.clicks.some((c) => c.like));
    check('writes nothing', writes(t.q).length === 0 && !r.liked);

    const t2 = load({ dryRun: true, targets: [req('lu')], tiles: [], profile: { state: 'ready', found: { label: 'Following' } } });
    await t2.mod.checkProfileLikesSchema();
    await t2.mod.likeFromProfile(t2.page);
    check('accepted request in a dry run writes nothing', writes(t2.q).length === 0);
  }

  console.log('\nno account due');
  {
    const t = load({ targets: [] });
    await t.mod.checkProfileLikesSchema();
    const r = await t.mod.likeFromProfile(t.page);
    check('nothing opened', !r.liked && t.page.st.gotos.length === 0);
  }

  console.log('\npicking accounts');
  {
    const t = load({ targets: [own('mo'), own('ned', { ig_user_id: '333' })], tiles: tiles('x', 1) });
    await t.mod.checkProfileLikesSchema();
    await t.mod.likeFromProfile(t.page);
    await t.mod.likeFromProfile(t.page);
    const picks = t.q.filter((x) => x.sql.includes('SELECT x.* FROM'));
    check('first pick has no exclusion', !/NOT IN/.test(picks[0].sql) && picks[0].params.length === 3);
    check('second pick excludes the account visited this run', /NOT IN/.test(picks[1].sql) && picks[1].params[3].includes('111'));
    check('our own account is excluded', picks[0].params[2] === 'verifiedbotanicals');
    check('revisit cutoff is 14 days back', picks[0].params[0] === new Date(Date.now() - 14 * 86400000).toISOString().replace('T', ' ').slice(0, 19).slice(0, 13) + picks[0].params[0].slice(13));
    check('requests need no ig_own_following row', /NOT EXISTS \(SELECT 1 FROM ig_own_following/.test(picks[0].sql));
  }

  console.log('\nbest-effort writes');
  {
    const t = load({ failWrites: true });
    await t.mod.checkProfileLikesSchema();
    let threw = false;
    try { await t.mod.recordPostLike('C9', 'zed', 'feed'); } catch (_) { threw = true; }
    check('a failed write does not throw', !threw);
    check('the failure is logged', t.logs.some((l) => /recordPostLike not recorded/.test(l)));

    const t2 = load({});
    await t2.mod.addOwnFollowing({ ig_user_id: 5, username: 'zed', is_private: 0 }, 'follow_script');
    check('nothing is written before the schema check passes', writes(t2.q).length === 0);
    await t2.mod.checkProfileLikesSchema();
    await t2.mod.addOwnFollowing({ ig_user_id: 5, username: 'zed', is_private: 0 }, 'follow_script');
    const ins = writes(t2.q)[0];
    check('addOwnFollowing upserts with ig_user_id as text', ins && /ON DUPLICATE KEY UPDATE/.test(ins.sql) && ins.params[0] === '5' && ins.params[3] === 'follow_script');
    await t2.mod.addOwnFollowing({ ig_user_id: null, username: 'nobody' }, 'follow_script');
    check('no ig_user_id: nothing written', writes(t2.q).length === 1);
  }

  console.log(`\n${failN ? 'FAILED' : 'ALL PASS'}: ${pass} passed, ${failN} failed\n`);
  process.exit(failN ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
