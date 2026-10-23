'use strict';
/**
 * Tests for watchStories() in instagram-follower.js (rebuilt 2026-10-22).
 *
 * Run with:  node tests/stories-test.js
 *
 * Like tests/profile-likes-test.js, this suite keeps no copy of the code it
 * tests. It cuts two sections out of instagram-follower.js by their marker
 * comments and evaluates them with stubbed dependencies:
 *
 *   "stories: pure helpers"   parseTrayJson, pickStoryTarget, nextClicksFor,
 *                             storyUserFromUrl, pruneWatched, parseRingLabel
 *   "stories: browser"        watchStories and what it calls
 *
 * No browser, no network and no database are needed. The page is a fake that
 * models the virtualized tray (rings 104 px apart, a 630 px wide scroller and a
 * Next button that scrolls 416 px), the tray JSON in the home page, and the
 * story viewer. The file system is a fake that holds story-watched.json.
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

const PURE = cut('// ----------------------------------------------------------- stories: pure helpers',
                 '// --------------------------------------------------------------- stories: browser', 'pure helpers');
const BROWSER = cut('// --------------------------------------------------------------- stories: browser',
                    '// -------------------------------------------------------- chrome plumbing', 'browser');

let pass = 0, failN = 0;
function check(name, cond, detail) {
  if (cond) { pass++; console.log(`  ok    ${name}`); }
  else { failN++; console.log(`  FAIL  ${name}${detail ? `\n          ${detail}` : ''}`); }
}

const IG_URL = 'https://www.instagram.com/';
const STATE_FILE = 'C:\\fake\\story-watched.json';
const CONSTANTS = {
  STORY_RING_PITCH_PX: 104,
  STORY_TRAY_NEXT_PX: 416,
  STORY_VISIBLE_PX: 520,
  STORY_NEXT_CLICKS_MAX: 40,
  STORY_MIN_LIFETIME_S: 300,
  STORY_REMEMBER_MS: 24 * 3600 * 1000,
};

const nowSec = () => Math.floor(Date.now() / 1000);

// ------------------------------------------------------------------- fixtures

/** A tray entry shaped like the real one (story-tray-findings.md section 2, C3). */
function entry(rank, username, over = {}) {
  const latest = nowSec() - 3600;
  return {
    id: String(1000 + rank), reel_type: 'user_reel', has_besties_media: false, latest_besties_reel_media: 0,
    muted: false, latest_reel_media: latest, seen: 0, expiring_at: latest + 86400,
    ranked_position: rank, seen_ranked_position: rank,
    user: { pk: String(1000 + rank), username, profile_pic_url: 'https://x/y.jpg?a={b}&c="d"',
            full_name: 'Brace } { quote \\" backslash \\\\ end', is_unpublished: false },
    __typename: 'XDTReelDict',
    ...over,
  };
}

/** The text of the real <script> element: prefix, the tray object, suffix. */
function trayScript(entries) {
  const prefix = '{"require":[["ScheduledServerJS","handle",null,[{"__bbox":{"require":[["RelayPrefetchedStreamCache","next",[],' +
    '["adp_PolarisStoriesV3TrayContainerQueryRelayPreloader_abc",{"__bbox":{"complete":true,"result":{"data":';
  const tray = JSON.stringify({ xdt_api__v1__feed__reels_tray: { broadcasts: [], tray: entries } });
  return prefix + tray + '}}}]]]}}]]]}';
}

function trayOf(n, over = {}) {
  const list = [];
  for (let r = 1; r <= n; r++) list.push(entry(r, `user${r}`, over[r] || {}));
  return list;
}

// -------------------------------------------------------------- pure helpers

const pure = new Function(...Object.keys(CONSTANTS),
  PURE + '\nreturn { parseTrayJson, pickStoryTarget, nextClicksFor, storyUserFromUrl, pruneWatched, parseRingLabel };')
  (...Object.values(CONSTANTS));

