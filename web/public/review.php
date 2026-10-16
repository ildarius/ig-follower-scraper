<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';
$authUser = require __DIR__ . '/page-guard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Review queue</title>
<style>
  :root {
    --bg:#fff; --fg:#1b1f24; --muted:#5c6771; --line:#dfe3e8; --accent:#2f6f4f;
    --warn:#8a4b12; --err:#9b2226; --panel:#f6f7f9; --chip:#e8edf1;
    --valid:#1f8a4c; --skip:#c0392b;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#14171a; --fg:#e8eaed; --muted:#9aa4af; --line:#2c3238; --accent:#6cc39a;
      --warn:#e0a463; --err:#e08585; --panel:#1c2024; --chip:#293038;
      --valid:#23985a; --skip:#c94a3c; }
  }
  * { box-sizing:border-box; }
  html, body { height:100%; }
  body { margin:0; background:var(--bg); color:var(--fg);
    font:16px/1.45 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    display:flex; flex-direction:column; height:100dvh; }
  a { color:var(--accent); }
  .top { display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:calc(10px + env(safe-area-inset-top)) 16px 10px; border-bottom:1px solid var(--line); background:var(--panel); }
  .top a { text-decoration:none; font-size:14px; }
  .left-count { font-size:14px; color:var(--muted); }
  #undo { padding:6px 11px; border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--fg);
    font:inherit; font-size:14px; cursor:pointer; max-width:46vw; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  #undo:disabled { opacity:.4; cursor:default; }
  .card { flex:1; overflow-y:auto; padding:22px 18px 28px; max-width:640px; width:100%; margin:0 auto; }
  .head { display:flex; align-items:center; gap:14px; }
  .avatar { flex:none; width:72px; height:72px; border-radius:50%; background:var(--chip); color:var(--muted);
    display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:600; text-transform:uppercase; }
  .who { min-width:0; }
  .username { font-size:22px; font-weight:700; word-break:break-all; text-decoration:none; }
  .fullname { color:var(--muted); margin-top:2px; word-break:break-word; }
  .badges { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
  .badge { padding:3px 9px; border-radius:999px; background:var(--chip); font-size:13px; }
  .badge.business { background:#ff1f1f; color:#fff; font-weight:700; }
  .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:18px 0; text-align:center; }
  .stats div { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:10px 4px; }
  .stats strong { display:block; font-size:19px; }
  .stats span { font-size:12px; color:var(--muted); }
  .category { font-weight:600; margin-bottom:6px; }
  .bio { white-space:pre-line; word-break:break-word; }
  .bio.none { color:var(--muted); font-style:italic; }
  .site { display:inline-block; margin-top:10px; word-break:break-all; }
  .open-ig { display:block; margin-top:22px; padding:12px; text-align:center; border:1px solid var(--line);
    border-radius:8px; text-decoration:none; font-weight:600; }
  .id { margin-top:14px; font-size:12px; color:var(--muted); }
  .empty { text-align:center; color:var(--muted); padding-top:30vh; }
  .toast { position:fixed; left:16px; right:16px; bottom:calc(96px + env(safe-area-inset-bottom)); margin:0 auto; max-width:600px;
    padding:10px 14px; border-radius:8px; background:var(--fg); color:var(--bg); font-size:14px; text-align:center;
    opacity:0; transition:opacity .2s; pointer-events:none; }
  .toast.show { opacity:.94; }
  .toast.error { background:var(--err); color:#fff; }
  .actions { display:grid; grid-template-columns:1fr 1fr; gap:10px;
    padding:10px 12px calc(10px + env(safe-area-inset-bottom)); border-top:1px solid var(--line); background:var(--panel); }
  .actions button { height:68px; border:0; border-radius:10px; color:#fff; font:inherit; font-size:20px;
    font-weight:700; letter-spacing:.06em; cursor:pointer; touch-action:manipulation; }
  #valid { background:var(--valid); }
  #skip { background:var(--skip); }
  .actions button:disabled { opacity:.45; cursor:default; }
</style>
</head>
<body>
<header class="top">
  <a href="accounts.php">&larr; Accounts</a>
  <span id="left" class="left-count">Loading…</span>
  <button type="button" id="undo" disabled>Undo</button>
</header>

<main id="card" class="card" aria-live="polite"></main>

<div id="toast" class="toast" role="status"></div>

<nav class="actions" aria-label="Decision">
  <button type="button" id="valid" disabled>VALID</button>
  <button type="button" id="skip" disabled>SKIP</button>
</nav>

<script>
(() => {
  'use strict';
  const card = document.getElementById('card');
  const left = document.getElementById('left');
  const validBtn = document.getElementById('valid');
  const skipBtn = document.getElementById('skip');
  const undoBtn = document.getElementById('undo');
  const toast = document.getElementById('toast');
  let current = null;   // the account on screen
  let last = null;      // {id, token, username, decision} of the decision that can be undone
  let busy = false;
  let toastTimer = null;

  async function api(action, body) {
    const options = body === undefined ? {} : {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(body)};
    const res = await fetch('api.php?action=' + action, options);
    let data;
    try { data = await res.json(); } catch (_) { throw new Error('The server returned an invalid response.'); }
    if (res.status === 401) { window.location.assign(data.login || 'login.php'); throw new Error('Not signed in.'); }
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed');
    return data;
  }

  function say(text, error = false) {
    toast.textContent = text; toast.className = 'toast show' + (error ? ' error' : '');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { toast.className = 'toast'; }, error ? 5000 : 2200);
  }

  function setBusy(value) {
    busy = value;
    validBtn.disabled = value || !current;
    skipBtn.disabled = value || !current;
    undoBtn.disabled = value || !last;
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function count(value) { return value === null || value === undefined ? '-' : Number(value).toLocaleString(); }

  function render(account) {
    card.replaceChildren();
    card.scrollTop = 0;
    if (!account) { card.append(el('p', 'empty', 'Nothing left to review.')); return; }

    const igUrl = 'https://www.instagram.com/' + encodeURIComponent(account.username) + '/';
    const head = el('div', 'head');
    head.append(el('div', 'avatar', (account.username || '?').replace(/[^a-z0-9]/gi, '').slice(0, 1) || '?'));
    const who = el('div', 'who');
    const link = el('a', 'username', '@' + account.username);
    link.href = igUrl; link.target = '_blank'; link.rel = 'noopener noreferrer';
    who.append(link);
    const fullName = account.user_full_name || account.full_name;
    if (fullName) who.append(el('div', 'fullname', fullName));
    head.append(who);
    card.append(head);

    const badges = el('div', 'badges');
    if (Number(account.is_verified)) badges.append(el('span', 'badge', 'Verified'));
    badges.append(el('span', 'badge', Number(account.is_private) ? 'Private' : 'Public'));
    // Business accounts are flagged bright red so they stand out at a glance.
    if (account.is_business !== null) badges.append(Number(account.is_business) ? el('span', 'badge business', 'Business') : el('span', 'badge', 'Personal'));
    card.append(badges);

    const stats = el('div', 'stats');
    [['posts_count', 'posts'], ['followers_count', 'followers'], ['follows_count', 'following']].forEach(([key, label]) => {
      const box = el('div'); box.append(el('strong', '', count(account[key])), el('span', '', label)); stats.append(box);
    });
    card.append(stats);

    if (account.category) card.append(el('div', 'category', account.category));
    card.append(account.biography ? el('div', 'bio', account.biography) : el('div', 'bio none', 'No bio.'));
    if (account.external_url && /^https?:\/\//i.test(account.external_url)) {
      const site = el('a', 'site', account.external_url.replace(/^https?:\/\//i, ''));
      site.href = account.external_url; site.target = '_blank'; site.rel = 'noopener noreferrer nofollow';
      card.append(site);
    }

    const open = el('a', 'open-ig', 'Open on Instagram');
    open.href = igUrl; open.target = '_blank'; open.rel = 'noopener noreferrer';
    card.append(open);
    card.append(el('div', 'id', 'Account #' + account.id));
  }

  async function loadNext() {
    setBusy(true);
    try {
      const data = await api('review-next');
      current = data.account;
      left.textContent = Number(data.remaining).toLocaleString() + ' left';
      render(current);
    } catch (e) {
      say(e.message, true);
    } finally {
      setBusy(false);
    }
  }

  async function decide(decision) {
    if (busy || !current) return;
    const account = current;
    setBusy(true);
    try {
      const data = await api('review-decide', {id: Number(account.id), decision});
      if (data.changed) {
        last = {id: Number(account.id), token: data.token, username: account.username, decision};
        undoBtn.textContent = 'Undo ' + (decision === 'valid' ? 'valid' : 'skip') + ' @' + account.username;
      } else {
        const now = data.current || {};
        const state = now.review_decision ? 'marked ' + now.review_decision + (now.reviewed_by ? ' by ' + now.reviewed_by : '') : 'now ' + String(now.follow_status || 'gone').replaceAll('_', ' ');
        say('@' + account.username + ' was already handled (' + state + ').');
      }
    } catch (e) {
      say(e.message, true);
      setBusy(false);
      return;
    }
    await loadNext();
  }

  async function undo() {
    if (busy || !last) return;
    const target = last;
    setBusy(true);
    try {
      const data = await api('review-undo', {id: target.id, token: target.token});
      say(data.changed ? 'Undone: @' + target.username + ' is back in the queue.' : 'Could not undo @' + target.username + ': it changed since.', !data.changed);
      last = null; undoBtn.textContent = 'Undo';
    } catch (e) {
      say(e.message, true);
      setBusy(false);
      return;
    }
    await loadNext();
  }

  validBtn.addEventListener('click', () => decide('valid'));
  skipBtn.addEventListener('click', () => decide('skip'));
  undoBtn.addEventListener('click', undo);
  document.addEventListener('keydown', e => {
    if (e.target.closest('input, textarea, select') || e.metaKey || e.ctrlKey || e.altKey) return;
    if (e.key === 'ArrowLeft') { e.preventDefault(); decide('valid'); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); decide('skip'); }
    else if (e.key === 'u' || e.key === 'U') { e.preventDefault(); undo(); }
  });

  loadNext();
})();
</script>
</body>
</html>
