# -*- coding: utf-8 -*-
"""
Z840 Background CAD Auto-Converter & Watcher (cad_auto_watcher.py)
1. Google Drive デスクトップ同期フォルダ (G:\\マイドライブ\\設計サポート) の watchdog リアルタイム監視
   -> .jww / .dxf 追加時に自動で同じフォルダへ 【プレビュー】{ファイル名}.pdf / .png を生成
2. ポータル (https://system.thanks.work/api_sync_cad_preview.php) の未変換CADキュー定期ポーリング
   -> 自動でダウンロード・PDF/PNG変換・Google Driveアップロード・DB紐付け・メール通知を実行
"""

import os
import sys
import time
import json
import tempfile
import threading
import urllib.request
import urllib.parse

from cad_converter import convert_cad_to_pdf_and_png

PORTAL_SYNC_URL = os.environ.get(
    'CAD_PORTAL_SYNC_URL',
    'https://system.thanks.work/api_sync_cad_preview.php'
)
SYNC_TOKEN = os.environ.get('CAD_SYNC_TOKEN', 'thankscad_z840_sync_2026')
GDRIVE_WATCH_ROOT = os.environ.get('CAD_GDRIVE_ROOT', r'G:\マイドライブ\設計サポート')

_SYNC_LOCK = threading.Lock()
_WATCHER_STARTED = False


def _multipart_post(url, fields, files, timeout=60):
    """標準ライブラリ urllib のみで multipart/form-data POST を行うヘルパー"""
    boundary = '----AntigravityCadSyncBoundary' + str(int(time.time() * 1000))
    body = bytearray()

    for k, v in fields.items():
        body.extend(f'--{boundary}\r\n'.encode('utf-8'))
        body.extend(f'Content-Disposition: form-data; name="{k}"\r\n\r\n'.encode('utf-8'))
        body.extend(f'{v}\r\n'.encode('utf-8'))

    for field_name, (filename, file_path, mime_type) in files.items():
        if not file_path or not os.path.exists(file_path):
            continue
        with open(file_path, 'rb') as f:
            file_bytes = f.read()
        body.extend(f'--{boundary}\r\n'.encode('utf-8'))
        body.extend(
            f'Content-Disposition: form-data; name="{field_name}"; filename="{filename}"\r\n'.encode('utf-8')
        )
        body.extend(f'Content-Type: {mime_type}\r\n\r\n'.encode('utf-8'))
        body.extend(file_bytes)
        body.extend(b'\r\n')

    body.extend(f'--{boundary}--\r\n'.encode('utf-8'))

    req = urllib.request.Request(url, data=bytes(body), method='POST')
    req.add_header('Content-Type', f'multipart/form-data; boundary={boundary}')
    req.add_header('X-Sync-Token', SYNC_TOKEN)
    req.add_header('User-Agent', 'Z840-CAD-Watcher/1.0')

    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode('utf-8', errors='replace'))


