# -*- coding: utf-8 -*-
"""
Local CAD / PDF Parser Service for Building Inspection
1. JWW ネイティブ直接解析 (外部アプリ不要・超高速)
2. DXF (ezdxf) テキスト・寸法・図枠解析
3. PDF (pypdf/pdfplumber) 申請書・図面テキスト解析
4. DRA-CAD / JacConvert 連携フォールバック
ポート 5005 でローカル起動し、ダッシュボード (ブラウザ) と CORS 連携します。
"""

import os
import re
import sys
import glob
import json
import shutil
import tempfile
import subprocess
from flask import Flask, request, jsonify
from flask_cors import CORS

try:
    import ezdxf
except ImportError:
    ezdxf = None

try:
    import pypdf
except ImportError:
    pypdf = None

try:
    import pdfplumber
except ImportError:
    pdfplumber = None

app = Flask(__name__)
# すべてのオリジンからのアクセスを許可 (ローカル/Web両方から連携可能)
CORS(app, resources={r"/*": {"origins": "*"}})

@app.after_request
def add_cors_headers(response):
    response.headers['Access-Control-Allow-Origin'] = '*'
    response.headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS'
    response.headers['Access-Control-Allow-Headers'] = '*'
    response.headers['Access-Control-Allow-Private-Network'] = 'true'
    return response

# DRA-CAD 19 の実行ファイル候補
DRACAD_CANDIDATES = [
    r"C:\Program Files\DRA-CAD19\DRAWIN.exe",
    r"C:\Program Files\DRA-CAD19\DRACAD.exe",
    r"C:\Program Files\kozo\DRACAD19\DRACAD.exe",
    r"C:\Program Files (x86)\kozo\DRACAD19\DRACAD.exe",
    r"C:\Program Files\kozo\DRACAD20\DRACAD.exe",
    r"C:\Program Files\kozo\DRACAD18\DRACAD.exe",
    r"C:\kozo\DRACAD19\DRACAD.exe",
    r"D:\Program Files\kozo\DRACAD19\DRACAD.exe",
]

# JacConvert の実行可能ファイル候補
JACCONVERT_CANDIDATES = [
    r"C:\Program Files\JacConvert\JacConvert.exe",
    r"C:\Program Files (x86)\JacConvert\JacConvert.exe",
    r"C:\JacConvert\JacConvert.exe",
    r"C:\Jac\JacConvert.exe",
    r"C:\jww\JacConvert.exe",
    r"C:\jw_win\JacConvert.exe",
    r"D:\Program Files\JacConvert\JacConvert.exe",
    r"D:\JacConvert\JacConvert.exe",
]

def find_dracad_path():
    """DRA-CAD のパスを自動探索"""
    for p in DRACAD_CANDIDATES:
        if os.path.exists(p):
            return p
    return shutil.which("DRACAD.exe") or shutil.which("dracad.exe")

def find_jacconvert_path():
    """環境内の JacConvert.exe パスを自動探索"""
    for p in JACCONVERT_CANDIDATES:
        if os.path.exists(p):
            return p
    return shutil.which("JacConvert.exe") or shutil.which("jacconvert.exe")

def normalize_number(num_str):
    """全角・カンマ・単位を除去して float または None を返す"""
    if not num_str:
        return None
    s = str(num_str).strip()
    zen = "０１２３４５６７８９．，"
    han = "0123456789.,"
    trans = str.maketrans(zen, han)
    s = s.translate(trans).replace(',', '')
    m = re.search(r'[\d\.]+', s)
    if m:
        try:
            return float(m.group(0))
        except ValueError:
            return None
    return None

def normalize_height(val):
    """
    高さの数値を m 単位に正規化
    例: 8520 -> 8.52 (mm表記の場合), 8.52 -> 8.52 (m表記の場合)
    """
    num = normalize_number(val)
    if num is None or num == 0:
        return None
    if num > 50.0:  # 50m以上はmm表記と判定 (8520mm -> 8.52m)
        return round(num / 1000.0, 3)
    return round(num, 3)

def convert_jww_to_dxf_via_dracad(jww_path, dxf_path):
    """
    DRA-CAD 19 の COM オートメーションまたはプロセス呼び出しを用いて JWW を DXF に自動変換
    """
    abs_jww = os.path.abspath(jww_path)
    abs_dxf = os.path.abspath(dxf_path)

    # 1. COM Automation による高速自動変換
    try:
        import win32com.client
        progids = ["DRACAD.Application", "DRACAD19.Application", "DRACAD20.Application", "DRACAD18.Application"]
        for progid in progids:
            try:
                cad = win32com.client.Dispatch(progid)
                if cad:
                    print(f"    [DRA-CAD] COMオートメーション接続成功 ({progid})")
                    doc = None
                    try:
                        doc = cad.Documents.Open(abs_jww)
                    except Exception:
                        try:
                            doc = cad.Open(abs_jww)
                        except Exception:
                            pass
                    
                    if doc:
                        try:
                            doc.SaveAs(abs_dxf)
                        except Exception:
                            try:
                                doc.SaveAs(abs_dxf, 2) # DXF format
                            except Exception:
                                pass
                        try:
                            doc.Close(False)
                        except Exception:
                            pass
                        
                        if os.path.exists(abs_dxf) and os.path.getsize(abs_dxf) > 0:
                            print(f"    ✓ [DRA-CAD] DXF自動変換成功: {os.path.basename(abs_dxf)}")
                            return True
            except Exception:
                continue
    except Exception:
        pass

    # 2. DRA-CAD 実行ファイル経由のバッチ変換（VBScript / WScript 連携）
    dracad_exe = find_dracad_path()
    if dracad_exe and os.path.exists(dracad_exe):
        try:
            vbs_content = f'''
On Error Resume Next
Set cad = CreateObject("DRACAD.Application")
If cad Is Nothing Then Set cad = CreateObject("DRACAD19.Application")
If Not cad Is Nothing Then
    Set doc = cad.Documents.Open("{abs_jww.replace('\\', '\\\\')}")
    If doc Is Nothing Then Set doc = cad.Open("{abs_jww.replace('\\', '\\\\')}")
    If Not doc Is Nothing Then
        doc.SaveAs "{abs_dxf.replace('\\', '\\\\')}"
        doc.Close False
    End If
    cad.Quit
End If
'''
            vbs_path = os.path.join(tempfile.gettempdir(), "_dracad_conv.vbs")
            with open(vbs_path, "w", encoding="shift_jis") as vf:
                vf.write(vbs_content)
            
            subprocess.run(["cscript.exe", "//nologo", vbs_path], capture_output=True, timeout=15)
            if os.path.exists(abs_dxf) and os.path.getsize(abs_dxf) > 0:
                print(f"    ✓ [DRA-CAD VBS] DXF自動変換成功: {os.path.basename(abs_dxf)}")
                return True
        except Exception:
            pass

    return False

