<?php
declare(strict_types=1);

mb_language('Japanese');
mb_internal_encoding('UTF-8');

// ConoHa WINGへアップロード後、実際に受信したいメールアドレスへ変更してください。
$adminEmail = 'info@example.com';
$fromEmail = 'info@example.com';
$fromName = 'GROW お問い合わせフォーム';
$siteName = 'GROW';
$sendAutoReply = true;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function post_value(string $key): string
{
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    return trim($value);
}

function clean_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

function send_site_mail(string $to, string $subject, string $body, string $fromEmail, string $fromName, ?string $replyTo = null): bool
{
    $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8');
    $headers = [
        'From: ' . $encodedFromName . ' <' . clean_header_value($fromEmail) . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: PHP/' . phpversion(),
    ];

    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . clean_header_value($replyTo);
    }

    if (function_exists('mb_send_mail')) {
        return mb_send_mail($to, $subject, $body, implode("\r\n", $headers));
    }

    return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
}

function render_page(string $title, string $lead, string $status, array $errors = []): void
{
    http_response_code($status === 'success' ? 200 : 400);
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= h($title) ?> | GROW</title>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-[#fbf7ef] text-stone-950">
  <main class="mx-auto flex min-h-screen max-w-3xl items-center px-5 py-16">
    <section class="w-full rounded-[3rem] bg-white p-8 shadow-2xl sm:p-12">
      <div class="mb-8 rounded-[2rem] <?= $status === 'success' ? 'bg-amber-100' : 'bg-red-50' ?> p-6">
        <p class="text-sm font-black uppercase tracking-[0.28em] text-amber-800">Contact Form</p>
        <h1 class="mt-3 text-3xl font-black sm:text-4xl"><?= h($title) ?></h1>
      </div>
      <p class="leading-8 text-stone-600"><?= nl2br(h($lead)) ?></p>
      <?php if ($errors): ?>
        <ul class="mt-6 space-y-2 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
          <?php foreach ($errors as $error): ?>
            <li>・<?= h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="mt-8 flex flex-col gap-3 sm:flex-row">
        <a class="inline-flex justify-center rounded-full bg-stone-950 px-6 py-3 text-sm font-black tracking-[0.18em] text-white transition hover:bg-amber-700" href="index.html#contact">
          お問い合わせへ戻る
        </a>
        <a class="inline-flex justify-center rounded-full border border-stone-300 px-6 py-3 text-sm font-bold tracking-[0.18em] text-stone-700 transition hover:border-amber-700 hover:text-amber-800" href="index.html">
          トップへ戻る
        </a>
      </div>
    </section>
  </main>
</body>
</html>
    <?php
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html#contact', true, 302);
    exit;
}

// Bot対策の簡易ハニーポット。入力があっても成功画面を返し、メールは送信しません。
if (post_value('website') !== '') {
    render_page('送信が完了しました', "お問い合わせありがとうございます。\n内容を確認後、電話またはメールにてご連絡いたします。", 'success');
    exit;
}

$fields = [
    'name' => mb_substr(post_value('name'), 0, 80),
    'phone' => mb_substr(post_value('phone'), 0, 50),
    'email' => mb_substr(post_value('email'), 0, 150),
    'inquiry_type' => mb_substr(post_value('inquiry_type'), 0, 80),
    'timing' => mb_substr(post_value('timing'), 0, 80),
    'site_address' => mb_substr(post_value('site_address'), 0, 150),
    'message' => mb_substr(post_value('message'), 0, 3000),
    'privacy' => post_value('privacy'),
];

$errors = [];
if ($fields['name'] === '') {
    $errors[] = 'お名前を入力してください。';
}
if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = '正しいメールアドレスを入力してください。';
}
if ($fields['message'] === '') {
    $errors[] = 'お問い合わせ内容を入力してください。';
}
if ($fields['privacy'] !== '同意する') {
    $errors[] = 'プライバシーポリシーへの同意が必要です。';
}

if ($errors) {
    render_page('入力内容をご確認ください', '未入力または形式に誤りがある項目があります。内容をご確認のうえ、再度送信してください。', 'error', $errors);
    exit;
}

$subject = '【GROW】お問い合わせがありました';
$submittedAt = date('Y-m-d H:i:s');
$body = <<<MAIL
GROWのホームページよりお問い合わせがありました。

------------------------------
お名前: {$fields['name']}
電話番号: {$fields['phone']}
メールアドレス: {$fields['email']}
ご相談内容: {$fields['inquiry_type']}
希望時期: {$fields['timing']}
現場住所・エリア: {$fields['site_address']}
------------------------------

お問い合わせ内容:
{$fields['message']}

------------------------------
送信日時: {$submittedAt}
送信元: {$siteName} お問い合わせフォーム
MAIL;

$sent = send_site_mail($adminEmail, $subject, $body, $fromEmail, $fromName, $fields['email']);

if ($sent && $sendAutoReply) {
    $replySubject = '【GROW】お問い合わせを受け付けました';
    $replyBody = <<<MAIL
{$fields['name']} 様

このたびはGROWへお問い合わせいただき、誠にありがとうございます。
以下の内容でお問い合わせを受け付けました。
内容を確認後、電話またはメールにてご連絡いたします。

------------------------------
お名前: {$fields['name']}
電話番号: {$fields['phone']}
メールアドレス: {$fields['email']}
ご相談内容: {$fields['inquiry_type']}
希望時期: {$fields['timing']}
現場住所・エリア: {$fields['site_address']}
------------------------------

お問い合わせ内容:
{$fields['message']}

------------------------------
GROW
小田原市府川2-14
0465-43-6900
MAIL;

    send_site_mail($fields['email'], $replySubject, $replyBody, $fromEmail, $fromName, $adminEmail);
}

if (!$sent) {
    render_page('送信できませんでした', '恐れ入りますが、時間をおいて再度お試しください。繰り返し送信できない場合は、サーバーのメール設定をご確認ください。', 'error');
    exit;
}

render_page('送信が完了しました', "お問い合わせありがとうございます。\n内容を確認後、電話またはメールにてご連絡いたします。", 'success');
