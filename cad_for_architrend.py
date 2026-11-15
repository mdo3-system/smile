# -*- coding: utf-8 -*-
"""
ARCHITREND ZERO 立体化用 DXF 自動前処理モジュール (cad_for_architrend.py)
JWW / DXF 図面を解析し、ARCHITREND ZERO の「DXF/DWG立体化」ウィザード
(Z100160) がそのまま認識できる標準レイヤ構成へ自動振り分けした
「【forA】{元ファイル名}.dxf」を生成する。

標準出力レイヤ:
  - 躯体 (Red)         : 壁の平行線ペア、柱矩形、構造躯体線
  - 建具 (Green)       : 開き戸(ARC+LINE)、窓・建具記号、建具ブロック
  - 間取り (Magenta)   : 部屋名テキスト、部屋区画ポリライン
  - 敷地 (Cyan)        : 配置図の敷地境界線・道路・方位記号
  - 通り芯 (Blue)      : 一点鎖線、通り芯記号
  - 文字_寸法_汎用 (7) : 注記テキスト、DIMENSION、引出線
  - ハッチ (8)         : HATCH / SOLID
  - 家具_部品 (Yellow) : 住設・家具ブロック、その他記号
  - その他 (9)         : 図枠・タイトル枠等
"""

import os
import re
import math
import tempfile
import ezdxf
from ezdxf import recover

from cad_converter import convert_via_jaccv

ARCHI_LAYERS = {
    '躯体': 1,             # Red
    '建具': 3,             # Green
    '間取り': 6,           # Magenta
    '敷地': 4,             # Cyan
    '通り芯': 5,           # Blue
    '文字_寸法_汎用': 7,   # White/Black
    'ハッチ': 8,           # Gray
    '家具_部品': 2,        # Yellow
    'その他': 9,           # Light Gray
}

ROOM_NAME_PATTERN = re.compile(
    r'^(?:LDK|L\.D\.K|DK|K|洋室|和室|寝室|子供室|主寝室|居室|玄関|ﾎｰﾙ|ホール|廊下|'
    r'ﾄｲﾚ|トイレ|WC|洗面|洗面脱衣|脱衣室|UB|浴室|風呂|収納|ｸﾛｰｾﾞｯﾄ|クローゼット|'
    r'WIC|SIC|納戸|ﾊﾟﾝﾄﾘｰ|パントリー|吹抜|吹き抜け|ﾊﾞﾙｺﾆｰ|バルコニー|ﾍﾞﾗﾝﾀﾞ|ベランダ|'
    r'ﾎﾟｰﾁ|ポーチ|階段|PS|MB|土間|ｼｭｰｽﾞｸﾛｰｸ|シューズクローク|書斎|家事室)(?:[\dA-Za-z\-・\s]*)$'
)

KUTAI_LAYER_HINTS = ('壁', '柱', '躯体', 'wall', 'col', 'kabe', 'hashira', 'kutai', 'a041', 'a000')
TATEGU_LAYER_HINTS = ('建具', '窓', '扉', '開口', 'door', 'win', 'tategu', 'sash', 'a040', 'a021')
MADORI_LAYER_HINTS = ('部屋', '間取', '室', 'room', 'madori')
SHIKICHI_LAYER_HINTS = ('敷地', '境界', '道路', '外構', '方位', 'site', 'road', 'a030', 'a031')
TORISHIN_LAYER_HINTS = ('通り芯', '通芯', '軸', 'grid', 'center', 'axis')


def _ensure_layers(doc):
    for name, color in ARCHI_LAYERS.items():
        if name not in doc.layers:
            doc.layers.add(name, color=color)
        else:
            try:
                doc.layers.get(name).color = color
            except Exception:
                pass


def _read_dxf_robust(dxf_path):
    for enc in (None, 'cp932', 'utf-8'):
        try:
            doc, _ = recover.readfile(dxf_path, encoding=enc) if enc else recover.readfile(dxf_path)
            if doc:
                return doc
        except Exception:
            continue
    return None


