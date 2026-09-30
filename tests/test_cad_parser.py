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

if __name__ == '__main__':
    test_height_neighbor()
    test_height_text()
    test_metadata_noise_filtering()
    print("ALL TESTS PASSED SUCCESSFULLY!")
