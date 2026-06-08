// Version history:
// 1.0.0: Initial version with Git repository setup
// 1.0.1: Automate order linkage and status transition
// 1.0.2: Implement CAD data access lock control
// 1.0.3: Add subcontractor delivery upload and admin review flow
// 1.0.4: Integrate delivery upload and admin confirmation flow
// 1.0.5: Integrate user authentication and role-based access control (RBAC)
// 1.0.6: Switch to passwordless Magic Link authentication
// 1.0.7: Complete Magic Link and RBAC integration
// 1.0.8: Initialize Composer and install Google API Client library
// 1.0.9: Introduce SMS Notification (Twilio SDK setup & Notifier)
// 1.0.10: Integrate Twilio SDK for SMS chat notifications and cooldown logic
// 1.0.11: Remove Twilio SMS notification integration
// 1.0.12: Setup Google Drive API integration and migration script
// 1.0.13: Migrate file storage to Google Drive API and perform database migration
// 1.0.14: Fix magic link URL generation using APP_URL or robust self-detection
// 1.0.15: Implement new request flow (Pattern A), register page, and redesign dashboard UI
// 1.0.16: Refine new request flow (Estimate vs Design request separation) and schedule criteria
// 1.0.17: Fix simulator visibility and relocate Google Drive auth button
// 1.0.18: Update version number
// 1.0.19: 見積書PDFのBillVector風レイアウト改修、見積履歴機能の追加、空白出力によるKaTeXエラーの修正
// 1.0.20: 各種PHPファイルのBOMおよび先頭空白文字を除去し、Quirks Modeの不具合を完全に解消
// 1.0.21: new_request.phpの許容応力度計算オプション位置を壁量計算の下へ移動、右パネルの自動見積シミュレーターのレイアウト修正
// 1.0.21: new_request.phpの許容応力度計算オプション位置を壁量計算の下へ移動、右パネルの自動見積シミュレーターのレイアウト修正
// 1.0.22: 依頼内容に応じた固定枠の表示と「他ファイルに記載」機能の実装、金銭・請求管理フォームの追加、ステータスフロー改修
// 1.0.23: 依頼内容に応じた固定枠の表示と「他ファイルに記載」機能の実装、金銭・請求管理フォームの追加、ステータスフロー改修
// 1.0.24: UIバグ修正、成果物枠の再構成、初期見積額の自動設定、スケジュール表示修正
// 1.0.26: 複数箇所での upload_slots.php 読み込みによるエラーを修正
// 1.0.27: 管理者ダッシュボード4カラム化、クライアントダッシュボード3カラム復元、天空率提出図書の自動判定、提出済み図書表示の改修
// 1.0.28: 依頼主アップロード図書のインライン化・履歴管理機能の追加、レイアウト余白の改善、見積時図面の分離と設計依頼時の変更確認追加
// 1.1.0: リファクタリング - スケジュール共通関数化、見積時図面コンポーネント化、project_detail_post.phpのアクション分割
// 1.1.1: 協力業者ポータル機能拡充（ファイル納品分離、チャット機能追加、25日締め月次請求）
// 1.1.2: 新規依頼画面の動的UI化、見積シミュレーターの表示バグとJS文字化けエラーの解消
// 1.1.3: 見積シミュレーターのJS（project_detail.js）の関数重複・構文エラーを完全修復し再計算・PDF出力を復旧
// 1.1.13: 正式依頼時のCAD必須化、確認申請書の全依頼での必須化（後出し可）、一次回答期限の起算条件を「必要図書が全て揃った時点」に修正、地盤調査報告書を許容・基礎梁のみ必須に変更
// 1.1.14: project_files.drive_file_id カラムを NULL 許容に変更し、他ファイルに記載チェック時のエラーを解消
// 1.1.15: 一次回答期日設定時の「実施日」同日保存、本見積確定ボタン、一次請求書(50%)発行機能、残金精算額（再集金）減額ロジックを追加
// 1.1.16: 一次請求書(inv_primary)表示時の配列オフセット参照エラーを修正、project_subcontractor.php の破損を過去コミットから復旧
// 1.1.17: functions.php の定数 SYSTEM_VERSION が 1.1.14 のままだったバグを修正、expected_delivery_date カラム追加のDB移行を実施
// 1.1.18: 協力業者ポータルにおける発注辞退機能の実装、およびステータスバッジの追加
// 1.1.19: 協力業者発注処理のモジュール化（SubcontractorOrderService新設）、依存性の注入（DI）、および単体テストの追加
// 1.2.0: リファクタリング、モジュール化、DI適用、PHPUnit自動テスト導入の完了
// 1.2.1: 通知メールに記載されるダッシュボードURLを一斉にサブドメイン（system.thanks.work）へ修正
// 1.2.2: 協力業者発注表記の修正、Google Driveトークンフォーマットエラー対策、および外皮計算の仕様書必須チェックのタイミング変更
// 1.2.4: 一次請求書の表示バグ修正、経理画面の図書非表示、外皮計算見積整合性の修正、DBリセット機能実装

window.APP_VERSION = "1.2.4";
const APP_LAST_UPDATED = '2026-06-08';