console.log('\nparseTrayJson');
{
  const list = [entry(1, 'alpha'), entry(2, 'beta', { muted: true }), entry(3, 'gamma', { seen: 5 })];
  const r = pure.parseTrayJson(trayScript(list));
  check('three entries', Array.isArray(r) && r.length === 3, JSON.stringify(r));
  check('fields mapped', r && r[0].username === 'alpha' && r[0].rank === 1 && r[0].muted === false &&
    r[0].unseen === true && r[0].reelType === 'user_reel' && r[0].expiresAt === list[0].expiring_at);
  check('muted read from the data', r && r[1].muted === true);
  check('partly seen is still unseen', r && r[2].unseen === true && r[2].partlySeen === true);
  check('never seen is not partly seen', r && r[0].partlySeen === false);
  check('fully seen is not unseen', pure.parseTrayJson(trayScript([entry(1, 'a', { seen: nowSec() - 3600 })]))[0].unseen === false);
  check('key absent gives null', pure.parseTrayJson('{"require":[["ScheduledServerJS"]]}') === null);
  const text = trayScript(list);
  check('truncated mid-object gives null', pure.parseTrayJson(text.slice(0, text.indexOf('"gamma"'))) === null);
  check('not a string gives null', pure.parseTrayJson(null) === null);
  check('broken JSON inside balanced braces gives null',
    pure.parseTrayJson('{"xdt_api__v1__feed__reels_tray":{"tray":[1,]}}') === null);
  check('empty tray gives []', JSON.stringify(pure.parseTrayJson(trayScript([]))) === '[]');
}

console.log('\npickStoryTarget');
{
  const now = nowSec();
  const e = (rank, username, over = {}) => ({ username, rank, muted: false, unseen: true, partlySeen: false,
    expiresAt: now + 7200, reelType: 'user_reel', ...over });
  const none = new Set();
  check('lowest rank wins', pure.pickStoryTarget([e(3, 'c'), e(1, 'a'), e(2, 'b')], none, now).username === 'a');
  check('muted skipped', pure.pickStoryTarget([e(1, 'a', { muted: true }), e(2, 'b')], none, now).username === 'b');
  check('fully seen skipped', pure.pickStoryTarget([e(1, 'a', { unseen: false }), e(2, 'b')], none, now).username === 'b');
  check('expiring in under 300 s skipped', pure.pickStoryTarget([e(1, 'a', { expiresAt: now + 299 }), e(2, 'b')], none, now).username === 'b');
  check('expiring in 301 s kept', pure.pickStoryTarget([e(1, 'a', { expiresAt: now + 301 })], none, now).username === 'a');
  check('non user_reel skipped', pure.pickStoryTarget([e(1, 'a', { reelType: 'mas_reel' }), e(2, 'b')], none, now).username === 'b');
  check('watched skipped', pure.pickStoryTarget([e(1, 'a'), e(2, 'b')], new Set(['a']), now).username === 'b');
  check('watched compared lowercased', pure.pickStoryTarget([e(1, 'Alpha'), e(2, 'b')], new Set(['alpha']), now).username === 'b');
  check('odd username skipped', pure.pickStoryTarget([e(1, 'a"]b'), e(2, 'b')], none, now).username === 'b');
  check('missing rank skipped', pure.pickStoryTarget([e(undefined, 'a'), e(2, 'b')], none, now).username === 'b');
  check('nothing qualifies gives null', pure.pickStoryTarget([e(1, 'a', { muted: true })], none, now) === null);
  check('empty list gives null', pure.pickStoryTarget([], none, now) === null);
}

console.log('\nnextClicksFor');
for (const [rank, want] of [[1, 0], [6, 0], [7, 1], [15, 3], [132, 32]]) {
  check(`rank ${rank} -> ${want}`, pure.nextClicksFor(rank) === want, `got ${pure.nextClicksFor(rank)}`);
}

