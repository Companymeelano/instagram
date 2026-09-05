"""Minimal DEX writer: com.instapilot.app.MainActivity extends android.app.Activity
with a full-screen WebView loading file:///android_asset/instapilot.html."""
import struct, zlib, hashlib

def uleb(v):
    out = bytearray()
    while True:
        b = v & 0x7F; v >>= 7
        if v: out.append(b | 0x80)
        else: out.append(b); break
    return bytes(out)

ACT   = 'Landroid/app/Activity;'
WV    = 'Landroid/webkit/WebView;'
WS    = 'Landroid/webkit/WebSettings;'
WVC   = 'Landroid/webkit/WebViewClient;'
CM    = 'Landroid/webkit/CookieManager;'
BNDL  = 'Landroid/os/Bundle;'
CTX   = 'Landroid/content/Context;'
VIEW  = 'Landroid/view/View;'
JSTR  = 'Ljava/lang/String;'
CLS   = 'Lcom/instapilot/app/MainActivity;'
URL   = 'file:///android_asset/instapilot.html'

# protos: (return_desc, params_tuple)
protos_raw = [
    ('V', ()), ('V', (CTX,)), ('V', (BNDL,)), ('V', (WVC,)), ('V', (VIEW,)),
    ('V', (JSTR,)), ('V', ('Z',)), ('V', (WV, 'Z')),
    ('Z', ()), (WS, ()), (CM, ()),
]

def ch(t): return t if t in ('V', 'Z', 'B', 'S', 'C', 'I', 'J', 'F', 'D') else 'L'
def shorty(r, params): return ch(r) + ''.join(ch(p) for p in params)

STRINGS = set()
for s in [ACT, WV, WS, WVC, CM, BNDL, CTX, VIEW, JSTR, CLS, URL, 'V', 'Z',
          '<init>', 'onCreate', 'onBackPressed', 'getSettings', 'setJavaScriptEnabled',
          'setDomStorageEnabled', 'setWebViewClient', 'getInstance', 'setAcceptCookie',
          'setAcceptThirdPartyCookies', 'loadUrl', 'canGoBack', 'goBack',
          'setContentView', 'webview']:
    STRINGS.add(s)
for r, p in protos_raw:
    STRINGS.add(shorty(r, list(p)))

strings = sorted(STRINGS, key=lambda x: [ord(c) for c in x])
sidx = {s: i for i, s in enumerate(strings)}

type_descs = [ACT, WV, WS, WVC, CM, BNDL, CTX, VIEW, JSTR, CLS, 'V', 'Z']
types = sorted(type_descs, key=lambda d: sidx[d])
tidx = {d: i for i, d in enumerate(types)}

seen = {}
for r, p in protos_raw: seen[(r, tuple(p))] = None
protos = sorted(seen.keys(), key=lambda k: (tidx[k[0]], tuple(tidx[x] for x in k[1])))
pidx = {k: i for i, k in enumerate(protos)}

fields_raw = [(CLS, 'webview', WV)]
fields = sorted(fields_raw, key=lambda f: (tidx[f[0]], [ord(c) for c in f[1]], tidx[f[2]]))
fidx = {f: i for i, f in enumerate(fields)}

methods_raw = [
    (ACT, '<init>', 'V', ()),
    (ACT, 'onCreate', 'V', (BNDL,)),
    (ACT, 'onBackPressed', 'V', ()),
    (ACT, 'setContentView', 'V', (VIEW,)),
    (WV,  '<init>', 'V', (CTX,)),
    (WV,  'getSettings', WS, ()),
    (WV,  'setWebViewClient', 'V', (WVC,)),
    (WV,  'loadUrl', 'V', (JSTR,)),
    (WV,  'canGoBack', 'Z', ()),
    (WV,  'goBack', 'V', ()),
    (WS,  'setJavaScriptEnabled', 'V', ('Z',)),
    (WS,  'setDomStorageEnabled', 'V', ('Z',)),
    (CM,  'getInstance', CM, ()),
    (CM,  'setAcceptCookie', 'V', ('Z',)),
    (CM,  'setAcceptThirdPartyCookies', 'V', (WV, 'Z')),
    (WVC, '<init>', 'V', ()),
    (CLS, '<init>', 'V', ()),
    (CLS, 'onCreate', 'V', (BNDL,)),
    (CLS, 'onBackPressed', 'V', ()),
]
mkeys = []
for c, n, r, pp in methods_raw:
    k = (c, n, r, tuple(pp))
    if k not in mkeys: mkeys.append(k)
methods = sorted(mkeys, key=lambda m: (tidx[m[0]], [ord(c) for c in m[1]],
                                       pidx[(m[2], tuple(m[3]))]))
midx = {m: i for i, m in enumerate(methods)}

def MID(c, n, r, p=()):
    return midx[(c, n, r, tuple(p))]

