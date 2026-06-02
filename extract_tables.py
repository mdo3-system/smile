from html.parser import HTMLParser
import urllib.request

class MyHTMLParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_table = False
        self.text = []
    def handle_starttag(self, tag, attrs):
        if tag in ('table', 'tr', 'td', 'th'): self.in_table = True
    def handle_endtag(self, tag):
        if tag == 'table': self.in_table = False
    def handle_data(self, data):
        if self.in_table and data.strip(): self.text.append(data.strip())

for title, url in [('Wall', 'https://thanks.work/%e5%a3%81%e9%87%8f%e8%a8%88%e7%ae%97%e3%80%80%e3%81%8a%e8%a6%8b%e7%a9%8d%e3%82%8a'),
                   ('Skin', 'https://thanks.work/%e5%a4%96%e7%9a%ae%e8%a8%88%e7%ae%97%e3%80%80%e3%81%8a%e8%a6%8b%e7%a9%8d%e3%82%8a'),
                   ('Sky', 'https://thanks.work/%e5%a4%a9%e7%a9%ba%e7%8e%87%e3%80%80%e3%81%8a%e8%a6%8b%e7%a9%8d%e3%82%8a')]:
    parser = MyHTMLParser()
    try:
        html = urllib.request.urlopen(url).read().decode('utf-8')
        parser.feed(html)
        print(f'\n--- {title} ---')
        print('\n'.join(parser.text))
    except Exception as e:
        print(f"Error {title}: {e}")