console.log('\nstoryUserFromUrl');
{
  const f = pure.storyUserFromUrl;
  check('first frame', f('/stories/emmyrobbindoula/') === 'emmyrobbindoula');
  check('later frame', f('/stories/kevin49206/4013158056458687666/') === 'kevin49206');
  check('card href with ?r=1', f('/stories/reidysweedy/4013693858641616298/?r=1') === 'reidysweedy');
  check('full URL', f('https://www.instagram.com/stories/logan_rainer/4013464681930938473/') === 'logan_rainer');
  check('dots in the name', f('https://www.instagram.com/stories/veronica.r.flores1/') === 'veronica.r.flores1');
  check('home page gives null', f('https://www.instagram.com/') === null);
  check('null gives null', f(null) === null);
}

console.log('\npruneWatched');
{
  const now = Date.now();
  const r = pure.pruneWatched({ old: now - 25 * 3600000, recent: now - 23 * 3600000, Mixed: now - 1000, junk: 'x' }, now);
  check('25 h old dropped', !('old' in r));
  check('23 h old kept', r.recent === now - 23 * 3600000);
  check('names lowercased', 'mixed' in r && !('Mixed' in r));
  check('non-number dropped', !('junk' in r));
  check('array gives {}', JSON.stringify(pure.pruneWatched([1, 2], now)) === '{}');
  check('null gives {}', JSON.stringify(pure.pruneWatched(null, now)) === '{}');
}

console.log('\nparseRingLabel');
{
  const p = pure.parseRingLabel;
  check('not seen', JSON.stringify(p('Story by emmyrobbindoula, not seen')) === '{"username":"emmyrobbindoula","seen":false}');
  check('seen', p('Story by bobbie.b.720, seen').seen === true);
  check('other text gives null', p('Your story') === null);
}

// ------------------------------------------------------------------- browser

const TRAY_X = 100, TRAY_W = 630, RING_W = 89;
const NEXT_SEL = 'div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]';
const READY_SEL = 'div[role="presentation"] ul li canvas';
const SCROLLER_SEL = 'div[role="presentation"]:has(ul li canvas)';
const ANY_RING_SEL = 'ul li div[role="button"][aria-label^="Story by "]';
const RING_RE = /^ul li div\[role="button"\]\[aria-label\^="Story by ([^,"]+),"\]$/;

/**
 * A fake Instagram home page and story viewer.
 *
 * opts.tray        tray entries (raw, as in the page JSON)
 * opts.noTrayData  the page has no tray <script>
 * opts.frames      { username: frame count }, default 5
 * opts.framesNull  countFrames finds no progress bar
 * opts.expired     usernames whose story expired after the page loaded
 * opts.showInstead { username: other } the viewer opens on another account
 * opts.advanceAfter { username: n } after n frames the viewer moves to the next account
 * opts.labelSeen   usernames whose ring label reads "seen" (labels default to the data)
 */