def convert_jww_to_dxf_via_jacconvert(jww_path, dxf_path):
    """JacConvert CLI による JWW -> DXF 変換"""
    jac = find_jacconvert_path()
    if not jac or not os.path.exists(jac):
        return False
    try:
        cmd = [jac, f"-f{os.path.abspath(jww_path)}", f"-o{os.path.abspath(dxf_path)}", "-c", "-q"]
        subprocess.run(cmd, capture_output=True, timeout=10)
        if os.path.exists(dxf_path) and os.path.getsize(dxf_path) > 0:
            print(f"    ✓ [JacConvert] DXF自動変換成功: {os.path.basename(dxf_path)}")
            return True
    except Exception:
        pass
    return False

def clean_jww_text_item(s):
    """JWWテキストのクリーニングとノイズ判定"""
    if not s:
        return ""
    # 制御文字除去
    cleaned = re.sub(r'[\x00-\x1F\x7F\x80]', '', s).strip()
    # フォント名プレフィックス除去
    cleaned = re.sub(r'^.*?ゴシック[\(\@\*\$\d\s]*', '', cleaned)
    cleaned = re.sub(r'^.*?明朝[\(\@\*\$\d\s]*', '', cleaned)
    # JWW書式文字除去 (^, ~, %, color/font commands)
    cleaned = re.sub(r'^\^[A-Za-z0-9]', '', cleaned)
    cleaned = cleaned.strip()
    if len(cleaned) < 2:
        return ""
    # 記号のみ、または短い英数字記号ゴミ（I@, `m, ffff, M@等）を除外
    if re.fullmatch(r'[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]+', cleaned):
        return ""
    if re.fullmatch(r'[A-Za-z0-9@\._\-~]{1,4}', cleaned):
        return ""
    return cleaned

def extract_from_jww_native(jww_path):
    """
    【外部アプリ不要】PythonによるJWWバイナリ直接テキスト・寸法抽出パーサー
    ゼロ終端チャンク分割 + 高度ノイズ除去 + 近傍走査により高精度抽出
    """
    texts = []
    try:
        with open(jww_path, 'rb') as f:
            raw_data = f.read()

        # ゼロ終端 (0x00) によるチャンク分割走査
        chunks = raw_data.split(b'\x00')
        for c in chunks:
            if len(c) < 2:
                continue
            try:
                decoded = c.decode('cp932')
                cleaned = clean_jww_text_item(decoded)
                if cleaned:
                    texts.append(cleaned)
            except Exception:
                continue

        # 念のため連続バイト列パターンでも追加走査（取りこぼし防止）
        pattern = re.compile(
            b'(?:[\x20-\x7E]|(?:[\x81-\x9F\xE0-\xFC][\x40-\x7E\x80-\xFC])){3,}'
        )
        for m in pattern.findall(raw_data):
            try:
                decoded = m.decode('cp932')
                cleaned = clean_jww_text_item(decoded)
                if cleaned and cleaned not in texts:
                    texts.append(cleaned)
            except Exception:
                continue

    except Exception as e:
        print(f"[JWW Native Parser Warning] {e}")

    combined_text = "\n".join(texts)
    return parse_building_text(combined_text, source="jww", texts_list=texts), texts

def extract_from_dxf_raw_fallback(dxf_path):
    """
    【絶対フォールバック】ezdxfで構文エラーになる非標準・破損DXFから、
    文字コード自動判定（CP932/Shift-JIS優先）によるグループコード直接走査でテキストを完全復元
    """
    texts = []
    encodings = ['cp932', 'shift_jis', 'utf-8', 'euc-jp']
    content = ""
    for enc in encodings:
        try:
            with open(dxf_path, 'r', encoding=enc, errors='strict') as f:
                content = f.read()
                if content:
                    break
        except Exception:
            continue

    if not content:
        try:
            with open(dxf_path, 'r', encoding='cp932', errors='replace') as f:
                content = f.read()
        except Exception:
            return []

    lines = content.splitlines()
    i = 0
    while i < len(lines) - 1:
        code_str = lines[i].strip()
        val_str = lines[i+1].strip()
        # グループコード 1 (テキスト値), 3 (MTEXTテキスト), 1000 (拡張文字列)
        if code_str in ('1', '3', '1000') and val_str:
            cleaned = re.sub(r'\\[A-Za-z0-9]+;', '', val_str).strip()
            cleaned = re.sub(r'[\{\}]', '', cleaned)
            if cleaned and len(cleaned) >= 2 and cleaned not in texts:
                texts.append(cleaned)
        i += 2

    return texts

