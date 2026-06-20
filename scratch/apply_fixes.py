import re

file_path = "project_subcontractor.php"

with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# 1. 業者用タスククエリの LEFT JOIN のフォールバックとキャンセル除外の修正
old_query_sub = """        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY subcontractor_order_id) f1 ON o.id = f1.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY subcontractor_order_id) f2 ON o.id = f2.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY subcontractor_order_id) f3 ON o.id = f3.subcontractor_order_id
        WHERE o.subcontractor_id = :sub_id AND o.project_id = :pid"""

new_query_sub = """        LEFT JOIN (
            SELECT subcontractor_order_id, project_id, drive_file_id, file_name, version 
            FROM project_files 
            WHERE file_category = 'sub_structural_pdf' AND is_latest = 1
        ) f1 ON (f1.subcontractor_order_id = o.id OR (f1.subcontractor_order_id IS NULL AND f1.project_id = o.project_id AND o.order_type = 'struct'))
        LEFT JOIN (
            SELECT subcontractor_order_id, project_id, drive_file_id, file_name, version 
            FROM project_files 
            WHERE file_category = 'sub_architrend_design' AND is_latest = 1
        ) f2 ON (f2.subcontractor_order_id = o.id OR (f2.subcontractor_order_id IS NULL AND f2.project_id = o.project_id AND o.order_type = 'design'))
        LEFT JOIN (
            SELECT subcontractor_order_id, project_id, drive_file_id, file_name, version 
            FROM project_files 
            WHERE file_category = 'sub_architrend_struct' AND is_latest = 1
        ) f3 ON (f3.subcontractor_order_id = o.id OR (f3.subcontractor_order_id IS NULL AND f3.project_id = o.project_id AND o.order_type = 'struct'))
        WHERE o.subcontractor_id = :sub_id AND o.project_id = :pid AND o.status != 'cancelled'"""

# 改行コードの差異（CRLF/LF）に対応するため、正規化して置換
normalized_content = content.replace("\r\n", "\n")
normalized_old_query_sub = old_query_sub.replace("\r\n", "\n")
normalized_new_query_sub = new_query_sub.replace("\r\n", "\n")

if normalized_old_query_sub in normalized_content:
    normalized_content = normalized_content.replace(normalized_old_query_sub, normalized_new_query_sub)
    print("Successfully updated subcontractor query.")
else:
    print("Error: Subcontractor query not found.")

# 2. 管理者用発注履歴クエリの LEFT JOIN のフォールバックとキャンセル除外の修正
old_query_admin = """        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_structural_pdf' AND is_latest = 1 GROUP BY subcontractor_order_id) f1 ON o.id = f1.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_design' AND is_latest = 1 GROUP BY subcontractor_order_id) f2 ON o.id = f2.subcontractor_order_id
        LEFT JOIN (SELECT subcontractor_order_id, drive_file_id, file_name, version FROM project_files WHERE file_category = 'sub_architrend_struct' AND is_latest = 1 GROUP BY subcontractor_order_id) f3 ON o.id = f3.subcontractor_order_id
        WHERE o.project_id = :pid"""

new_query_admin = """        LEFT JOIN (
            SELECT subcontractor_order_id, project_id, drive_file_id, file_name, version 
            FROM project_files 
            WHERE file_category = 'sub_structural_pdf' AND is_latest = 1
        ) f1 ON (f1.subcontractor_order_id = o.id OR (f1.subcontractor_order_id IS NULL AND f1.project_id = o.project_id AND o.order_type = 'struct'))
        LEFT JOIN (
            SELECT subcontractor_order_id, project_id, drive_file_id, file_name, version 
            FROM project_files 
            WHERE file_category = 'sub_architrend_design' AND is_latest = 1
        ) f2 ON (f2.subcontractor_order_id = o.id OR (f2.subcontractor_order_id IS NULL AND f2.project_id = o.project_id AND o.order_type = 'design'))
        LEFT JOIN (
            SELECT subcontractor_order_id, project_id, drive_file_id, file_name, version 
            FROM project_files 
            WHERE file_category = 'sub_architrend_struct' AND is_latest = 1
        ) f3 ON (f3.subcontractor_order_id = o.id OR (f3.subcontractor_order_id IS NULL AND f3.project_id = o.project_id AND o.order_type = 'struct'))
        WHERE o.project_id = :pid AND o.status != 'cancelled'"""

normalized_old_query_admin = old_query_admin.replace("\r\n", "\n")
normalized_new_query_admin = new_query_admin.replace("\r\n", "\n")

if normalized_old_query_admin in normalized_content:
    normalized_content = normalized_content.replace(normalized_old_query_admin, normalized_new_query_admin)
    print("Successfully updated admin orders query.")
else:
    print("Error: Admin orders query not found.")

# 3. 古い重複コード（646〜889行目付近）の削除
# 3カラムコンテナ（display: grid）の終了直後にある重複部分を正規表現で探す
# 重複部分の開始パターン
dup_start_pattern = r'</div>\s*</div>\s*</div>\s*\n\s*<!-- 発注履歴 -->'
# 重複部分の終了パターン（協力業者専用ダッシュボードの開始 <?php else: ?> の直前）
# 重複部分には "<!-- 管理者用 案件別チャットUI -->" の中に "chatFile_" が含まれており、
# その後に "</div>\s*</div>\s*</div>\s*</div>\s*\n\s*<?php else:" が来る

match = re.search(dup_start_pattern, normalized_content)
if match:
    # 最初の 3カラムコンテナの終わりから探す
    # 管理者画面のHTMLは：
    # <div style="display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 20px; align-items: start;">
    #   <!-- カラム1 -->
    #   ...
    #   <!-- カラム2 -->
    #   ...
    #   <!-- カラム3 -->
    #     ... chatList ... chatFile ...
    #   </div>  <-- col-right の終わり
    # </div> <-- display:grid コンテナの終わり (644行目)
    
    # 重複ブロックの終了位置を探す。
    # <?php else: ?> の直前にある </div> を探す。
    # 646行目付近の <!-- 発注履歴 --> から、891行目の <?php else: ?> までを削除したい。
    dup_block_start = normalized_content.find("<!-- 発注履歴 -->", match.end() - 50)
    dup_block_end = normalized_content.find("<?php else:", dup_block_start)
    
    if dup_block_start != -1 and dup_block_end != -1:
        # dup_block_end の手前にある最後の </div> の後までを削除する
        # dup_block_end から前に辿って、重複ブロックの最後の </div> を特定
        last_div_index = normalized_content.rfind("</div>", dup_block_start, dup_block_end)
        if last_div_index != -1:
            end_pos = last_div_index + 6 # "</div>"の長さ
            # 改行などを考慮
            while end_pos < len(normalized_content) and normalized_content[end_pos] in [' ', '\t', '\r', '\n']:
                end_pos += 1
            
            # 置換（削除）
            to_remove = normalized_content[dup_block_start:end_pos]
            normalized_content = normalized_content.replace(to_remove, "")
            print("Successfully removed duplicated code blocks.")
        else:
            print("Error: Could not find ending </div> of duplicated blocks.")
    else:
        print("Error: Could not determine duplicate block positions.")
else:
    print("Error: Duplicate start pattern not found.")

# 改行コードをCRLFに戻して保存
with open(file_path, "w", encoding="utf-8", newline="\r\n") as f:
    f.write(normalized_content)
print("Finished applying fixes to project_subcontractor.php.")