def _detect_drawing_role(filename, texts):
    fn = (filename or '').lower()
    if any(k in fn for k in ('配置', '敷地', 'site', 'layout')):
        return 'site'
    if any(k in fn for k in ('立面', 'elev')):
        return 'elevation'
    if any(k in fn for k in ('矩計', '断面', 'sec')):
        return 'section'
    joined = ' '.join(texts[:80])
    if '配置図' in joined or '道路境界' in joined or '隣地境界' in joined:
        if 'LDK' not in joined and '浴室' not in joined and 'トイレ' not in joined:
            return 'site'
    return 'plan'


def _estimate_scale_factor(msp):
    """
    図面が実寸(mm)か縮小図面(1/100等)かを自動判定し、実寸(mm)へ戻す倍率を返す。
    """
    xs, ys = [], []
    for e in msp:
        t = e.dxftype()
        if t == 'LINE':
            xs.extend([e.dxf.start.x, e.dxf.end.x])
            ys.extend([e.dxf.start.y, e.dxf.end.y])
            if len(xs) > 400:
                break
    if not xs:
        return 1.0
    span = max(max(xs) - min(xs), max(ys) - min(ys))
    if 10.0 < span < 800.0:
        # 用紙mmスケール (1/100等) と推定
        return 100.0
    return 1.0


def _line_angle_and_len(x1, y1, x2, y2):
    dx = x2 - x1
    dy = y2 - y1
    length = math.hypot(dx, dy)
    ang = math.degrees(math.atan2(dy, dx)) % 180.0
    return ang, length


def _is_center_linetype(e):
    lt = str(getattr(e.dxf, 'linetype', '')).lower()
    return any(k in lt for k in ('center', 'dashdot', 'dot2', '一点', '鎖線', 'axis'))


def _point_to_line_dist(px, py, x1, y1, x2, y2):
    dx = x2 - x1
    dy = y2 - y1
    l2 = dx * dx + dy * dy
    if l2 < 1e-9:
        return math.hypot(px - x1, py - y1)
    t = ((px - x1) * dx + (py - y1) * dy) / l2
    # 線分同士の重なりチェック (-0.2 .. 1.2 程度を許容)
    if t < -0.2 or t > 1.2:
        return None
    proj_x = x1 + t * dx
    proj_y = y1 + t * dy
    return math.hypot(px - proj_x, py - proj_y)