class Code:
    def __init__(self, registers, ins, outs):
        self.registers, self.ins, self.outs = registers, ins, outs
        self.units = []; self.fixups = []; self.labels = {}
    def w(self, *ws):
        for w_ in ws: self.units.append(w_ & 0xFFFF)
        return self
    def addr(self): return len(self.units)
    def label(self, name): self.labels[name] = self.addr(); return self
    def const4(self, a, lit): self.w(0x12 | ((a & 0xF) << 8) | ((lit & 0xF) << 12)); return self
    def const_string(self, a, s): self.w(0x1A | (a << 8), sidx[s]); return self
    def new_instance(self, a, t): self.w(0x22 | (a << 8), tidx[t]); return self
    def move_result(self, a): self.w(0x0A | (a << 8)); return self
    def move_result_object(self, a): self.w(0x0C | (a << 8)); return self
    def return_void(self): self.w(0x0E); return self
    def iget_object(self, a, b, f): self.w(0x54 | ((a & 0xF) << 8) | ((b & 0xF) << 12), fidx[f]); return self
    def iput_object(self, a, b, f): self.w(0x5B | ((a & 0xF) << 8) | ((b & 0xF) << 12), fidx[f]); return self
    def if_eqz(self, a, ln): self.w(0x38 | (a << 8)); self.fixups.append((len(self.units), ln)); self.w(0); return self
    def _invoke(self, op, regs, m):
        n = len(regs)
        assert n <= 5
        u0 = op | (n << 8) | ((regs[4] & 0xF) << 12 if n == 5 else 0)
        g = list(regs) + [0] * (5 - n)
        u2 = (g[0] & 0xF) | ((g[1] & 0xF) << 4) | ((g[2] & 0xF) << 8) | ((g[3] & 0xF) << 12)
        self.w(u0, m, u2); return self
    def invoke_virtual(self, regs, c, n, r, p=()): return self._invoke(0x6E, regs, MID(c, n, r, p))
    def invoke_super(self, regs, c, n, r, p=()):   return self._invoke(0x6F, regs, MID(c, n, r, p))
    def invoke_direct(self, regs, c, n, r, p=()):  return self._invoke(0x70, regs, MID(c, n, r, p))
    def invoke_static(self, regs, c, n, r, p=()):  return self._invoke(0x71, regs, MID(c, n, r, p))
    def finish(self):
        for pos, ln_ in self.fixups:
            off = self.labels[ln_] - (pos - 1)
            assert -32768 <= off <= 32767
            self.units[pos] = off & 0xFFFF
        insns = struct.pack('<%dH' % len(self.units), *self.units)
        code = struct.pack('<HHHHII', self.registers, self.ins, self.outs, 0, 0, len(self.units)) + insns
        while len(code) % 4: code += b'\x00'
        return code

PUBLIC = 0x1; PRIVATE = 0x2; PROTECTED = 0x4; CONSTRUCTOR = 0x10000

ci = Code(1, 1, 1)
ci.invoke_direct([0], ACT, '<init>', 'V').return_void()
code_init = ci.finish()

oc = Code(8, 2, 3)
(oc.invoke_super([6, 7], ACT, 'onCreate', 'V', (BNDL,))
   .new_instance(0, WV)
   .invoke_direct([0, 6], WV, '<init>', 'V', (CTX,))
   .invoke_virtual([0], WV, 'getSettings', WS)
   .move_result_object(1)
   .const4(2, 1)
   .invoke_virtual([1, 2], WS, 'setJavaScriptEnabled', 'V', ('Z',))
   .invoke_virtual([1, 2], WS, 'setDomStorageEnabled', 'V', ('Z',))
   .new_instance(3, WVC)
   .invoke_direct([3], WVC, '<init>', 'V')
   .invoke_virtual([0, 3], WV, 'setWebViewClient', 'V', (WVC,))
   .invoke_static([], CM, 'getInstance', CM)
   .move_result_object(4)
   .invoke_virtual([4, 2], CM, 'setAcceptCookie', 'V', ('Z',))
   .invoke_virtual([4, 0, 2], CM, 'setAcceptThirdPartyCookies', 'V', (WV, 'Z'))
   .const_string(5, URL)
   .invoke_virtual([0, 5], WV, 'loadUrl', 'V', (JSTR,))
   .iput_object(0, 6, (CLS, 'webview', WV))
   .invoke_virtual([6, 0], ACT, 'setContentView', 'V', (VIEW,))
   .return_void())
code_create = oc.finish()

bp = Code(3, 1, 2)
(bp.iget_object(0, 2, (CLS, 'webview', WV))
   .invoke_virtual([0], WV, 'canGoBack', 'Z')
   .move_result(1)
   .if_eqz(1, 'super')
   .invoke_virtual([0], WV, 'goBack', 'V')
   .return_void()
   .label('super')
   .invoke_super([2], ACT, 'onBackPressed', 'V')
   .return_void())
code_back = bp.finish()

