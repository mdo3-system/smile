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

def extract_from_jww_native(jww_path):
    """
    【外部アプリ不要】PythonによるJWWバイナリ直接テキスト・寸法抽出パーサー
    JWW (Jw_cad) ファイルのバイナリから Shift-JIS / CP932 文字列チャンクを直接復元・走査
    """
    texts = []
    try:
        with open(jww_path, 'rb') as f:
            raw_data = f.read()

        # Shift-JIS (CP932) のバイトシーケンスパターン
        # 連続した2文字以上の日本語/ASCII文字（文字コード 0x20〜0x7E, 0x81〜0x9F, 0xE0〜0xFC + 0x40〜0xFC）を抽出
        pattern = re.compile(
            b'(?:[\x20-\x7E]|(?:[\x81-\x9F\xE0-\xFC][\x40-\x7E\x80-\xFC])){2,}'
        )

        matches = pattern.findall(raw_data)
        for m in matches:
            try:
                decoded = m.decode('cp932', errors='ignore').strip()
                # 制御文字や明らかなノイズを除去
                cleaned = re.sub(r'[\x00-\x1F\x7F]', '', decoded)
                # JWW文字書式プレフィックスの除去 (^, ~, %, color/font commands)
                cleaned = re.sub(r'\^[A-Za-z0-9]', '', cleaned)
                cleaned = cleaned.strip()

                # 有意な文字列（2文字以上）のみ採用
                if len(cleaned) >= 2:
                    texts.append(cleaned)
            except Exception:
                continue

    except Exception as e:
        print(f"[JWW Native Parser Warning] {e}")

    combined_text = "\n".join(texts)
    return parse_building_text(combined_text, source="jww"), texts

def extract_from_dxf_content(dxf_path):
    """ezdxf を用いて DXF からテキスト・寸法・図枠文字を抽出"""
    if not ezdxf:
        raise ImportError("ezdxf がインストールされていません")

    try:
        doc = ezdxf.readfile(dxf_path)
    except Exception:
        doc = ezdxf.readfile(dxf_path, encoding='cp932')

    msp = doc.modelspace()
    texts = []

    # 1. TEXT / MTEXT
    for e in msp.query('TEXT MTEXT'):
        try:
            txt = e.dxf.text if e.dxftype() == 'TEXT' else e.text
            if txt:
                cleaned = re.sub(r'\\[A-Za-z0-9]+;', '', txt).strip()
                cleaned = re.sub(r'[\{\}]', '', cleaned)
                if cleaned:
                    texts.append(cleaned)
        except Exception:
            continue

    # 2. DIMENSION
    for dim in msp.query('DIMENSION'):
        try:
            dim_text = dim.dxf.get('text', '')
            if dim_text:
                texts.append(dim_text.strip())
        except Exception:
            continue

    # 3. INSERT (ブロック属性 ATTRIB)
    for insert in msp.query('INSERT'):
        try:
            for attrib in insert.attribs:
                if attrib.dxf.text:
                    texts.append(attrib.dxf.text.strip())
        except Exception:
            continue

    combined_text = "\n".join(texts)
    return parse_building_text(combined_text, source="cad"), texts

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

