"""Assemble + v1-sign the InstaPilot APK (pure Python signing — no jarsigner)."""
import base64, hashlib, io, zipfile, struct, datetime
from cryptography import x509
from cryptography.x509.oid import NameOID
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa, padding
from cryptography.hazmat.primitives.serialization import pkcs7

ASSET_HTML = '/tmp/apk_asset_index.html'
OUT = '/tmp/apkbuild/InstaPilot_v48.apk'

exec(open('make_manifest.py').read().split("if __name__")[0])
exec(open('make_dex.py').read().split("if __name__")[0])
manifest_bin = build_manifest()   # from make_manifest
dex = dex                         # from make_dex (module-level variable)
html = open(ASSET_HTML, 'rb').read()

def e16384(name):  # fixed DOS date 2026-09-05 00:00
    return (2026, 9, 5, 0, 0, 0)

# ---------- unsigned zip ----------
buf = io.BytesIO()
zf = zipfile.ZipFile(buf, 'w')
def add(name, data, store=False):
    zi = zipfile.ZipInfo(name, date_time=e16384(name))
    zi.external_attr = 0o644 << 16
    zi.compress_type = zipfile.ZIP_STORED if store else zipfile.ZIP_DEFLATED
    zf.writestr(zi, data)
add('AndroidManifest.xml', manifest_bin, store=True)
add('classes.dex', dex, store=True)
add('assets/instapilot.html', html)
zf.close()

# ---------- v1 (JAR) signing ----------
raw = buf.getvalue()
z = zipfile.ZipFile(io.BytesIO(raw))
entries = [(i, z.read(i.filename)) for i in z.infolist()]

def b64(b): return base64.b64encode(b).decode()

# MANIFEST.MF
main_sec = b"Manifest-Version: 1.0\r\nCreated-By: 1.0 (InstaPilot)\r\n\r\n"
mf_sections = []
mf = io.BytesIO(); mf.write(main_sec)
for info, content in entries:
    sec = ("Name: %s\r\nSHA-256-Digest: %s\r\n\r\n" % (info.filename, b64(hashlib.sha256(content).digest()))).encode()
    mf_sections.append(sec); mf.write(sec)
mf_bytes = mf.getvalue()

# CERT.SF
sf = io.BytesIO()
sf.write(b"Signature-Version: 1.0\r\nCreated-By: 1.0 (InstaPilot)\r\n")
sf.write(b"SHA-256-Digest-Manifest: " + b64(hashlib.sha256(mf_bytes).digest()).encode() + b"\r\n\r\n")
for (info, _), sec in zip(entries, mf_sections):
    sf.write(("Name: %s\r\nSHA-256-Digest: %s\r\n\r\n" %
              (info.filename, b64(hashlib.sha256(sec).digest()))).encode())
sf_bytes = sf.getvalue()

# key + self-signed cert (reuse stable signing identity if present)
import os
KEYF='/home/user/instagram/.apk-build/keystore.p8'; CERTF='/home/user/instagram/.apk-build/cert.pem'
if os.path.exists(KEYF) and os.path.exists(CERTF):
    key = serialization.load_pem_private_key(open(KEYF,'rb').read(), password=None)
    cert = x509.load_pem_x509_certificate(open(CERTF,'rb').read())
else:
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'InstaPilot'),
                      x509.NameAttribute(NameOID.ORGANIZATION_NAME, 'InstaPilot')])
    cert = (x509.CertificateBuilder()
        .subject_name(name).issuer_name(name)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.datetime(2024, 1, 1))
        .not_valid_after(datetime.datetime(2055, 1, 1))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(x509.KeyUsage(digital_signature=True, key_encipherment=False, content_commitment=False,
                                     data_encipherment=False, key_agreement=False, key_cert_sign=False,
                                     crl_sign=False, encipher_only=False, decipher_only=False), critical=False)
        .sign(key, hashes.SHA256()))
    open(KEYF,'wb').write(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    open(CERTF,'wb').write(cert.public_bytes(serialization.Encoding.PEM))

# PKCS#7 / CERT.RSA  (detached, signed attributes)
sig = (pkcs7.PKCS7SignatureBuilder()
       .set_data(sf_bytes)
       .add_signer(cert, key, hashes.SHA256())
       .sign(serialization.Encoding.DER,
             [pkcs7.PKCS7Options.DetachedSignature]))

# ---------- final signed zip ----------
out = io.BytesIO()
zo = zipfile.ZipFile(out, 'w')
for info, content in entries:
    zi = zipfile.ZipInfo(info.filename, date_time=e16384(info.filename))
    zi.external_attr = 0o644 << 16
    zi.compress_type = info.compress_type
    zo.writestr(zi, content)
for nme, payload in [('META-INF/MANIFEST.MF', mf_bytes), ('META-INF/CERT.SF', sf_bytes), ('META-INF/CERT.RSA', sig)]:
    zi = zipfile.ZipInfo(nme, date_time=e16384(nme))
    zi.external_attr = 0o644 << 16
    zi.compress_type = zipfile.ZIP_DEFLATED
    zo.writestr(zi, payload)
zo.close()
open(OUT, 'wb').write(out.getvalue())
open('/tmp/apkbuild/keystore.p8', 'wb').write(key.private_bytes(
    serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
open('/tmp/apkbuild/cert.pem', 'wb').write(cert.public_bytes(serialization.Encoding.PEM))
print('signed APK:', OUT, len(out.getvalue()), 'bytes')

# ---------- verify ----------
import logging; logging.disable(logging.CRITICAL)
from androguard.core.apk import APK
a = APK(OUT)
print('package:', a.get_package())
print('versionCode:', a.get_androidversion_code(), '| versionName:', a.get_androidversion_name())
print('minSdk:', a.get_min_sdk_version(), '| targetSdk:', a.get_target_sdk_version())
print('permissions:', a.get_permissions())
print('main activity:', a.get_main_activity())
print('signed v1:', a.is_signed_v1())
certs = a.get_certificates_der_v1()
print('v1 certs:', len(certs) > 0)
tw = hashlib.sha256()
tw.update(open(OUT,'rb').read())
print('sha256:', tw.hexdigest())
