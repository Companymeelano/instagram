"""Minimal binary AndroidManifest.xml (AXML) writer for InstaPilot APK."""
import struct

NO = 0xFFFFFFFF

class Pool:
    def __init__(self):
        self.strings = []; self.index = {}
    def add(self, s):
        if s not in self.index:
            self.index[s] = len(self.strings); self.strings.append(s)
        return self.index[s]
    def idx(self, s): return self.index.get(s, NO)

def _len8(v):
    if v < 0x80: return bytes([v])
    return bytes([0x80 | (v >> 8), v & 0xFF])

def build_pool(pool: Pool, resmap_ids):
    # strings with resource ids must begin the pool (resmap[i] <-> string[i])
    data = b''; offsets = []
    for s in pool.strings:
        b = s.encode('utf-8')
        offsets.append(len(data))
        data += _len8(len(s)) + _len8(len(b)) + b + b'\x00'
    while len(data) % 4: data += b'\x00'
    n = len(pool.strings)
    offsize = 4 * n
    stringsStart = 28 + offsize
    size = stringsStart + len(data)
    hdr = struct.pack('<HHIIIIII', 0x0001, 28, size, n, 0, 0x100, 28 + 4 * n, 0)  # stringsStart right after offsets
    chunk = hdr + struct.pack('<%dI' % n, *offsets) + data
    resmap = b''
    if resmap_ids is not None:
        resmap = struct.pack('<HHI', 0x0180, 8, 8 + 4 * len(resmap_ids)) + struct.pack('<%dI' % len(resmap_ids), *resmap_ids)
    return chunk, resmap

def node(t, line, ext=b'', total=None):
    size = 8 + 8 + len(ext)
    return struct.pack('<HHIII', t, 16, total or size, line, NO) + ext

def start_el(pool, line, name, attrs):
    # attrs: list of (attr_name, raw_value_str_or_None, type, data[, use_ns=True])
    ext = struct.pack('<IIHHHHHH', NO, pool.idx(name), 0x14, 0x14, len(attrs), 0, 0, 0)
    body = b''
    uri_idx = pool.idx('http://schemas.android.com/apk/res/android')
    for a in attrs:
        aname, raw, typ, data = a[0], a[1], a[2], a[3]
        ns = uri_idx if (len(a) < 5 or a[4]) else NO
        raw_idx = NO if raw is None else pool.add(raw)
        body += struct.pack('<IIIHBBI', ns, pool.idx(aname), raw_idx, 8, 0, typ, data)
    return node(0x0102, line, ext + body, 36 + 20 * len(attrs))

def end_el(pool, line, name):
    return node(0x0103, line, struct.pack('<II', NO, pool.idx(name)))

STR = 0x03; INT = 0x10; BOOL = 0x12

def build_manifest(package='com.instapilot.app', version_code=48, version_name='4.8.0',
                   min_sdk=24, target_sdk=29, label='InstaPilot', activity='.MainActivity'):
    pool = Pool()
    ATTR_IDS = {
        'name': 0x01010003, 'label': 0x01010001, 'exported': 0x01010010,
        'versionCode': 0x0101021b, 'versionName': 0x0101021c,
        'minSdkVersion': 0x0101020c, 'targetSdkVersion': 0x01010270,
    }
    # first: strings that carry resource ids (order defines resmap)
    for n in ['versionCode', 'versionName', 'minSdkVersion', 'targetSdkVersion', 'name', 'label', 'exported']:
        pool.add(n)
    resmap = [ATTR_IDS[n] for n in ['versionCode', 'versionName', 'minSdkVersion', 'targetSdkVersion', 'name', 'label', 'exported']]
    for s in ['package','manifest', 'uses-sdk', 'uses-permission', 'application', 'activity', 'intent-filter',
              'action', 'category', 'android', 'http://schemas.android.com/apk/res/android',
              package, str(version_code), version_name, str(min_sdk), str(target_sdk),
              'android.permission.INTERNET', label, activity, 'true',
              'android.intent.action.MAIN', 'android.intent.category.LAUNCHER']:
        pool.add(s)

    pool_chunk, resmap = build_pool(pool, resmap)
    uri = pool.idx('http://schemas.android.com/apk/res/android')
    ans = pool.idx('android')
    chunks = [pool_chunk, resmap]
    chunks.append(node(0x0100, 0, struct.pack('<II', ans, uri)))  # start namespace android
    L = 1
    chunks.append(start_el(pool, L, 'manifest', [
        ('package', package, STR, pool.idx(package), False),
        ('versionCode', str(version_code), INT, version_code),
        ('versionName', version_name, STR, pool.idx(version_name)),
    ]) + b'')
    L += 1
    chunks.append(start_el(pool, L, 'uses-sdk', [
        ('minSdkVersion', str(min_sdk), INT, min_sdk),
        ('targetSdkVersion', str(target_sdk), INT, target_sdk)]))
    chunks.append(end_el(pool, L, 'uses-sdk')); L += 1
    chunks.append(start_el(pool, L, 'uses-permission', [
        ('name', 'android.permission.INTERNET', STR, pool.idx('android.permission.INTERNET'))]))
    chunks.append(end_el(pool, L, 'uses-permission')); L += 1
    chunks.append(start_el(pool, L, 'application', [('label', label, STR, pool.idx(label))])); L += 1
    chunks.append(start_el(pool, L, 'activity', [
        ('name', activity, STR, pool.idx(activity)),
        ('exported', 'true', BOOL, 0xFFFFFFFF)])); L += 1
    chunks.append(start_el(pool, L, 'intent-filter', [])); L += 1
    chunks.append(start_el(pool, L, 'action', [
        ('name', 'android.intent.action.MAIN', STR, pool.idx('android.intent.action.MAIN'))]))
    chunks.append(end_el(pool, L, 'action')); L += 1
    chunks.append(start_el(pool, L, 'category', [
        ('name', 'android.intent.category.LAUNCHER', STR, pool.idx('android.intent.category.LAUNCHER'))]))
    chunks.append(end_el(pool, L, 'category')); L += 1
    chunks.append(end_el(pool, L, 'intent-filter')); L += 1
    chunks.append(end_el(pool, L, 'activity')); L += 1
    chunks.append(end_el(pool, L, 'application')); L += 1
    chunks.append(end_el(pool, L, 'manifest'))
    chunks.append(node(0x0101, L, struct.pack('<II', ans, uri)))  # end namespace

    # NOTE: attrs referenced above must exist in pool already
    body = b''.join(chunks)
    total = 8 + len(body)
    return struct.pack('<HHI', 0x0003, 8, total) + body

if __name__ == '__main__':
    m = build_manifest()
    open('/tmp/apkbuild/AndroidManifest.xml', 'wb').write(m)
    # sanity parse
    from androguard.core.axml import AXMLPrinter
    p = AXMLPrinter(m)
    print(p.get_xml_obj().toprettyxml())
