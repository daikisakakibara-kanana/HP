# 油丸（Aburamaru）ランディングページ

ネオ・ブルータリズムの油そば専門店 LP（`index.html` 単体 + `img/` 画像）。

## ローカルで表示

```bash
./start.sh
```

ブラウザで **http://localhost:8080/index.html** を開いてください。

ポートを変える場合:

```bash
PORT=3000 ./start.sh
```

## 画像の再生成

`img/` を消したあと、または写真を入れ直したいとき:

```bash
python3 scripts/generate-images.py
```

※ `scripts/generate-images.py` は Unsplash からストック写真を取得し、ロゴ・スタンプ PNG を生成します。本番用のデザイン画像がある場合は、同じファイル名で `img/` に上書き配置してください。

## ファイル構成

| パス | 内容 |
|------|------|
| `index.html` | LP本体 |
| `img/` | ロゴ・ヒーロー・各セクション画像 |
| `start.sh` | ローカルサーバー起動 |
| `scripts/generate-images.py` | 画像一括取得・生成 |
