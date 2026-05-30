# Instagram 連携の完全リセット＆一から検証

エラー例:
`Unsupported request - method type: get` / `Error validating application`

→ **古い callback.php がサーバーに残っている**か、**Meta／Instagram 側の連携が壊れた状態**のことが多いです。  
以下を **上から順に** 実施してください。

---

## チェックリスト（印刷用）

- [ ] A. Instagram アプリ連携を解除
- [ ] B. Meta Developer の設定を確認
- [ ] C. サーバー上のトークン・キャッシュを削除
- [ ] D. 最新 `callback.php` をデプロイ（v2.3.0）
- [ ] E. `health.php` で curl: OK
- [ ] F. 再認可 → 長期トークン取得成功
- [ ] G. LP に `instagram-token.json` 配置

---

## A. Instagram アプリ連携を解除（スマホ）

1. Instagram アプリ → **設定とアクティビティ**
2. **アプリとウェブサイト** → **アプリ**
3. **Kanana-HP-IG**（または該当アプリ）→ **削除** / 連携解除

これで「すでにリンクされています」の状態をリセットできます。

---

## B. Meta Developer（Kanana-HP-IG）

https://developers.facebook.com/apps/1470160134857234/

### B-1. ロール（開発モードの場合）

1. **アプリの役割** → **Instagram テスター**
2. 一度テスターを削除 → 再度追加（油丸の Instagram ビジネスアカウント）
3. Instagram アプリで **設定 → アプリとウェブサイト → テスター招待** を承認

### B-2. Instagram Login 設定

**製品** → **Instagram** → **API setup with Instagram login** → **Business login settings**

| 項目 | 値 |
|------|-----|
| OAuth リダイレクト URI | `https://insta-api.kanana-tech.jp/insta-token/callback.php` |
| Deauthorize callback URL | `https://insta-api.kanana-tech.jp/insta-token/callback.php` |
| Data deletion request URL | `https://insta-api.kanana-tech.jp/insta-token/callback.php` |

※ 空欄だと長期トークン交換が失敗することがあります。

### B-3. 権限（scope）

`instagram_business_basic` のみ（余計な権限は付けない）

### B-4. App Secret の再確認（重要）

**Instagram → API setup with Instagram login → Business login settings → Instagram app secret**  
の値をコピーし、`callback.php` の `INSTAGRAM_APP_SECRET` と **完全一致** しているか確認。

⚠️ **ベーシック設定の App Secret とは別の値** の場合があります。短期トークンは取れても長期トークンだけ失敗する典型パターンです。

変更したら Secret を再生成し、callback.php も更新。

### B-5. Access Verification（本番 Live モードの場合）

アプリが **Live（公開）** のとき、長期トークン交換で `Unsupported request - method type: get/post` が出る場合、  
**Access Verification（アクセス確認）** が未完了であることが多いです。

Meta Developer → アプリ → **アプリの設定** → **ベーシック** 付近の「Access verification」を完了してください。  
`instagram_business_basic` の App Review 承認だけでは足りないケースがあります。

参考: https://developers.facebook.com/docs/development/release/access-verification/

---

## C. サーバー（さくら VPS）のファイル削除

SSH（root）で:

```bash
# パスは環境に合わせて変更
BASE=/var/www/html/insta-token

# 保存済みトークン・キャッシュを削除
rm -f "$BASE/storage/instagram-token-latest.json"
rm -f "$BASE/storage/instagram-token-latest.json.tmp"
rm -f "$BASE/../instagram-feed-cache.json" 2>/dev/null

# 油丸 LP 側（ある場合）
rm -f /var/www/html/instagram-token.json
rm -f /var/www/html/instagram-feed-cache.json
```

---

## D. 最新 callback.php をデプロイ（重要）

```bash
curl -fsSL -o /var/www/html/insta-token/callback.php \
  "https://raw.githubusercontent.com/daikisakakibara-kanana/HP/cursor/aburamaru-design-lp-9e6c/insta-token/callback.php"

curl -fsSL -o /var/www/html/insta-token/health.php \
  "https://raw.githubusercontent.com/daikisakibara-kanana/HP/cursor/aburamaru-design-lp-9e6c/insta-token/health.php"

chmod 644 /var/www/html/insta-token/callback.php /var/www/html/insta-token/health.php
```

### デプロイ確認

https://insta-api.kanana-tech.jp/insta-token/callback.php  

ページ下部に **`callback v2.3.0`** と表示されていれば最新版です。  
**v2.3.0 が無い場合は古いファイルのまま**なので、D をやり直してください。

---

## E. 環境チェック

https://insta-api.kanana-tech.jp/insta-token/health.php

```
curl: OK
instagram.com reach: HTTP 200（または 3xx/4xx で到達OK）
```

`curl: MISSING` のとき:

```bash
apt install -y php-curl
systemctl restart apache2
```

---

## F. 再認可（クリーンな状態から）

1. ブラウザの **シークレットウィンドウ** を開く  
2. 次の URL を開く（または callback ページの「自分でログインしてテスト」）:

```
https://www.instagram.com/oauth/authorize?client_id=1470160134857234&redirect_uri=https%3A%2F%2Finsta-api.kanana-tech.jp%2Finsta-token%2Fcallback.php&response_type=code&scope=instagram_business_basic
```

3. **油丸の Instagram ビジネス／クリエイター** でログイン  
4. **許可**  
5. **「長期アクセストークン取得完了」** と **店舗用 JSON** が表示されれば成功  

失敗時は画面のエラー全文（trace 含む）を控える。

---

## G. 油丸 LP へ反映

1. 店舗用 JSON を `instagram-token.json` として LP と同じ階層に保存  
2. `instagram-feed.php` を同階層に配置  
3. https://（LPのURL）/verify-instagram.php で確認  
4. LP の INSTAGRAM セクションをリロード  

---

## よくある原因まとめ

| 症状 | 原因 | 対処 |
|------|------|------|
| method type: **get** | 古い callback（GET のみ）または Meta 側ブロック | D: v2.3.0 をデプロイ。GET/POST 両方失敗なら B-5 |
| method type: **post** | **Meta 側の権限・検証不足**（コードではない） | B-4 Instagram app secret / B-1 テスター / B-5 Access Verification |
| Error validating application | Facebook 側フォールバック（旧コード） | D: v2.3.0（Instagram GET→POST 両方試行） |
| すでにリンクされています | 過去の連携が残存 | **共有を続ける**で OK。完全リセットなら A + B-1 |
| redirect_uri mismatch | Meta の URI 不一致 | B-2 |
| 500 エラー | php-curl 未導入 | E |

---

## それでもダメな場合

1. Meta で **新しいテスト用アプリ** を作り、App ID / Secret を差し替える  
2. または Meta サポート用に `fbtrace_id` を添えて問い合わせ  

callback.php の `INSTAGRAM_APP_ID` / `SECRET` / `REDIRECT_URI` の3つが Meta と完全一致しているか、最後にもう一度確認してください。