def extract_from_dxf_content(dxf_path):
    """ezdxf (通常 + recover自動修復) + RAWテキスト完全マージによる超堅牢DXF抽出"""
    if not ezdxf:
        raise ImportError("ezdxf がインストールされていません")

    texts = []
    doc = None

    # 1. ezdxf.recover による自動修復モードでの読み込み試行 (ACDBDICTIONARYWDFLT等の非標準ヘッダー自動修復)
    try:
        from ezdxf import recover
        doc, auditor = recover.readfile(dxf_path)
    except Exception:
        try:
            from ezdxf import recover
            doc, auditor = recover.readfile(dxf_path, encoding='cp932')
        except Exception:
            pass

    # 2. 通常の readfile (recoverでダメだった場合のフォールバック)
    if not doc:
        try:
            doc = ezdxf.readfile(dxf_path)
        except Exception:
            try:
                doc = ezdxf.readfile(dxf_path, encoding='cp932')
            except Exception:
                pass

    # 3. doc が取得できた場合はモデル空間から走査
    if doc:
        try:
            msp = doc.modelspace()
            # TEXT / MTEXT
            for e in msp.query('TEXT MTEXT'):
                try:
                    txt = e.dxf.text if e.dxftype() == 'TEXT' else e.text
                    if txt:
                        cleaned = re.sub(r'\\[A-Za-z0-9]+;', '', txt).strip()
                        cleaned = re.sub(r'[\{\}]', '', cleaned)
                        if cleaned and cleaned not in texts:
                            texts.append(cleaned)
                except Exception:
                    continue

            # DIMENSION
            for dim in msp.query('DIMENSION'):
                try:
                    dim_text = dim.dxf.get('text', '')
                    if dim_text and dim_text not in texts:
                        texts.append(dim_text.strip())
                except Exception:
                    continue

            # INSERT (ブロック属性 ATTRIB)
            for insert in msp.query('INSERT'):
                try:
                    for attrib in insert.attribs:
                        if attrib.dxf.text and attrib.dxf.text.strip() not in texts:
                            texts.append(attrib.dxf.text.strip())
                except Exception:
                    continue
        except Exception as e:
            print(f"[DXF Modelspace Warning] {e}")

    # 4. RAWテキスト走査結果をマージ（ezdxfが破損スキップした非標準要素・寸法値も100%回収）
    raw_texts = extract_from_dxf_raw_fallback(dxf_path)
    has_surrogates = any(any(0xD800 <= ord(c) <= 0xDFFF for c in t) for t in texts)
    if has_surrogates or not texts:
        # ezdxfが誤ったエンコーディングでサロゲート文字を生成した場合は、文字コード自動判別のRAWテキストを完全採用
        texts = raw_texts
    else:
        for rt in raw_texts:
            if rt not in texts:
                texts.append(rt)

    combined_text = "\n".join(texts)
    return parse_building_text(combined_text, source="cad", texts_list=texts), texts

def extract_from_pdf_content(pdf_path):
    """PDF からテキストを抽出（pypdf + pdfplumber フォールバック）"""
    full_text = ""
    if pdfplumber:
        try:
            with pdfplumber.open(pdf_path) as pdf:
                for page in pdf.pages:
                    t = page.extract_text()
                    if t:
                        full_text += t + "\n"
        except Exception:
            pass

    if not full_text.strip() and pypdf:
        try:
            reader = pypdf.PdfReader(pdf_path)
            for page in reader.pages:
                t = page.extract_text()
                if t:
                    full_text += t + "\n"
        except Exception:
            pass

    return parse_building_text(full_text, source="pdf"), full_text

