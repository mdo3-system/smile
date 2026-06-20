with open('functions.php', 'r', encoding='utf-8', newline='') as f:
    content = f.read()
content = content.replace("define('SYSTEM_VERSION', 'v1.4.5');", "define('SYSTEM_VERSION', 'v1.4.6');")
with open('functions.php', 'w', encoding='utf-8', newline='') as f:
    f.write(content)
print("Updated functions.php version to v1.4.6")

with open('Version.js', 'r', encoding='utf-8', newline='') as f:
    content = f.read()
history_line = "// 1.4.5: 重複表示バグ、SQLエラー修正、ボールステータス補正、自動ステータス遷移（申請中/補正対応中）の実装、お取引条件注意書き追記"
history_new = history_line + "\n// 1.4.6: バージョン更新および不具合修正の最終デプロイ"

content = content.replace('// 1.4.4: 許容応力度シミュレーターのオプション追加・変更、発注時の希望納品日チャット表示、成果物スロット名修正、発注履歴重複表示バグ修正', 
                          '// 1.4.4: 許容応力度シミュレーターのオプション追加・変更、発注時の希望納品日チャット表示、成果物スロット名修正、発注履歴重複表示バグ修正\n' + history_new)
content = content.replace('window.APP_VERSION = "1.4.4";', 'window.APP_VERSION = "1.4.6";')
content = content.replace("const APP_LAST_UPDATED = '2026-06-18';", "const APP_LAST_UPDATED = '2026-06-19';")

with open('Version.js', 'w', encoding='utf-8', newline='') as f:
    f.write(content)
print("Updated Version.js version to v1.4.6")