def parse_building_text(text, source="pdf"):
    """
    確認申請書・図面・面積表・JWW等のテキストから正規表現で各種数値を抽出
    """
    data = {
        'max_height': None,       # 最高の高さ (m)
        'eaves_height': None,     # 最高の軒の高さ (m)
        'building_area': None,    # 建築面積 (㎡)
        'floor_1_area': None,     # 1階床面積 (㎡)
        'floor_2_area': None,     # 2階床面積 (㎡)
        'total_area': None,       # 延べ面積 (㎡)
        'project_name': "",       # 工事名称
        'client_name': "",        # 建築主
        'architect_name': "",     # 設計者
        'location': "",           # 敷地の地名地番・所在地
    }

    if not text:
        return data

    # 1. 最高の高さ
    m_h_max = re.search(r'(?:最高(?:の)?高(?:さ)?|最高高|最高頂部)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
    if m_h_max:
        data['max_height'] = normalize_height(m_h_max.group(1))

    # 2. 最高の軒の高さ
    m_h_eaves = re.search(r'(?:最高(?:の)?軒(?:の)?高(?:さ)?|軒高|軒の高さ)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:m|mm|M|MM))?', text)
    if m_h_eaves:
        data['eaves_height'] = normalize_height(m_h_eaves.group(1))

    # 3. 建築面積
    m_b_area = re.search(r'建築面積[^\d\n\r]*([0-9\.,]+)(?:\s*(?:㎡|m2|M2))?', text)
    if m_b_area:
        val = normalize_number(m_b_area.group(1))
        if val is not None:
            data['building_area'] = round(val, 2)

    # 4. 延べ面積
    m_t_area = re.search(r'(?:延べ面積|延床面積|延面積)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:㎡|m2|M2))?', text)
    if m_t_area:
        val = normalize_number(m_t_area.group(1))
        if val is not None:
            data['total_area'] = round(val, 2)

    # 5. 1階床面積
    m_f1_area = re.search(r'(?:1階(?:床)?面積|１階(?:床)?面積|1F(?:床)?面積)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:㎡|m2|M2))?', text)
    if m_f1_area:
        val = normalize_number(m_f1_area.group(1))
        if val is not None:
            data['floor_1_area'] = round(val, 2)

    # 6. 2階床面積
    m_f2_area = re.search(r'(?:2階(?:床)?面積|２階(?:床)?面積|2F(?:床)?面積)[^\d\n\r]*([0-9\.,]+)(?:\s*(?:㎡|m2|M2))?', text)
    if m_f2_area:
        val = normalize_number(m_f2_area.group(1))
        if val is not None:
            data['floor_2_area'] = round(val, 2)

    # 7. 建築物等の名称又は工事名 / 工事名称
    m_proj = re.search(r'(?:建築物等の名称(?:又は工事名)?|工事名称|工事名)[：:\s]+([^\n\r]{2,50})', text)
    if m_proj:
        data['project_name'] = m_proj.group(1).strip()
    else:
        m_proj2 = re.search(r'建築物等の名称又は工事名[^\n]*\n\s*([^\n\r]{2,50})', text)
        if m_proj2:
            data['project_name'] = m_proj2.group(1).strip()

    # 8. 建築主 / 建築主の氏名
    m_client = re.search(r'(?:建築主(?:の氏名)?|お施主様名?|施主名?)[：:\s]+([^\n\r]{2,30})', text)
    if m_client:
        data['client_name'] = m_client.group(1).strip()
    else:
        m_client2 = re.search(r'建築主\s*氏名[^\n]*\n\s*([^\n\r]{2,30})', text)
        if m_client2:
            data['client_name'] = m_client2.group(1).strip()

    # 9. 設計者 / 設計事務所
    m_arch = re.search(r'(?:設計者(?:氏名)?|設計事務所|設計監理)[：:\s]+([^\n\r]{2,50})', text)
    if m_arch:
        data['architect_name'] = m_arch.group(1).strip()

    # 10. 敷地の地名地番 / 所在地
    m_loc = re.search(r'(?:地名地番|敷地の位置|敷地の所在地|建設地|工事場所)[：:\s]+([^\n\r]{2,60})', text)
    if m_loc:
        data['location'] = m_loc.group(1).strip()

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
        'pypdf_available': pypdf is not None or pdfplumber is not None
    })

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
    files_map = {
        'app': request.files.get('app_doc'),          # 確認申請書
        'kanabakari': request.files.get('kanabakari'),# 矩計図
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
            fname_lower = f.filename.lower()

            try:
                if fname_lower.endswith('.pdf'):
                    data, _ = extract_from_pdf_content(input_path)
                elif fname_lower.endswith('.dxf'):
                    data, _ = extract_from_dxf_content(input_path)
                elif fname_lower.endswith('.jww'):
                    # JWW ネイティブ直接解析（超高速・外部依存なし）
                    data, _ = extract_from_jww_native(input_path)
                else:
                    data = {'error': f'未対応形式: {f.filename}'}
                results[doc_type] = data
            except Exception as e:
                print(f"[Error parsing {doc_type}] {e}")
                results[doc_type] = {'error': str(e)}

    # 突合判定ロジック
    judgements = perform_comparison(results)

    return jsonify({
        'status': 'ok',
        'results': results,
        'judgements': judgements
    })

def perform_comparison(res):
    """各抽出データの照合判定を行う"""
    app_data = res.get('app') or {}
    kana_data = res.get('kanabakari') or {}
    area_data = res.get('area') or {}
    elev_data = res.get('elevation') or {}

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
            'kanabakari': kana_data.get('max_height'),
            'elevation': elev_data.get('max_height'),
            'is_match': is_match_num(app_data.get('max_height'), kana_data.get('max_height')) and \
                        is_match_num(app_data.get('max_height'), elev_data.get('max_height'))
        },
        'eaves_height': {
            'app': app_data.get('eaves_height'),
            'kanabakari': kana_data.get('eaves_height'),
            'elevation': elev_data.get('eaves_height'),
            'is_match': is_match_num(app_data.get('eaves_height'), kana_data.get('eaves_height')) and \
                        is_match_num(app_data.get('eaves_height'), elev_data.get('eaves_height'))
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
    print(f" JWW解析: [有効] Python直接ネイティブ解析 (外部ツール不要)")
    dracad = find_dracad_path()
    if dracad:
        print(f" DRA-CAD: [検出] {dracad}")
    jac = find_jacconvert_path()
    if jac:
        print(f" JacConvert: [検出] {jac}")
    print(f"=====================================================")
    app.run(host='0.0.0.0', port=port, debug=False)
