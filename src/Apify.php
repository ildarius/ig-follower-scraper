<?php
declare(strict_types=1);

/**
 * Thin Apify REST client. Only the three calls this project needs.
 *
 * API docs: https://docs.apify.com/api/v2
 * Actor:    https://apify.com/apify/instagram-followers-following-scraper
 */
final class Apify
{
    private const BASE = 'https://api.apify.com/v2';

    private string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? (string) Config::get('apify_token', '');
        if ($this->token === '' || str_contains($this->token, 'PUT_YOURS_HERE')) {
            throw new RuntimeException('Apify token is not set in config.php.');
        }
    }

    /**
     * Start an actor run. Returns the run object.
     *
     * The actor id uses a tilde in URLs: apify/x becomes apify~x.
     */
    public function startRun(string $actorId, array $input): array
    {
        $path = '/acts/' . str_replace('/', '~', $actorId) . '/runs';
        return $this->request('POST', $path, $input);
    }

    public function getRun(string $runId): array
    {
        return $this->request('GET', '/actor-runs/' . rawurlencode($runId));
    }

    /**
     * The authenticated account. Used to tell a paid plan apart from prepaid
     * credit on a free plan, which the actor treats very differently.
     */
    public function getMe(): array
    {
        return $this->request('GET', '/users/me');
    }

    /**
     * An actor's own record, including its pricing model and per-event prices.
     * The authoritative answer to "what does this actually cost", as opposed to
     * the "from $X" headline on its store page.
     */
    public function getActor(string $actorId): array
    {
        return $this->request('GET', '/acts/' . str_replace('/', '~', $actorId));
    }

    /**
     * This month's usage against the plan's prepaid credit.
     * Reading the real figure beats computing it from per-result prices, which
     * are quoted as "from $X" and can differ from the headline.
     */
    public function getMonthlyUsage(): array
    {
        return $this->request('GET', '/users/me/usage/monthly');
    }

    /**
     * Account limits, including the hard monthly spend ceiling.
     */
    public function getLimits(): array
    {
        return $this->request('GET', '/users/me/limits');
    }

    /**
     * The run's plain-text log. Useful when a run succeeds but returns less than
     * it should: the actor usually says why in here.
     */
    public function getRunLog(string $runId): string
    {
        $url = 'https://api.apify.com/v2/actor-runs/' . rawurlencode($runId) . '/log';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not fetch run log: ' . $err);
        }
        return (string) $raw;
    }

    /**
     * Dataset metadata, including itemCount.
     *
     * The run object does NOT carry the item count. Its `stats` holds compute
     * units and timings only, so the count has to come from the dataset itself.
     */
    public function getDataset(string $datasetId): array
    {
        return $this->request('GET', '/datasets/' . rawurlencode($datasetId));
    }

    /**
     * Abort a running actor run. Useful if a run was started by mistake,
     * since a pay-per-result actor keeps charging while it runs.
     */
    public function abortRun(string $runId): array
    {
        return $this->request('POST', '/actor-runs/' . rawurlencode($runId) . '/abort');
    }

    /**
     * One page of dataset items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDatasetItems(string $datasetId, int $offset, int $limit, bool $clean = true): array
    {
        // clean=true skips hidden fields (those prefixed with #) and empty items.
        // Pass false to see literally everything the actor wrote.
        $query = http_build_query([
            'offset' => $offset,
            'limit'  => $limit,
            'clean'  => $clean ? 'true' : 'false',
        ]);
        $items = $this->request('GET', '/datasets/' . rawurlencode($datasetId) . '/items?' . $query, null, false);
        return is_array($items) ? $items : [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param bool $unwrapData Apify wraps most responses in {"data": {...}}, dataset items are returned bare.
     */
    private function request(string $method, string $path, ?array $body = null, bool $unwrapData = true): array
    {
        $url = self::BASE . $path;

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                throw new RuntimeException('Could not encode request body as JSON.');
            }
            $options[CURLOPT_POSTFIELDS] = $json;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $raw    = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errStr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            throw new RuntimeException(sprintf('Apify request failed (%s %s): curl %d %s', $method, $path, $errNo, $errStr));
        }

        $decoded = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? substr((string) $raw, 0, 500);
            throw new RuntimeException(sprintf('Apify returned HTTP %d for %s %s: %s', $status, $method, $path, (string) $message));
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Apify returned non-JSON for %s %s: %s', $method, $path, substr((string) $raw, 0, 200)));
        }

        if ($unwrapData && array_key_exists('data', $decoded) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }
}
