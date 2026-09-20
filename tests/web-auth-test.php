<?php
/**
 * HTTP regressions using a temporary app copy and synthetic login sessions.
 * No production sessions, config, database, or Apify calls are used.
 * Run: php tests/web-auth-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/igfs-web-auth-' . bin2hex(random_bytes(8));
$process = null;
$checks = 0;

function expect(string $label, mixed $actual, mixed $expected): void
{
    global $checks;
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': got ' . json_encode($actual) . ', expected ' . json_encode($expected));
    }
    $checks++;
}

try {
    mkdir($temp, 0700);
    foreach (['app/src', 'app/web/public', 'sessions'] as $path) {
        mkdir($temp . '/' . $path, 0700, true);
    }
    copy($root . '/bootstrap.php', $temp . '/app/bootstrap.php');
    foreach (['src', 'web/public'] as $path) {
        foreach (glob($root . '/' . $path . '/*.php') as $file) {
            copy($file, $temp . '/app/' . $path . '/' . basename($file));
        }
    }

    ini_set('session.save_path', $temp . '/sessions');
    ini_set('session.use_cookies', '0');
    session_cache_limiter('');
    session_name('PHPSESSID');
    $sessions = [];
    foreach (['admin' => 'admin', 'operator' => 'marketer', 'unknown' => 'guest'] as $name => $role) {
        session_id(bin2hex(random_bytes(16)));
        session_start();
        $_SESSION = [
            'user' => ['email' => $name . '@example.com'],
            'app_user' => ['email' => $name . '@example.com', 'role' => $role, 'is_active' => 1],
            'oauth_state' => 'must-be-preserved',
        ];
        $sessions[$name] = session_id();
        session_write_close();
    }
    $adminFile = $temp . '/sessions/sess_' . $sessions['admin'];
    $originalSession = file_get_contents($adminFile);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Cannot reserve a local test port: ' . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open([
        PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0',
        '-d', 'session.save_path=' . $temp . '/sessions',
        '-S', $address, '-t', $temp . '/app',
    ], [0 => ['pipe', 'r'], 1 => ['file', $temp . '/server.log', 'a'],
        2 => ['file', $temp . '/server.log', 'a']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the PHP test server.');
    }
    fclose($pipes[0]);

    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException('Test server did not start: ' . file_get_contents($temp . '/server.log'));
    }

    $request = static function (string $path, ?string $session = null, array $extraHeaders = []) use ($address): array {
        $headers = [];
        if ($session !== null) {
            $extraHeaders[] = 'Cookie: PHPSESSID=' . $session;
        }
        $curl = curl_init('http://' . $address . '/web/public/' . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => $extraHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        if ($body === false) {
            throw new RuntimeException(curl_error($curl));
        }
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        expect('response contains no PHP warnings', str_contains($body, 'Warning:') || str_contains($body, 'Fatal error:'), false);
        expect('response is not cacheable', str_contains($headers['cache-control'] ?? '', 'no-store'), true);
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    };

    foreach (['index.php', 'accounts.php', 'login.php'] as $page) {
        $response = $request($page);
        expect($page . ' redirects anonymously', $response['status'], 302);
        expect($page . ' uses parent login', $response['headers']['location'] ?? null, '/login.php');
    }
    $response = $request('api.php?action=dump');
    expect('anonymous API is denied', $response['status'], 401);
    expect('anonymous API keeps JSON contract', json_decode($response['body'], true),
        ['ok' => false, 'error' => 'Not signed in.', 'login' => '/login.php']);

    $response = $request('api.php?action=start', null,
        ['X-Auth-User: admin@example.com', 'X-Auth-Role: admin', 'Cookie: user=admin@example.com; role=admin']);
    expect('forged identity headers/cookies are denied', $response['status'], 401);
    $response = $request('api.php?action=start', bin2hex(random_bytes(16)));
    expect('unknown session id is denied', $response['status'], 401);

    $response = $request('accounts.php', $sessions['operator']);
    expect('parent marketer opens accounts', $response['status'], 200);
    expect('accounts HTML is rendered', str_contains($response['body'], '<title>Accounts Browser</title>'), true);
    $response = $request('index.php', $sessions['operator']);
    expect('operator cannot open dashboard', $response['status'], 403);
    foreach (['start', 'enrich', 'dump', 'migrate'] as $action) {
        $response = $request('api.php?action=' . $action, $sessions['operator']);
        expect('operator cannot call ' . $action, $response['status'], 403);
    }
    $response = $request('api.php?action=accounts-search', $sessions['operator']);
    expect('operator passes auth for accounts search before missing config', $response['status'], 503);

    $response = $request('index.php', $sessions['admin']);
    expect('parent admin opens dashboard', $response['status'], 200);
    $response = $request('api.php?action=start', $sessions['admin']);
    expect('admin passes auth, but unconfigured actions do not run', $response['status'], 503);
    expect('API explains missing setup', json_decode($response['body'], true)['error'] ?? null,
        'Application setup is incomplete: config.php is missing.');
    expect('shared session remains intact', file_get_contents($adminFile), $originalSession);

    $response = $request('accounts.php', $sessions['unknown']);
    expect('unknown host role is denied', $response['status'], 302);
    expect('unknown host role gets parent login', $response['headers']['location'] ?? null, '/login.php');

    $response = $request('login.php?next=index.php', $sessions['operator']);
    expect('operator login lands on accounts', $response['headers']['location'] ?? null, 'accounts.php');
    $response = $request('login.php?next=login.php', $sessions['admin']);
    expect('login cannot redirect to itself', $response['headers']['location'] ?? null, 'accounts.php');
    $response = $request('login.php?next=https://example.com/evil.php', $sessions['admin']);
    expect('login cannot redirect off-site', $response['headers']['location'] ?? null, 'accounts.php');
    $response = $request('login.php?logout=1', $sessions['admin']);
    expect('logout uses the parent endpoint', $response['headers']['location'] ?? null, '/logout.php');
    expect('redirect does not destroy the shared session', file_get_contents($adminFile), $originalSession);

    unlink($temp . '/sessions/sess_' . $sessions['operator']);
    $response = $request('api.php?action=accounts-search', $sessions['operator']);
    expect('expired session loses API access', $response['status'], 401);
    $response = $request('accounts.php', $sessions['operator']);
    expect('expired session loses page access', $response['status'], 302);

    echo $checks . " HTTP authentication checks passed.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_dir($temp)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temp);
    }
}
