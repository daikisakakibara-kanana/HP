# insta-token デプロイ手順

`https://kanana-tech.jp/insta-token/callback.php` は **PHP が実行できるサーバー** にのみ配置できます。

## 403 Forbidden の原因（2026-05-30 確認）

`kanana-tech.jp` 本体は Studio.Design 等の **静的ホスティング** のため、`.php` へのアクセスはすべて **403 Forbidden** になります（PHP は実行されません）。

```
curl -I https://kanana-tech.jp/insta-token/callback.php
→ HTTP/2 403
```

OAuth の `code` は **1回限り・約1時間** で失効します。403 解消後、**もう一度 Instagram 認可** が必要です。

## 解決策（いずれか）

### A. サブドメインを PHP サーバーへ向ける（推奨）

例: `insta.kanana-tech.jp` を **さくらの VPS / レンタルサーバ** に DNS 設定

1. `insta-token/callback.php` をサーバーの `www/insta-token/` に SFTP アップロード
2. Meta の Redirect URI を `https://insta.kanana-tech.jp/insta-token/callback.php` に変更
3. `callback.php` 内の `INSTAGRAM_REDIRECT_URI` も同じ URL に更新

**さくら向け詳細手順:** [DEPLOY-SAKURA.md](./DEPLOY-SAKURA.md)

### B. 別ドメインで運用

例: `https://kanana.jp/insta-token/callback.php`（PHP 対応レンタルサーバー）

### C. Cloud Run / VPS に PHP を置き、リバースプロキシ

本番ドメインの `/insta-token/*` だけ PHP サーバーへ振り分け（要サーバー設定）

## アップロードするファイル

- `insta-token/callback.php`（このフォルダ内）

## 動作確認

ブラウザで `callback.php` を開く（`?code=` なし）→ **黄色いログイン画面** が出れば成功。403 のままなら PHP 未配置または静的ホストのままです。
