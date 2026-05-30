# さくらインターネットへのデプロイ手順（insta-token）

`kanana-tech.jp` 本体（Studio.Design）は PHP 不可のため、**さくらの VPS またはレンタルサーバ** に `callback.php` だけを置きます。

> 「さくらVPN」→ 通常は **さくらの VPS** または **さくらのレンタルサーバ** を指します。本手順は両方に対応しています。

---

## 全体像

```
kanana-tech.jp          → Studio.Design（LP・静的）※そのまま
insta.kanana-tech.jp  → さくら（PHP）← callback.php をここに置く
```

Meta の Redirect URI と `callback.php` の `INSTAGRAM_REDIRECT_URI` は **必ず同じ URL** にします。

推奨 URL:

`https://insta.kanana-tech.jp/insta-token/callback.php`

---

## 手順 1: DNS（お名前.com / さくら DNS 等）

`kanana-tech.jp` の DNS 管理画面で追加:

| 種別 | ホスト名 | 値 |
|------|----------|-----|
| A | `insta` | さくらサーバーの IPv4 アドレス |

反映まで最大 1〜24 時間（多くは数十分）。

---

## 手順 2-A: さくらのレンタルサーバ（スタンダード等）

### 1. サブドメイン SSL

1. [サーバコントロールパネル](https://secure.sakura.ad.jp/rscontrol/) にログイン
2. **ドメイン設定** → `kanana-tech.jp` を追加（未設定の場合）
3. **サブドメイン設定** → `insta.kanana-tech.jp` を追加
4. **SSL設定** → Let's Encrypt で `insta.kanana-tech.jp` を有効化

### 2. ファイルアップロード

SFTP（推奨）または FTP で接続:

| 項目 | 値 |
|------|-----|
| ホスト | `insta.kanana-tech.jp` または初期ドメイン `○○.sakura.ne.jp` |
| ユーザー | コントロールパネルの FTP アカウント |
| ポート | 22（SFTP） |

アップロード先（ドキュメントルート直下）:

```
www/
└── insta-token/
    └── callback.php    ← リポジトリの insta-token/callback.php
```

レンタルサーバでは `www` フォルダが公開ディレクトリです（プランにより `www/insta` がサブドメイン用の場合あり。パネルの「サブドメインのフォルダ」を確認）。

### 3. PHP バージョン

コントロールパネル → **PHP設定** → `insta.kanana-tech.jp` で **PHP 8.1 以上** を選択。

### 4. 動作確認

ブラウザで開く:

`https://insta.kanana-tech.jp/insta-token/callback.php`

- ⭕ 黄色い「Instagram 共通 OAuth」画面 → 成功
- ❌ 403 / 404 → パスまたはサブドメイン設定を再確認

---

## 手順 2-B: さくらの VPS

### 1. 初期設定（未構築の場合）

- OS: Ubuntu 22.04 等
- パッケージ: `nginx` + `php-fpm`（または Apache + mod_php）

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-curl php-json php-mbstring
```

### 2. 配置

```bash
sudo mkdir -p /var/www/insta/public/insta-token
sudo cp callback.php /var/www/insta/public/insta-token/
sudo chown -R www-data:www-data /var/www/insta
```

### 3. nginx 例（`/etc/nginx/sites-available/insta`）

```nginx
server {
    listen 80;
    server_name insta.kanana-tech.jp;
    root /var/www/insta/public;
    index index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/insta /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 4. SSL（Let's Encrypt）

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d insta.kanana-tech.jp
```

### 5. 動作確認

`https://insta.kanana-tech.jp/insta-token/callback.php` で黄色画面を確認。

---

## 手順 3: Meta（Instagram）側

[Meta for Developers](https://developers.facebook.com/) → アプリ → **Instagram ログイン** → 設定:

**有効な OAuth リダイレクト URI** に追加:

```
https://insta.kanana-tech.jp/insta-token/callback.php
```

（`kanana-tech.jp` 直下の URL は Studio では 403 のため登録しない）

---

## 手順 4: callback.php の Redirect URI を更新

`insta-token/callback.php` 18 行目付近:

```php
const INSTAGRAM_REDIRECT_URI = 'https://insta.kanana-tech.jp/insta-token/callback.php';
```

アップロード後、**もう一度** Instagram 認可（以前の `code` は失効済み）。

---

## 手順 5: 店舗 LP へのトークン配置（量産）

1. 認可完了後、画面の **店舗用 JSON** をコピー
2. 各店舗サーバーの `instagram-token.json` に貼り付け
3. 同階層に `instagram-feed.php` を配置

---

## トラブルシュート

| 症状 | 原因 | 対処 |
|------|------|------|
| 403 Forbidden | 静的ホストのまま / 権限 | さくらに PHP ファイルがあるか確認。ファイル権限 `644`、ディレクトリ `755` |
| 404 Not Found | パス違い | `www/insta-token/callback.php` の位置を確認 |
| redirect_uri mismatch | Meta と PHP の URI 不一致 | 完全一致（https・末尾スラッシュなし）で統一 |
| Invalid platform app | アプリ種別 | Instagram Login 用アプリか確認 |
| cURL エラー | PHP 拡張 | `php-curl` を有効化 |

---

## さくらサポート向けメモ

- 実行したいのは **PHP 1 ファイル**（OAuth コールバック）のみ
- 必要拡張: **curl**, **json**, **mbstring**
- 外向き HTTPS: `graph.facebook.com`, `graph.instagram.com`, `api.instagram.com`
