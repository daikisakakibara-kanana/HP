# Instagram 連携 — 認可 URL 取得後の手順

認可 URL（取得済み）:

https://www.instagram.com/oauth/authorize?client_id=1470160134857234&redirect_uri=https%3A%2F%2Finsta-api.kanana-tech.jp%2Finsta-token%2Fcallback.php&response_type=code&scope=instagram_business_basic

---

## あなたが今すぐやること（①のみ人間操作）

### ① Instagram でログイン・許可（約1分）

上記 URL を開く → **油丸の Instagram ビジネスアカウント**でログイン → **許可**

成功すると次のページに遷移します:

`https://insta-api.kanana-tech.jp/insta-token/callback.php?code=...`

→ **「長期アクセストークン取得完了」** の黄色画面

### ② 店舗用 JSON をコピー

画面の **「店舗用 instagram-token.json」** テキストエリア → すべてコピー

### ③ 油丸 LP サーバー（PHP 可）に配置

`index.html` と同じ階層に:

- `instagram-token.json`（②の内容）
- `instagram-feed.php`（リポジトリ直下）
- `verify-instagram.php`（`lp-instagram/` 内）
- `img/insta-fallback-1.jpg` 〜 `3.jpg`（既存 `img/` をそのまま）

### ④ 動作確認

ブラウザで:

`https://（油丸LPのURL）/verify-instagram.php`

すべて OK → LP の INSTAGRAM をリロード → 実投稿3件表示

---

## さくらへ再アップロード（推奨）

`insta-token/callback.php` を更新済み（トークン自動バックアップ付き）。  
FTP で上書きしてください。

---

## 自動でできないこと

Instagram ログインは **店舗アカウントの認証情報** が必要なため、制作側の代行はできません。①だけ実施をお願いします。
