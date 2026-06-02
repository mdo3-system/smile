from html.parser import HTMLParser
import urllib.request

class TextParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.text = []
        self.record = True
    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'style', 'header', 'footer', 'nav'): self.record = False
    def handle_endtag(self, tag):
        if tag in ('script', 'style', 'header', 'footer', 'nav'): self.record = True
    def handle_data(self, data):
        if self.record and data.strip(): self.text.append(data.strip())

for title, url in [('Wall', 'https://thanks.work/%e5%a3%81%e9%87%8f%e8%a8%88%e7%ae%97%e3%80%80%e3%81%8a%e8%a6%8b%e7%a9%8d%e3%82%8a'), ('Skin', 'https://thanks.work/%e5%a4%96%e7%9a%ae%e8%a8%88%e7%ae%97%e3%80%80%e3%81%8a%e8%a6%8b%e7%a9%8d%e3%82%8a'), ('Sky', 'https://thanks.work/%e5%a4%a9%e7%a9%ba%e7%8e%87%e3%80%80%e3%81%8a%e8%a6%8b%e7%a9%8d%e3%82%8a')]:
    p = TextParser()
    try:
        p.feed(urllib.request.urlopen(url).read().decode('utf-8'))
        with open(f'e:/Dropbox/■設計ｻﾎﾟｰﾄ/■note/antigravity/system/text_{title}.txt', 'w', encoding='utf-8') as f:
            f.write('\n'.join(p.text))
    except Exception as e:
        print(f"Error {title}: {e}")
