<?php
declare(strict_types=1);

/**
 * Instagram フィード API 中継
 * - 最新3件を2時間ファイルキャッシュ
 * - キャッシュ更新時に長期トークンを自動延長
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

// callback.php で回収した長期トークン（自動保存ファイルがあればそちらを優先）
$access_token = '';

const CACHE_TTL_SECONDS = 7200;
const CACHE_FILE        = __DIR__ . '/instagram-feed-cache.json';
const TOKEN_FILE        = __DIR__ . '/instagram-token.json';
const APP_SECRET_ENV    = 'INSTAGRAM_APP_SECRET';
const GRAPH_API_BASE    = 'https://graph.instagram.com';
const MEDIA_FIELDS      = 'id,caption,media_type,media_url,thumbnail_url,permalink';
const MEDIA_LIMIT       = 3;

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(string $message, int $status = 500): never
{
    respond([
        'success' => false,
        'error'   => $message,
        'posts'   => [],
    ], $status);
}

function curlGet(string $url): array
{
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL の初期化に失敗しました。'];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'AburamaruInstagramFeed/1.0',
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $body === false) {
        return ['ok' => false, 'error' => $error !== '' ? $error : '通信エラー', 'httpCode' => $httpCode];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'JSON 解析エラー', 'httpCode' => $httpCode];
    }

    if ($httpCode >= 400 || isset($decoded['error'])) {
        $msg = $decoded['error_message']
            ?? ($decoded['error']['message'] ?? null)
            ?? 'Instagram API エラー（HTTP ' . $httpCode . '）';
        return ['ok' => false, 'error' => (string) $msg, 'httpCode' => $httpCode, 'data' => $decoded];
    }

    return ['ok' => true, 'data' => $decoded, 'httpCode' => $httpCode];
}

function readJsonFile(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonFile(string $path, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $path);
}

function resolveAccessToken(string $inlineToken): array
{
    $tokenFile = readJsonFile(TOKEN_FILE);
    if ($tokenFile !== null && !empty($tokenFile['access_token'])) {
        return [
            'token'      => (string) $tokenFile['access_token'],
            'username'   => (string) ($tokenFile['username'] ?? ''),
            'expires_at' => (int) ($tokenFile['expires_at'] ?? 0),
            'updated_at' => (string) ($tokenFile['updated_at'] ?? ''),
            'source'     => 'file',
        ];
    }

    if ($inlineToken !== '') {
        return [
            'token'      => $inlineToken,
            'username'   => '',
            'expires_at' => 0,
            'updated_at' => '',
            'source'     => 'inline',
        ];
    }

    return ['token' => '', 'username' => '', 'expires_at' => 0, 'updated_at' => '', 'source' => 'none'];
}

function persistToken(string $token, string $username, int $expiresAt): void
{
    $existing = readJsonFile(TOKEN_FILE) ?? [];
    writeJsonFile(TOKEN_FILE, [
        'access_token' => $token,
        'user_id'      => (string) ($existing['user_id'] ?? ''),
        'username'     => $username !== '' ? $username : (string) ($existing['username'] ?? ''),
        'expires_at'   => $expiresAt,
        'updated_at'   => gmdate('c'),
    ]);
}

/**
 * 長期トークンを自動延長（60日リフレッシュ）
 * - トークンが24時間以上経過している必要あり（Meta 仕様）
 * - 期限7日以内、または updated_at から24h以上経過で試行
 */
function refreshLongLivedTokenIfNeeded(array $tokenMeta): array
{
    $token = $tokenMeta['token'];
    if ($token === '') {
        return $tokenMeta;
    }

    $now = time();
    $expiresAt = (int) $tokenMeta['expires_at'];
    $updatedAtTs = 0;
    if (!empty($tokenMeta['updated_at'])) {
        $updatedAtTs = strtotime($tokenMeta['updated_at']) ?: 0;
    }

    $expiresSoon = ($expiresAt > 0 && $expiresAt <= ($now + 7 * 86400));
    $oldEnough   = ($updatedAtTs === 0 || ($now - $updatedAtTs) >= 86400);

    if (!$expiresSoon && !$oldEnough) {
        return $tokenMeta;
    }

    $query = http_build_query([
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $token,
    ]);

    $result = curlGet(GRAPH_API_BASE . '/refresh_access_token?' . $query);
    if (!$result['ok'] || empty($result['data']['access_token'])) {
        // 24時間未満などで失敗する場合は現行トークンを継続利用
        return $tokenMeta;
    }

    $newToken = (string) $result['data']['access_token'];
    $expiresIn = (int) ($result['data']['expires_in'] ?? 5184000);
    $newExpiresAt = $now + $expiresIn;

    persistToken($newToken, (string) $tokenMeta['username'], $newExpiresAt);

    return [
        'token'      => $newToken,
        'username'   => (string) $tokenMeta['username'],
        'expires_at' => $newExpiresAt,
        'updated_at' => gmdate('c'),
        'source'     => (string) $tokenMeta['source'],
        'refreshed'  => true,
    ];
}

