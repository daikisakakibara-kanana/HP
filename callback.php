<?php
declare(strict_types=1);

/**
 * Instagram OAuth コールバック — 長期アクセストークン回収・表示
 * クライアントは Instagram ログイン → 承認のみ（約1分）
 */

// =============================================================================
// App Config（本番値は環境変数またはここに直接設定）
// =============================================================================
const INSTAGRAM_APP_ID         = ''; // 例: '1234567890123456'
const INSTAGRAM_APP_SECRET     = ''; // Meta App Dashboard の App Secret
const INSTAGRAM_REDIRECT_URI   = ''; // 例: 'https://your-domain.com/callback.php'
const INSTAGRAM_OAUTH_SCOPE    = 'instagram_business_basic';
const TOKEN_STORAGE_FILE       = __DIR__ . '/instagram-token.json';

// 環境変数があれば優先（本番推奨）
function cfg(string $envKey, string $constant): string
{
    $fromEnv = getenv($envKey);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    return $constant;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function curlRequest(string $method, string $url, array $options = []): array
{
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cURL の初期化に失敗しました。'];
    }

    $curlOpts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'AburamaruInstagramOAuth/1.0',
    ];

    if (strtoupper($method) === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        if (!empty($options['body'])) {
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
        return [
            'ok'       => false,
            'error'    => $error !== '' ? $error : '通信エラーが発生しました。',
            'httpCode' => $httpCode,
        ];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [
            'ok'       => false,
            'error'    => 'Instagram API から不正なレスポンスを受信しました。',
            'httpCode' => $httpCode,
            'raw'      => $body,
        ];
    }

    if ($httpCode >= 400 || isset($decoded['error_type']) || isset($decoded['error_message']) || isset($decoded['error'])) {
        $message = $decoded['error_message']
            ?? ($decoded['error']['message'] ?? null)
            ?? ($decoded['error']['error_user_msg'] ?? null)
            ?? 'Instagram API エラー（HTTP ' . $httpCode . '）';

        return [
            'ok'       => false,
            'error'    => (string) $message,
            'httpCode' => $httpCode,
            'data'     => $decoded,
        ];
    }

    return ['ok' => true, 'data' => $decoded, 'httpCode' => $httpCode];
}

function saveToken(array $tokenData): bool
{
    $payload = [
        'access_token' => $tokenData['access_token'],
        'user_id'      => $tokenData['user_id'] ?? '',
        'username'     => $tokenData['username'] ?? '',
        'expires_at'   => $tokenData['expires_at'],
        'updated_at'   => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }

    $dir = dirname(TOKEN_STORAGE_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }

    $tmp = TOKEN_STORAGE_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, TOKEN_STORAGE_FILE);
}

