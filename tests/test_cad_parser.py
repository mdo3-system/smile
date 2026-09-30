import os, sys
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from local_cad_parser import extract_height_data, extract_metadata_data, extract_area_data

def test_height_neighbor():
    t_list1 = ['設計GL', '±0', '1FL', '2FL', '最高部', '8436', '最高軒高', '6076']
    res = extract_height_data('', texts_list=t_list1)
    assert res['max_height'] == 8.436, f"Expected 8.436, got {res['max_height']}"
    assert res['eaves_height'] == 6.076, f"Expected 6.076, got {res['eaves_height']}"
    print("test_height_neighbor PASSED")

def test_height_text():
    res = extract_height_data('最高の高さ: 8436mm\n最高の軒の高さ: 6076mm')
    assert res['max_height'] == 8.436
    assert res['eaves_height'] == 6.076
    print("test_height_text PASSED")

def test_metadata_noise_filtering():
    t_list = ['I@', 'M@', 'ffff', '一級建築士事務所', '株式会社　フジ設計企画', '舟腰　拓司様邸　新築工事', '千葉県船橋市西習志野４-12-12']
    meta = extract_metadata_data('', texts_list=t_list)
    assert meta['project_name'] == '舟腰　拓司様邸　新築工事'
    assert meta['client_name'] == '舟腰拓司様'
    assert meta['architect_name'] == '株式会社　フジ設計企画'
    assert meta['location'] == '千葉県船橋市西習志野４-12-12'
    print("test_metadata_noise_filtering PASSED")

def test_area_neighbor():
    t_list = ['求積表', '建築面積', '80.00', '延床面積', '400.00', '1階床面積', '200.00', '2階床面積', '200.00']
    res = extract_area_data('', texts_list=t_list)
    assert res['building_area'] == 80.0
    assert res['total_area'] == 400.0
    assert res['floor_1_area'] == 200.0
    assert res['floor_2_area'] == 200.0
    print("test_area_neighbor PASSED")

def test_dxf_raw_fallback(tmp_path):
    from local_cad_parser import extract_from_dxf_content
    # 非標準ディクショナリを持つ模擬破損DXF
    fake_dxf = """  0
SECTION
  2
ENTITIES
  0
TEXT
  8
0
 10
0.0
 20
0.0
  1
最高高さ
  0
TEXT
  8
0
 10
0.0
 20
10.0
  1
8436
  0
TEXT
  8
0
  1
最高軒高
  0
TEXT
  8
0
  1
6076
  0
ENDSEC
  0
EOF
"""
    p = os.path.join(tmp_path, "broken.dxf")
    with open(p, "w", encoding="cp932") as f:
        f.write(fake_dxf)

    data, texts = extract_from_dxf_content(p)
    assert data['max_height'] == 8.436
    assert data['eaves_height'] == 6.076
    print("test_dxf_raw_fallback PASSED")

if __name__ == '__main__':
    test_height_neighbor()
    test_height_text()
    test_metadata_noise_filtering()
    test_area_neighbor()
    import tempfile
    with tempfile.TemporaryDirectory() as td:
        test_dxf_raw_fallback(td)
    print("ALL TESTS PASSED SUCCESSFULLY!")