function fakePage(opts) {
  const tray = opts.tray || [];
  const byRank = tray.slice().sort((a, b) => a.ranked_position - b.ranked_position);
  const st = {
    url: 'about:blank', title: '', scrollLeft: 0,
    viewer: null,              // { user, frame }
    clicks: [], keys: [], gotos: [],
  };
  const total = byRank.length;
  const maxScroll = Math.max(0, total * 104 - TRAY_W);
  const ringX = (rank) => TRAY_X + (rank - 1) * 104 - st.scrollLeft;
  // Virtualized: rings within about 5 positions of the visible area exist.
  const rendered = (rank) => {
    const left = (rank - 1) * 104;
    return st.url === IG_URL && left >= st.scrollLeft - 5 * 104 && left < st.scrollLeft + TRAY_W + 5 * 104;
  };
  const entryOf = (u) => byRank.find((e) => e.user.username === u);
  const framesOf = (u) => (opts.frames && opts.frames[u]) || 5;
  const label = (e) => {
    const seen = (opts.labelSeen || []).includes(e.user.username) || e.muted || e.seen >= e.latest_reel_media;
    return `Story by ${e.user.username}, ${seen ? 'seen' : 'not seen'}`;
  };
  const openViewer = (u, id) => { st.viewer = { user: u, frame: 0 }; st.url = `${IG_URL}stories/${u}/${id ? id + '/' : ''}`; st.title = 'Stories • Instagram'; };

  function locator(sel) {
    const ringUser = (RING_RE.exec(sel) || [])[1];
    const self = {
      first: () => self,
      async count() {
        if (sel === NEXT_SEL) return st.url === IG_URL && st.scrollLeft < maxScroll ? 1 : 0;
        if (ringUser) { const e = entryOf(ringUser); return e && rendered(e.ranked_position) ? 1 : 0; }
        throw new Error(`fake page: unexpected count() selector ${sel}`);
      },
      async waitFor() {
        if (sel === READY_SEL) return;
        throw new Error(`fake page: unexpected waitFor() selector ${sel}`);
      },
      async boundingBox() {
        if (sel === SCROLLER_SEL) return { x: TRAY_X, y: 0, width: TRAY_W, height: 124 };
        if (ringUser) {
          const e = entryOf(ringUser);
          if (!e || !rendered(e.ranked_position)) return null;
          return { x: ringX(e.ranked_position), y: 10, width: RING_W, height: 100 };
        }
        throw new Error(`fake page: unexpected boundingBox() selector ${sel}`);
      },
      async isVisible() {
        if (ringUser) { const e = entryOf(ringUser); return !!(e && rendered(e.ranked_position)); }
        throw new Error(`fake page: unexpected isVisible() selector ${sel}`);
      },
      async evaluateAll(fn) {
        if (sel !== ANY_RING_SEL) throw new Error(`fake page: unexpected evaluateAll() selector ${sel}`);
        const els = byRank.filter((e) => rendered(e.ranked_position))
          .map((e) => ({ getAttribute: (a) => (a === 'aria-label' ? label(e) : null) }));
        return fn(els);
      },
      async click() {
        if (sel === NEXT_SEL) {
          if (st.url !== IG_URL || st.scrollLeft >= maxScroll) throw new Error('fake page: no Next button');
          st.clicks.push({ kind: 'next' });
          st.scrollLeft = Math.min(maxScroll, st.scrollLeft + 416);
          return;
        }
        if (ringUser) {
          const e = entryOf(ringUser);
          if (!e || !rendered(e.ranked_position)) throw new Error(`fake page: ring ${ringUser} not rendered`);
          st.clicks.push({ kind: 'ring', user: ringUser });
          if ((opts.expired || []).includes(ringUser)) {
            st.url = `${IG_URL}stories/${ringUser}/`; st.title = "Page couldn't load • Instagram"; st.viewer = null;
            return;
          }
          const shown = (opts.showInstead && opts.showInstead[ringUser]) || ringUser;
          openViewer(shown, null);
          return;
        }
        throw new Error(`fake page: unexpected click() selector ${sel}`);
      },
    };
    return self;
  }

  const page = {
    st,
    locator,
    url: () => st.url,
    async title() { return st.title; },
    async goto(url) { st.gotos.push(url); st.url = url; st.title = 'Instagram'; st.viewer = null; st.scrollLeft = 0; },
    async waitForURL(want) {
      const ok = want instanceof RegExp ? want.test(st.url) : st.url === want;
      if (!ok) throw new Error(`fake page: waitForURL ${want} timed out at ${st.url}`);
    },
    async evaluate(fn, arg) {
      if (fn.name === 'storyTrayScriptText') return opts.noTrayData ? null : trayScript(tray);
      if (fn.name === 'storyFrameCountIn') {
        if (opts.framesNull || !st.viewer || st.viewer.user !== arg) return null;
        return framesOf(arg);
      }
      throw new Error(`fake page: unexpected evaluate(${fn.name})`);
    },
    keyboard: {
      async press(k) {
        st.keys.push(k);
        if (k === 'Escape') { if (st.viewer || /\/stories\//.test(st.url)) { st.viewer = null; st.url = IG_URL; st.title = 'Instagram'; } return; }
        if (k === 'ArrowRight' && st.viewer) {
          const v = st.viewer;
          v.frame++;
          const limit = opts.advanceAfter && opts.advanceAfter[v.user];
          if (v.frame >= framesOf(v.user) || (limit && v.frame >= limit)) {
            const cur = entryOf(v.user);
            const next = cur && byRank.find((e) => e.ranked_position === cur.ranked_position + 1);
            if (next) openViewer(next.user.username, '4013000000000000000');
            else { st.viewer = null; st.url = IG_URL; }
          } else {
            st.url = `${IG_URL}stories/${v.user}/40130000000000000${v.frame}/`;
          }
          return;
        }
        throw new Error(`fake page: unexpected key ${k}`);
      },
    },
  };
  return page;
}

/** A fake fs holding only the story state file. */
function fakeFs(files) {
  return {
    files,
    existsSync: (p) => p in files,
    readFileSync: (p) => { if (!(p in files)) throw new Error('ENOENT'); return files[p]; },
    writeFileSync: (p, data) => { if (files.__failWrites) throw new Error('EACCES'); files[p] = data; },
    renameSync: (a, b) => { if (!(a in files)) throw new Error('ENOENT'); files[b] = files[a]; delete files[a]; },
  };
}

class ActionBlocked extends Error {}

/** Load the stories sections as one fresh run. `files` is shared between runs. */
function load(opts, files) {
  const page = fakePage(opts);
  const logs = [], jlogs = [], shots = [];
  const deps = {
    ...CONSTANTS,
    fs: fakeFs(files),
    path,
    log: (tag, msg) => logs.push(`${tag} ${msg}`),
    jlog: (e) => jlogs.push(e),
    shot: async (p, name) => { shots.push(name); return `${name}.png`; },
    sleep: async () => {},
    rand: (a) => a,
    randInt: (a, b) => b,                 // always the top of the range: the most frames
    isActionBlocked: async () => !!opts.blocked,
    ActionBlocked,
    IG_URL,
    STORY_STATE_FILE: STATE_FILE,
    STORY_SESSION_RANGE: opts.session || [1, 3],
    STORY_SECONDS_RANGE: null,
  };
  const names = Object.keys(deps);
  const mod = new Function(...names, PURE + BROWSER + '\nreturn { watchStories };')(...names.map((n) => deps[n]));
  return { mod, page, logs, jlogs, shots };
}

const allClicks = [];
async function run(opts, files = {}) {
  const t = load(opts, files);
  let result, error;
  try { result = await t.mod.watchStories(t.page); } catch (e) { error = e; }
  allClicks.push(...t.page.st.clicks);
  return { ...t, result, error, files, state: () => JSON.parse(files[STATE_FILE] || '{}') };
}

(async () => {
  console.log('\nwatchStories: happy path, rank 15 needs 3 Next clicks');
  {
    // Ranks 1 to 14 are fully seen, so the first eligible account is rank 15.
    const over = {};
    for (let r = 1; r <= 14; r++) over[r] = { seen: nowSec() };
    const t = await run({ tray: trayOf(40, over) });
    const nexts = t.page.st.clicks.filter((c) => c.kind === 'next').length;
    check('opened', t.result && t.result.opened === true, JSON.stringify(t.result || t.error && t.error.message));
    check('3 Next clicks', nexts === 3, `got ${nexts}`);
    check('the rank 15 ring was clicked', JSON.stringify(t.page.st.clicks.filter((c) => c.kind === 'ring')) === '[{"kind":"ring","user":"user15"}]');
    check('ArrowRight pressed toWatch - 1 = 2 times', t.page.st.keys.filter((k) => k === 'ArrowRight').length === 2, t.page.st.keys.join(','));
    check('Escape pressed once', t.page.st.keys.filter((k) => k === 'Escape').length === 1);
    check('3 frames watched of 5', t.result.watched === 3 && t.result.frames === 5);
    check('user15 in the state file', typeof t.state().user15 === 'number');
    check('back on the home page', t.page.st.url === IG_URL);
    check('jlog has source tray-data and rank', t.jlogs.some((j) => j.action === 'story' && j.source === 'tray-data' && j.rank === 15 && j.trayCount === 40));
    check('a full page load first', t.page.st.gotos[0] === IG_URL);
  }

  console.log('\nwatchStories: two calls pick two different accounts');
  {
    const files = {};
    const t = load({ tray: trayOf(10) }, files);
    const a = await t.mod.watchStories(t.page);
    const b = await t.mod.watchStories(t.page);
    allClicks.push(...t.page.st.clicks);
    check('first is user1', a.user === 'user1', JSON.stringify(a));
    check('second is user2', b.user === 'user2', JSON.stringify(b));
    check('both in the state file', Object.keys(JSON.parse(files[STATE_FILE])).sort().join() === 'user1,user2');
  }

  console.log('\nwatchStories: the state file carries over to a second run');
  {
    const files = { [STATE_FILE]: JSON.stringify({ user1: Date.now() - 2 * 3600000, user2: Date.now() - 25 * 3600000 }) };
    const t = await run({ tray: trayOf(10) }, files);
    check('watched 2 h ago is skipped, 25 h ago is eligible again', t.result.user === 'user2', JSON.stringify(t.result));
    check('the 25 h entry was refreshed, not kept old', Date.now() - t.state().user2 < 60000);
  }

  console.log('\nwatchStories: a corrupt state file');
  {
    const files = { [STATE_FILE]: '{not json' };
    const t = await run({ tray: trayOf(3) }, files);
    check('still watches', t.result.opened === true && t.result.user === 'user1');
    check('corrupt file kept aside as .bad', files[`${STATE_FILE}.bad`] === '{not json');
    check('a fresh state file written', typeof t.state().user1 === 'number');
    check('a WARN was logged', t.logs.some((l) => l.startsWith('WARN') && l.includes('.bad')));
  }

  console.log('\nwatchStories: a state file that cannot be written');
  {
    const files = { __failWrites: true };
    const t = load({ tray: trayOf(5) }, files);
    const a = await t.mod.watchStories(t.page);
    const b = await t.mod.watchStories(t.page);
    allClicks.push(...t.page.st.clicks);
    check('the run goes on', a.opened && b.opened);
    check('memory still prevents a repeat', a.user !== b.user, `${a.user} ${b.user}`);
    check('a WARN was logged', t.logs.some((l) => l.startsWith('WARN') && l.includes('could not write')));
  }

  console.log('\nwatchStories: expired story');
  {
    const t = await run({ tray: trayOf(5), expired: ['user1'] });
    check('returns expired, not opened', t.result.opened === false && t.result.expired === true, JSON.stringify(t.result));
    check('went home', t.page.st.url === IG_URL && t.page.st.gotos.length === 2);
    check('no keys pressed', t.page.st.keys.length === 0);
    check('remembered so it is not clicked again', typeof t.state().user1 === 'number');
  }

  console.log('\nwatchStories: auto-advance to another account');
  {
    const t = await run({ tray: trayOf(5), advanceAfter: { user1: 1 } });
    check('watching stops after the first ArrowRight', t.page.st.keys.filter((k) => k === 'ArrowRight').length === 1, t.page.st.keys.join(','));
    check('Escape pressed', t.page.st.keys.includes('Escape'));
    check('counted 1 frame', t.result.opened && t.result.watched === 1);
  }

  console.log('\nwatchStories: frame count not found');
  {
    const t = await run({ tray: trayOf(5), framesNull: true });
    check('one frame, no ArrowRight', t.result.watched === 1 && !t.page.st.keys.includes('ArrowRight'));
    check('frames reported as null', t.result.frames === null);
  }

  console.log('\nwatchStories: partly seen account');
  {
    const t = await run({ tray: trayOf(5, { 1: { seen: nowSec() - 7200 } }) });
    check('picked although partly seen', t.result.user === 'user1');
    check('one frame only, no ArrowRight', t.result.watched === 1 && !t.page.st.keys.includes('ArrowRight'));
  }

  console.log('\nwatchStories: fewer frames than the session asks for');
  {
    const t = await run({ tray: trayOf(5), frames: { user1: 2 }, session: [3, 6] });
    check('capped at the frame count', t.result.watched === 2 && t.page.st.keys.filter((k) => k === 'ArrowRight').length === 1);
  }

  console.log('\nwatchStories: the viewer shows another account');
  {
    const t = await run({ tray: trayOf(5), showInstead: { user1: 'user9' } });
    check('not counted as opened', t.result.opened === false);
    check('both accounts remembered', typeof t.state().user1 === 'number' && typeof t.state().user9 === 'number');
    check('left with Escape, no ArrowRight', t.page.st.keys.join() === 'Escape');
  }

  console.log('\nwatchStories: tray data missing, ring label fallback');
  {
    const tray = trayOf(30);
    // Every rendered ring at scrollLeft 0 (ranks 1 to 12) reads "seen" in its label.
    const labelSeen = tray.slice(0, 12).map((e) => e.user.username);
    const t = await run({ tray, noTrayData: true, labelSeen });
    const nexts = t.page.st.clicks.filter((c) => c.kind === 'next').length;
    check('opened from the labels', t.result.opened === true && t.result.source === 'labels', JSON.stringify(t.result));
    check('first "not seen" ring after Next clicks', t.result.user === 'user13' && nexts >= 1, `${t.result.user} after ${nexts}`);
    check('one frame only (no seen data)', t.result.watched === 1);
    check('WARN logged once', t.logs.filter((l) => l.includes('tray data not found')).length === 1);
    check('jlog source labels', t.jlogs.some((j) => j.action === 'story' && j.source === 'labels'));
  }

  console.log('\nwatchStories: label fallback skips watched accounts, and the WARN is once per run');
  {
    const files = {};
    const t = load({ tray: trayOf(8), noTrayData: true }, files);
    const a = await t.mod.watchStories(t.page);
    const b = await t.mod.watchStories(t.page);
    allClicks.push(...t.page.st.clicks);
    check('two different accounts', a.user === 'user1' && b.user === 'user2', `${a.user} ${b.user}`);
    check('one WARN for two calls', t.logs.filter((l) => l.includes('tray data not found')).length === 1);
  }

  console.log('\nwatchStories: nothing eligible');
  {
    const over = {}; for (let r = 1; r <= 6; r++) over[r] = { muted: r > 3, seen: r <= 3 ? nowSec() : 0 };
    const t = await run({ tray: trayOf(6, over) });
    check('returns not opened', t.result.opened === false && !t.result.expired);
    check('no click at all', t.page.st.clicks.length === 0);
    check('jlog says none eligible', t.jlogs.some((j) => j.skipped === 'none eligible' && j.tray === 6));
  }

  console.log('\nwatchStories: everything already watched');
  {
    const files = { [STATE_FILE]: JSON.stringify({ user1: Date.now(), user2: Date.now() }) };
    const t = await run({ tray: trayOf(2) }, files);
    check('no click', t.result.opened === false && t.page.st.clicks.length === 0);
    check('log says how many were watched', t.logs.some((l) => l.includes('2 in tray, 2 watched')));
  }

  console.log('\nwatchStories: action block on the home page');
  {
    const t = await run({ tray: trayOf(5), blocked: true });
    check('throws ActionBlocked', t.error instanceof ActionBlocked);
    check('nothing clicked', t.page.st.clicks.length === 0);
  }

  console.log('\nwatchStories: last rank in a long tray');
  {
    const over = {}; for (let r = 1; r < 132; r++) over[r] = { seen: nowSec() };
    const t = await run({ tray: trayOf(132, over) });
    const nexts = t.page.st.clicks.filter((c) => c.kind === 'next').length;
    check('reached rank 132', t.result.user === 'user132', JSON.stringify(t.result));
    check('at most 34 Next clicks', nexts <= 34, `${nexts}`);
  }

  console.log('\nclick audit across every case');
  {
    const kinds = new Set(allClicks.map((c) => c.kind));
    check('only ring and tray Next clicks', [...kinds].every((k) => k === 'ring' || k === 'next'), [...kinds].join());
    check('clicks were recorded', allClicks.length > 10, `${allClicks.length}`);
  }

  console.log(`\n${pass} passed, ${failN} failed`);
  process.exit(failN ? 1 : 0);
})();
