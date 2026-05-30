<?php
declare(strict_types=1);

/** 致命的エラー時も画面に表示（500 の空ページを防ぐ） */
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>PHP エラー</title></head><body style="font-family:sans-serif;padding:24px">';
    echo '<h1>callback.php で PHP エラーが発生しました</h1>';
    echo '<pre style="background:#fff;padding:12px;border:2px solid #000">';
    echo htmlspecialchars($err['message'] . "\n" . $err['file'] . ':' . $err['line'], ENT_QUOTES, 'UTF-8');
    echo '</pre>';
    echo '<p>サーバーで <code>php-curl</code> が有効か確認してください: <code>php -m | grep curl</code></p>';
    echo '</body></html>';
});

/**
 * 共通 OAuth コールバック（自社ドメインに1枚のみ配置）
 * 例: https://kanana-tech.jp/callback.php
 *
 * クライアント: Instagram ログイン → 許可（約1分）
 * 制作側: 画面の JSON を各店舗 LP の instagram-token.json にコピー
 */

// =============================================================================
// App Config
// =============================================================================
const INSTAGRAM_APP_ID       = '1470160134857234';
const INSTAGRAM_APP_SECRET   = '6696b8c1d3e8c4964094aebbac4a81fd';
const INSTAGRAM_REDIRECT_URI = 'https://insta-api.kanana-tech.jp/insta-token/callback.php';
const INSTAGRAM_OAUTH_SCOPE  = 'instagram_business_basic';
const GRAPH_API_VERSION      = 'v20.0';

function cfg(string $envKey, string $constant): string
{
    $fromEnv = getenv($envKey);
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }
    return $constant;
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function errorMessage(mixed $value, string $fallback = '不明なエラー'): string
{
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    return $fallback;
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
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'KananaInstagramOAuth/2.0',
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
            'error'    => 'API から不正なレスポンスを受信しました。',
            'httpCode' => $httpCode,
            'raw'      => $body,
        ];
    }

    if ($httpCode >= 400 || isset($decoded['error']) || isset($decoded['error_type']) || isset($decoded['error_message'])) {
        $message = $decoded['error_message']
            ?? ($decoded['error']['message'] ?? null)
            ?? ($decoded['error']['error_user_msg'] ?? null)
            ?? 'API エラー（HTTP ' . $httpCode . '）';

        return [
            'ok'       => false,
            'error'    => (string) $message,
            'httpCode' => $httpCode,
            'data'     => $decoded,
        ];
    }

    return ['ok' => true, 'data' => $decoded, 'httpCode' => $httpCode];
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

