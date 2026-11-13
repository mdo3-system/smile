# -*- coding: utf-8 -*-
"""
High-Precision CAD (JWW / DXF / ZIP) -> PDF & PNG Converter Module for Z840
1. JacConvert (C:\\JacConvert\\JacCv.exe) CLI による高精度ベクターPDF・DXF変換
2. ezdxf + matplotlib による DXF -> PDF/PNG フォールバック変換
3. ZIPアーカイブ内の複数 JWW / DXF / PDF 自動展開＆マルチページPDF結合
4. pypdfium2 による高速・高解像度 PNG プレビュー画像生成
"""

import os
import re
import sys
import time
import uuid
import shutil
import zipfile
import tempfile
import threading
import subprocess

# JacCv.exe はカレントディレクトリに一時ファイル ($IND_B$.TMP 等) を作るため排他ロックで保護
_JAC_LOCK = threading.Lock()

JAC_CANDIDATES = [
    r"C:\JacConvert\JacCv.exe",
    r"C:\JacConvert\JacConvert.exe",
    r"C:\Program Files\JacConvert\JacCv.exe",
    r"C:\Program Files (x86)\JacConvert\JacCv.exe",
    r"C:\Jac\JacCv.exe",
]


def find_jaccv_exe():
    """JacCv.exe / JacConvert.exe のパスを取得"""
    for p in JAC_CANDIDATES:
        if os.path.exists(p):
            return p
    return shutil.which("JacCv.exe") or shutil.which("JacConvert.exe")


def convert_via_jaccv(input_path, output_path, mode='P', option='C', timeout=45):
    """
    JacCv.exe CLI を用いて JWW/DXF/JWC を PDF (mode='P') または DXF (mode='D') に変換する。
    ※日本語パス・空白文字によるコマンドライン解釈バグを防ぐため、ASCII一時ファイル名経由で実行する。
    """
    jac_exe = find_jaccv_exe()
    if not jac_exe or not os.path.exists(jac_exe):
        return False

    jac_dir = os.path.dirname(os.path.abspath(jac_exe))
    ext = os.path.splitext(input_path)[1].lower()
    out_ext = '.pdf' if mode.upper() == 'P' else ('.dxf' if mode.upper() == 'D' else '.jww')

    token = uuid.uuid4().hex[:8]
    tmp_in = os.path.join(jac_dir, f"_in_{token}{ext}")
    tmp_out = os.path.join(jac_dir, f"_out_{token}{out_ext}")

    with _JAC_LOCK:
        try:
            shutil.copy2(input_path, tmp_in)
            # 第4引数(option='C')を常に渡すことで JacCv.exe の末尾1文字欠落バグを回避
            cmd = [jac_exe, tmp_in, mode.upper(), tmp_out, option]
            subprocess.run(
                cmd,
                cwd=jac_dir,
                capture_output=True,
                timeout=timeout
            )
            # 万一末尾1文字欠落ファイル (_out_xxx.pd / .dx) が生成された場合の救済
            if not os.path.exists(tmp_out) and os.path.exists(tmp_out[:-1]):
                shutil.move(tmp_out[:-1], tmp_out)

            if os.path.exists(tmp_out) and os.path.getsize(tmp_out) > 200:
                os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
                shutil.move(tmp_out, output_path)
                return True
        except subprocess.TimeoutExpired:
            try:
                subprocess.run(['taskkill', '/F', '/IM', 'JacCv.exe'], capture_output=True)
            except Exception:
                pass
        except Exception as e:
            print(f"[JacCv Error] {e}")
        finally:
            for p in (tmp_in, tmp_out, tmp_out[:-1]):
                if os.path.exists(p):
                    try:
                        os.remove(p)
                    except Exception:
                        pass
    return False