def convert_to_architrend_dxf(input_path, output_dxf_path, doc_hint=None):
    """
    JWW / DXF を ARCHITREND ZERO 立体化用標準レイヤ付き DXF に変換して保存する。
    """
    if not input_path or not os.path.exists(input_path):
        return False

    ext = os.path.splitext(input_path)[1].lower()
    with tempfile.TemporaryDirectory() as tmpdir:
        src_dxf = input_path
        if ext in ('.jww', '.jwc'):
            src_dxf = os.path.join(tmpdir, "src.dxf")
            if not convert_via_jaccv(input_path, src_dxf, mode='D', option='C'):
                return False
        elif ext != '.dxf':
            return False

        doc = _read_dxf_robust(src_dxf)
        if not doc:
            return False

        _ensure_layers(doc)
        msp = doc.modelspace()

        scale = _estimate_scale_factor(msp)
        inv_scale = 1.0 / scale  # mm換算用の逆係数

        # 全テキスト収集して図面種別判定
        all_texts = []
        for e in msp:
            t = e.dxftype()
            if t == 'TEXT':
                all_texts.append(str(e.dxf.text or '').strip())
            elif t == 'MTEXT':
                all_texts.append(str(e.text or '').strip())

        role = doc_hint or _detect_drawing_role(os.path.basename(input_path), all_texts)

        # 幾何情報キャッシュ
        lines = []   # (entity, x1, y1, x2, y2, ang, length_mm)
        arcs = []    # (entity, cx, cy, r_mm, start_ang, end_ang)
        circles = [] # (entity, cx, cy, r_mm)

        for e in msp:
            t = e.dxftype()
            if t == 'LINE':
                x1, y1 = e.dxf.start.x, e.dxf.start.y
                x2, y2 = e.dxf.end.x, e.dxf.end.y
                ang, length = _line_angle_and_len(x1, y1, x2, y2)
                lines.append((e, x1, y1, x2, y2, ang, length * scale))
            elif t == 'ARC':
                cx, cy = e.dxf.center.x, e.dxf.center.y
                r = e.dxf.radius * scale
                arcs.append((e, cx, cy, r, e.dxf.start_angle, e.dxf.end_angle))
            elif t == 'CIRCLE':
                cx, cy = e.dxf.center.x, e.dxf.center.y
                r = e.dxf.radius * scale
                circles.append((e, cx, cy, r))

        # 図面全体のスパン（図枠判定用）
        all_xs = [x for item in lines for x in (item[1], item[3])]
        all_ys = [y for item in lines for y in (item[2], item[4])]
        total_w = (max(all_xs) - min(all_xs)) if all_xs else 0.0
        total_h = (max(all_ys) - min(all_ys)) if all_ys else 0.0

        assigned = {}  # id(entity) -> target_layer

        # 0. 図枠・外枠の巨大線分を「その他」へ退避
        if total_w > 0 and total_h > 0:
            for le, x1, y1, x2, y2, ang, l_mm in lines:
                raw_len = math.hypot(x2 - x1, y2 - y1)
                if raw_len > max(total_w, total_h) * 0.65:
                    assigned[id(le)] = 'その他'

        # 1. テキスト・寸法・ハッチ・ブロックの分類
        for e in msp:
            t = e.dxftype()
            orig_lay = str(getattr(e.dxf, 'layer', '')).lower()
            eid = id(e)

            if t in ('TEXT', 'MTEXT'):
                raw_txt = (e.dxf.text if t == 'TEXT' else e.text) or ''
                clean_txt = re.sub(r'\\[A-Za-z0-9]+;|\{|\}', '', raw_txt).strip()
                if role == 'plan' and ROOM_NAME_PATTERN.match(clean_txt):
                    assigned[eid] = '間取り'
                else:
                    assigned[eid] = '文字_寸法_汎用'
            elif t == 'DIMENSION' or t == 'LEADER':
                assigned[eid] = '文字_寸法_汎用'
            elif t in ('HATCH', 'SOLID'):
                assigned[eid] = 'ハッチ'
            elif t == 'INSERT':
                bname = str(getattr(e.dxf, 'name', '')).lower()
                if any(h in orig_lay or h in bname for h in TATEGU_LAYER_HINTS):
                    assigned[eid] = '建具'
                elif any(h in orig_lay or h in bname for h in ('枠', 'frame', 'title', '図枠')):
                    assigned[eid] = 'その他'
                else:
                    assigned[eid] = '家具_部品'

        # 2. 開き戸検出 (70〜110度のARC + 半径500〜1300mm + 付近の同長LINE)
        if role == 'plan':
            for arc_e, cx, cy, r_mm, sa, ea in arcs:
                span_ang = (ea - sa) % 360.0
                if 70.0 <= span_ang <= 110.0 and 450.0 <= r_mm <= 1350.0:
                    assigned[id(arc_e)] = '建具'
                    # 扉本体ラインを近傍探索
                    for le, x1, y1, x2, y2, ang, l_mm in lines:
                        if abs(l_mm - r_mm) <= 120.0:
                            d1 = math.hypot(x1 - cx, y1 - cy) * scale
                            d2 = math.hypot(x2 - cx, y2 - cy) * scale
                            if min(d1, d2) <= 150.0:
                                assigned[id(le)] = '建具'

        # 3. 通り芯丸記号の検出
        for ce, cx, cy, r_mm in circles:
            if 120.0 <= r_mm <= 600.0 and _is_center_linetype(ce):
                assigned[id(ce)] = '通り芯'

        # 4. 平行壁線ペア検出 (法線距離 80〜240mm、長さ 300mm以上、角度差 1.5度以内)
        if role == 'plan':
            # 長さ 300mm 以上の線分を対象に高速バケット探索
            cand_lines = [item for item in lines if item[6] >= 250.0 and id(item[0]) not in assigned]
            # 角度バケット (5度単位)
            buckets = {}
            for item in cand_lines:
                if _is_center_linetype(item[0]):
                    assigned[id(item[0])] = '通り芯'
                    continue
                b = int(round(item[5] / 5.0)) % 36
                buckets.setdefault(b, []).append(item)

            for b, b_items in buckets.items():
                n = len(b_items)
                for i in range(n):
                    e1, x1, y1, x2, y2, a1, l1 = b_items[i]
                    mx1, my1 = (x1 + x2) * 0.5, (y1 + y2) * 0.5
                    for j in range(i + 1, n):
                        e2, u1, v1, u2, v2, a2, l2 = b_items[j]
                        if abs(a1 - a2) > 1.5 and abs(abs(a1 - a2) - 180.0) > 1.5:
                            continue
                        # 中点から相手線分への法線距離
                        dist_raw = _point_to_line_dist(mx1, my1, u1, v1, u2, v2)
                        if dist_raw is None:
                            continue
                        dist_mm = dist_raw * scale
                        if 80.0 <= dist_mm <= 240.0:
                            assigned[id(e1)] = '躯体'
                            assigned[id(e2)] = '躯体'

        # 5. 残りのエンティティを元レイヤ名ヒント・線種・図面種別で最終確定
        for e in msp:
            eid = id(e)
            if eid in assigned:
                e.dxf.layer = assigned[eid]
                try:
                    e.dxf.color = 256  # BYLAYER
                except Exception:
                    pass
                continue

            orig_lay = str(getattr(e.dxf, 'layer', '')).lower()
            t = e.dxftype()

            if _is_center_linetype(e) or any(h in orig_lay for h in TORISHIN_LAYER_HINTS):
                target = '敷地' if role == 'site' else '通り芯'
            elif role == 'site':
                if any(h in orig_lay for h in SHIKICHI_LAYER_HINTS) or t in ('LINE', 'LWPOLYLINE', 'POLYLINE'):
                    target = '敷地'
                else:
                    target = '文字_寸法_汎用'
            else:
                if any(h in orig_lay for h in KUTAI_LAYER_HINTS):
                    target = '躯体'
                elif any(h in orig_lay for h in TATEGU_LAYER_HINTS):
                    target = '建具'
                elif any(h in orig_lay for h in MADORI_LAYER_HINTS):
                    target = '間取り'
                elif any(h in orig_lay for h in SHIKICHI_LAYER_HINTS):
                    target = '通り芯'
                elif t in ('LWPOLYLINE', 'POLYLINE'):
                    # 閉ポリラインで柱・部屋・壁候補
                    target = '躯体'
                elif t == 'LINE':
                    # 残りの短線分は建具・造作候補、長線分はその他/家具
                    x1, y1 = e.dxf.start.x, e.dxf.start.y
                    x2, y2 = e.dxf.end.x, e.dxf.end.y
                    l_mm = math.hypot(x2 - x1, y2 - y1) * scale
                    if 90.0 <= l_mm <= 220.0:
                        # 壁端部や柱の短辺
                        target = '躯体'
                    else:
                        target = '家具_部品'
                else:
                    target = '家具_部品'

            e.dxf.layer = target
            try:
                e.dxf.color = 256  # BYLAYER でARCHITREND上のレイヤ色分けを有効化
            except Exception:
                pass

        # 6. 縮尺が1/100等の場合は1:1(mm)へスケーリングしてARCHITREND倍率1.0読込に対応
        if abs(scale - 1.0) > 1e-3:
            try:
                from ezdxf.math import Matrix44
                m = Matrix44.scale(scale, scale, 1.0)
                for e in msp:
                    try:
                        e.transform(m)
                    except Exception:
                        pass
            except Exception:
                pass

        os.makedirs(os.path.dirname(os.path.abspath(output_dxf_path)), exist_ok=True)
        doc.saveas(output_dxf_path)
        return os.path.exists(output_dxf_path) and os.path.getsize(output_dxf_path) > 200
