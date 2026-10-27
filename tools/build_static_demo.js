#!/usr/bin/env node
/**
 * Build the static portfolio snapshot for GitHub Pages.
 *
 *   1. Run the app in demo mode (DEMO_MODE=true, seeded with tools/seed_demo.php).
 *   2. node tools/build_static_demo.js http://127.0.0.1:8081/local-lgu-research portfolio-demo
 *
 * Crawls every page reachable from the dashboard, saves it as .html with relative links,
 * copies assets/ (not uploaded avatars), and injects a small script so forms and buttons
 * show a "static demo" notice instead of failing.
 */
const fs = require('fs');
const path = require('path');

const [, , baseArg, outArg] = process.argv;
if (!baseArg || !outArg) {
  console.error('Usage: node tools/build_static_demo.js <demo base URL> <output folder>');
  process.exit(1);
}
const BASE = new URL(baseArg.replace(/\/?$/, '/'));
const OUT = path.resolve(outArg);
const ROOT = path.resolve(__dirname, '..');

// Public sites of the other LGU2 systems (same list as super_admin_uitheme/super_sso.php)
const SYSTEMS = {
  racs: 'https://racs.jampzdev.com', ordinance: 'https://lgu2ordinance.jampzdev.com',
  minutes: 'https://lgu2minutes.jampzdev.com', agenda: 'https://lgu2agenda.jampzdev.com',
  committee: 'https://lgu2committee.jampzdev.com', committeejournal: 'https://lgu2committeejournal.jampzdev.com',
  journal: 'https://lgu2journal.jampzdev.com', archives: 'https://lgu2archives.jampzdev.com',
  research: './dashboard.html',
};
// Endpoints that are not pages
const SKIP = /\/(logout|sso_go|sso_login|download_file|generate_pdf|verify_password|save_review|create_measure|fetch_activity_history|api_research|send_to_research|sync_endpoint|receive_[a-z_]+|update_[a-z_]+)\.php$/;

let cookie = '';
async function get(url) {
  const res = await fetch(url, { headers: { cookie, accept: 'text/html' }, redirect: 'manual' });
  const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  if (set.length) cookie = set.map((c) => c.split(';')[0]).join('; ');
  return res;
}

/** Path of a page inside the snapshot, e.g. contents/x/y.php -> contents/x/y.html */
function outPath(urlPath) {
  const rel = decodeURIComponent(urlPath.slice(BASE.pathname.length)) || 'dashboard.php';
  return rel.replace(/\.php$/, '.html');
}

function relativeTo(fromFile, toFile) {
  let r = path.posix.relative(path.posix.dirname(fromFile), toFile);
  return r === '' ? path.posix.basename(toFile) : r;
}

const pages = new Map(); // url path -> html

