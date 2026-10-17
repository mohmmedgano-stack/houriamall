"""
HTTP smoke test: boots the app on PHP's built-in server with a throwaway data dir,
runs the real setup → login flow, then opens every page (desktop + phone width) and fails on
PHP errors, 5xx, missing CSRF protection, or access-control leaks.

usage: python3 tests/http_smoke.py [--screens DIR]
"""
import os, re, subprocess, sys, tempfile, time, json, urllib.parse, shutil
import http.cookiejar, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8765
data = tempfile.mkdtemp(prefix='mbcc_http_')
env = dict(os.environ, MBCC_DATA_DIR=data)
srv = subprocess.Popen(['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-S', f'127.0.0.1:{PORT}', '-t', ROOT],
                       cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
time.sleep(0.8)
BASE = f'http://127.0.0.1:{PORT}/index.php'
failures = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

def opener(follow=True):
    jar = http.cookiejar.CookieJar()
    hs = [urllib.request.HTTPCookieProcessor(jar)]
    if not follow: hs.append(NoRedirect())
    o = urllib.request.build_opener(*hs); o.jar = jar
    return o

def req(o, url, data=None, files=None):
    body = None; headers = {}
    if files:
        b = '----mbcc' + str(time.time()).replace('.', '')
        parts = []
        for k, v in (data or {}).items():
            vals = v if isinstance(v, list) else [v]
            for x in vals: parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{x}\r\n'.encode())
        for k, (fn, content, ctype) in files.items():
            parts.append(f'--{b}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n')
        parts.append(f'--{b}--\r\n'.encode()); body = b''.join(parts); headers['Content-Type'] = 'multipart/form-data; boundary=' + b
    elif data is not None:
        body = urllib.parse.urlencode(data, doseq=True).encode()
    r = urllib.request.Request(url, data=body, headers=headers)
    try:
        resp = o.open(r, timeout=30); return resp.status, resp.read().decode('utf-8', 'replace'), resp.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), url

def csrf(html):
    m = re.search(r'name="csrf" value="([a-f0-9]+)"', html); return m.group(1) if m else ''

def check(name, status, html, expect=200):
    bad = re.search(r'(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|Parse error|حصل خطأ غير متوقع)', html)
    if status != expect or bad:
        failures.append(f'{name}: status {status}' + (f' — {html[max(0, bad.start()-80):bad.end()+220]!r}' if bad else ''))
        print('  ✗', name, status); return False
    print('  ✓', name); return True

