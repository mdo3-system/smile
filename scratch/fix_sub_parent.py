import os

file_path = os.path.join(os.path.dirname(__file__), '../project_subcontractor.php')
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Add effective_sub_id definition
target1 = "$user_id = $_SESSION['user_id'];"
replacement1 = "$user_id = $_SESSION['user_id'];\n\n$effective_sub_id = !empty($_SESSION['parent_id']) ? $_SESSION['parent_id'] : $user_id;"

if target1 in content:
    content = content.replace(target1, replacement1)
    print("1. Added $effective_sub_id definition.")
else:
    print("1. FAILED to find $user_id definition.")

# 2. Update task delivery execution sub_id
target2 = "$stmtOrder->execute(['id' => $order_id, 'sub_id' => $user_id]);"
replacement2 = "$stmtOrder->execute(['id' => $order_id, 'sub_id' => $effective_sub_id]);"

if target2 in content:
    content = content.replace(target2, replacement2)
    print("2. Updated task delivery execution sub_id.")
else:
    # Try with spaces/newlines
    target2_alt = "$stmtOrder->execute([\n\n'id' => $order_id,\n\n'sub_id' => $user_id\n\n]);"
    if target2_alt in content:
        content = content.replace(target2_alt, "$stmtOrder->execute(['id' => $order_id, 'sub_id' => $effective_sub_id]);")
        print("2. Updated task delivery execution sub_id (alt).")
    else:
        # Fallback to general replace
        import re
        content, count = re.subn(r'\'sub_id\'\s*=>\s*\$user_id\s*\]\s*\)\s*;', "'sub_id' => $effective_sub_id]);", content)
        if count > 0:
            print(f"2. Updated {count} occurrences via regex.")
        else:
            print("2. FAILED to update task delivery.")

# 3. Update orders query execution sub_id
# Let's search for the execute line:
# $stmt->execute(['sub_id' => $user_id, 'pid' => $project_id]);
# Since it has multiple newlines, let's normalize or regex match
import re
pattern = r'\$stmt->execute\(\[\s*\'sub_id\'\s*=>\s*\$user_id,\s*\'pid\'\s*=>\s*\$project_id\s*\]\);'
content, count = re.subn(pattern, "$stmt->execute(['sub_id' => $effective_sub_id, 'pid' => $project_id]);", content)
if count > 0:
    print(f"3. Updated {count} occurrences of orders query sub_id.")
else:
    # Fallback to normalized match
    target3 = "$stmt->execute(['sub_id' => $user_id, 'pid' => $project_id]);"
    # Try finding with any whitespace/newlines
    pattern_loose = r'\$stmt\s*->\s*execute\s*\(\s*\[\s*\'sub_id\'\s*=>\s*\$user_id,\s*\'pid\'\s*=>\s*\$project_id\s*\]\s*\)\s*;'
    content, count = re.subn(pattern_loose, "$stmt->execute(['sub_id' => $effective_sub_id, 'pid' => $project_id]);", content)
    if count > 0:
        print(f"3. Updated {count} occurrences of orders query sub_id (loose).")
    else:
        print("3. FAILED to update orders query sub_id.")

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("Done!")
