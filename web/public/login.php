<?php
/**
 * Reuse the parent site's login/logout under external authentication.
 * The builtin provider has its own sign-in form.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$provider = Auth::provider();
$error    = null;

// Only a bare file name is accepted as the post-login destination, so that this
// page cannot be used to bounce a visitor to another site.
$next = basename((string) ($_GET['next'] ?? $_POST['next'] ?? 'accounts.php'));
if (!preg_match('/^[a-z0-9_-]+\.php$/i', $next)) {
    $next = 'accounts.php';
}

if ($provider === 'external') {
    if (isset($_GET['logout'])) {
        header('Location: ' . Auth::logoutUrl());
    } else {
        $user = Auth::user();
        // Do not redirect back to login.php, even if requested via ?next=.
        $destination = in_array($next, ['index.php', 'accounts.php'], true)
            && $user !== null && Acl::allowsPage($user['role'], $next)
            ? $next : 'accounts.php';
        header('Location: ' . ($user === null ? Auth::loginUrl() : $destination));
    }
    exit;
}

if (isset($_GET['logout'])) {
    Auth::logout();
    header('Location: login.php');
    exit;
}

if ($provider === 'builtin' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $role = Auth::attemptLogin($username, $password);

    if ($role === null) {
        // One message for both a wrong username and a wrong password. Telling
        // them apart would confirm which usernames exist.
        $error = 'Wrong username or password.';
        usleep(400000);
    } else {
        $destination = Acl::allowsPage($role, $next) ? $next : 'accounts.php';
        header('Location: ' . $destination);
        exit;
    }
}

$alreadyIn = Auth::user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in</title>
<style>
  :root {
    --bg:#fff; --fg:#1b1f24; --muted:#5c6771; --line:#dfe3e8; --accent:#2f6f4f;
    --err:#9b2226; --panel:#f6f7f9;
  }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#14171a; --fg:#e8eaed; --muted:#9aa4af; --line:#2c3238; --accent:#6cc39a;
      --err:#e08585; --panel:#1c2024; }
  }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
    background:var(--bg); color:var(--fg); padding:24px;
    font:15px/1.45 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .card { width:100%; max-width:360px; background:var(--panel); border:1px solid var(--line);
    border-radius:8px; padding:22px; }
  h1 { font-size:19px; margin:0 0 4px; }
  p.sub { margin:0 0 18px; color:var(--muted); font-size:13px; }
  label { display:block; font-size:13px; color:var(--muted); margin:0 0 4px; }
  input[type=text], input[type=password] { width:100%; padding:8px 10px; margin-bottom:14px;
    border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--fg);
    font:inherit; }
  button { width:100%; padding:9px 12px; border:0; border-radius:6px; background:var(--accent);
    color:#fff; font:inherit; font-weight:600; cursor:pointer; }
  .err { color:var(--err); font-size:13px; margin:0 0 14px; }
  .note { color:var(--muted); font-size:13px; margin:14px 0 0; }
  a { color:var(--accent); }
</style>
</head>
<body>
<div class="card">
<?php if ($provider !== 'builtin'): ?>
  <h1>Sign in</h1>
  <p class="sub">IG follower scraper</p>
  <p class="note">
    This installation authenticates with the <strong><?= htmlspecialchars($provider, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
    provider, which has no form of its own. Sign in through whatever already
    protects this host, then open
    <a href="accounts.php">accounts.php</a>.
  </p>
<?php elseif ($alreadyIn !== null): ?>
  <h1>Signed in</h1>
  <p class="sub">
    <?= htmlspecialchars($alreadyIn['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>,
    <?= htmlspecialchars($alreadyIn['role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
  </p>
  <p class="note">
    <a href="accounts.php">Accounts browser</a>
    <?php if ($alreadyIn['role'] === Auth::ROLE_ADMIN): ?>
      &middot; <a href="index.php">Dashboard</a>
    <?php endif; ?>
    &middot; <a href="login.php?logout=1">Sign out</a>
  </p>
<?php else: ?>
  <h1>Sign in</h1>
  <p class="sub">IG follower scraper</p>
  <?php if ($error !== null): ?>
    <p class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
  <?php endif; ?>
  <form method="post" action="login.php">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" autocomplete="username" autofocus required>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