def convert_dxf_via_ezdxf_matplotlib(dxf_path, output_pdf_path, output_png_path=None):
    """
    ezdxf + matplotlib による DXF -> PDF / PNG レンダリング (フォールバック用)
    """
    try:
        import ezdxf
        from ezdxf import recover
        from ezdxf.addons.drawing import RenderContext, Frontend
        from ezdxf.addons.drawing.matplotlib import MatplotlibBackend
        import matplotlib
        matplotlib.use('Agg')
        import matplotlib.pyplot as plt

        # 日本語フォント設定
        plt.rcParams['font.sans-serif'] = ['MS Gothic', 'Yu Gothic', 'Meiryo', 'DejaVu Sans']
        plt.rcParams['axes.unicode_minus'] = False

        doc = None
        for enc in (None, 'cp932', 'utf-8'):
            try:
                if enc:
                    doc, _ = recover.readfile(dxf_path, encoding=enc)
                else:
                    doc, _ = recover.readfile(dxf_path)
                if doc:
                    break
            except Exception:
                continue

        if not doc:
            return False

        msp = doc.modelspace()
        fig = plt.figure(figsize=(16.53, 11.69), dpi=200)  # A3 Landscape
        ax = fig.add_axes([0.02, 0.02, 0.96, 0.96])
        ctx = RenderContext(doc)
        out = MatplotlibBackend(ax)
        Frontend(ctx, out).draw_layout(msp, finalize=True)
        ax.set_axis_off()

        if output_pdf_path:
            os.makedirs(os.path.dirname(os.path.abspath(output_pdf_path)), exist_ok=True)
            fig.savefig(output_pdf_path, format='pdf', dpi=200, bbox_inches='tight', facecolor='white')

        if output_png_path:
            os.makedirs(os.path.dirname(os.path.abspath(output_png_path)), exist_ok=True)
            fig.savefig(output_png_path, format='png', dpi=180, bbox_inches='tight', facecolor='white')

        plt.close(fig)
        return os.path.exists(output_pdf_path) and os.path.getsize(output_pdf_path) > 500
    except Exception as e:
        print(f"[ezdxf Matplotlib Fallback Warning] {e}")
        return False


def render_pdf_to_png(pdf_path, png_path, scale=2.0):
    """
    pypdfium2 を用いて PDF の1ページ目を高解像度 PNG に変換する
    """
    if not pdf_path or not os.path.exists(pdf_path):
        return False
    try:
        import pypdfium2 as pdfium
        doc = pdfium.PdfDocument(pdf_path)
        if len(doc) == 0:
            return False
        page = doc[0]
        bitmap = page.render(scale=scale)
        pil_img = bitmap.to_pil()
        os.makedirs(os.path.dirname(os.path.abspath(png_path)), exist_ok=True)
        pil_img.save(png_path, format='PNG')
        doc.close()
        return os.path.exists(png_path) and os.path.getsize(png_path) > 200
    except Exception as e:
        print(f"[render_pdf_to_png Warning] {e}")
        return False


def decode_zip_filename(info):
    """ZIP内の日本語ファイル名文字化け防止デコード"""
    if info.flag_bits & 0x800:
        return info.filename
    try:
        return info.filename.encode('cp437').decode('cp932')
    except Exception:
        return info.filename


