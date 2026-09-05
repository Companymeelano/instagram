#!/usr/bin/env python3
"""Builds InstaPilot V48 single-file index.html from index.php + assets.
Usage: python3 build_single_file.py  (run inside instapilot_v48/)"""
import re, sys
from pathlib import Path

root = Path(__file__).parent
html = (root/'index.php').read_text(encoding='utf-8')
app_css = (root/'assets/css/app.css').read_text(encoding='utf-8')
final_css = (root/'assets/css/final.css').read_text(encoding='utf-8')
compat_js = (root/'assets/js/compat.js').read_text(encoding='utf-8')
app_js = (root/'assets/js/app.js').read_text(encoding='utf-8')

# safety: inline scripts must not contain a literal closing tag
for name, payload in [('app.js', app_js), ('compat.js', compat_js)]:
    if '</script' in payload.lower():
        payload = payload.replace('</script', '<\\/script')
        print(f'note: escaped </script> in {name}')

# 1) replace the stylesheet link with one merged inline style block
css_block = ('<style>\n/* ===== InstaPilot V48 merged runtime CSS (app + final stabilization layer) ===== */\n'
             + app_css.rstrip() + '\n\n/* ===== stabilization layer (formerly final.css) ===== */\n'
             + final_css.rstrip() + '\n</style>')
html, n = re.subn(r'\s*<link rel="stylesheet" href="assets/css/app\.css\?v=\d+">', lambda m: '\n' + css_block, html, count=1)
assert n == 1, 'app.css link not found'

# 2) drop the external compat.js tag (it is inlined with app.js below)
html, n = re.subn(r'\s*<script src="assets/js/compat\.js\?v=\d+"></script>', lambda m: '', html, count=1)
assert n == 1, 'compat.js tag not found'

# 3) replace the external app.js tag with inline scripts
js_block = ('<script>\n/* ===== InstaPilot V48 compat layer ===== */\n' + compat_js.rstrip()
            + '\n\n/* ===== InstaPilot V48 application runtime ===== */\n' + app_js.rstrip() + '\n</script>')
html, n = re.subn(r'\s*<script src="assets/js/app\.js\?v=\d+"></script>', lambda m: '\n' + js_block, html, count=1)
assert n == 1, 'app.js tag not found'

# 4) no external asset references are allowed to remain
left = re.findall(r'assets/(?:css|js)/[^"\']+', html)
assert not left, f'external asset refs remain: {left}'

html = html.replace('InstaPilot</title>', 'InstaPilot V48</title>') if 'InstaPilot</title>' in html else html

out = root/'index.html'
out.write_text(html, encoding='utf-8')
print(f'built {out.name}: {out.stat().st_size/1024:.1f} KB (single file, fully inlined)')