def sync_pending_portal_files(project_id=None, limit=10):
    """
    ポータルDB上の未変換CADファイルを取得し、Z840でPDF/PNGに変換してアップロード・紐付けする
    """
    if not _SYNC_LOCK.acquire(blocking=False):
        return {'status': 'busy', 'converted': 0}

    converted_count = 0
    touched_projects = set()
    try:
        params = {
            'action': 'list_pending',
            'sync_token': SYNC_TOKEN,
            'limit': str(limit),
        }
        if project_id:
            params['project_id'] = str(project_id)

        list_url = f"{PORTAL_SYNC_URL}?{urllib.parse.urlencode(params)}"
        req = urllib.request.Request(list_url, headers={'User-Agent': 'Z840-CAD-Watcher/1.0'})
        with urllib.request.urlopen(req, timeout=20) as resp:
            data = json.loads(resp.read().decode('utf-8', errors='replace'))

        items = data.get('items') or []
        if not items:
            return {'status': 'ok', 'converted': 0}

        print(f"🔄 [CAD Auto-Sync] 未変換CAD図面 {len(items)} 件の変換を開始します...")

        for item in items:
            fid = item.get('file_id')
            pid = item.get('project_id')
            fname = item.get('file_name') or f"file_{fid}.jww"
            ext = os.path.splitext(fname)[1].lower()
            base_name = os.path.splitext(fname)[0]

            with tempfile.TemporaryDirectory() as tmpdir:
                raw_path = os.path.join(tmpdir, f"raw_{fid}{ext}")
                dl_url = f"{PORTAL_SYNC_URL}?action=download_raw&file_id={fid}&sync_token={urllib.parse.quote(SYNC_TOKEN)}"
                try:
                    dl_req = urllib.request.Request(dl_url, headers={'User-Agent': 'Z840-CAD-Watcher/1.0'})
                    with urllib.request.urlopen(dl_req, timeout=45) as dl_resp, open(raw_path, 'wb') as out_f:
                        out_f.write(dl_resp.read())
                except Exception as de:
                    print(f"  ⚠️ [Download Error] file_id={fid} ({fname}): {de}")
                    continue

                if not os.path.exists(raw_path) or os.path.getsize(raw_path) == 0:
                    continue

                out_pdf = os.path.join(tmpdir, f"preview_{fid}.pdf")
                out_png = os.path.join(tmpdir, f"preview_{fid}.png")

                ok = convert_cad_to_pdf_and_png(raw_path, out_pdf, out_png)
                if ok and os.path.exists(out_pdf):
                    try:
                        up_res = _multipart_post(
                            PORTAL_SYNC_URL,
                            fields={
                                'action': 'upload_preview',
                                'sync_token': SYNC_TOKEN,
                                'file_id': str(fid),
                                'status': 'ready',
                            },
                            files={
                                'preview_pdf': (f"【プレビュー】{base_name}.pdf", out_pdf, 'application/pdf'),
                                'preview_png': (f"【プレビュー】{base_name}.png", out_png, 'image/png'),
                            },
                            timeout=90
                        )
                        if up_res.get('status') == 'ok':
                            converted_count += 1
                            touched_projects.add(pid)
                            print(f"  ✅ [変換＆アップロード完了] 案件ID:{pid} {fname} -> PDF ID: {up_res.get('preview_drive_file_id')}")
                    except Exception as ue:
                        print(f"  ⚠️ [Upload Error] file_id={fid}: {ue}")
                else:
                    print(f"  ✗ [変換失敗] file_id={fid} ({fname})")
                    try:
                        _multipart_post(
                            PORTAL_SYNC_URL,
                            fields={
                                'action': 'upload_preview',
                                'sync_token': SYNC_TOKEN,
                                'file_id': str(fid),
                                'status': 'error',
                            },
                            files={},
                            timeout=20
                        )
                    except Exception:
                        pass

        # 変換完了した案件について、照合用図書が2点以上揃っていれば自動で整合性照合も事前実行・DB保存
        for pid in touched_projects:
            try:
                auto_run_project_comparison(pid)
            except Exception as ce:
                print(f"  ⚠️ [Auto-Compare Warning] project_id={pid}: {ce}")

        return {
            'status': 'ok',
            'converted': converted_count,
            'projects': list(touched_projects)
        }
    except Exception as e:
        print(f"⚠️ [CAD Auto-Sync Warning] {e}")
        return {'status': 'error', 'message': str(e), 'converted': converted_count}
    finally:
        _SYNC_LOCK.release()


def auto_run_project_comparison(project_id):
    """
    指定された案件の最新スロット図書 (app, kanabakari, section, area, elevation) を取得し、
    ローカル解析サービス (http://127.0.0.1:5005/api/compare) で自動照合を実行して結果をポータルDBへ保存する
    """
    docs_url = f"{PORTAL_SYNC_URL}?action=get_project_comparison_docs&project_id={project_id}&sync_token={urllib.parse.quote(SYNC_TOKEN)}"
    req = urllib.request.Request(docs_url, headers={'User-Agent': 'Z840-CAD-Watcher/1.0'})
    with urllib.request.urlopen(req, timeout=20) as resp:
        info = json.loads(resp.read().decode('utf-8', errors='replace'))

    docs = info.get('docs') or {}
    if len(docs) < 2:
        return False

    field_map = {
        'app': 'app_doc',
        'kanabakari': 'kanabakari',
        'section': 'cross_section',
        'area': 'area_calc',
        'elevation': 'elevation',
    }

    with tempfile.TemporaryDirectory() as tmpdir:
        files_to_send = {}
        for slot_key, form_field in field_map.items():
            doc_row = docs.get(slot_key)
            if not doc_row:
                continue
            fid = doc_row.get('file_id')
            orig_name = doc_row.get('file_name') or f"{slot_key}.pdf"
            has_preview = bool(doc_row.get('preview_drive_file_id'))
            ext = '.pdf' if has_preview else os.path.splitext(orig_name)[1].lower()
            send_name = (os.path.splitext(orig_name)[0] + '.pdf') if has_preview else orig_name
            local_p = os.path.join(tmpdir, f"slot_{slot_key}_{fid}{ext}")

            dl_url = f"{PORTAL_SYNC_URL}?action=download_raw&file_id={fid}&prefer_preview=1&sync_token={urllib.parse.quote(SYNC_TOKEN)}"
            try:
                dl_req = urllib.request.Request(dl_url, headers={'User-Agent': 'Z840-CAD-Watcher/1.0'})
                with urllib.request.urlopen(dl_req, timeout=45) as dl_resp, open(local_p, 'wb') as out_f:
                    out_f.write(dl_resp.read())
                if os.path.exists(local_p) and os.path.getsize(local_p) > 0:
                    mime = 'application/pdf' if ext == '.pdf' else 'application/octet-stream'
                    files_to_send[form_field] = (send_name, local_p, mime)
            except Exception:
                continue

        if len(files_to_send) < 2:
            return False

        comp_res = _multipart_post(
            'http://127.0.0.1:5005/api/compare',
            fields={},
            files=files_to_send,
            timeout=120
        )
        if comp_res and comp_res.get('status') == 'ok':
            _multipart_post(
                PORTAL_SYNC_URL,
                fields={
                    'action': 'save_comparison_result',
                    'sync_token': SYNC_TOKEN,
                    'project_id': str(project_id),
                    'comparison_json': json.dumps(comp_res, ensure_ascii=False),
                },
                files={},
                timeout=30
            )
            print(f"  📐 [自動整合性照合＆DB保存完了] 案件ID:{project_id} (対象図書:{len(files_to_send)}件)")
            return True
    return False


