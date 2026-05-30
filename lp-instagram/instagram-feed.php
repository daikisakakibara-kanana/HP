<?php
declare(strict_types=1);

/**
 * 店舗個別 Instagram フィード中継（各 LP サーバーに配置）
 * 同階層の instagram-token.json を読み込み、2時間キャッシュで最新3件を返却
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

const CACHE_TTL_SECONDS = 7200;
const CACHE_FILE        = __DIR__ . '/instagram-feed-cache.json';
const TOKEN_FILE        = __DIR__ . '/instagram-token.json';
const GRAPH_API_VERSION = 'v20.0';
const MEDIA_FIELDS      = 'id,media_url,permalink,caption,thumbnail_url,media_type';
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

function graphUrl(string $path, array $query = []): string
{
    $base = 'https://graph.facebook.com/' . GRAPH_API_VERSION . '/' . ltrim($path, '/');
    if ($query === []) {
        return $base;
    }
    return $base . '?' . http_build_query($query);
}

function curlRequest(string $method, string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL 拡張が有効化されていません。'];
    }

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL の初期化に失敗しました。'];
    }

    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 28,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'KananaInstagramFeed/2.0',
    ];

    if (strtoupper($method) === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        if (isset($options['body'])) {
            $curlOpts[CURLOPT_POSTFIELDS] = $options['body'];
        }
    }

    if (!empty($options['headers'])) {
        $curlOpts[CURLOPT_HTTPHEADER] = $options['headers'];
    }

    curl_setopt_array($ch, $curlOpts);

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
            ?? 'Instagram Graph API エラー（HTTP ' . $httpCode . '）';
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
    if ($raw === false || trim($raw) === '') {
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

function loadTokenFromFile(): array
{
    $file = readJsonFile(TOKEN_FILE);
    if ($file === null) {
        return [
            'ok'    => false,
            'error' => 'instagram-token.json が見つかりません。callback.php で取得した JSON を同階層に配置してください。',
        ];
    }

    $token = trim((string) ($file['access_token'] ?? ''));
    $igId  = trim((string) ($file['instagram_business_account_id'] ?? $file['user_id'] ?? ''));

    if ($token === '') {
        return ['ok' => false, 'error' => 'instagram-token.json に access_token がありません。'];
    }
    if ($igId === '') {
        return ['ok' => false, 'error' => 'instagram-token.json に instagram_business_account_id がありません。'];
    }

    return [
        'ok'                            => true,
        'access_token'                  => $token,
        'instagram_business_account_id' => $igId,
        'username'                      => (string) ($file['username'] ?? ''),
        'expires_at'                    => (int) ($file['expires_at'] ?? 0),
        'updated_at'                    => (string) ($file['updated_at'] ?? ''),
    ];
}

function persistToken(array $tokenData): void
{
    writeJsonFile(TOKEN_FILE, [
        'access_token'                  => $tokenData['access_token'],
        'instagram_business_account_id' => $tokenData['instagram_business_account_id'],
        'username'                      => $tokenData['username'],
        'expires_at'                    => $tokenData['expires_at'],
        'updated_at'                    => gmdate('c'),
    ]);
}

/**
 * 長期トークン自動延長（最大60日）
 * graph.facebook.com / graph.instagram.com の refresh_access_token
 */
function refreshLongLivedTokenIfNeeded(array $meta): array
{
    $token = $meta['access_token'];
    if ($token === '') {
        return $meta;
    }

    $now = time();
    $expiresAt = (int) $meta['expires_at'];
    $updatedAtTs = !empty($meta['updated_at']) ? (strtotime($meta['updated_at']) ?: 0) : 0;

    $expiresSoon = ($expiresAt > 0 && $expiresAt <= ($now + 7 * 86400));
    $oldEnough   = ($updatedAtTs === 0 || ($now - $updatedAtTs) >= 86400);

    if (!$expiresSoon && !$oldEnough) {
        return $meta;
    }

    $query = http_build_query([
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $token,
    ]);

    $endpoints = [
        graphUrl('refresh_access_token', [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $token,
        ]),
        'https://graph.instagram.com/refresh_access_token?' . $query,
    ];

    $result = null;
    foreach ($endpoints as $url) {
        $result = curlGet($url);
        if ($result['ok'] && !empty($result['data']['access_token'])) {
            break;
        }
    }

    if ($result === null || !$result['ok'] || empty($result['data']['access_token'])) {
        return $meta;
    }

    $newToken = (string) $result['data']['access_token'];
    $expiresIn = (int) ($result['data']['expires_in'] ?? 5184000);
    $newExpiresAt = $now + $expiresIn;

    $meta['access_token'] = $newToken;
    $meta['expires_at']   = $newExpiresAt;
    $meta['updated_at']   = gmdate('c');
    $meta['refreshed']    = true;

    persistToken($meta);

    return $meta;
}

function normalizeCaption(?string $caption): string
{
    if ($caption === null || $caption === '') {
        return '';
    }
    return preg_replace('/\s+/u', ' ', trim($caption)) ?? '';
}

function pickMediaUrl(array $item): string
{
    $type = strtoupper((string) ($item['media_type'] ?? 'IMAGE'));
    if ($type === 'VIDEO' || $type === 'REELS' || $type === 'CAROUSEL_ALBUM') {
        $thumb = (string) ($item['thumbnail_url'] ?? '');
        if ($thumb !== '') {
            return $thumb;
        }
    }
    return (string) ($item['media_url'] ?? $item['thumbnail_url'] ?? '');
}

function fetchLatestPosts(string $igAccountId, string $accessToken): array
{
    $result = curlGet(graphUrl($igAccountId . '/media', [
        'fields'       => MEDIA_FIELDS,
        'limit'        => MEDIA_LIMIT,
        'access_token' => $accessToken,
    ]));

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error']];
    }

    $items = $result['data']['data'] ?? [];
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
try {
    $cached = loadCache();
    if ($cached !== null && !empty($cached['posts']) && is_array($cached['posts'])) {
        respond([
            'success'  => true,
            'cached'   => true,
            'username' => (string) ($cached['username'] ?? ''),
            'posts'    => array_slice($cached['posts'], 0, MEDIA_LIMIT),
        ]);
    }

    $tokenMeta = loadTokenFromFile();
    if (!$tokenMeta['ok']) {
        respondError($tokenMeta['error'], 503);
    }

    $tokenMeta = refreshLongLivedTokenIfNeeded($tokenMeta);

    $feed = fetchLatestPosts(
        $tokenMeta['instagram_business_account_id'],
        $tokenMeta['access_token']
    );

    if (!$feed['ok']) {
        respondError($feed['error'], 502);
    }

    $username = (string) $tokenMeta['username'];
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