try:
    o = opener()
    st, html, _ = req(o, BASE)
    check('setup page', st, html)
    key = open(os.path.join(data, 'setup.key')).read().strip()
    # wrong key is rejected
    st, html, _ = req(o, BASE, {'csrf': csrf(html), 'setup_key': 'nope', 'name': 'x', 'email': 'x@x.co', 'password': 'abcdefgh12', 'password2': 'abcdefgh12'})
    if 'مفتاح التجهيز غلط' not in html: failures.append('setup accepted a wrong key')
    st, html, _ = req(o, BASE, {'csrf': csrf(html), 'setup_key': key, 'name': 'JANO', 'email': 'owner@test.local', 'password': 'Secret12345', 'password2': 'Secret12345'})
    check('setup → home', st, html)
    if os.path.exists(os.path.join(data, 'setup.key')): failures.append('setup.key not removed after setup')

    # seed demo data so pages have content
    st, html, _ = req(o, BASE + '?r=settings'); check('settings', st, html)
    st, html, _ = req(o, BASE + '?r=settings', {'csrf': csrf(html), 'a': 'demo_seed'}); check('demo seed', st, html)

    routes = json.loads(subprocess.check_output(['php', '-r', 'require "index_routes.php";'], cwd=os.path.join(ROOT, 'tests'), env=env).decode())
    extra = os.environ.get('SMOKE_EXTRA', '')
    DETAIL = ('client', 'campaign', 'lead', 'report')
    pages = [(r, {}) for r in routes if r not in ('export', 'oauth') + DETAIL and os.path.exists(os.path.join(ROOT, 'views', r + '.php'))]
    # detail pages need ids
    ids = json.loads(subprocess.check_output(['php', 'tests/ids.php'], cwd=ROOT, env=env).decode())
    for k, v in ids.items():
        if v and os.path.exists(os.path.join(ROOT, 'views', k + '.php')): pages.append((k, {'id': v}))
    for r, params in pages:
        q = urllib.parse.urlencode(dict(r=r, **params))
        st, html, _ = req(o, BASE + '?' + q); check(f'page {r} {params}', st, html)

    # ---- real POST flows ----
    def raw(o, url):
        try:
            r = o.open(url, timeout=60); return r.status, r.headers.get('Content-Type', ''), r.read()
        except urllib.error.HTTPError as e: return e.code, '', e.read()
    st, html, _ = req(o, BASE + '?r=settings'); req(o, BASE, {'csrf': csrf(html), 'mode': 'live'}) if False else None
    st, html, _ = req(o, BASE + '?r=home'); t = csrf(html)
    req(o, BASE + '?r=mode', {'csrf': t, 'mode': 'live'})
    st, html, _ = req(o, BASE + '?r=clients'); t = csrf(html)
    st, html, url = req(o, BASE + '?r=clients', {'csrf': t, 'a': 'create', 'name': 'عميل اختبار', 'currency': 'EGP', 'timezone': 'Africa/Cairo'}, files={'logo': ('x.txt', b'notimage', 'text/plain')})
    check('create client (live)', st, html)
    cid = re.search(r'id=(\d+)', url).group(1)
    st, html, _ = req(o, BASE + f'?r=client&id={cid}'); t = csrf(html)
    st, html, _ = req(o, BASE + f'?r=client&id={cid}', {'csrf': t, 'a': 'account_save', 'name': 'Main', 'currency': 'EGP', 'platform': 'meta'}); check('add account', st, html)
    aid = re.search(r'<option value="(\d+)">عميل اختبار', req(o, BASE + '?r=import')[1]).group(1)
    st, html, _ = req(o, BASE + '?r=import'); t = csrf(html)
    csv = 'Campaign name,Day,Objective,Amount spent (EGP),Impressions,Link clicks,Leads\nحملة ليدز,2026-10-10,OUTCOME_LEADS,500,20000,200,10\n'.encode('utf-8')
    st, html, _ = req(o, BASE + '?r=import', {'csrf': t, 'account': aid}, files={'file': ('ads.csv', csv, 'text/csv')})
    check('import CSV', st, html)
    if 'نتيجة الاستيراد' not in html: failures.append('import result not shown')
    st, html, _ = req(o, BASE + '?r=dashboard&from=2026-10-10&to=2026-10-10'); check('dashboard after import', st, html)
    if '50.00' not in html: failures.append('dashboard CPL 50.00 not shown after import')
    st, html, _ = req(o, BASE + '?r=crm&view=add&client=' + cid); t = csrf(html)
    st, html, _ = req(o, BASE + '?r=crm', {'csrf': t, 'a': 'create', 'client_id': cid, 'full_name': 'ليد تجربة', 'phone': '01011112222'}); check('add lead', st, html)
    st, html, _ = req(o, BASE + '?r=reports&client=' + cid + '&type=weekly'); t = csrf(html)
    st, html, url = req(o, BASE + '?r=reports', {'csrf': t, 'a': 'generate', 'client_id': cid, 'type': 'client', 'from': '2026-10-01', 'to': '2026-10-18'}); check('generate report', st, html)
    rid = re.search(r'id=(\d+)', url).group(1)
    for fmt, ctype in (('pdf', 'application/pdf'), ('xlsx', 'spreadsheetml'), ('csv', 'text/csv')):
        code, ct, body = raw(o, BASE + f'?r=export&kind=report&id={rid}&format={fmt}')
        if code != 200 or ctype not in ct: failures.append(f'report export {fmt}: {code} {ct}')
        else: print('  ✓ report export', fmt, len(body), 'bytes')
    for kind in ('perf', 'leads'):
        code, ct, body = raw(o, BASE + f'?r=export&kind={kind}&format=csv&from=2026-10-01&to=2026-10-18')
        if code != 200 or 'text/csv' not in ct: failures.append(f'{kind} export {code}')
        else: print('  ✓ export', kind)
    # permissions over HTTP: a sales user cannot open finance / users
    st, html, _ = req(o, BASE + '?r=users'); t = csrf(html)
    req(o, BASE + '?r=users', {'csrf': t, 'a': 'create', 'name': 'Sales', 'email': 'sales@test.local', 'role': 'sales', 'password': 'SalesPass123', 'clients[]': [cid]})
    so = opener(); st, html, _ = req(so, BASE)
    st, html, _ = req(so, BASE + '?r=login', {'csrf': csrf(html), 'email': 'sales@test.local', 'password': 'SalesPass123'})
    for r in ('finance', 'users', 'dashboard', 'integrations'):
        st, html, _ = req(so, BASE + '?r=' + r)
        if st != 403: failures.append(f'sales user got {st} on {r}')
    st, html, _ = req(so, BASE + '?r=crm'); check('sales sees CRM', st, html)
    code, ct, body = raw(so, BASE + '?r=export&kind=perf&format=csv')
    if code != 403: failures.append(f'sales perf export {code}')
    else: print('  ✓ role restrictions enforced')
    req(o, BASE + '?r=mode', {'csrf': csrf(req(o, BASE)[1]), 'mode': 'demo'})

    # CSRF: a POST without a token must be rejected
    st, html, _ = req(o, BASE + '?r=clients', {'a': 'create', 'name': 'NoToken'})
    if st != 400: failures.append(f'POST without CSRF returned {st}')
    else: print('  ✓ csrf enforced')

    # unauthenticated access is blocked
    anon = opener()
    st, html, _ = req(anon, BASE + '?r=clients')
    if 'تسجيل الدخول' not in html: failures.append('anonymous user could open clients')
    else: print('  ✓ anonymous redirected to login')
    # private folders are not served by Apache rules (built-in server ignores .htaccess, so just make sure rules exist)
    ht = open(os.path.join(ROOT, '.htaccess')).read()
    for f in ('src', 'data', 'views'):
        if f not in ht: failures.append(f'.htaccess does not block {f}/')

    if '--screens' in sys.argv:
        out = sys.argv[sys.argv.index('--screens') + 1]; os.makedirs(out, exist_ok=True)
        from playwright.sync_api import sync_playwright
        cookies = [{'name': c.name, 'value': c.value, 'domain': '127.0.0.1', 'path': '/'} for c in o.jar]
        shots = os.environ.get('SHOTS', 'home,dashboard').split(',')
        with sync_playwright() as p:
            b = p.chromium.launch()
            for label, vp in (('desktop', {'width': 1440, 'height': 900}), ('phone', {'width': 390, 'height': 844})):
                ctx = b.new_context(viewport=vp, device_scale_factor=1, locale='ar-EG'); ctx.add_cookies(cookies)
                pg = ctx.new_page(); errs = []
                pg.on('pageerror', lambda e: errs.append(str(e)))
                for r in shots:
                    pg.goto(BASE + '?r=' + r, wait_until='networkidle'); pg.wait_for_timeout(500)
                    w = pg.evaluate('document.documentElement.scrollWidth'); vw = vp['width']
                    if w > vw + 2: failures.append(f'{label} {r}: horizontal overflow {w}px > {vw}px')
                    pg.screenshot(path=f'{out}/{r}-{label}.png', full_page=True)
                if errs: failures.append(f'{label}: JS errors {errs[:3]}')
                ctx.close()
            b.close()
        print('  screenshots →', out)
finally:
    srv.terminate()
    err = srv.stderr.read().decode('utf-8', 'replace') if srv.stderr else ''
    php_errs = [l for l in err.splitlines() if re.search(r'PHP (Fatal|Warning|Notice|Deprecated|Parse)', l)]
    if php_errs: failures.extend(php_errs[:10])
    lg = os.path.join(data, 'app.log')
    if os.path.exists(lg) and failures: print(open(lg).read()[-1500:])
    if failures and os.path.exists(lg): failures.extend(['app.log: ' + l for l in open(lg).read().splitlines()[-5:]])
    shutil.rmtree(data, ignore_errors=True)

print()
if failures:
    print('FAILURES:'); [print(' -', f) for f in failures]; sys.exit(1)
print('HTTP smoke: all good')