def extract_height_data(text, texts_list=None):
    """高さ・軒高・床高・階高の高精度抽出（正規表現 + 近傍走査 + PHFL除外）"""
    res = {
        'max_height': None,
        'eaves_height': None,
        'floor_1_height': None,
        'floor_2_height': None,
        'story_height': None
    }
    if not text and not texts_list:
        return res

    # 1. テキスト全体からの正規表現マッチ
    if text:
        m_h_max = re.search(r'(?:最高(?:の)?高(?:さ)?|最高部|最高高|最高頂部|棟高|最高棟高)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
        if m_h_max:
            res['max_height'] = normalize_height(m_h_max.group(1))

        m_h_eaves = re.search(r'(?:最高(?:の)?軒(?:の)?高(?:さ)?|最高軒高|軒高|軒の高さ)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
        if m_h_eaves:
            res['eaves_height'] = normalize_height(m_h_eaves.group(1))

        m_1fl = re.search(r'(?:1FL|1階床(?:の)?高(?:さ)?|床の高さ)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
        if m_1fl:
            val = normalize_height(m_1fl.group(1))
            if val and 0.1 <= val <= 2.0:
                res['floor_1_height'] = val

        m_2fl = re.search(r'(?:2FL|2階床(?:の)?高(?:さ)?)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
        if m_2fl:
            val = normalize_height(m_2fl.group(1))
            if val and 2.0 <= val <= 5.0:
                res['floor_2_height'] = val

        m_story = re.search(r'(?:階高|横架材間)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
        if m_story:
            val = normalize_height(m_story.group(1))
            if val and 2.2 <= val <= 4.0:
                res['story_height'] = val

    # 2. 矩計図等の近傍走査（テキストリストがある場合）
    if texts_list:
        # A. 連続する寸法値のペアリング探索（最高高さ8.436と軒高6.076が連続しているパターン）
        for i in range(len(texts_list) - 1):
            t1 = texts_list[i].strip()
            t2 = texts_list[i+1].strip()
            # 日付や記号混じりを除外
            if any(c in t1 or c in t2 for c in ['R', 'H', '-', '/', ':', '図', '日', '年', 'G']):
                continue
            v1 = normalize_height(t1)
            v2 = normalize_height(t2)
            if v1 and v2:
                # v1 が最高高さ(7.0〜15.0m), v2 が軒高(5.0〜7.5m)
                if 7.0 <= v1 <= 15.0 and 5.0 <= v2 <= 7.5:
                    if res['max_height'] is None: res['max_height'] = v1
                    if res['eaves_height'] is None: res['eaves_height'] = v2
                    break

        # B. ラベル走査（最近傍順: -1, +1, -2, +2, -3, +3, -4, +4, -5, +5 で最も近い寸法を最優先）
        neighbor_offsets = [-1, 1, -2, 2, -3, 3, -4, 4, -5, 5]
        for idx, t in enumerate(texts_list):
            # 最高高さの探索
            if res['max_height'] is None and re.search(r'(?:最高(?:の)?高(?:さ)?|最高部|最高高|棟高)', t):
                for off in neighbor_offsets:
                    c_idx = idx + off
                    if 0 <= c_idx < len(texts_list):
                        candidate = texts_list[c_idx]
                        if any(c in candidate for c in ['R', 'H', '-', '/', ':', '図', '日', '年', '軒']): continue
                        candidate_clean = candidate.replace(',', '')
                        m = re.search(r'([1-9][0-9]{3,4}|[1-9]\.[0-9]{2,3})', candidate_clean)
                        if m:
                            val = normalize_height(m.group(1))
                            if val and 4.0 <= val <= 25.0:
                                res['max_height'] = val
                                break

            # 軒高の探索
            if res['eaves_height'] is None and re.search(r'(?:最高の軒高|軒高|軒の高さ)', t):
                for off in neighbor_offsets:
                    c_idx = idx + off
                    if 0 <= c_idx < len(texts_list):
                        candidate = texts_list[c_idx]
                        if any(k in candidate for k in ['PH', 'FL', '天端', '土間', '基礎', 'パラペット', 'R', 'H', '日', '年']):
                            continue
                        candidate_clean = candidate.replace(',', '')
                        m = re.search(r'([1-9][0-9]{3,4}|[1-9]\.[0-9]{2,3})', candidate_clean)
                        if m:
                            val = normalize_height(m.group(1))
                            # 2階建て住宅の軒高は通常 2.5m 〜 7.5m
                            if val and 2.5 <= val <= 7.5:
                                res['eaves_height'] = val
                                break

            # 1FLの探索
            if res['floor_1_height'] is None and re.search(r'(?:1FL|1階床高)', t):
                for off in neighbor_offsets:
                    c_idx = idx + off
                    if 0 <= c_idx < len(texts_list):
                        candidate = texts_list[c_idx]
                        candidate_clean = candidate.replace(',', '')
                        m = re.search(r'([1-9][0-9]{2,3}|0\.[0-9]{2,3})', candidate_clean)
                        if m:
                            val = normalize_height(m.group(1))
                            if val and 0.1 <= val <= 2.0:
                                res['floor_1_height'] = val
                                break

            # 2FLの探索
            if res['floor_2_height'] is None and re.search(r'(?:2FL|2階床高)', t):
                for off in neighbor_offsets:
                    c_idx = idx + off
                    if 0 <= c_idx < len(texts_list):
                        candidate = texts_list[c_idx]
                        candidate_clean = candidate.replace(',', '')
                        m = re.search(r'([1-9][0-9]{3}|[2-4]\.[0-9]{2,3})', candidate_clean)
                        if m:
                            val = normalize_height(m.group(1))
                            if val and 2.0 <= val <= 5.0:
                                res['floor_2_height'] = val
                                break

            # 階高の探索
            if res['story_height'] is None and re.search(r'(?:階高|横架材間)', t):
                for off in neighbor_offsets:
                    c_idx = idx + off
                    if 0 <= c_idx < len(texts_list):
                        candidate = texts_list[c_idx]
                        candidate_clean = candidate.replace(',', '')
                        m = re.search(r'([1-9][0-9]{3}|[2-3]\.[0-9]{2,3})', candidate_clean)
                        if m:
                            val = normalize_height(m.group(1))
                            if val and 2.2 <= val <= 3.8:
                                res['story_height'] = val
                                break

    return res

def extract_area_data(text, texts_list=None):
    """面積情報の高精度抽出（確認申請書特化 + CAD m^ u2 単位解析 + 近傍走査）"""
    res = {
        'building_area': None,
        'floor_1_area': None,
        'floor_2_area': None,
        'total_area': None
    }
    if not text and not texts_list:
        return res

    # 1. 確認申請書（PDF）の専用抽出パターン（【10.建築面積】、【11.延べ面積】）
    if text:
        # 建築面積: 【10.建築面積】...【イ.建築物全体】（ 38.07 ）
        m_b_app = re.search(r'【10\.建築面積】[\s\S]{0,150}?【イ\.建築物全体】[^\d]*([0-9\.,]+)', text)
        if m_b_app:
            v = normalize_number(m_b_app.group(1))
            if v: res['building_area'] = round(v, 2)

        # 延べ面積: 【11.延べ面積】...【イ.建築物全体】（ 78.97 ）または 【2.延べ面積】 78.97 ㎡
        m_t_app = re.search(r'【11\.延べ面積】[\s\S]{0,150}?【イ\.建築物全体】[^\d]*([0-9\.,]+)', text)
        if not m_t_app:
            m_t_app = re.search(r'【2\.延べ面積】\s*([0-9\.,]+)\s*㎡', text)
        if m_t_app:
            v = normalize_number(m_t_app.group(1))
            if v: res['total_area'] = round(v, 2)

    # 2. texts_list からの面積走査
    if texts_list:
        # A. 単位(m^ u2, ㎡, m2)付き確定面積の走査（最優先）
        m2_vals = []
        for t in texts_list:
            m = re.search(r'([0-9\.,]+)\s*(?:m\^?\s*u?2|㎡|m2)', t)
            if m:
                v = normalize_number(m.group(1))
                if v and 15.0 <= v <= 3000.0 and v not in m2_vals:
                    m2_vals.append(v)

        if m2_vals:
            # 延べ面積は最大値 (例: 78.97)
            if res['total_area'] is None:
                res['total_area'] = round(max(m2_vals), 2)

            # 建築面積は延べ面積より小さい値で、1階床面積に相当するもの (例: 38.07)
            if res['building_area'] is None:
                for v in m2_vals:
                    if 20.0 <= v <= 200.0 and v < res['total_area']:
                        if v > res['total_area'] * 0.75:
                            continue
                        res['building_area'] = round(v, 2)
                        break

        # B. ラベル走査による補完（単位付きで見つからなかった項目のフォールバック）
        for idx, t in enumerate(texts_list):
            if res['building_area'] is None and re.search(r'^(?:建築面積|建面積)$', t.strip()):
                for cand in texts_list[idx+1:min(len(texts_list), idx+5)]:
                    v = normalize_number(cand)
                    if v and 10.0 <= v <= 1000.0:
                        res['building_area'] = round(v, 2)
                        break

            if res['total_area'] is None and re.search(r'^(?:延べ面積|延床面積|延面積)$', t.strip()):
                for cand in texts_list[idx+1:min(len(texts_list), idx+5)]:
                    v = normalize_number(cand)
                    if v and 10.0 <= v <= 1500.0:
                        res['total_area'] = round(v, 2)
                        break

            if res['floor_1_area'] is None and re.search(r'(?:1階(?:床)?面積|１階(?:床)?面積|1F(?:床)?面積)', t):
                for cand in texts_list[idx+1:min(len(texts_list), idx+5)]:
                    v = normalize_number(cand)
                    if v and 10.0 <= v <= 1000.0:
                        res['floor_1_area'] = round(v, 2)
                        break

            if res['floor_2_area'] is None and re.search(r'(?:2階(?:床)?面積|２階(?:床)?面積|2F(?:床)?面積)', t):
                for cand in texts_list[idx+1:min(len(texts_list), idx+5)]:
                    v = normalize_number(cand)
                    if v and 10.0 <= v <= 1000.0:
                        res['floor_2_area'] = round(v, 2)
                        break

    # 3. 一般的な正規表現マッチ（パーセント % / ％ は完全除外）
    if text:
        if res['building_area'] is None:
            m_b = re.search(r'建築面積[^\d\n\r:：]*[:：\s]*[（\(]?\s*([0-9\.,]+)\s*[）\)]?(?:\s*(?:㎡|m2|m\^?\s*u?2))?(?![\s]*[%％])', text)
            if m_b:
                val = normalize_number(m_b.group(1))
                if val is not None and val < 3000: res['building_area'] = round(val, 2)

        if res['total_area'] is None:
            m_t = re.search(r'(?:延べ面積|延床面積|延面積)[^\d\n\r:：]*[:：\s]*[（\(]?\s*([0-9\.,]+)\s*[）\)]?(?:\s*(?:㎡|m2|m\^?\s*u?2))?(?![\s]*[%％])', text)
            if m_t:
                val = normalize_number(m_t.group(1))
                if val is not None and val < 5000: res['total_area'] = round(val, 2)

        if res['floor_1_area'] is None:
            m_f1 = re.search(r'(?:1階(?:床)?面積|１階(?:床)?面積|1F(?:床)?面積)[^\d\n\r:：]*[:：\s]*([0-9\.,]+)(?:\s*(?:㎡|m2|M2))?', text)
            if m_f1:
                val = normalize_number(m_f1.group(1))
                if val is not None: res['floor_1_area'] = round(val, 2)

        if res['floor_2_area'] is None:
            m_f2 = re.search(r'(?:2階(?:床)?面積|２階(?:床)?面積|2F(?:床)?面積)[^\d\n\r:：]*[:：\s]*([0-9\.,]+)(?:\s*(?:㎡|m2|M2))?', text)
            if m_f2:
                val = normalize_number(m_f2.group(1))
                if val is not None: res['floor_2_area'] = round(val, 2)

    return res

def extract_metadata_data(text, texts_list=None):
    """図枠情報（工事名称・建築主・設計者・所在地）の高精度抽出＆ノイズ完全排除"""
    res = {
        'project_name': "",
        'client_name': "",
        'architect_name': "",
        'location': ""
    }

    # 1. texts_list がある場合（CAD / JWW 等の文字列リスト走査）
    if texts_list:
        # 工事名称
        for t in texts_list:
            m = re.search(r'([^\n\r]{2,30}(?:様邸|邸)[\s　]*(?:新築|増築|改築)?(?:住宅)?(?:工事|計画)?)', t)
            if m and len(m.group(1).strip()) >= 5:
                res['project_name'] = m.group(1).strip()
                # 建築主も同時に取得
                m_cli = re.search(r'([^\n\r]{2,15})(?:様邸|邸)', m.group(1))
                if m_cli and not res['client_name']:
                    res['client_name'] = re.sub(r'[\s　]+', '', m_cli.group(1))
                break

        # 設計者 (具体的な会社名・事務所名を優先)
        arch_candidates = []
        for t in texts_list:
            if ('株式会社' in t or '有限会社' in t or '合同会社' in t) and any(k in t for k in ['設計', '建築', '企画', '工房', 'スタジオ', 'オフィス']):
                arch_candidates.insert(0, t.strip())
            elif any(k in t for k in ['建築士事務所', '設計事務所', '設計室', 'アトリエ']) and len(t.strip()) >= 6:
                # 明らかなノイズ（英記号のみ）を除外
                if not re.search(r'^[a-zA-Z0-9\s@\.\-_]+$', t.strip()):
                    arch_candidates.append(t.strip())

        if arch_candidates:
            res['architect_name'] = arch_candidates[0]

        # 所在地 (都道府県から始まる住所表記)
        for t in texts_list:
            m_addr = re.search(r'((?:東京都|北海道|(?:京都|大阪)府|.{2,3}県)[^\n\r]{2,40}(?:市|区|町|村)[^\n\r]{1,30})', t)
            if m_addr:
                res['location'] = m_addr.group(1).strip()
                break

    # 2. テキスト全体からのフォールバック（PDF申請書や不足項目の補完）
    if text:
        if not res['project_name']:
            m_proj = re.search(r'(?:建築物等の名称(?:又は工事名)?|工事名称|工事名)[：:\s]+([^\n\r]{2,50})', text)
            if m_proj:
                cand = m_proj.group(1).strip()
                if not re.search(r'^[a-zA-Z0-9\s@\.\-_]+$', cand):
                    res['project_name'] = cand

        if not res['client_name']:
            m_client = re.search(r'(?:建築主(?:の氏名)?|お施主様名?|施主名?)[：:\s]+([^\n\r]{2,30})', text)
            if m_client:
                cand = m_client.group(1).strip()
                if not re.search(r'^[a-zA-Z0-9\s@\.\-_]+$', cand):
                    res['client_name'] = cand

        if not res['architect_name']:
            m_arch = re.search(r'(?:設計者(?:氏名)?|設計事務所|設計監理)[：:\s]+([^\n\r]{2,50})', text)
            if m_arch:
                cand = m_arch.group(1).strip()
                # ffff や M@ などの英記号ノイズを除外
                if not re.search(r'^[a-zA-Z0-9\s@\.\-_]+$', cand):
                    res['architect_name'] = cand

        if not res['location']:
            m_loc = re.search(r'(?:地名地番|敷地の位置|敷地の所在地|建設地|工事場所)[：:\s]+([^\n\r]{2,60})', text)
            if m_loc:
                cand = m_loc.group(1).strip()
                if not re.search(r'^[a-zA-Z0-9\s@\.\-_]+$', cand):
                    res['location'] = cand

    return res

def parse_building_text(text, source="pdf", texts_list=None):
    """
    建築図書テキスト解析の統合ディスパッチャー
    SRPに基づき、高さ・面積・メタデータの抽出関数を協調実行
    """
    data = {
        'max_height': None,
        'eaves_height': None,
        'building_area': None,
        'floor_1_area': None,
        'floor_2_area': None,
        'total_area': None,
        'project_name': "",
        'client_name': "",
        'architect_name': "",
        'location': "",
    }
    if not text and not texts_list:
        return data

    heights = extract_height_data(text, texts_list=texts_list)
    areas = extract_area_data(text, texts_list=texts_list)
    metadata = extract_metadata_data(text, texts_list=texts_list)

    data.update(heights)
    data.update(areas)
    data.update(metadata)
    return data

@app.route('/api/health', methods=['GET'])
def health():
    """ヘルスチェック & 実行環境情報の取得"""
    dracad_path = find_dracad_path()
    jac_path = find_jacconvert_path()
    return jsonify({
        'status': 'ok',
        'service': 'Building CAD/PDF Parser',
        'port': 5005,
        'jww_native_parser': '有効 (Python直接解析・外部ツール不要)',
        'dracad_available': dracad_path is not None,
        'dracad_path': dracad_path or '未検出',
        'jacconvert_available': jac_path is not None,
        'jacconvert_path': jac_path or '未検出',
        'ezdxf_available': ezdxf is not None,
        'pypdf_available': pypdf is not None or pdfplumber is not None,
        'gemini_available': bool(os.environ.get('GEMINI_API_KEY') or os.environ.get('GOOGLE_API_KEY'))
    })

def extract_with_gemini_vision(file_path):
    """
    Gemini 2.0 / 1.5 Flash によるマルチモーダル図面視覚推論
    PDF / 画像ファイルを直接解析し、寸法線・引出線・図枠から人間目線で高精度抽出
    """
    api_key = os.environ.get('GEMINI_API_KEY') or os.environ.get('GOOGLE_API_KEY')
    if not api_key:
        env_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env')
        if os.path.exists(env_path):
            try:
                with open(env_path, 'r', encoding='utf-8', errors='ignore') as f:
                    for line in f:
                        if line.startswith('GEMINI_API_KEY='):
                            api_key = line.strip().split('=', 1)[1].strip('"\'')
                            break
            except Exception:
                pass

    if not api_key:
        return None

    try:
        import google.generativeai as genai
        genai.configure(api_key=api_key)
        model = genai.GenerativeModel("gemini-2.0-flash")

        sample_file = genai.upload_file(path=file_path)
        prompt = """
あなたは建築確認申請・設計図書の専門家です。提示された建築図書（PDFまたは図面画像）を詳細に視覚的に読解し、以下の項目を正確に抽出してJSON形式のみで出力してください。
マークダウンのコードブロック（```json ... ```）形式で出力してください。

{
  "max_height": 最高の高さ（GL基準、メートル単位の数値。例: 8.436。見つからない場合はnull）,
  "eaves_height": 最高の軒の高さ（GL基準、メートル単位の数値。例: 6.076。見つからない場合はnull）,
  "floor_1_height": 1階床高（1FL、GL基準、メートル単位の数値。例: 0.476。見つからない場合はnull）,
  "floor_2_height": 2階床高（2FL、GL基準、メートル単位の数値。例: 3.276。見つからない場合はnull）,
  "story_height": 階高（1階または2階の横架材間等、メートル単位の数値。例: 2.800。見つからない場合はnull）,
  "building_area": 建築面積（㎡単位の数値。例: 38.07。建蔽率%は除外。見つからない場合はnull）,
  "floor_1_area": 1階床面積（㎡単位の数値。例: 36.45。見つからない場合はnull）,
  "floor_2_area": 2階床面積（㎡単位の数値。例: 38.07。見つからない場合はnull）,
  "total_area": 延べ面積（㎡単位の数値。例: 78.97。容積率%は除外。見つからない場合はnull）,
  "project_name": "工事名称・物件名（文字列。見つからない場合は空文字）",
  "client_name": "建築主（文字列。見つからない場合は空文字）",
  "architect_name": "設計者・事務所名（文字列。見つからない場合は空文字）",
  "location": "地名地番・所在地（文字列。見つからない場合は空文字）"
}
"""
        response = model.generate_content([sample_file, prompt])
        res_text = response.text
        m = re.search(r'```(?:json)?\s*(\{.*?\})\s*```', res_text, re.DOTALL)
        json_str = m.group(1) if m else res_text.strip()
        data = json.loads(json_str)
        print(f"    🌟 [Gemini API 視覚推論成功] 抽出データ: {data}")
        return data
    except Exception as e:
        print(f"    ℹ [Gemini API スキップ/エラー: ローカルパーサーへフォールバックします] {e}")
        return None

@app.route('/api/analyze', methods=['POST'])
def analyze():
    """単一ファイルの解析"""
    doc_type = request.form.get('doc_type', 'unknown')
    file = request.files.get('file')
    if not file:
        return jsonify({'error': 'ファイルが添付されていません'}), 400

    filename = file.filename.lower()

    with tempfile.TemporaryDirectory() as tmpdir:
        input_path = os.path.join(tmpdir, file.filename)
        file.save(input_path)

        try:
            # 1. Gemini 視覚推論API連携（PDFの場合）
            data = None
            if filename.endswith(('.pdf', '.png', '.jpg', '.jpeg')):
                data = extract_with_gemini_vision(input_path)

            if not data:
                if filename.endswith('.pdf'):
                    data, _ = extract_from_pdf_content(input_path)
                elif filename.endswith('.dxf'):
                    data, _ = extract_from_dxf_content(input_path)
                elif filename.endswith('.jww'):
                    # 外部アプリ不要のPythonネイティブ直接解析
                    data, _ = extract_from_jww_native(input_path)
                else:
                    return jsonify({'error': '未対応の拡張子です（.pdf, .dxf, .jww のみ対応）'}), 400

            return jsonify({
                'status': 'ok',
                'filename': file.filename,
                'doc_type': doc_type,
                'data': data
            })
        except Exception as e:
            return jsonify({'error': f'解析処理エラー: {str(e)}'}), 500

@app.route('/api/compare', methods=['POST'])
def compare_all():
    """
    複数図書（申請書、矩計図、面積表、立面図）の一括解析＆突合判定
    """
    print("\n-----------------------------------------------------")
    print("📥 [受信] 建築図書の照合リクエストを受信しました (/api/compare)")
    
    files_map = {
        'app': request.files.get('app_doc'),          # 確認申請書
        'kanabakari': request.files.get('kanabakari'),# 矩計図
        'section': request.files.get('cross_section') or request.files.get('section'), # 断面図
        'area': request.files.get('area_calc'),       # 面積表
        'elevation': request.files.get('elevation'),  # 立面図
    }

    results = {}
    with tempfile.TemporaryDirectory() as tmpdir:
        for doc_type, f in files_map.items():
            if not f:
                results[doc_type] = None
                continue

            input_path = os.path.join(tmpdir, f.filename)
            f.save(input_path)
            file_size_kb = round(os.path.getsize(input_path) / 1024, 1)
            fname_lower = f.filename.lower()
            print(f"  ▶ [{doc_type}] 解析開始: {f.filename} ({file_size_kb} KB)")

            try:
                # 1. Gemini 視覚推論API連携の試行（PDFまたは画像）
                data = None
                if fname_lower.endswith(('.pdf', '.png', '.jpg', '.jpeg')):
                    data = extract_with_gemini_vision(input_path)

                # 2. ローカル高精度解析（Gemini未設定時またはフォールバック）
                if not data:
                    if fname_lower.endswith('.pdf'):
                        data, _ = extract_from_pdf_content(input_path)
                    elif fname_lower.endswith('.dxf'):
                        data, _ = extract_from_dxf_content(input_path)
                    elif fname_lower.endswith('.jww'):
                        # 1. DRA-CAD 19 による自動DXF変換を最優先試行
                        converted_dxf = os.path.join(tmpdir, os.path.splitext(f.filename)[0] + "_dracad.dxf")
                        success = convert_jww_to_dxf_via_dracad(input_path, converted_dxf)
                        if not success:
                            # 2. JacConvert による変換試行
                            success = convert_jww_to_dxf_via_jacconvert(input_path, converted_dxf)
                        
                        if success and os.path.exists(converted_dxf):
                            print(f"    ✓ [{doc_type}] DXF変換成功 ➔ 高精度DXF解析を実行します")
                            data, _ = extract_from_dxf_content(converted_dxf)
                        else:
                            print(f"    ℹ [{doc_type}] DXF自動変換スキップ ➔ Python直接ネイティブ解析を実行します")
                            data, _ = extract_from_jww_native(input_path)
                    else:
                        data = {'error': f'未対応形式: {f.filename}'}
                results[doc_type] = data
                print(f"    ✓ [{doc_type}] 抽出完了: {data}")
            except Exception as e:
                print(f"    ✗ [Error parsing {doc_type}] {e}")
                results[doc_type] = {'error': str(e)}

    # 突合判定ロジック
    judgements = perform_comparison(results)
    print("📊 [完了] 突合判定が完了しました。ブラウザへ結果を返却します。")
    print("-----------------------------------------------------\n")

    return jsonify({
        'status': 'ok',
        'results': results,
        'judgements': judgements
    })

def perform_comparison(res):
    """各抽出データの照合判定を行う（申請書・立面図・矩計図・断面図 4者の高さ不整合を完全検知）"""
    app_data = res.get('app') or {}
    kana_data = res.get('kanabakari') or {}
    sec_data = res.get('section') or {}
    area_data = res.get('area') or {}
    elev_data = res.get('elevation') or {}

    def is_match_all_num(vals, tol=0.01):
        valid = [v for v in vals if v is not None]
        if len(valid) < 2:
            return None
        return all(abs(v - valid[0]) <= tol for v in valid)

    def is_match_num(val1, val2, tol=0.01):
        if val1 is None or val2 is None:
            return None
        return abs(val1 - val2) <= tol

    def is_match_text(txt1, txt2):
        if not txt1 or not txt2:
            return None
        t1 = re.sub(r'\s+', '', str(txt1))
        t2 = re.sub(r'\s+', '', str(txt2))
        return (t1 in t2) or (t2 in t1)

    return {
        'max_height': {
            'app': app_data.get('max_height'),
            'elevation': elev_data.get('max_height'),
            'kanabakari': kana_data.get('max_height'),
            'section': sec_data.get('max_height'),
            'is_match': is_match_all_num([
                app_data.get('max_height'),
                elev_data.get('max_height'),
                kana_data.get('max_height'),
                sec_data.get('max_height')
            ])
        },
        'eaves_height': {
            'app': app_data.get('eaves_height'),
            'elevation': elev_data.get('eaves_height'),
            'kanabakari': kana_data.get('eaves_height'),
            'section': sec_data.get('eaves_height'),
            'is_match': is_match_all_num([
                app_data.get('eaves_height'),
                elev_data.get('eaves_height'),
                kana_data.get('eaves_height'),
                sec_data.get('eaves_height')
            ])
        },
        'floor_1_height': {
            'app': app_data.get('floor_1_height'),
            'elevation': elev_data.get('floor_1_height'),
            'kanabakari': kana_data.get('floor_1_height'),
            'section': sec_data.get('floor_1_height'),
            'is_match': is_match_all_num([
                app_data.get('floor_1_height'),
                elev_data.get('floor_1_height'),
                kana_data.get('floor_1_height'),
                sec_data.get('floor_1_height')
            ])
        },
        'floor_2_height': {
            'app': app_data.get('floor_2_height'),
            'elevation': elev_data.get('floor_2_height'),
            'kanabakari': kana_data.get('floor_2_height'),
            'section': sec_data.get('floor_2_height'),
            'is_match': is_match_all_num([
                app_data.get('floor_2_height'),
                elev_data.get('floor_2_height'),
                kana_data.get('floor_2_height'),
                sec_data.get('floor_2_height')
            ])
        },
        'story_height': {
            'app': app_data.get('story_height'),
            'elevation': elev_data.get('story_height'),
            'kanabakari': kana_data.get('story_height'),
            'section': sec_data.get('story_height'),
            'is_match': is_match_all_num([
                app_data.get('story_height'),
                elev_data.get('story_height'),
                kana_data.get('story_height'),
                sec_data.get('story_height')
            ])
        },
        'building_area': {
            'app': app_data.get('building_area'),
            'area': area_data.get('building_area'),
            'is_match': is_match_num(app_data.get('building_area'), area_data.get('building_area'))
        },
        'floor_1_area': {
            'app': app_data.get('floor_1_area'),
            'area': area_data.get('floor_1_area'),
            'is_match': is_match_num(app_data.get('floor_1_area'), area_data.get('floor_1_area'))
        },
        'floor_2_area': {
            'app': app_data.get('floor_2_area'),
            'area': area_data.get('floor_2_area'),
            'is_match': is_match_num(app_data.get('floor_2_area'), area_data.get('floor_2_area'))
        },
        'total_area': {
            'app': app_data.get('total_area'),
            'area': area_data.get('total_area'),
            'is_match': is_match_num(app_data.get('total_area'), area_data.get('total_area'))
        },
        'project_name': {
            'app': app_data.get('project_name'),
            'elevation': elev_data.get('project_name'),
            'is_match': is_match_text(app_data.get('project_name'), elev_data.get('project_name'))
        },
        'client_name': {
            'app': app_data.get('client_name'),
            'elevation': elev_data.get('client_name'),
            'is_match': is_match_text(app_data.get('client_name'), elev_data.get('client_name'))
        },
        'architect_name': {
            'app': app_data.get('architect_name'),
            'elevation': elev_data.get('architect_name'),
            'is_match': is_match_text(app_data.get('architect_name'), elev_data.get('architect_name'))
        }
    }

if __name__ == '__main__':
    port = int(os.environ.get('PORT', 5005))
    print(f"=====================================================")
    print(f" 建築図書 (CAD/JWW/PDF) 自動照合サービス")
    print(f" 稼働ポート: http://localhost:{port}")
    dracad = find_dracad_path()
    if dracad:
        print(f" DRA-CAD: [検出] 自動DXF変換連携 有効 ({dracad})")
    else:
        print(f" DRA-CAD: [COM/プロセス探索]")
    jac = find_jacconvert_path()
    if jac:
        print(f" JacConvert: [検出] {jac}")
    print(f" JWW解析: [有効] DRA-CAD/JacConvert DXF変換 ➔ Pythonネイティブ解析")
    print(f"=====================================================")
    app.run(host='0.0.0.0', port=port, debug=False)