/** 認可コード → 短期トークン（Instagram Login 公式） */
function exchangeCodeForShortToken(string $appId, string $appSecret, string $redirectUri, string $code): array
{
    return curlRequest('POST', 'https://api.instagram.com/oauth/access_token', [
        'body'    => http_build_query([
            'client_id'     => $appId,
            'client_secret' => $appSecret,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
}

/** 短期トークン応答の正規化（API 仕様差異に対応） */
function normalizeShortTokenResponse(array $result): array
{
    if (!$result['ok'] || !isset($result['data']) || !is_array($result['data'])) {
        return $result;
    }

    $data = $result['data'];

    if (!empty($data['access_token'])) {
        return $result;
    }

    if (isset($data['data'][0]['access_token'])) {
        $result['data']['access_token'] = $data['data'][0]['access_token'];
        $result['data']['user_id']      = $data['data'][0]['user_id'] ?? '';
        return $result;
    }

    return ['ok' => false, 'error' => '短期トークンの形式が想定外です。', 'data' => $data];
}

/**
 * 短期 → 60日長期
 * 1) graph.instagram.com + ig_exchange_token（Instagram Login 公式）
 * 2) graph.facebook.com + fb_exchange_token + client_id（フォールバック）
 */
function exchangeForLongLivedToken(string $appId, string $appSecret, string $shortToken): array
{
    $igResult = curlRequest('GET', 'https://graph.instagram.com/access_token?' . http_build_query([
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => $appSecret,
        'access_token'  => $shortToken,
    ]));

    if ($igResult['ok'] && !empty($igResult['data']['access_token'])) {
        $igResult['exchange_via'] = 'graph.instagram.com';
        return $igResult;
    }

    $igError = (string) ($igResult['error'] ?? 'graph.instagram.com での交換に失敗');

    $fbResult = curlRequest('GET', graphUrl('oauth/access_token', [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $appSecret,
        'fb_exchange_token' => $shortToken,
    ]));

    if ($fbResult['ok'] && !empty($fbResult['data']['access_token'])) {
        $fbResult['exchange_via'] = 'graph.facebook.com';
        return $fbResult;
    }

    $fbError = (string) ($fbResult['error'] ?? 'graph.facebook.com での交換に失敗');

    return [
        'ok'    => false,
        'error' => $igError . ' / ' . $fbError,
        'data'  => $fbResult['data'] ?? $igResult['data'] ?? null,
    ];
}

/** プロフィール取得（graph.facebook.com） */
function fetchInstagramProfile(string $longToken): array
{
    $fields = 'id,username,user_id,account_type';
    $result = curlRequest('GET', graphUrl('me', [
        'fields'       => $fields,
        'access_token' => $longToken,
    ]));

    if ($result['ok']) {
        $id = (string) ($result['data']['id'] ?? $result['data']['user_id'] ?? '');
        return [
            'ok'                            => true,
            'username'                      => (string) ($result['data']['username'] ?? ''),
            'instagram_business_account_id' => $id,
        ];
    }

    // フォールバック: graph.instagram.com
    $fallback = curlRequest('GET', 'https://graph.instagram.com/me?' . http_build_query([
        'fields'       => 'id,username,user_id',
        'access_token' => $longToken,
    ]));

    if (!$fallback['ok']) {
        return ['ok' => false, 'error' => $fallback['error']];
    }

    return [
        'ok'                            => true,
        'username'                      => (string) ($fallback['data']['username'] ?? ''),
        'instagram_business_account_id' => (string) ($fallback['data']['id'] ?? $fallback['data']['user_id'] ?? ''),
    ];
}

function buildTokenJsonPayload(string $longToken, string $username, string $igAccountId, int $expiresAt): string
{
    $payload = [
        'access_token'                    => $longToken,
        'instagram_business_account_id'   => $igAccountId,
        'username'                        => $username,
        'expires_at'                      => $expiresAt,
        'updated_at'                      => gmdate('c'),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : '{}';
}

/** サーバー内バックアップ（Web 非公開 storage/） */
function saveTokenPayloadToDisk(string $tokenJson): bool
{
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    $tmp = $dir . '/instagram-token-latest.json.tmp';
    if (file_put_contents($tmp, $tokenJson, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, $dir . '/instagram-token-latest.json');
}

function renderPage(string $title, string $bodyHtml, bool $isError = false): void
{
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:"Noto Sans JP",system-ui,sans-serif;background:#FBBF24;color:#000;min-height:100vh;padding:20px}
    .wrap{max-width:960px;margin:0 auto}
    .panel{background:#fff;border:4px solid #000;box-shadow:10px 10px 0 #000;padding:24px 28px 32px}
    h1{font-size:clamp(22px,4vw,34px);font-weight:900;line-height:1.25;margin-bottom:10px}
    p,li{line-height:1.65;font-weight:600;font-size:15px;margin-bottom:10px}
    .err{background:#fff0f0;border:4px solid #000;padding:16px;margin-bottom:16px;box-shadow:6px 6px 0 #000}
    .label{display:block;font-size:12px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;margin:20px 0 8px}
    textarea{width:100%;min-height:88px;border:4px solid #000;box-shadow:6px 6px 0 #000;background:#fffde8;font-family:ui-monospace,monospace;font-size:14px;font-weight:700;line-height:1.45;padding:14px;resize:vertical}
    textarea.tall{min-height:200px}
    .row{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
    .btn{display:inline-block;background:#000;color:#FBBF24;border:4px solid #000;box-shadow:6px 6px 0 #000;padding:12px 20px;font-weight:900;font-size:14px;cursor:pointer;text-decoration:none}
    .btn:hover,.btn:focus-visible{transform:translate(4px,4px);box-shadow:2px 2px 0 #000;outline:none}
    .btn.sec{background:#fff;color:#000}
    .hint{font-size:13px;color:#333;margin-top:6px}
    code{background:#eee;border:2px solid #000;padding:1px 6px;font-size:13px}
    ol{padding-left:20px;margin-bottom:14px}
    .ok-badge{display:inline-block;background:#000;color:#FBBF24;padding:4px 10px;font-weight:900;font-size:12px;margin-bottom:12px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="panel">
      <?= $bodyHtml ?>
    </div>
  </div>
  <script>
  function copyField(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.select();
    el.setSelectionRange(0,99999);
    try{ document.execCommand('copy'); alert('コピーしました'); }
    catch(e){ navigator.clipboard.writeText(el.value).then(function(){ alert('コピーしました'); }); }
  }
  </script>
</body>
</html>
    <?php
    exit;
}

// =============================================================================
// メイン
// =============================================================================
try {
$appId       = cfg('INSTAGRAM_APP_ID', INSTAGRAM_APP_ID);
$appSecret   = cfg('INSTAGRAM_APP_SECRET', INSTAGRAM_APP_SECRET);
$redirectUri = cfg('INSTAGRAM_REDIRECT_URI', INSTAGRAM_REDIRECT_URI);
$scope       = cfg('INSTAGRAM_OAUTH_SCOPE', INSTAGRAM_OAUTH_SCOPE);

if (!extension_loaded('curl')) {
    renderPage(
        '環境エラー',
        '<div class="err"><h1>PHP の cURL 拡張が無効です</h1>
         <p>サーバーで <code>sudo apt install php-curl</code> の後 <code>sudo systemctl restart apache2</code> を実行してください。</p></div>',
        true
    );
}

if ($appId === '' || $appSecret === '') {
    renderPage(
        '設定エラー',
        '<h1>App Config が未設定です</h1>
         <p><code>INSTAGRAM_APP_ID</code> と <code>INSTAGRAM_APP_SECRET</code> を callback.php 上部、またはサーバー環境変数に設定してください。</p>
         <p class="hint">Redirect URI: <code>' . h($redirectUri) . '</code></p>',
        true
    );
}

$authUrl = buildAuthorizeUrl($appId, $redirectUri, $scope);

if (isset($_GET['error'])) {
    $reason = (string) ($_GET['error_description'] ?? $_GET['error_reason'] ?? $_GET['error']);
    renderPage(
        '認可エラー',
        '<div class="err"><h1>Instagram ログインが中断されました</h1><p>' . h($reason) . '</p></div>
         <a class="btn" href="' . h($authUrl) . '">もう一度ログインする</a>',
        true
    );
}

$code = '';
if (isset($_GET['code']) && is_string($_GET['code'])) {
    $code = trim($_GET['code']);
}
if ($code !== '') {
    $stripped = preg_replace('/#_$/', '', $code);
    $code = is_string($stripped) ? $stripped : $code;

    $short = normalizeShortTokenResponse(exchangeCodeForShortToken($appId, $appSecret, $redirectUri, $code));
    $shortData = is_array($short['data'] ?? null) ? $short['data'] : [];
    if (!$short['ok'] || ($shortData['access_token'] ?? '') === '') {
        renderPage(
            '短期トークンエラー',
            '<div class="err"><h1>短期トークンの取得に失敗しました</h1><p>' . h(errorMessage($short['error'] ?? null)) . '</p>
             <p class="hint">認可コードは1回限りです。下のボタンから再度ログインしてください。</p></div>
             <a class="btn" href="' . h($authUrl) . '">再試行</a>',
            true
        );
    }

    $shortToken = (string) $shortData['access_token'];

    $long = exchangeForLongLivedToken($appId, $appSecret, $shortToken);
    $longData = is_array($long['data'] ?? null) ? $long['data'] : [];
    if (!$long['ok'] || ($longData['access_token'] ?? '') === '') {
        renderPage(
            '長期トークンエラー',
            '<div class="err"><h1>60日長期トークンへの交換に失敗しました</h1><p>' . h(errorMessage($long['error'] ?? null)) . '</p></div>
             <a class="btn" href="' . h($authUrl) . '">再試行</a>',
            true
        );
    }

    $longToken = (string) $longData['access_token'];
    $expiresIn = (int) ($longData['expires_in'] ?? 5184000);
    $expiresAt = time() + $expiresIn;

    $profile = fetchInstagramProfile($longToken);
    if (!$profile['ok']) {
        renderPage(
            'プロフィール取得エラー',
            '<div class="err"><h1>Instagram アカウント ID の取得に失敗しました</h1><p>' . h(errorMessage($profile['error'] ?? null)) . '</p></div>
             <a class="btn" href="' . h($authUrl) . '">再試行</a>',
            true
        );
    }

    $username = (string) $profile['username'];
    $igId     = (string) $profile['instagram_business_account_id'];
    $tokenJson = buildTokenJsonPayload($longToken, $username, $igId, $expiresAt);
    $savedDisk = saveTokenPayloadToDisk($tokenJson);
    $saveNote = $savedDisk
        ? '<p class="hint">✅ サーバー内バックアップ: <code>storage/instagram-token-latest.json</code>（Web 非公開）</p>'
        : '<p class="hint">⚠️ サーバー内バックアップの保存に失敗しました。下記 JSON を必ずコピーしてください。</p>';

    $body = '
      <span class="ok-badge">TOKEN OK</span>
      <h1>長期アクセストークン取得完了</h1>
      ' . $saveNote . '
      <p>以下をコピーし、油丸 LP サーバー（PHP 可）の <code>instagram-token.json</code> として保存してください。</p>
      <ol>
        <li>下の「店舗用 JSON」をコピー</li>
        <li>LP と同じ階層に <code>instagram-token.json</code> を作成して貼り付け</li>
        <li><code>instagram-feed.php</code> と <code>verify-instagram.php</code> を同階層にアップロード</li>
        <li><code>verify-instagram.php</code> をブラウザで開き、投稿3件取得を確認</li>
        <li>LP の INSTAGRAM セクションをリロード</li>
      </ol>

      <span class="label">Instagram ユーザー名</span>
      <textarea id="field-username" readonly>@' . h($username) . '</textarea>
      <div class="row"><button type="button" class="btn sec" onclick="copyField(\'field-username\')">ユーザー名をコピー</button></div>

      <span class="label">Instagram Business Account ID</span>
      <textarea id="field-igid" readonly>' . h($igId) . '</textarea>
      <div class="row"><button type="button" class="btn sec" onclick="copyField(\'field-igid\')">ID をコピー</button></div>

      <span class="label">長期アクセストークン（60日・自動延長対応）</span>
      <textarea id="field-token" readonly>' . h($longToken) . '</textarea>
      <div class="row"><button type="button" class="btn" onclick="copyField(\'field-token\')">トークンをコピー</button></div>
      <p class="hint">有効期限: 約 ' . h((string) (int) floor($expiresIn / 86400)) . ' 日（' . h(gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC') . ' まで）</p>

      <span class="label">店舗用 instagram-token.json（このまま貼り付け）</span>
      <textarea id="field-json" class="tall" readonly>' . h($tokenJson) . '</textarea>
      <div class="row">
        <button type="button" class="btn" onclick="copyField(\'field-json\')">JSON をコピー</button>
        <a class="btn sec" href="' . h($authUrl) . '">別アカウントで再取得</a>
      </div>
    ';

    renderPage('トークン回収完了', $body);
}

renderPage(
    'Instagram 共通トークン回収',
    '<h1>Instagram 共通 OAuth（量産用）</h1>
     <p>クライアントには下の「共通認可 URL」を送付してください。ログインと許可のみで完了です。</p>
     <span class="label">共通認可 URL（クライアント送付用）</span>
     <textarea id="field-auth" readonly>' . h($authUrl) . '</textarea>
     <div class="row">
       <button type="button" class="btn" onclick="copyField(\'field-auth\')">認可 URL をコピー</button>
       <a class="btn sec" href="' . h($authUrl) . '">自分でログインしてテスト</a>
     </div>
     <p class="hint">Redirect URI（Meta に登録）: <code>' . h($redirectUri) . '</code></p>
     <p class="hint">App ID: <code>' . h($appId) . '</code></p>'
);
} catch (Throwable $e) {
    renderPage(
        '例外エラー',
        '<div class="err"><h1>処理中に例外が発生しました</h1><p>' . h($e->getMessage()) . '</p>
         <pre style="font-size:12px;overflow:auto">' . h($e->getFile() . ':' . $e->getLine()) . '</pre></div>
         <a class="btn" href="' . h(buildAuthorizeUrl(
             cfg('INSTAGRAM_APP_ID', INSTAGRAM_APP_ID),
             cfg('INSTAGRAM_REDIRECT_URI', INSTAGRAM_REDIRECT_URI),
             cfg('INSTAGRAM_OAUTH_SCOPE', INSTAGRAM_OAUTH_SCOPE)
         )) . '">再試行</a>',
        true
    );
}
