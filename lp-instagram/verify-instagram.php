<?php
declare(strict_types=1);

/**
 * 油丸 LP 用 Instagram 連携チェック（ブラウザで開く）
 * instagram-token.json / instagram-feed.php が正しく動くか確認
 */

header('Content-Type: text/html; charset=UTF-8');

$tokenFile = __DIR__ . '/instagram-token.json';
$feedUrl   = './instagram-feed.php';

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$checks = [];

if (!is_readable($tokenFile)) {
    $checks[] = ['ng', 'instagram-token.json', '見つかりません。callback 完了後の JSON を同階層に配置してください。'];
} else {
    $raw = file_get_contents($tokenFile);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $checks[] = ['ng', 'instagram-token.json', 'JSON 形式が不正です。'];
    } else {
        $checks[] = empty($data['access_token'])
            ? ['ng', 'access_token', '未設定']
            : ['ok', 'access_token', '設定済み（' . strlen((string) $data['access_token']) . ' 文字）'];
        $checks[] = empty($data['instagram_business_account_id'])
            ? ['ng', 'instagram_business_account_id', '未設定']
            : ['ok', 'instagram_business_account_id', (string) $data['instagram_business_account_id']];
        $checks[] = empty($data['username'])
            ? ['warn', 'username', '未設定（任意）']
            : ['ok', 'username', '@' . (string) $data['username']];
    }
}

if (!is_readable(__DIR__ . '/instagram-feed.php')) {
    $checks[] = ['ng', 'instagram-feed.php', '同階層に配置してください。'];
} else {
    $checks[] = ['ok', 'instagram-feed.php', '配置済み'];
}

$feedResult = null;
if (function_exists('curl_init')) {
    $ch = curl_init();
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    curl_setopt_array($ch, [
        CURLOPT_URL            => $base . '/instagram-feed.php',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (is_string($body)) {
        $feedResult = json_decode($body, true);
    }
}

if (is_array($feedResult) && !empty($feedResult['success'])) {
    $count = is_array($feedResult['posts'] ?? null) ? count($feedResult['posts']) : 0;
    $checks[] = ['ok', 'フィード API', '取得成功（投稿 ' . $count . ' 件）'];
} else {
    $err = is_array($feedResult) ? (string) ($feedResult['error'] ?? '不明') : '応答なし';
    $checks[] = ['ng', 'フィード API', $err];
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Instagram 連携チェック</title>
  <style>
    body{font-family:"Noto Sans JP",system-ui,sans-serif;background:#FBBF24;padding:24px;color:#000}
    .panel{max-width:720px;margin:0 auto;background:#fff;border:4px solid #000;box-shadow:8px 8px 0 #000;padding:24px}
    h1{font-size:24px;font-weight:900;margin-bottom:16px}
    li{margin:10px 0;font-weight:700;list-style:none;border:3px solid #000;padding:10px 12px;box-shadow:4px 4px 0 #000}
    li.ok{background:#ecfdf5}
    li.ng{background:#fff0f0}
    li.warn{background:#fffbeb}
    a{color:#000;font-weight:900}
    pre{background:#fffde8;border:3px solid #000;padding:12px;overflow:auto;font-size:12px;margin-top:16px}
  </style>
</head>
<body>
  <div class="panel">
    <h1>Instagram 連携チェック</h1>
    <ul>
      <?php foreach ($checks as [$st, $label, $msg]): ?>
        <li class="<?= h($st) ?>"><strong><?= h($label) ?></strong> — <?= h($msg) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if (is_array($feedResult)): ?>
      <pre><?= h(json_encode($feedResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
    <?php endif; ?>
    <p style="margin-top:16px"><a href="../index.html#insta">← LP の INSTAGRAM セクションへ</a></p>
  </div>
</body>
</html>
