# 油丸 LP — Instagram 連携ファイル

油丸 LP（`index.html`）と **同じ階層** に以下をアップロードしてください。

| ファイル | 必須 |
|----------|------|
| `instagram-token.json` | ✅ callback 完了後にコピー |
| `instagram-feed.php` | ✅ |
| `verify-instagram.php` | ✅ 動作確認用 |
| `img/insta-fallback-*.jpg` | 推奨（`../img/` からコピー可） |

## 手順

1. https://www.instagram.com/oauth/authorize?client_id=1470160134857234&redirect_uri=https%3A%2F%2Finsta-api.kanana-tech.jp%2Finsta-token%2Fcallback.php&response_type=code&scope=instagram_business_basic  
   でログイン・許可
2. 表示された **店舗用 JSON** を `instagram-token.json` として保存
3. このフォルダの PHP を LP サーバーへアップロード
4. `verify-instagram.php` をブラウザで開いてすべて OK を確認
5. LP の INSTAGRAM をリロード

**注意:** Studio.Design 等の静的ホストのみでは PHP は動きません。さくら等 PHP 可のパスが必要です。
