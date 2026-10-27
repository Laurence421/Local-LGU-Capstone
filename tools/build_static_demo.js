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
const { execFileSync } = require('child_process');

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

  // Forms: GET forms (search, filters) reload this page with their query; POST forms go to demo-backend.js
  html = html.replace(/<form\b[^>]*>/gi, (tag) => {
    if (!/\saction="/i.test(tag)) return tag;
    const isPost = /\smethod="?post/i.test(tag);
    return tag.replace(/\saction="[^"]*"/i, ` action="${isPost ? '#' : path.posix.basename(file)}"`);
  });

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
      // Keep ?tab=, ?page=, ?view=, ?search= — demo-backend.js reads them
      const query = u.search && !u.searchParams.has('action') ? u.search.replace(/&/g, '&amp;') : '';
      return target ? `${attr}="${relativeTo(file, target)}${query}${hash}"` : `${attr}="#"`;
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

  // The in-browser backend (tools/static-demo/demo-backend.js) and its sample data load before the page scripts
  const scripts = `<script src="${toRoot}/demo-data.js"></script>
<script src="${toRoot}/demo-backend.js"></script>`;
  return html.replace(/<head>/i, '<head>\n' + scripts);
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
  fs.copyFileSync(path.join(__dirname, 'static-demo', 'demo-backend.js'), path.join(OUT, 'demo-backend.js'));
  // Sample data from the demo database (export_demo_data.php refuses to run outside demo mode).
  // PHP_BIN / PHP_ARGS override the PHP command, e.g. for a portable PHP.
  const phpArgs = process.env.PHP_ARGS ? process.env.PHP_ARGS.split(' ') : [];
  const exported = execFileSync(process.env.PHP_BIN || 'php', [...phpArgs, path.join(__dirname, 'export_demo_data.php')],
    { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  fs.writeFileSync(path.join(OUT, 'demo-data.js'), exported);
  fs.writeFileSync(path.join(OUT, 'index.html'),
    '<!doctype html><meta charset="utf-8"><title>LGU2 Research Section — Portfolio Demo</title>' +
    '<meta http-equiv="refresh" content="0; url=dashboard.html"><a href="dashboard.html">Open the demo</a>\n');
  fs.writeFileSync(path.join(OUT, '.nojekyll'), '');
  console.log(`Wrote ${count} pages to ${OUT}`);
})().catch((e) => { console.error(e); process.exit(1); });
