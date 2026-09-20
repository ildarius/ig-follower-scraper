<?php
/**
 * Page guard. Included at the very top of every HTML page, before any output.
 *
 * Usage, as the first two statements of the page:
 *
 *     require dirname(__DIR__, 2) . '/bootstrap.php';
 *     $authUser = require __DIR__ . '/page-guard.php';
 *
 * It returns the signed-in user so the page can show who is signed in and hide
 * controls the role cannot use. Hiding a control is presentation only. The
 * decision that matters is made in api.php, which checks the role again on
 * every request.
 */
declare(strict_types=1);

$guardPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

$guardUser = Auth::user();

if ($guardUser === null) {
    // Not signed in. The builtin provider has a login form to send them to.
    // The other providers do not, so they get a plain 401 instead of a
    // redirect to a page that would not help them.
    if (Auth::provider() === 'builtin') {
        header('Location: login.php?next=' . rawurlencode($guardPage));
        exit;
    }

    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not signed in.\n";
    exit;
}

if (!Acl::allowsPage($guardUser['role'], $guardPage)) {
    // 403 and not a redirect. A redirect to a login page would confirm that
    // this page exists and is merely out of reach, which is information the
    // operator role has no reason to be given.
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden.\n";
    exit;
}

return $guardUser;
