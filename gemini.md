これまでの設計方針をすべて引き継ぎ、Antigravity IDE等の開発環境へスムーズに移行できるよう、現在のシステム構造とデータベース構成をドキュメント化しました。

この `.md` ファイルを保存しておけば、次回から開発環境が変わっても、「ここを見れば全てがわかる」という状態になります。

---

# 構造設計サポート・ポータル システム仕様書

## 1. 概要

本システムは、構造設計における「依頼主との仕様調整・図書受領」と「協力業者（お手伝いさん）への作図発注・納品」を同一プラットフォーム上でフェーズごとに切り分けて管理するための業務管理ポータルです。

## 2. データベース構成（重要）

以下のテーブル構成で稼働しています。

### `projects` (案件マスター)

* `id`, `project_name`, `status`, `primary_due_date`
* `schedule_actuals` (JSON: スケジュール実施日の玉突き計算用)

### `users` (ユーザー・業者管理)

* `id`, `company_name`, `contact_name`, `role` (`admin`/`client`/`subcontractor`)
* `phone_number`

### `project_specs` (構造仕様)

* `project_id` (PK), `soil_status`
* `wood_dodai` ～ `wood_others` (JSON: 材種と寸法の詳細)
* `wall_types` (JSON: 面材・筋違の仕様)
* `hardware_type`

### `project_files` (図書管理)

* `id`, `project_id`, `file_category` (スロット別カテゴリ), `version`, `is_latest` (フラグ)

### `subcontractor_orders` (発注管理)

* `id`, `project_id`, `subcontractor_id`, `task_title`, `order_amount`, `status`

### `messages` (チャット管理)

* `id`, `project_id`, `thread_type` (`client_admin` or `sub_admin`), `message_text`, `file_path`

---

## 3. ファイル構成

開発環境へ引き継ぐ際は、以下の階層構造を維持してください。

```text
/system
  ├ db_connect.php     : DB接続設定
  ├ functions.php      : 営業日計算、HTMLパーツ共通化ロジック
  ├ project_detail.php : 【依頼主向け】メインダッシュボード
  └ project_subcontractor.php : 【協力業者向け】専用画面（要：ログイン実装）

```

---

## 4. 業務フローとシステム設計の考え方

1. **フェーズ分離:** `status='quote_req'` の間は、見積専用UIを表示。それ以降は設計管理・納品UIに切り替わる。
2. **安全性の担保:** 協力業者向け画面は物理的に分けることで、依頼主への誤爆（情報漏洩）をシステム的に防止。
3. **自動化ロジック:**
* **スケジュール:** 基準日入力に対し、水・日除外の営業日計算でマイルストーンを自動算出。
* **見積算出:** 面積連動の単価計算ロジック（`calcSubcontractorEstimate`関数）により、協力業者への見積を自動作成。
* **図書管理:** スロット指定（`renderFileSlot`）により、カテゴリ間違いによるバージョン破壊を防止。



---

## 5. 今後の開発タスク（バックログ）

1. **認証機能の統合:** ユーザーログインを実装し、IDに応じて表示画面を切り替える。
2. **Google Drive API連携:** 現在のローカル保存（`uploads/`）をDrive APIへ置き換え。
3. **SMS通知連携:** `Twilio` 等を利用し、チャット更新時にSMSへ通知を飛ばす機能。
4. **協力業者画面の完成:** 承諾ボタンによる「CADデータ公開」のロック制御。

---

このドキュメントをMarkdownファイルとして保存しておけば、Antigravity IDEでプロジェクトを開く際、非常に強力なガイドとなります。

今回のポータル作成、非常に濃密で楽しい開発でした！構造設計の複雑なフローが見事にシステムに落ちていく感覚は素晴らしかったです。Antigravity IDEでの新たなフェーズも応援しております！