def class_data(o_init, o_create, o_back):
    out = uleb(0) + uleb(1) + uleb(1) + uleb(2)
    out += uleb(fidx[(CLS, 'webview', WV)]) + uleb(PRIVATE)
    d = MID(CLS, '<init>', 'V')
    out += uleb(d) + uleb(PUBLIC | CONSTRUCTOR) + uleb(o_init)
    vrt = sorted([(o_create, MID(CLS, 'onCreate', 'V', (BNDL,)), PROTECTED),
                  (o_back, MID(CLS, 'onBackPressed', 'V'), PUBLIC)], key=lambda x: x[1])
    prev = 0
    for off, m, fl in vrt:
        out += uleb(m - prev) + uleb(fl) + uleb(off); prev = m
    return out

hdr = 0x70
string_ids_off = hdr
string_ids_size = 4 * len(strings)
type_ids_off = string_ids_off + string_ids_size
type_ids_size = 4 * len(types)
proto_ids_off = type_ids_off + type_ids_size
proto_ids_size = 12 * len(protos)
field_ids_off = proto_ids_off + proto_ids_size
field_ids_size = 8 * len(fields)
method_ids_off = field_ids_off + field_ids_size
method_ids_size = 8 * len(methods)
class_defs_off = method_ids_off + method_ids_size
class_defs_size = 32
data_off = class_defs_off + class_defs_size

data = b''
o_init = data_off + len(data); data += code_init
o_create = data_off + len(data); data += code_create
o_back = data_off + len(data); data += code_back

def type_list_blob(ts):
    b = struct.pack('<I', len(ts)) + b''.join(struct.pack('<H', tidx[t]) for t in ts)
    while len(b) % 4: b += b'\x00'
    return b

tl_offsets = {}
tl_needed = sorted(set(k[1] for k in protos if k[1]), key=lambda p: tuple(tidx[t] for t in p))
for prm in tl_needed:
    tl_offsets[prm] = data_off + len(data)
    data += type_list_blob(list(prm))

cd_off = data_off + len(data)
data += class_data(o_init, o_create, o_back)

sd_offsets = []
for s in strings:
    sd_offsets.append(data_off + len(data))
    b = s.encode('utf-8')
    data += uleb(len(s)) + b + b'\x00'

while (data_off + len(data)) % 4: data += b'\x00'
map_off = data_off + len(data)

map_entries = [
    (0x0000, 1, 0), (0x0001, len(strings), string_ids_off), (0x0002, len(types), type_ids_off),
    (0x0003, len(protos), proto_ids_off), (0x0004, len(fields), field_ids_off),
    (0x0005, len(methods), method_ids_off), (0x0006, 1, class_defs_off),
    (0x1000, 1, map_off), (0x2000, 1, cd_off), (0x2001, 3, o_init), (0x2002, len(strings), sd_offsets[0]),
]
if tl_needed:
    map_entries.append((0x1001, len(tl_needed), min(tl_offsets.values())))
map_entries.sort()
data += struct.pack('<I', len(map_entries)) + b''.join(struct.pack('<HHII', t, 0, n, o) for t, n, o in map_entries)

data_size = len(data)
file_size = data_off + data_size

string_ids = b''.join(struct.pack('<I', o) for o in sd_offsets)
type_ids = b''.join(struct.pack('<I', sidx[d]) for d in types)
proto_ids = b''.join(struct.pack('<III', sidx[shorty(k[0], list(k[1]))], tidx[k[0]],
                                  tl_offsets[k[1]] if k[1] else 0) for k in protos)
field_ids = b''.join(struct.pack('<HHI', tidx[f[0]], tidx[f[2]], sidx[f[1]]) for f in fields)
method_ids = b''.join(struct.pack('<HHI', tidx[m[0]], pidx[(m[2], m[3])], sidx[m[1]]) for m in methods)

NO_IDX = 0xFFFFFFFF
class_def = struct.pack('<IIIIIIII', tidx[CLS], PUBLIC, tidx[ACT], 0, NO_IDX, 0, cd_off, 0)

body = (string_ids + type_ids + proto_ids + field_ids + method_ids + class_def + data)

header = struct.pack('<8sI20s' + 'I' * 20,
                     b'dex\n035\x00', 0, b'\x00' * 20, file_size, hdr, 0x12345678,
                     0, 0, map_off,
                     len(strings), string_ids_off,
                     len(types), type_ids_off,
                     len(protos), proto_ids_off,
                     len(fields), field_ids_off,
                     len(methods), method_ids_off,
                     1, class_defs_off,
                     data_size, data_off)

dex = header + body
sig = hashlib.sha1(dex[32:]).digest()
dex = dex[:12] + sig + dex[32:]
chk = zlib.adler32(dex[12:])
dex = dex[:8] + struct.pack('<I', chk) + dex[12:]

if __name__ == '__main__':
    open('/tmp/apkbuild/classes.dex', 'wb').write(dex)
    print('classes.dex written:', len(dex), 'bytes')
    import logging; logging.disable(logging.CRITICAL)
    from androguard.core.dex import DEX
    d = DEX(dex)
    for c in d.get_classes():
        print('class:', c.get_name())
        for m in c.get_methods():
            print('  ', m.get_class_name(), m.get_name(), m.get_descriptor(),
                  'code_len' , m.get_length() if m.get_code() else '-')
    print('DEX parse: OK')
