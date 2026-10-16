<?php
declare(strict_types=1);

/**
 * Minimal HikerAPI (hikerapi.com) client: one profile lookup, for the review
 * screen's Fetch photo button.
 *
 * The SEO dashboard one level up has a fuller client (lib/hikerapi.php), but it
 * logs into that site's database, and this app is its own repository, so it
 * carries its own. Same account and token: config.php 'hikerapi' => ['token'].
 *
 * HikerAPI reaches Instagram with its own access, not with the follow script's
 * @verifiedbotanicals session, so a lookup puts nothing of ours at risk.
 * Billed per request ($0.001 on the Standard plan as of 2026-10-16).
 */
final class HikerApi
{
    private const BASE = 'https://api.hikerapi.com';

    /** The profile as HikerAPI returns it: pk, username, profile_pic_url_hd, ... */
    public static function userByUsername(string $username): array
    {
        $token = (string) Config::get('hikerapi.token', '');
        if ($token === '') {
            throw new RuntimeException('HikerAPI is not configured: add hikerapi.token to config.php.');
        }

        $ch = curl_init(self::BASE . '/v1/user/by/username?' . http_build_query(['username' => $username]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['x-access-key: ' . $token, 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('HikerAPI could not be reached: ' . $error);
        }
        $body = json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($body)) {
            // The token is never echoed: only the status and HikerAPI's own message.
            $detail = is_array($body) ? (string) ($body['detail'] ?? $body['message'] ?? $body['error'] ?? '') : '';
            throw new RuntimeException('HikerAPI returned HTTP ' . $status
                . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : '') . '.');
        }

        return isset($body['user']) && is_array($body['user']) ? $body['user'] : $body;
    }
}