def convert_zip_to_pdf_and_png(zip_path, output_pdf_path, output_png_path=None):
    """
    ZIP内の .jww / .jwc / .dxf / .pdf を抽出し、すべてPDFに変換して1つのマルチページPDFに結合する
    """
    try:
        import pypdf
    except ImportError:
        return False

    converted_pdfs = []
    with tempfile.TemporaryDirectory() as tmpdir:
        try:
            with zipfile.ZipFile(zip_path, 'r') as zf:
                infos = zf.infolist()
                extracted_items = []
                for idx, info in enumerate(infos):
                    if info.is_dir():
                        continue
                    real_name = decode_zip_filename(info)
                    base_name = os.path.basename(real_name)
                    if not base_name or base_name.startswith('.'):
                        continue
                    ext = os.path.splitext(base_name)[1].lower()
                    if ext in ('.jww', '.jwc', '.dxf', '.pdf'):
                        safe_extract_path = os.path.join(tmpdir, f"item_{idx:03d}{ext}")
                        with zf.open(info) as src, open(safe_extract_path, 'wb') as dst:
                            shutil.copyfileobj(src, dst)
                        extracted_items.append((base_name, ext, safe_extract_path))

                # 名前順にソート
                extracted_items.sort(key=lambda x: x[0])

                for idx, (orig_name, ext, fpath) in enumerate(extracted_items):
                    if ext == '.pdf':
                        converted_pdfs.append(fpath)
                    else:
                        sub_pdf = os.path.join(tmpdir, f"conv_{idx:03d}.pdf")
                        ok = convert_cad_to_pdf_and_png(fpath, sub_pdf, output_png_path=None)
                        if ok and os.path.exists(sub_pdf):
                            converted_pdfs.append(sub_pdf)

                if not converted_pdfs:
                    return False

                # マルチページPDFとして結合
                writer = pypdf.PdfWriter()
                for c_pdf in converted_pdfs:
                    try:
                        reader = pypdf.PdfReader(c_pdf)
                        for p in reader.pages:
                            writer.add_page(p)
                    except Exception:
                        continue

                if len(writer.pages) == 0:
                    return False

                os.makedirs(os.path.dirname(os.path.abspath(output_pdf_path)), exist_ok=True)
                with open(output_pdf_path, 'wb') as out_f:
                    writer.write(out_f)

                if output_png_path:
                    render_pdf_to_png(output_pdf_path, output_png_path)

                return os.path.exists(output_pdf_path) and os.path.getsize(output_pdf_path) > 500
        except Exception as e:
            print(f"[convert_zip_to_pdf_and_png Error] {e}")
            return False


def convert_cad_to_pdf_and_png(input_path, output_pdf_path, output_png_path=None):
    """
    任意のCAD図面ファイル (.jww, .jwc, .dxf, .zip) を高精度 PDF & PNG に自動変換する統合関数
    """
    if not input_path or not os.path.exists(input_path):
        return False

    ext = os.path.splitext(input_path)[1].lower()

    # 1. ZIPファイルの場合
    if ext == '.zip':
        return convert_zip_to_pdf_and_png(input_path, output_pdf_path, output_png_path)

    # 2. 既にPDFの場合
    if ext == '.pdf':
        if os.path.abspath(input_path) != os.path.abspath(output_pdf_path):
            os.makedirs(os.path.dirname(os.path.abspath(output_pdf_path)), exist_ok=True)
            shutil.copy2(input_path, output_pdf_path)
        if output_png_path:
            render_pdf_to_png(output_pdf_path, output_png_path)
        return True

    pdf_ok = False

    # 3. JWW / JWC / DXF -> JacCv.exe による直接PDF変換 (最優先・最高精度)
    if ext in ('.jww', '.jwc', '.dxf'):
        pdf_ok = convert_via_jaccv(input_path, output_pdf_path, mode='P', option='C')

    # 4. DXF で JacCv が失敗した場合、または JWW -> DXF -> ezdxf フォールバック
    if not pdf_ok:
        if ext == '.dxf':
            pdf_ok = convert_dxf_via_ezdxf_matplotlib(input_path, output_pdf_path, output_png_path)
        elif ext in ('.jww', '.jwc'):
            with tempfile.TemporaryDirectory() as tmpdir:
                tmp_dxf = os.path.join(tmpdir, "fallback.dxf")
                if convert_via_jaccv(input_path, tmp_dxf, mode='D', option='C'):
                    pdf_ok = convert_dxf_via_ezdxf_matplotlib(tmp_dxf, output_pdf_path, output_png_path)

    # 5. PDF生成成功時、PNGが未生成なら pypdfium2 で高解像度レンダリング
    if pdf_ok and os.path.exists(output_pdf_path):
        if output_png_path and (not os.path.exists(output_png_path) or os.path.getsize(output_png_path) == 0):
            render_pdf_to_png(output_pdf_path, output_png_path)
        return True

    return False


if __name__ == '__main__':
    if len(sys.argv) >= 3:
        in_f = sys.argv[1]
        out_pdf = sys.argv[2]
        out_png = sys.argv[3] if len(sys.argv) >= 4 else os.path.splitext(out_pdf)[0] + '.png'
        ok = convert_cad_to_pdf_and_png(in_f, out_pdf, out_png)
        print(f"Conversion result: {ok} -> PDF: {out_pdf}, PNG: {out_png}")
        sys.exit(0 if ok else 1)
