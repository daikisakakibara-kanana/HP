# Meta 側ブロック解消手順（method type: get / post）

## 症状

callback v2.3.0 以降で次のエラー:

```
GET: Unsupported request - method type: get
POST: Unsupported request - method type: post
```

**短期トークンは取得できている** → OAuth・Redirect URI・App ID は正常。  
**長期トークン交換だけ Meta が拒否**している状態です。コードでは解決できません。

---

## 最優先チェック（この順で）

### 1. Instagram App Secret の取り違え（最多）

| 場所 | 用途 |
|------|------|
| **Instagram → Business login settings → Instagram app secret** | OAuth 全体で使う正しい Secret |
| アプリの設定 → ベーシック → App Secret | **別物** のことが多い |

手順:

1. https://developers.facebook.com/apps/1470160134857234/instagram-business/API-Setup/
2. **3. Set up Instagram business login** → **Business login settings**
3. **Instagram app secret** を「表示」→ コピー
4. さくら VPS の `callback.php` の `INSTAGRAM_APP_SECRET` と **1文字も違わないか** 比較
5. 違っていたら callback.php を更新 → 再デプロイ → 再 OAuth

環境変数 `INSTAGRAM_APP_SECRET` を設定している場合は、そちらが定数より優先されます。

---

### 2. Instagram テスター（開発モード必須）

アプリが **開発（Development）** モードのとき、ログインする Instagram は **必ずテスター** である必要があります。

1. Meta Developer → **アプリの役割** → **役割** → **Instagram テスター**
2. 油丸の Instagram ユーザー名を追加
3. Instagram アプリ（スマホ）→ **設定とアクティビティ** → **アプリとウェブサイト** → **テスター招待** → **承認**

または PC: https://www.instagram.com/accounts/manage_access/

テスター未設定でも **短期トークンまでは取れる** ことがあり、**長期交換だけ失敗**します。

---

### 3. Access Verification（Live モード必須）

アプリを **Live（公開）** にしている、または本番店舗アカウントで運用する場合:

1. Meta Developer → **アプリの設定** → **ベーシック**
2. **Access verification（アクセス確認）** の状態を確認
3. 未完了なら申請・完了まで待つ

参考: https://developers.facebook.com/docs/development/release/access-verification/

App Review で `instagram_business_basic` が Approved でも、Access Verification が別途必要なケースがあります。

---

### 4. Business login settings の URL

**Instagram → Business login settings** で以下が **空欄でない** こと:

| 項目 | 推奨値 |
|------|--------|
| OAuth redirect URIs | `https://insta-api.kanana-tech.jp/insta-token/callback.php` |
| Deauthorize callback URL | 同上 |
| Data deletion request URL | 同上 |

保存後、数分待ってから再 OAuth。

---

### 5. アカウント種別

Instagram Login API は **プロフェッショナル（ビジネス / クリエイター）** アカウントのみ対象です。  
個人アカウントの場合は Instagram 設定から切り替えてください。

---

## 暫定運用（v2.4.0 以降）

Meta 設定完了まで LP 動作確認だけ先に進める場合:

1. callback v2.4.0 をデプロイ
2. 再度 OAuth を実行
3. 長期交換が失敗しても **「暫定アクセストークン（約1時間）」** 画面が出れば JSON をコピー
4. LP に `instagram-token.json` として配置 → `verify-instagram.php` で確認

本番運用には 60 日トークンが必要です。Meta 設定完了後に再 OAuth してください。

---

## デプロイ（v2.4.0）

```bash
curl -fsSL -o /var/www/html/insta-token/callback.php \
  "https://raw.githubusercontent.com/daikisakakibara-kanana/HP/cursor/aburamaru-design-lp-9e6c/insta-token/callback.php"
```

確認: https://insta-api.kanana-tech.jp/insta-token/callback.php  
ページ下部 **`callback v2.4.0`**

---

## Meta サポート問い合わせ用

エラー画面の trace ID を添付:

- 例: `AnKTT57cPkBJ09gNXTtLmY8`

「Instagram Login で短期トークンは取得できるが、`graph.instagram.com/access_token` の `ig_exchange_token` が GET/POST 両方で code 100 になる」と記載。

---

## チェックリスト

- [ ] Business login settings の Instagram app secret を callback.php に反映
- [ ] 開発モード → Instagram テスター追加・招待承認
- [ ] Live モード → Access Verification 完了
- [ ] Deauthorize / Data deletion URL 設定済み
- [ ] ログインアカウントがビジネス/クリエイター
- [ ] callback v2.4.0 デプロイ済み
- [ ] 再 OAuth → 60日トークン成功、または暫定1時間トークンで LP テスト
