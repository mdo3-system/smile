# 木造住宅設計サポート・ポータル デプロイ・サーバー同期仕様書 (DEPLOYMENT.md)

本ドキュメントは、本システム（木造住宅設計サポート・ポータル）を GitHub および Xserver 本番環境（`https://system.thanks.work`）へ安全かつ迅速にデプロイ・同期するための運用仕様を定義するものです。

---

## 1. サーバー環境仕様

- **本番ドメイン**: [https://system.thanks.work](https://system.thanks.work)
- **サーバーID / ホスト**: `mdo3@mdo3.xsrv.jp`
- **SSHポート**: `10022`
- **サーバー保存先ディレクトリ**: `/home/mdo3/thanks.work/public_html/system`
- **GitHubリポジトリ**: `git@github.com:mdo3-system/smile.git`
- **運用ブランチ**: `main`

---

## 2. 日常のデプロイフロー

開発作業完了時の基本デプロイサイクルは以下の通りです。

```bash
# 1. バージョン更新
# functions.php (SYSTEM_VERSION) および Version.js (window.APP_VERSION) をインクリメント
# FIXED_LOGIC.md に確定仕様を追記

# 2. ローカルGitコミット & GitHubプッシュ
git add .
git commit -m "fix: [作業内容・バージョン更新] vX.X.XX"
git push origin main

# 3. Xserver本番環境への自動SSHデプロイ（git pull）
ssh -o BatchMode=yes -p 10022 mdo3@mdo3.xsrv.jp "cd thanks.work/public_html/system && git pull origin main"
```

---

## 3. データベース変更時の反映（マイグレーション）

データベースの変更（ALTER TABLE等のマイグレーションスクリプト）を作成した場合は、SSH経由でコマンドラインから実行してXServer上で直接反映させます。

```bash
ssh -o BatchMode=yes -p 10022 mdo3@mdo3.xsrv.jp "cd thanks.work/public_html/system && php scratch/マイグレーションスクリプト.php"
```
