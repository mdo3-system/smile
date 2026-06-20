file_path = "project_subcontractor.php"

with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

normalized_content = content.replace("\r\n", "\n")

first_index = normalized_content.find("<!-- 発注履歴 -->")
if first_index == -1:
    print("Error: Could not find first '<!-- 発注履歴 -->'")
    exit(1)

second_index = normalized_content.find("<!-- 発注履歴 -->", first_index + len("<!-- 発注履歴 -->"))
if second_index == -1:
    print("Error: Could not find second '<!-- 発注履歴 -->'")
    exit(1)

# 管理者ifを閉じる else の一意な特徴を検索
else_pattern = "<?php else: ?>\n        <div style=\"display:flex; justify-content:space-between;"
else_index = normalized_content.find(else_pattern, second_index)

if else_index == -1:
    # 別のパターンかもしれないので、もう少し短縮して検索
    else_pattern = "<?php else: ?>\n        <div style=\"display:flex;"
    else_index = normalized_content.find(else_pattern, second_index)

if else_index == -1:
    print("Error: Could not find the else block for subcontractors")
    exit(1)

print(f"Second index: {second_index}")
print(f"Else index: {else_index}")

# second_index から else_index までの余白をトリミングして削除
# else_index の直前に div の閉じタグがあるはず
last_div_index = normalized_content.rfind("</div>", second_index, else_index)
if last_div_index == -1:
    print("Error: Could not find </div> before else_index")
    exit(1)

end_pos = last_div_index + len("</div>")
while end_pos < else_index and normalized_content[end_pos] in [' ', '\t', '\r', '\n']:
    end_pos += 1

print(f"Removing content from index {second_index} to {end_pos}")

new_content = normalized_content[:second_index] + normalized_content[end_pos:]

# 保存
with open(file_path, "w", encoding="utf-8", newline="\r\n") as f:
    f.write(new_content)

print("Successfully removed duplicated block.")