function exchangeCodeForShortToken(string $appId, string $appSecret, string $redirectUri, string $code): array
{
    $result = curlRequest('POST', 'https://api.instagram.com/oauth/access_token', [
        'body' => http_build_query([
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if (!$result['ok']) {
        return $result;
    }

    if (empty($result['data']['access_token'])) {
        return ['ok' => false, 'error' => '短期トークンの取得に失敗しました。'];
    }

    return $result;
}

function exchangeForLongLivedToken(string $appSecret, string $shortToken): array
{
    $query = http_build_query([
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => $appSecret,
        'access_token'  => $shortToken,
    ]);

    $result = curlRequest('GET', 'https://graph.instagram.com/access_token?' . $query);

    if (!$result['ok']) {
        return $result;
    }

    if (empty($result['data']['access_token'])) {
        return ['ok' => false, 'error' => '長期トークンの交換に失敗しました。'];
    }

    return $result;
}

function fetchInstagramUsername(string $accessToken): array
{
    $query = http_build_query([
        'fields'       => 'username,id',
        'access_token' => $accessToken,
    ]);

    $result = curlRequest('GET', 'https://graph.instagram.com/me?' . $query);

    if (!$result['ok']) {
        return $result;
    }

    return [
        'ok'       => true,
        'username' => (string) ($result['data']['username'] ?? ''),
        'user_id'  => (string) ($result['data']['id'] ?? ''),
    ];
}

function buildAuthorizeUrl(string $appId, string $redirectUri, string $scope): string
{
    return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
        'client_id'     => $appId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => $scope,
    ]);
}

function renderPage(string $title, string $bodyHtml, bool $isError = false): never
{
    $accent = $isError ? '#ef4444' : '#FBBF24';
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:"Noto Sans JP",system-ui,sans-serif;background:#FBBF24;color:#000;min-height:100vh;padding:24px}
    .wrap{max-width:920px;margin:0 auto}
    .panel{background:#fff;border:4px solid #000;box-shadow:10px 10px 0 #000;padding:28px}
    h1{font-size:clamp(24px,4vw,36px);font-weight:900;margin-bottom:12px;line-height:1.2}
    p{line-height:1.7;font-weight:600;margin-bottom:14px}
    .label{display:block;font-size:13px;font-weight:900;letter-spacing:.08em;margin:18px 0 8px}
    .token-box{background:<?= h($accent) ?>;border:4px solid #000;box-shadow:8px 8px 0 #000;padding:18px 20px;font-family:ui-monospace,monospace;font-size:clamp(14px,2.2vw,18px);font-weight:800;word-break:break-all;line-height:1.5}
    .btn{display:inline-block;margin-top:20px;background:#000;color:#FBBF24;border:4px solid #000;box-shadow:6px 6px 0 #000;padding:14px 24px;font-weight:900;text-decoration:none}
    .btn:hover{transform:translate(3px,3px);box-shadow:3px 3px 0 #000}
    .err{background:#fff5f5;border:4px solid #000;padding:16px;margin-bottom:16px}
    .hint{font-size:13px;color:#333;margin-top:8px}
    code{background:#eee;padding:2px 6px;border:1px solid #000}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="panel">
      <?= $bodyHtml ?>
    </div>
  </div>
</body>
</html>
    <?php
    exit;
}

// =============================================================================
// メイン処理
// =============================================================================
$appId       = cfg('INSTAGRAM_APP_ID', INSTAGRAM_APP_ID);
$appSecret   = cfg('INSTAGRAM_APP_SECRET', INSTAGRAM_APP_SECRET);
$redirectUri = cfg('INSTAGRAM_REDIRECT_URI', INSTAGRAM_REDIRECT_URI);
$scope       = cfg('INSTAGRAM_OAUTH_SCOPE', INSTAGRAM_OAUTH_SCOPE);

if ($appId === '' || $appSecret === '' || $redirectUri === '') {
    renderPage(
        '設定エラー',
        '<h1>App Config が未設定です</h1>
         <p><code>INSTAGRAM_APP_ID</code>・<code>INSTAGRAM_APP_SECRET</code>・<code>INSTAGRAM_REDIRECT_URI</code> を callback.php 上部または環境変数に設定してください。</p>',
        true
    );
}

if (isset($_GET['error'])) {
    $reason = (string) ($_GET['error_description'] ?? $_GET['error_reason'] ?? $_GET['error']);
    renderPage(
        '認可エラー',
        '<div class="err"><h1>Instagram ログインが中断されました</h1><p>' . h($reason) . '</p></div>
         <a class="btn" href="' . h(buildAuthorizeUrl($appId, $redirectUri, $scope)) . '">もう一度ログインする</a>',
        true
    );
}

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
if ($code !== '') {
    // Meta は code 末尾に #_ を付けることがある
    $code = preg_replace('/#_$/', '', $code) ?? $code;

    $short = exchangeCodeForShortToken($appId, $appSecret, $redirectUri, $code);
    if (!$short['ok']) {
        renderPage(
            'トークン取得エラー',
            '<div class="err"><h1>短期トークンの取得に失敗しました</h1><p>' . h($short['error']) . '</p></div>
             <a class="btn" href="' . h(buildAuthorizeUrl($appId, $redirectUri, $scope)) . '">再試行</a>',
            true
        );
    }

    $shortToken = (string) $short['data']['access_token'];
    $userId     = (string) ($short['data']['user_id'] ?? '');

    $long = exchangeForLongLivedToken($appSecret, $shortToken);
    if (!$long['ok']) {
        renderPage(
            'トークン交換エラー',
            '<div class="err"><h1>長期トークンへの交換に失敗しました</h1><p>' . h($long['error']) . '</p></div>
             <a class="btn" href="' . h(buildAuthorizeUrl($appId, $redirectUri, $scope)) . '">再試行</a>',
            true
        );
    }

    $longToken  = (string) $long['data']['access_token'];
    $expiresIn  = (int) ($long['data']['expires_in'] ?? 5184000);
    $expiresAt  = time() + $expiresIn;

    $profile = fetchInstagramUsername($longToken);
    $username = $profile['ok'] ? $profile['username'] : '';
    if ($profile['ok'] && $profile['user_id'] !== '') {
        $userId = $profile['user_id'];
    }

    $saved = saveToken([
        'access_token' => $longToken,
        'user_id'      => $userId,
        'username'     => $username,
        'expires_at'   => $expiresAt,
    ]);

    $saveNote = $saved
        ? '<p class="hint">✅ トークンは <code>instagram-token.json</code> に自動保存されました。各 LP の instagram-feed.php から利用されます。</p>'
        : '<p class="hint">⚠️ ファイル保存に失敗しました。下記トークンを手動で instagram-feed.php に設定してください。</p>';

    $body = '
      <h1>🎉 長期アクセストークンの取得に成功しました</h1>
      <p>以下をコピーして、各店舗 LP の <code>instagram-feed.php</code> に反映するか、そのまま自動保存ファイルをご利用ください。</p>
      ' . $saveNote . '
      <span class="label">Instagram ユーザー名</span>
      <div class="token-box">@' . h($username !== '' ? $username : '取得できませんでした') . '</div>
      <span class="label">長期アクセストークン（60日・自動延長対応）</span>
      <div class="token-box" id="token">' . h($longToken) . '</div>
      <p class="hint">有効期限: 約 ' . h((string) (int) floor($expiresIn / 86400)) . ' 日（' . h(gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC') . ' まで）</p>
      <a class="btn" href="' . h(buildAuthorizeUrl($appId, $redirectUri, $scope)) . '">別アカウントで再取得</a>
    ';

    renderPage('トークン取得完了', $body);
}

// 初回: ログイン導線
$authUrl = buildAuthorizeUrl($appId, $redirectUri, $scope);
renderPage(
    'Instagram 連携',
    '<h1>Instagram アクセストークン自動回収</h1>
     <p>下のボタンから Instagram にログインし、表示された権限を承認するだけで完了です（約1分）。</p>
     <a class="btn" href="' . h($authUrl) . '">Instagram でログインして承認する</a>
     <p class="hint">リダイレクト URI: <code>' . h($redirectUri) . '</code></p>'
);