function normalizeCaption(?string $caption): string
{
    if ($caption === null || $caption === '') {
        return '';
    }
    $caption = preg_replace('/\s+/u', ' ', trim($caption)) ?? $caption;
    return $caption;
}

function pickMediaUrl(array $item): string
{
    $type = strtoupper((string) ($item['media_type'] ?? 'IMAGE'));
    if ($type === 'VIDEO' || $type === 'REELS') {
        return (string) ($item['thumbnail_url'] ?? $item['media_url'] ?? '');
    }
    return (string) ($item['media_url'] ?? $item['thumbnail_url'] ?? '');
}

function fetchLatestPosts(string $accessToken): array
{
    $query = http_build_query([
        'fields'       => MEDIA_FIELDS,
        'limit'        => MEDIA_LIMIT,
        'access_token' => $accessToken,
    ]);

    $media = curlGet(GRAPH_API_BASE . '/me/media?' . $query);
    if (!$media['ok']) {
        return ['ok' => false, 'error' => $media['error']];
    }

    $items = $media['data']['data'] ?? [];
    if (!is_array($items)) {
        return ['ok' => false, 'error' => '投稿データの形式が不正です。'];
    }

    $posts = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $mediaUrl = pickMediaUrl($item);
        if ($mediaUrl === '') {
            continue;
        }
        $posts[] = [
            'media_url' => $mediaUrl,
            'permalink' => (string) ($item['permalink'] ?? ''),
            'caption'   => normalizeCaption(isset($item['caption']) ? (string) $item['caption'] : ''),
        ];
        if (count($posts) >= MEDIA_LIMIT) {
            break;
        }
    }

    return ['ok' => true, 'posts' => $posts];
}

function fetchUsername(string $accessToken, string $known = ''): string
{
    if ($known !== '') {
        return $known;
    }
    $query = http_build_query([
        'fields'       => 'username',
        'access_token' => $accessToken,
    ]);
    $result = curlGet(GRAPH_API_BASE . '/me?' . $query);
    if (!$result['ok']) {
        return '';
    }
    return (string) ($result['data']['username'] ?? '');
}

function loadCache(): ?array
{
    $cache = readJsonFile(CACHE_FILE);
    if ($cache === null || empty($cache['fetched_at'])) {
        return null;
    }
    if ((time() - (int) $cache['fetched_at']) >= CACHE_TTL_SECONDS) {
        return null;
    }
    return $cache;
}

function saveCache(array $posts, string $username): void
{
    writeJsonFile(CACHE_FILE, [
        'fetched_at' => time(),
        'username'   => $username,
        'posts'      => $posts,
    ]);
}

// =============================================================================
// エントリーポイント
// =============================================================================
try {
    if (!function_exists('curl_init')) {
        respondError('サーバーに cURL 拡張が有効化されていません。', 500);
    }

    $cached = loadCache();
    if ($cached !== null && !empty($cached['posts']) && is_array($cached['posts'])) {
        respond([
            'success'  => true,
            'cached'   => true,
            'username' => (string) ($cached['username'] ?? ''),
            'posts'    => array_slice($cached['posts'], 0, MEDIA_LIMIT),
        ]);
    }

    $tokenMeta = resolveAccessToken($access_token);
    if ($tokenMeta['token'] === '') {
        respondError('Instagram アクセストークンが未設定です。callback.php でトークンを取得してください。', 503);
    }

    $tokenMeta = refreshLongLivedTokenIfNeeded($tokenMeta);
    $token = $tokenMeta['token'];

    $feed = fetchLatestPosts($token);
    if (!$feed['ok']) {
        respondError($feed['error'], 502);
    }

    $username = fetchUsername($token, (string) $tokenMeta['username']);
    if ($username !== '' && $username !== $tokenMeta['username']) {
        persistToken($token, $username, (int) $tokenMeta['expires_at']);
    }

    $posts = $feed['posts'];
    saveCache($posts, $username);

    respond([
        'success'   => true,
        'cached'    => false,
        'refreshed' => !empty($tokenMeta['refreshed']),
        'username'  => $username,
        'posts'     => $posts,
    ]);
} catch (Throwable $e) {
    respondError('サーバー内部エラー: ' . $e->getMessage(), 500);
}
