<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';
$authUser = require __DIR__ . '/page-guard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accounts Browser</title>
<style>
  :root {
    --bg:#fff; --fg:#1b1f24; --muted:#5c6771; --line:#dfe3e8; --accent:#2f6f4f;
    --warn:#8a4b12; --err:#9b2226; --panel:#f6f7f9; --chip:#e8edf1; --row-hover:#f8faf9;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#14171a; --fg:#e8eaed; --muted:#9aa4af; --line:#2c3238; --accent:#6cc39a;
      --warn:#e0a463; --err:#e08585; --panel:#1c2024; --chip:#293038; --row-hover:#191e21; }
  }
  * { box-sizing:border-box; }
  body { margin:0; padding:24px 16px 80px; background:var(--bg); color:var(--fg);
    font:15px/1.45 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .wrap { max-width:1500px; margin:0 auto; }
  h1 { font-size:22px; margin:0; }
  a { color:var(--accent); }
  .header { display:flex; justify-content:space-between; align-items:baseline; gap:16px; margin-bottom:18px; }
  .header-links { display:flex; gap:14px; flex-wrap:wrap; justify-content:flex-end; }
  .sign-out { padding:7px 12px; border:1px solid var(--line); border-radius:6px; text-decoration:none; }
  .sign-out:hover { border-color:var(--accent); }
  .panel { background:var(--panel); border:1px solid var(--line); border-radius:8px; padding:14px 16px; }
  .filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:13px; }
  label, legend { display:block; font-size:13px; color:var(--muted); margin-bottom:4px; }
  fieldset { border:0; margin:0; padding:0; min-width:0; }
  input, select { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:6px;
    background:var(--bg); color:var(--fg); font:inherit; }
  input[type=checkbox] { width:auto; padding:0; accent-color:var(--accent); }
  .empty-check { display:flex; align-items:center; gap:6px; margin:5px 0 0; font-size:12px; color:var(--muted); }
  .range, .buttons, .quick, .result-tools, .selection-tools, .pagination { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
  .range input { min-width:0; }
  .status-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:4px 8px; }
  .status-options label { color:var(--fg); margin:0; white-space:nowrap; }
  button { padding:8px 13px; border:1px solid var(--line); border-radius:6px; cursor:pointer;
    background:var(--bg); color:var(--fg); font:inherit; }
  button:hover:not(:disabled) { border-color:var(--accent); }
  button.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
  button.danger { background:var(--err); border-color:var(--err); color:#fff; }
  button:disabled { opacity:.45; cursor:not-allowed; }
  .buttons { margin-top:14px; }
  .hint, .muted { color:var(--muted); font-size:13px; }
  .hint { margin:10px 0 0; }
  .result-bar { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin:16px 0 10px; }
  .result-tools select { width:auto; }
  .chips { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 10px; }
  .chip { display:inline-flex; align-items:center; gap:5px; border-radius:999px; padding:4px 9px;
    background:var(--chip); font-size:12px; }
  .chip button { padding:0; border:0; background:transparent; font-size:16px; line-height:1; }
  .message { display:none; margin:12px 0; padding:10px 12px; border:1px solid var(--line); border-radius:6px; }
  .message.show { display:block; }
  .message.error { color:var(--err); border-color:var(--err); }
  .table-wrap { border:1px solid var(--line); border-radius:8px; overflow:auto; max-height:66vh; }
  table { border-collapse:separate; border-spacing:0; width:100%; min-width:1320px; }
  th, td { text-align:left; padding:7px 9px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { position:sticky; top:0; z-index:2; color:var(--muted); background:var(--panel); font-size:12px; white-space:nowrap; }
  th button { border:0; padding:0; background:transparent; color:inherit; font-weight:600; }
  tbody tr:hover { background:var(--row-hover); }
  tr.dim { opacity:.58; }
  td.num, th.num { text-align:right; }
  td.bio, td.note-cell { max-width:270px; }
  .truncate { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .status { display:inline-block; padding:2px 7px; border-radius:999px; background:var(--chip); font-size:12px; }
  .status.pending { color:var(--accent); } .status.error { color:var(--err); }
  .status.skipped { color:var(--warn); }
  .empty { text-align:center; padding:34px; color:var(--muted); }
  .action-bar { display:none; margin-top:14px; position:sticky; bottom:10px; z-index:3; box-shadow:0 4px 18px rgba(0,0,0,.14); }
  .action-bar.show { display:block; }
  .action-grid { display:grid; grid-template-columns:minmax(150px,.6fr) minmax(280px,1.5fr); gap:14px; align-items:end; }
  .note-row { display:flex; align-items:center; gap:8px; }
  .counter { color:var(--muted); min-width:52px; font-size:12px; }
  .quick { margin-top:7px; gap:5px; } .quick button { padding:4px 8px; font-size:12px; }
  .pagination { justify-content:center; margin-top:16px; }
  .pagination button.active { background:var(--accent); color:#fff; border-color:var(--accent); }
  @media (max-width:1000px) { .action-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<main class="wrap">
  <header class="header"><div><h1>Accounts browser</h1><div class="muted">Filter and tag the Instagram follow queue.</div></div><nav class="header-links" aria-label="Account navigation"><a href="index.php">Back to dashboard</a><a class="sign-out" href="login.php?logout=1">Sign out</a></nav></header>

  <form id="filter-form" class="panel">
    <div class="filters">
      <div><label for="username">Username</label><input id="username" name="username" autocomplete="off"><label class="empty-check"><input type="checkbox" name="username_empty" value="1"> is empty</label></div>
      <div><label for="full_name">Full name</label><input id="full_name" name="full_name"><label class="empty-check"><input type="checkbox" name="full_name_empty" value="1"> is empty</label></div>
      <div><label for="biography">Biography</label><input id="biography" name="biography"><label class="empty-check"><input type="checkbox" name="biography_empty" value="1"> is empty</label></div>
      <div><label for="category">Category</label><select id="category" name="category"><option value="">Any</option><option value="__none__">(none)</option></select></div>
      <div><label for="is_business">Business account</label><select id="is_business" name="is_business"><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
      <fieldset><legend>Follow status</legend><div class="status-options" id="status-options"></div></fieldset>
      <div><label for="is_private">Private</label><select id="is_private" name="is_private"><option value="">Any</option><option value="1">Yes</option><option value="0">No</option></select></div>
      <div><label>Followers</label><div class="range"><input name="followers_min" type="number" min="0" placeholder="Min"><input name="followers_max" type="number" min="0" placeholder="Max"></div></div>
      <div><label>Posts</label><div class="range"><input name="posts_min" type="number" min="0" placeholder="Min"><input name="posts_max" type="number" min="0" placeholder="Max"></div></div>
      <div><label for="note">Note</label><input id="note" name="note"><label class="empty-check"><input type="checkbox" name="note_empty" value="1"> is empty</label></div>
    </div>
    <div class="buttons"><button type="submit" class="primary">Apply filters</button><button type="button" id="reset-filters">Reset</button></div>
    <p class="hint">Text filters: comma separates alternatives. Terms contain by default; use <code>%</code> for any number of characters or <code>_</code> for one character (for example <code>kava%, %bar</code>).</p>
  </form>

  <div id="message" class="message" role="status"></div>
  <div class="result-bar">
    <div><strong id="summary">Loading…</strong><div id="selection-text" class="muted"></div></div>
    <div class="result-tools"><div class="selection-tools"><button id="select-all" type="button">Select all matching</button><button id="clear-selection" type="button" hidden>Clear selection</button></div><label for="per-page">Rows</label><select id="per-page"><option>50</option><option selected>100</option><option>200</option></select></div>
  </div>
  <div id="chips" class="chips"></div>

  <div class="table-wrap">
    <table>
      <thead><tr>
        <th><input type="checkbox" id="select-visible" aria-label="Select actionable rows on this page"></th>
        <th><button data-sort="id">ID <span></span></button></th>
        <th><button data-sort="username">Username <span></span></button></th>
        <th>Full name</th>
        <th class="num"><button data-sort="followers_count">Followers <span></span></button></th>
        <th class="num"><button data-sort="posts_count">Posts <span></span></button></th>
        <th>Business</th><th>Private</th><th>Category</th><th>Biography</th><th>Status</th><th>Note</th>
      </tr></thead>
      <tbody id="rows"><tr><td colspan="12" class="empty">Loading accounts…</td></tr></tbody>
    </table>
  </div>

  <section id="action-bar" class="action-bar panel">
    <div class="action-grid">
      <div><strong id="action-count"></strong><div class="muted" id="action-detail"></div></div>
      <div><label for="bulk-note">Reason for do-not-follow</label><div class="note-row"><input id="bulk-note" maxlength="255" placeholder="Required when marking do-not-follow"><span id="counter" class="counter">0 / 255</span></div><div class="quick" id="quick"></div></div>
    </div>
    <div class="buttons"><button type="button" id="skip" class="danger">Do not follow</button><button type="button" id="revert">Revert to pending</button></div>
  </section>
  <nav id="pagination" class="pagination" aria-label="Pagination"></nav>
</main>

<script>
(() => {
  'use strict';
  const statuses = ['pending','followed','requested','already_following','error','skipped'];
  const filterNames = ['username','full_name','biography','category','is_business','is_private','followers_min','followers_max','posts_min','posts_max','note'];
  const emptyNames = ['username_empty','full_name_empty','biography_empty','note_empty'];
  const labels = {username:'Username',full_name:'Full name',biography:'Biography',category:'Category',is_business:'Business',is_private:'Private',followers_min:'Followers min',followers_max:'Followers max',posts_min:'Posts min',posts_max:'Posts max',note:'Note',follow_status:'Status'};
  const form = document.getElementById('filter-form');
  const tbody = document.getElementById('rows');
  const summary = document.getElementById('summary');
  const message = document.getElementById('message');
  const selection = new Map();
  let selectAll = false;
  let result = {total:0, rows:[], eligible_for_action:0, eligible_for_revert:0, page:1, pages:1};
  let sort = 'id', dir = 'asc', loading = false;

  function statusControls() {
    const box = document.getElementById('status-options');
    statuses.forEach(status => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'checkbox'; input.name = 'follow_status'; input.value = status;
      label.append(input, ' ' + status.replaceAll('_', ' ')); box.append(label);
    });
  }

  async function api(action, options = {}) {
    const res = await fetch('api.php?action=' + action + (options.query ? '&' + options.query : ''), options.fetch || {});
    let body;
    try { body = await res.json(); } catch (_) { throw new Error('The server returned an invalid response.'); }
    if (res.status === 401) window.location.assign(body.login || 'login.php');
    if (!res.ok || !body.ok) throw new Error(body.error || 'Request failed');
    return body;
  }

  function showMessage(text, error = false) {
    message.textContent = text; message.className = 'message show' + (error ? ' error' : '');
  }
  function clearMessage() { message.className = 'message'; message.textContent = ''; }

  function filtersFromForm() {
    const data = {};
    filterNames.forEach(name => { const value = form.elements[name].value.trim(); if (value !== '') data[name] = value; });
    emptyNames.forEach(name => { if (form.elements[name].checked) data[name] = '1'; });
    const checked = [...form.querySelectorAll('input[name=follow_status]:checked')].map(el => el.value);
    if (checked.length) data.follow_status = checked.join(',');
    return data;
  }

  function stateFromUrl() {
    const q = new URLSearchParams(location.search);
    const data = {};
    [...filterNames, ...emptyNames].forEach(name => { if (q.has(name)) data[name] = q.get(name); });
    if (q.has('follow_status')) data.follow_status = q.get('follow_status');
    sort = ['id','username','followers_count','posts_count'].includes(q.get('sort')) ? q.get('sort') : 'id';
    dir = q.get('dir') === 'desc' ? 'desc' : 'asc';
    return data;
  }

  function fillForm(data) {
    form.reset();
    filterNames.forEach(name => { form.elements[name].value = data[name] || ''; });
    emptyNames.forEach(name => { form.elements[name].checked = data[name] === '1'; });
    const selected = new Set((data.follow_status || '').split(','));
    form.querySelectorAll('input[name=follow_status]').forEach(el => { el.checked = selected.has(el.value); });
  }

  function searchParams(page = 1) {
    const q = new URLSearchParams(filtersFromForm());
    q.set('sort', sort); q.set('dir', dir); q.set('page', String(page)); q.set('per_page', document.getElementById('per-page').value);
    return q;
  }

  function updateUrl(page = 1, replace = false) {
    const q = searchParams(page);
    const url = location.pathname + (q.toString() ? '?' + q : '');
    history[replace ? 'replaceState' : 'pushState']({}, '', url);
  }

  function clearSelection() { selection.clear(); selectAll = false; renderSelection(); renderRows(); }

  async function load(page = null) {
    if (loading) return;
    loading = true; clearMessage();
    try {
      const q = new URLSearchParams(location.search);
      if (page !== null) q.set('page', String(page));
      q.set('sort', sort); q.set('dir', dir); q.set('per_page', document.getElementById('per-page').value);
      result = await api('accounts-search', {query:q.toString()});
      render();
    } catch (e) { showMessage(e.message, true); tbody.innerHTML = '<tr><td colspan="12" class="empty">Could not load accounts.</td></tr>'; }
    finally { loading = false; }
  }

  function render() {
    summary.textContent = result.total.toLocaleString() + ' matching, ' + result.eligible_for_action.toLocaleString() + ' can be marked do-not-follow';
    renderRows(); renderChips(); renderPagination(); renderSelection(); renderSort();
  }

  function text(value, fallback = '-') { return value === null || value === '' ? fallback : String(value); }
  function td(value, className = '', title = '') { const cell = document.createElement('td'); cell.className = className; cell.textContent = value; if (title) cell.title = title; return cell; }

  function renderRows() {
    tbody.replaceChildren();
    if (!result.rows.length) {
      const row = document.createElement('tr'), cell = td('No accounts match these filters.', 'empty'); cell.colSpan = 12; row.append(cell); tbody.append(row);
      document.getElementById('select-visible').checked = false; return;
    }
    result.rows.forEach(item => {
      const row = document.createElement('tr');
      if (item.follow_status !== 'pending') row.classList.add('dim');
      const checkCell = document.createElement('td'), check = document.createElement('input');
      check.type = 'checkbox'; check.className = 'row-check'; check.value = item.id;
      check.disabled = item.follow_status !== 'pending' || selectAll;
      check.checked = selectAll || selection.has(Number(item.id));
      check.setAttribute('aria-label', 'Select ' + item.username);
      check.addEventListener('change', () => { check.checked ? selection.set(Number(item.id), item.follow_status) : selection.delete(Number(item.id)); renderSelection(); });
      checkCell.append(check); row.append(checkCell, td(String(item.id)));
      const userCell = document.createElement('td'), link = document.createElement('a');
      link.href = 'https://www.instagram.com/' + encodeURIComponent(item.username) + '/'; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = '@' + item.username; userCell.append(link); row.append(userCell);
      row.append(td(text(item.user_full_name)), td(item.followers_count === null ? '-' : Number(item.followers_count).toLocaleString(), 'num'), td(item.posts_count === null ? '-' : Number(item.posts_count).toLocaleString(), 'num'));
      row.append(td(item.is_business === null ? '-' : Number(item.is_business) ? 'Yes' : 'No'), td(Number(item.is_private) ? 'Yes' : 'No'), td(text(item.category)));
      const bio = text(item.biography), bioCell = td(bio, 'bio', bio === '-' ? '' : bio); bioCell.innerHTML = ''; const bioSpan = document.createElement('span'); bioSpan.className = 'truncate'; bioSpan.textContent = bio; bioCell.append(bioSpan); row.append(bioCell);
      const statusCell = document.createElement('td'), status = document.createElement('span'); status.className = 'status ' + item.follow_status; status.textContent = item.follow_status.replaceAll('_',' '); statusCell.append(status); row.append(statusCell);
      const note = text(item.follow_note), noteCell = td(note, 'note-cell', note === '-' ? '' : note); noteCell.innerHTML = ''; const noteSpan = document.createElement('span'); noteSpan.className = 'truncate'; noteSpan.textContent = note; noteCell.append(noteSpan); row.append(noteCell);
      tbody.append(row);
    });
    const available = result.rows.filter(r => r.follow_status === 'pending');
    document.getElementById('select-visible').checked = available.length > 0 && available.every(r => selection.has(Number(r.id)));
  }

  function renderSelection() {
    const selectedStatuses = [...selection.values()];
    const pending = selectAll ? result.eligible_for_action : selectedStatuses.filter(status => status === 'pending').length;
    const skipped = selectAll ? result.eligible_for_revert : selectedStatuses.filter(status => status === 'skipped').length;
    const count = selectAll ? result.total : selection.size;
    document.getElementById('selection-text').textContent = selectAll ? 'All ' + result.total.toLocaleString() + ' matching rows selected.' : (count ? count.toLocaleString() + ' selected on loaded pages.' : '');
    document.getElementById('clear-selection').hidden = count === 0;
    const bar = document.getElementById('action-bar'); bar.classList.toggle('show', count > 0 && result.total > 0);
    document.getElementById('action-count').textContent = count.toLocaleString() + ' selected';
    document.getElementById('action-detail').textContent = pending.toLocaleString() + ' pending; ' + skipped.toLocaleString() + ' skipped';
    document.getElementById('skip').disabled = pending === 0;
    document.getElementById('revert').disabled = skipped === 0;
    document.getElementById('select-all').disabled = result.total === 0;
  }

  function renderChips() {
    const box = document.getElementById('chips'); box.replaceChildren(); const data = filtersFromForm();
    Object.entries(data).forEach(([name,value]) => {
      const chip = document.createElement('span'); chip.className = 'chip';
      let shown = value; if (value === '1' && name.endsWith('_empty')) shown = 'empty';
      if ((name === 'is_business' || name === 'is_private') && ['0','1'].includes(value)) shown = value === '1' ? 'Yes' : 'No';
      if (value === '__none__') shown = '(none)';
      chip.append(document.createTextNode((labels[name.replace('_empty','')] || name) + ': ' + shown + ' '));
      const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.title = 'Remove filter';
      remove.addEventListener('click', () => { if (name === 'follow_status') form.querySelectorAll('input[name=follow_status]').forEach(el => el.checked = false); else if (name.endsWith('_empty')) form.elements[name].checked = false; else form.elements[name].value = ''; applyFilters(); });
      chip.append(remove); box.append(chip);
    });
  }

  function renderSort() {
    document.querySelectorAll('[data-sort]').forEach(btn => { btn.querySelector('span').textContent = btn.dataset.sort === sort ? (dir === 'asc' ? '▲' : '▼') : ''; });
  }

  function renderPagination() {
    const nav = document.getElementById('pagination'); nav.replaceChildren(); if (result.total === 0) return;
    const add = (label, page, disabled = false, active = false) => { const b = document.createElement('button'); b.type='button'; b.textContent=label; b.disabled=disabled; b.classList.toggle('active',active); b.addEventListener('click',()=>goPage(page)); nav.append(b); };
    add('First',1,result.page===1); add('Previous',result.page-1,result.page===1);
    const start=Math.max(1,result.page-2), end=Math.min(result.pages,result.page+2); for(let p=start;p<=end;p++) add(String(p),p,false,p===result.page);
    add('Next',result.page+1,result.page===result.pages); add('Last',result.pages,result.page===result.pages);
    const info=document.createElement('span'); info.className='muted'; const first=(result.page-1)*result.per_page+1, last=Math.min(result.total,result.page*result.per_page); info.textContent='Showing '+first.toLocaleString()+' to '+last.toLocaleString()+' of '+result.total.toLocaleString(); nav.append(info);
  }

  function applyFilters() { clearSelection(); updateUrl(1); load(); }
  function goPage(page) { updateUrl(page); load(); window.scrollTo({top:document.querySelector('.result-bar').offsetTop-10,behavior:'smooth'}); }

  async function bulk(operation) {
    const note = document.getElementById('bulk-note').value.trim();
    if (operation === 'skip' && !note) { showMessage('Enter a reason before marking accounts do-not-follow.', true); document.getElementById('bulk-note').focus(); return; }
    const selectedStatuses = [...selection.values()];
    const eligible = operation === 'skip' ? (selectAll ? result.eligible_for_action : selectedStatuses.filter(status=>status==='pending').length) : (selectAll ? result.eligible_for_revert : selectedStatuses.filter(status=>status==='skipped').length);
    const unchanged = (selectAll ? result.total : selection.size) - eligible;
    const verb = operation === 'skip' ? 'mark ' + eligible.toLocaleString() + ' rows do-not-follow with note “' + note + '”' : 'revert ' + eligible.toLocaleString() + ' skipped rows to pending';
    if (!confirm('Confirm: ' + verb + '?' + (unchanged > 0 ? '\n' + unchanged.toLocaleString() + ' selected rows are ineligible and will remain unchanged.' : ''))) return;
    const payload = {operation}; if (operation === 'skip') payload.note = note;
    if (selectAll) { payload.select_all = true; payload.filters = filtersFromForm(); } else payload.ids = [...selection.keys()];
    document.querySelectorAll('#action-bar button').forEach(b=>b.disabled=true);
    try {
      const response = await api('accounts-bulk', {fetch:{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)}});
      clearSelection(); await load(); showMessage(response.changed.toLocaleString() + (operation === 'skip' ? ' rows marked do-not-follow.' : ' rows reverted to pending.') + (response.unchanged ? ' ' + response.unchanged.toLocaleString() + ' rows unchanged because they were ' + response.unchanged_reason + '.' : ''));
    } catch (e) { showMessage(e.message,true); renderSelection(); }
  }

  statusControls();
  form.addEventListener('submit', e => { e.preventDefault(); applyFilters(); });
  form.addEventListener('input', () => clearSelection());
  form.addEventListener('change', () => clearSelection());
  document.getElementById('reset-filters').addEventListener('click', () => { fillForm({}); sort='id'; dir='asc'; document.getElementById('per-page').value='100'; applyFilters(); });
  document.getElementById('per-page').addEventListener('change', () => { clearSelection(); updateUrl(1); load(); });
  document.getElementById('select-visible').addEventListener('change', e => { result.rows.filter(r=>r.follow_status==='pending').forEach(r=>e.target.checked?selection.set(Number(r.id),r.follow_status):selection.delete(Number(r.id))); renderRows(); renderSelection(); });
  document.getElementById('select-all').addEventListener('click', () => { selection.clear(); selectAll=true; renderRows(); renderSelection(); });
  document.getElementById('clear-selection').addEventListener('click', clearSelection);
  document.querySelectorAll('[data-sort]').forEach(btn => btn.addEventListener('click', () => { if(sort===btn.dataset.sort) dir=dir==='asc'?'desc':'asc'; else {sort=btn.dataset.sort;dir='asc';} clearSelection(); updateUrl(1); load(); }));
  document.getElementById('skip').addEventListener('click',()=>bulk('skip')); document.getElementById('revert').addEventListener('click',()=>bulk('revert'));
  const bulkNote=document.getElementById('bulk-note'), counter=document.getElementById('counter'); bulkNote.addEventListener('input',()=>counter.textContent=bulkNote.value.length+' / 255');
  ['kava bar','kratom vendor','smoke shop','CBD or hemp seller','competitor brand','wholesaler','bot or empty account'].forEach(reason=>{const b=document.createElement('button');b.type='button';b.textContent=reason;b.addEventListener('click',()=>{bulkNote.value=reason;bulkNote.dispatchEvent(new Event('input'));});document.getElementById('quick').append(b);});
  window.addEventListener('popstate',()=>{ const data=stateFromUrl(); fillForm(data); document.getElementById('per-page').value=new URLSearchParams(location.search).get('per_page')||'100'; clearSelection(); load(); });

  (async () => {
    try {
      const facets = await api('accounts-facets'); const category=document.getElementById('category');
      facets.categories.forEach(item=>{const option=document.createElement('option');option.value=item.category;option.textContent=item.category+' ('+Number(item.n).toLocaleString()+')';category.append(option);});
      const data=stateFromUrl(); fillForm(data); const pp=new URLSearchParams(location.search).get('per_page'); if(['50','100','200'].includes(pp))document.getElementById('per-page').value=pp;
      updateUrl(Number(new URLSearchParams(location.search).get('page')||1),true); await load();
    } catch(e) { showMessage(e.message,true); }
  })();
})();
</script>
</body>
</html>