def convert_local_gdrive_file(file_path):
    """
    ローカル Google Drive フォルダ (G:\\マイドライブ\\設計サポート) 内に追加された
    .jww / .dxf ファイルを検知し、同じフォルダに 【プレビュー】{元ファイル名}.pdf を生成する
    """
    if not file_path or not os.path.exists(file_path):
        return False
    fname = os.path.basename(file_path)
    if fname.startswith(('【プレビュー】', '_', '.', '~$')):
        return False
    ext = os.path.splitext(fname)[1].lower()
    if ext not in ('.jww', '.jwc', '.dxf'):
        return False

    parent_dir = os.path.dirname(file_path)
    base_name = os.path.splitext(fname)[0]
    preview_pdf = os.path.join(parent_dir, f"【プレビュー】{base_name}.pdf")

    # 既に最新のプレビューが存在する場合はスキップ
    if os.path.exists(preview_pdf):
        try:
            if os.path.getmtime(preview_pdf) >= os.path.getmtime(file_path) and os.path.getsize(preview_pdf) > 500:
                return False
        except Exception:
            pass

    print(f"📂 [Google Drive Watch] 新規/更新CAD検知: {file_path}")
    ok = convert_cad_to_pdf_and_png(file_path, preview_pdf, output_png_path=None)
    if ok:
        print(f"  ✅ [ローカルプレビュー生成完了] {preview_pdf}")
    return ok


def _watcher_loop():
    """バックグラウンド常駐スレッド（watchdog監視 + 定期ポータル同期）"""
    observer = None
    if os.path.exists(GDRIVE_WATCH_ROOT):
        try:
            from watchdog.observers import Observer
            from watchdog.events import FileSystemEventHandler

            class CadDriveHandler(FileSystemEventHandler):
                def on_created(self, event):
                    if not event.is_directory:
                        time.sleep(1.5)
                        convert_local_gdrive_file(event.src_path)
                        sync_pending_portal_files(limit=5)

                def on_modified(self, event):
                    if not event.is_directory:
                        time.sleep(1.5)
                        convert_local_gdrive_file(event.src_path)

            observer = Observer()
            observer.schedule(CadDriveHandler(), GDRIVE_WATCH_ROOT, recursive=True)
            observer.start()
            print(f"👁️ [Watchdog] Google Drive フォルダ監視を開始しました: {GDRIVE_WATCH_ROOT}")
        except Exception as we:
            print(f"⚠️ [Watchdog Warning] {we}")

    # 起動直後にまず初回ポーリングを実行
    time.sleep(3)
    while True:
        try:
            sync_pending_portal_files(limit=10)
        except Exception as e:
            print(f"[Watcher Poll Error] {e}")
        time.sleep(30)


def start_background_watcher():
    """local_cad_parser.py から呼び出されるバックグラウンド監視スレッド起動関数"""
    global _WATCHER_STARTED
    if _WATCHER_STARTED:
        return
    _WATCHER_STARTED = True
    t = threading.Thread(target=_watcher_loop, name="Z840CadAutoWatcher", daemon=True)
    t.start()


if __name__ == '__main__':
    print("Running one-shot portal CAD preview sync...")
    res = sync_pending_portal_files(limit=25)
    print("Result:", res)