async function crawl() {
  // Entering through index.php logs the demo visitor in
  await get(new URL('index.php', BASE));
  // Start from the dashboard, plus pages no menu links to
  const queue = ['dashboard.php', 'contents/legislative-research-section/document_sender.php'].map((p) => new URL(p, BASE).pathname);
  while (queue.length) {
    const p = queue.shift();
    if (pages.has(p) || SKIP.test(p)) continue;
    const res = await get(new URL(p, BASE.origin));
    if (res.status !== 200 || !(res.headers.get('content-type') || '').includes('text/html')) {
      console.warn(`skip ${p} (${res.status})`);
      pages.set(p, null);
      continue;
    }
    const html = await res.text();
    pages.set(p, html);
    for (const m of html.matchAll(/\b(?:href|action)="([^"#]+)/g)) {
      let u;
      try { u = new URL(m[1].replace(/&amp;/g, '&'), new URL(p, BASE.origin)); } catch { continue; }
      if (u.origin === BASE.origin && u.pathname.startsWith(BASE.pathname) && u.pathname.endsWith('.php') && !pages.has(u.pathname)) {
        queue.push(u.pathname);
      }
    }
  }
}

function rewrite(p, html) {
  const file = outPath(p);
  const toRoot = path.posix.relative(path.posix.dirname(file), '.') || '.';

  html = html.replace(/\b(href|src|action)="([^"]*)"/g, (all, attr, value) => {
    if (/^(#|javascript:|mailto:|data:|https?:\/\/(?!127\.0\.0\.1))/i.test(value)) return all;
    let u;
    try { u = new URL(value.replace(/&amp;/g, '&') || p, new URL(p, BASE.origin)); } catch { return all; }
    if (u.origin !== BASE.origin || !u.pathname.startsWith(BASE.pathname)) return all;
    const hash = u.hash || '';
    if (u.pathname.endsWith('/sso_go.php')) {
      const target = SYSTEMS[u.searchParams.get('system')] || './dashboard.html';
      return `${attr}="${target.startsWith('./') ? relativeTo(file, 'dashboard.html') : target}"`;
    }
    if (u.pathname.endsWith('.php')) {
      if (attr === 'action') return `${attr}="#"`;
      const target = pages.get(u.pathname) ? outPath(u.pathname) : (u.pathname.endsWith('/logout.php') || u.pathname.endsWith('/index.php') ? 'dashboard.html' : null);
      return target ? `${attr}="${relativeTo(file, target)}${hash}"` : `${attr}="#"`;
    }
    const asset = decodeURIComponent(u.pathname.slice(BASE.pathname.length));
    return `${attr}="${relativeTo(file, asset)}"`;
  });

  // Scripts build URLs from window.APP_BASE: make it the snapshot root, wherever it is hosted
  html = html.replace(/window\.APP_BASE = [^;]+;/, `window.APP_BASE = new URL(${JSON.stringify(toRoot + '/')}, location.href).pathname.replace(/\\/$/, '');`);
  html = html.replace(/'\/logout\.php'/g, "'/dashboard.html'");
  // Absolute app paths inside inline handlers (e.g. onerror="this.src='/base/assets/...'")
  html = html.split(`'${BASE.pathname}`).join(`(window.APP_BASE || '') + '/`);
  html = html.replace(/<!-- App base path for scripts[^>]*-->\s*/, '');
  html = html.replace(/Portfolio demo with sample data: saving, sending and deleting are turned off\./g,
    'Static portfolio snapshot with sample data: buttons that save, send or search are turned off.');

  const shim = `<script>
/* Static snapshot: there is no server, so answer requests and form posts with a notice */
(function () {
  var msg = 'This is a static portfolio snapshot of the LGU2 Research Section system. Saving, sending and searching need the live demo.';
  function toast() {
    var t = document.createElement('div');
    t.textContent = msg;
    t.setAttribute('role', 'status');
    t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;max-width:90%;padding:12px 18px;border-radius:10px;background:var(--brand-primary,#1a4d64);color:#fff;font:14px Montserrat,system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25)';
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4000);
  }
  var demoReply = { success: false, status: 'error', demo: true, message: msg, data: [], requests: [] };
  var realFetch = window.fetch;
  window.fetch = function (input) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (/\\.php(\\?|$)/.test(url) || url === '' || url[0] === '?') {
      toast();
      return Promise.resolve(new Response(JSON.stringify(demoReply), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    }
    return realFetch.apply(this, arguments);
  };
  var open = XMLHttpRequest.prototype.open, send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (m, url) { this._demo = /\\.php(\\?|$)/.test(url) || url === '' || String(url)[0] === '?'; return open.apply(this, arguments); };
  XMLHttpRequest.prototype.send = function () {
    if (!this._demo) return send.apply(this, arguments);
    var x = this;
    Object.defineProperty(x, 'readyState', { value: 4 });
    Object.defineProperty(x, 'status', { value: 200 });
    Object.defineProperty(x, 'responseText', { value: JSON.stringify(demoReply) });
    Object.defineProperty(x, 'response', { value: JSON.stringify(demoReply) });
    toast();
    setTimeout(function () { x.onreadystatechange && x.onreadystatechange(); x.onload && x.onload(); }, 0);
  };
  document.addEventListener('submit', function (e) { e.preventDefault(); toast(); }, true);
})();
</script>`;
  return html.replace(/<head>/i, '<head>\n' + shim);
}

function copyDir(src, dst, skip) {
  fs.mkdirSync(dst, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    if (entry.name.startsWith('.') || (skip && skip(entry.name))) continue;
    const s = path.join(src, entry.name), d = path.join(dst, entry.name);
    if (entry.isDirectory()) copyDir(s, d, skip);
    else fs.copyFileSync(s, d);
  }
}

(async () => {
  await crawl();
  // Empty the folder rather than deleting it (a preview server may have it open)
  fs.mkdirSync(OUT, { recursive: true });
  for (const entry of fs.readdirSync(OUT)) fs.rmSync(path.join(OUT, entry), { recursive: true, force: true });
  let count = 0;
  for (const [p, html] of pages) {
    if (!html) continue;
    const file = path.join(OUT, outPath(p));
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, rewrite(p, html));
    count++;
  }
  copyDir(path.join(ROOT, 'assets'), path.join(OUT, 'assets'), (name) => name === 'avatars');
  fs.writeFileSync(path.join(OUT, 'index.html'),
    '<!doctype html><meta charset="utf-8"><title>LGU2 Research Section — Portfolio Demo</title>' +
    '<meta http-equiv="refresh" content="0; url=dashboard.html"><a href="dashboard.html">Open the demo</a>\n');
  fs.writeFileSync(path.join(OUT, '.nojekyll'), '');
  console.log(`Wrote ${count} pages to ${OUT}`);
})().catch((e) => { console.error(e); process.exit(1); });
