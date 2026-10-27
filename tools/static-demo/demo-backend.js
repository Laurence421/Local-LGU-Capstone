/*
 * In-browser backend for the static GitHub Pages demo.
 *
 * GitHub Pages cannot run PHP, so this file answers the requests the pages send to the PHP endpoints,
 * using the sample data in demo-data.js. Changes are kept for this browser tab (sessionStorage): they survive
 * moving between pages and reloading, and closing the tab or clicking "Reset demo" starts fresh. Pages the
 * server used to fill in (Draft Measures tabs, Accounts, dashboard
 * counters) are redrawn from that data by cloning the rows and cards already in the page, so they look
 * exactly like the real system.
 */
(function () {
  'use strict';

  var STORE_KEY = 'lgu2-demo-data-v1';
  var FLASH_KEY = 'lgu2-demo-flash';
  var DEMO_EMAIL = 'demo@example.com';
  var READINGS = ['1st Reading', '2nd Reading', '3rd Reading'];
  var PER_PAGE = 5;

  var page = (location.pathname.split('/').pop() || 'index.html').replace(/\.html$/, '');
  var params = new URLSearchParams(location.search);

  // ------------------------------------------------------------------ data store
  function freshData() {
    return JSON.parse(JSON.stringify(window.DEMO_DATA || {}));
  }
  var db;
  try {
    db = JSON.parse(sessionStorage.getItem(STORE_KEY)) || freshData();
  } catch (e) {
    db = freshData();
  }
  ['measures', 'reviews', 'requests', 'users', 'activity', 'docketing'].forEach(function (k) {
    if (!Array.isArray(db[k])) db[k] = [];
  });

  function save() {
    try { sessionStorage.setItem(STORE_KEY, JSON.stringify(db)); } catch (e) { /* storage full or blocked */ }
  }
  function resetDemo() {
    try { sessionStorage.removeItem(STORE_KEY); } catch (e) { /* ignore */ }
    flashNext('The demo data has been reset.');
    location.href = location.pathname;
  }
  window.resetLguDemo = resetDemo;

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function now() {
    var d = new Date();
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }
  function today() { return now().slice(0, 10); }
  function nextId(list, key) {
    return list.reduce(function (max, r) { return Math.max(max, +r[key] || 0); }, 0) + 1;
  }
  function me() {
    return db.users.filter(function (u) { return u.email === DEMO_EMAIL; })[0] || { id: 1, username: 'demo', fullname: 'Demo Administrator' };
  }
  function logActivity(type, description) {
    var user = me();
    db.activity.unshift({ id: Date.now(), user_id: user.id, username: user.username, action_type: type, action_description: description, created_at: now() });
  }
  function measureById(id) {
    return db.measures.filter(function (m) { return String(m.m9_SC_ID) === String(id); })[0];
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function timeAgo(datetime) {
    var seconds = (Date.now() - new Date(String(datetime).replace(' ', 'T')).getTime()) / 1000;
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) { var m = Math.floor(seconds / 60); return m + ' minute' + (m > 1 ? 's' : '') + ' ago'; }
    if (seconds < 86400) { var h = Math.floor(seconds / 3600); return h + ' hour' + (h > 1 ? 's' : '') + ' ago'; }
    if (seconds < 604800) { var d = Math.floor(seconds / 86400); return d + ' day' + (d > 1 ? 's' : '') + ' ago'; }
    return String(datetime).slice(0, 10);
  }

  // ------------------------------------------------------------------ notices
  function toast(text, isError) {
    var show = function () {
      var t = document.createElement('div');
      t.textContent = text;
      t.setAttribute('role', 'status');
      t.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;max-width:90%;' +
        'padding:12px 18px;border-radius:10px;color:#fff;font:14px Montserrat,system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25);' +
        'background:' + (isError ? 'var(--brand-accent,#cc192b)' : 'var(--brand-primary,#1a4d64)');
      document.body.appendChild(t);
      setTimeout(function () { t.remove(); }, 4500);
    };
    if (document.body) show(); else document.addEventListener('DOMContentLoaded', show);
  }
  function flashNext(text) {
    try { sessionStorage.setItem(FLASH_KEY, text); } catch (e) { /* ignore */ }
  }

  // ------------------------------------------------------------------ endpoint handlers
  function json(body) {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
  }
  function bodyJson(req) {
    try { return typeof req.body === 'string' ? JSON.parse(req.body) : {}; } catch (e) { return {}; }
  }
  function bodyForm(req) {
    if (req.body instanceof FormData) return req.body;
    if (typeof req.body === 'string') return new URLSearchParams(req.body);
    return new URLSearchParams();
  }

  var routes = {
    'save_review.php': function (req) {
      var stages = ['1st_review', '2nd_review', 'final_review'];
      if (req.method === 'GET') {
        var id = req.url.searchParams.get('m9_SC_ID');
        var list = db.reviews.filter(function (r) { return String(r.m9_SC_ID) === String(id); })
          .sort(function (a, b) { return stages.indexOf(a.review_stage) - stages.indexOf(b.review_stage); });
        return json({ success: true, reviews: list, total_reviews: list.length, is_completed: list.length === 3 });
      }
      if (req.method === 'DELETE') {
        var delId = bodyForm(req).get('m9_SC_ID');
        var before = db.reviews.length;
        db.reviews = db.reviews.filter(function (r) { return String(r.m9_SC_ID) !== String(delId); });
        logActivity('review_reset', 'Reset all reviews for measure ID: ' + delId);
        save();
        return json({ success: true, message: 'All reviews have been reset successfully', deleted_count: before - db.reviews.length });
      }
      var input = bodyJson(req);
      var stage = input.review_stage;
      if (!measureById(input.m9_SC_ID)) return json({ success: false, message: 'Invalid measure ID' });
      if (stages.indexOf(stage) < 0) return json({ success: false, message: 'Invalid review stage' });
      if (!String(input.review_notes || '').trim()) return json({ success: false, message: 'Review notes are required' });
      var done = db.reviews.filter(function (r) { return String(r.m9_SC_ID) === String(input.m9_SC_ID); }).map(function (r) { return r.review_stage; });
      if (done.indexOf(stage) >= 0) return json({ success: false, message: 'This review stage has already been completed' });
      if (stage === '2nd_review' && done.indexOf('1st_review') < 0) return json({ success: false, message: 'Please complete 1st Review before proceeding to 2nd Review' });
      if (stage === 'final_review' && (done.indexOf('1st_review') < 0 || done.indexOf('2nd_review') < 0)) {
        return json({ success: false, message: 'Please complete 1st Review and 2nd Review before proceeding to Final Review' });
      }
      db.reviews.push({ m9_SC_ID: +input.m9_SC_ID, review_stage: stage, review_notes: input.review_notes, reviewed_by: me().fullname, reviewed_at: now() });
      logActivity('review', 'Completed ' + stage.replace('_', ' ') + ' for measure ID: ' + input.m9_SC_ID);
      save();
      return json({ success: true, message: 'Review saved successfully' });
    },

    'generate_pdf.php': function (req) {
      var form = bodyForm(req);
      var measure = measureById(form.get('m9_SC_ID') || form.get('measure_id')) || {};
      var title = form.get('measure_title') || form.get('title') || measure.measure_title || 'Measure';
      var content = form.get('measure_content') || form.get('content') || measure.measure_content || '';
      return new Response(simplePdf(title, content), { status: 200, headers: { 'Content-Type': 'application/pdf' } });
    },

    'verify_password.php': function () {
      return json({ success: true, message: 'Password verified' }); // screen lock: any password unlocks the demo
    },

    'fetch_activity_history.php': function () {
      var list = db.activity.slice(0, 100).map(function (a) {
        return { id: a.id, user_id: a.user_id, username: a.username, action_type: a.action_type, action_description: a.action_description, ip_address: '127.0.0.1', created_at: a.created_at, time_ago: timeAgo(a.created_at) };
      });
      return json({ success: true, activities: list, total: list.length });
    },

    'accounts_list.php': function (req) {
      var action = req.url.searchParams.get('action');
      var id = req.url.searchParams.get('id');
      var user = db.users.filter(function (u) { return String(u.id) === String(id); })[0];
      switch (action) {
        case 'get_user':
          return json(user ? { id: user.id, fullname: user.fullname, username: user.username, email: user.email, user_type: user.user_type, avatar_url: user.avatar_url } : { error: 'User not found.' });
        case 'update_user': {
          var data = bodyJson(req);
          var target = db.users.filter(function (u) { return String(u.id) === String(data.id); })[0];
          if (!target) return json({ success: false, message: 'User not found.' });
          if (!data.fullname || !data.username || !data.email || !data.user_type) {
            return json({ success: false, message: 'Full name, username, email, and user type are required.' });
          }
          if (target.email === DEMO_EMAIL && data.email !== DEMO_EMAIL) {
            return json({ success: false, message: 'The demo account keeps its email address.' });
          }
          target.fullname = data.fullname; target.username = data.username; target.email = data.email; target.user_type = data.user_type;
          if (data.avatar_url) target.avatar_url = data.avatar_url;
          logActivity('update', 'Updated account ' + data.username);
          save();
          return json({ success: true, message: 'User updated successfully.' });
        }
        case 'archive_user':
        case 'restore_user':
        case 'delete_user':
          if (!user) return json({ success: false, message: 'User not found.' });
          if (user.email === DEMO_EMAIL) return json({ success: false, message: 'The demo account cannot be archived or deleted.' });
          if (action === 'delete_user') {
            db.users = db.users.filter(function (u) { return u !== user; });
          } else {
            user.archived = action === 'archive_user' ? 1 : 0;
          }
          logActivity(action.split('_')[0], (action === 'delete_user' ? 'Deleted' : action === 'archive_user' ? 'Archived' : 'Restored') + ' account ' + user.username);
          save();
          return json({ success: true, message: action === 'delete_user' ? 'User deleted permanently.' : action === 'archive_user' ? 'User archived successfully.' : 'User restored successfully.' });
        case 'upload_avatar': {
          var file = bodyForm(req).get('avatar');
          if (!file || !file.size) return json({ success: false, message: 'No file uploaded or upload error.' });
          if (file.size > 400000) return json({ success: false, message: 'In the demo, avatars must be under 400 KB.' });
          return new Promise(function (resolve) {
            var reader = new FileReader();
            reader.onload = function () { resolve(json({ success: true, avatar_url: reader.result })); };
            reader.readAsDataURL(file);
          });
        }
      }
      return json({ success: false, message: 'Unknown action.' });
    },

    'document_management.php': function (req) {
      var action = req.url.searchParams.get('action');
      if (action === 'get_requests') {
        var mine = db.requests.filter(function (r) { return String(r.user_id) === String(me().id); })
          .sort(function (a, b) { return String(b.request_date).localeCompare(String(a.request_date)); });
        return json({ success: true, data: mine });
      }
      if (action === 'submit_request') {
        var data = bodyJson(req);
        if (!String(data.email || '').trim() || !String(data.measure_title || '').trim() || !String(data.request_reason || '').trim()) {
          return json({ success: false, message: 'All fields are required.' });
        }
        db.requests.push({
          id: nextId(db.requests, 'id'), user_id: me().id, username: data.username || me().fullname, email: data.email,
          measure_title: data.measure_title, request_reason: data.request_reason, status: 'Pending',
          request_date: now(), updated_at: now(), admin_notes: null,
        });
        logActivity('create', 'Requested a copy of "' + data.measure_title + '"');
        save();
        return json({ success: true, message: 'Document request submitted successfully!' });
      }
      return json({ success: false, message: 'Unknown action.' });
    },

    'document_sender.php': function (req) {
      var action = req.url.searchParams.get('action');
      if (action === 'get_requests') {
        var all = db.requests.slice().sort(function (a, b) { return String(b.request_date).localeCompare(String(a.request_date)); });
        return json({ success: true, data: all });
      }
      var data = action === 'update_status' ? bodyJson(req) : Object.fromEntries(bodyForm(req));
      var request = db.requests.filter(function (r) { return String(r.id) === String(data.request_id); })[0];
      if (!request) return json({ success: false, message: 'Request not found' });
      if (action === 'update_status') {
        request.status = data.status; request.admin_notes = data.admin_notes || ''; request.updated_at = now();
        logActivity('update', 'Set document request #' + request.id + ' to ' + data.status);
        save();
        return json({ success: true, message: 'Request updated successfully' });
      }
      if (action === 'upload_and_send') {
        request.status = 'Completed'; request.admin_notes = data.admin_notes || ''; request.updated_at = now();
        logActivity('send', 'Sent the requested document to ' + request.email);
        save();
        return json({ success: true, message: 'Document sent successfully (demo: no email was actually sent)' });
      }
      return json({ success: false, message: 'Unknown action.' });
    },

    'data_synchronization.php': function (req) {
      var action = req.url.searchParams.get('action');
      var tables = { users: db.users, audit_logs: db.activity, document_requests: db.requests, m9_similaritychecking: db.measures, research_inbox: [] };
      if (action === 'get_sync_status') {
        var total = Object.keys(tables).reduce(function (n, t) { return n + tables[t].length; }, 0);
        return json({ success: true, data: { total_records: total, last_sync: db.last_sync || null } });
      }
      if (action === 'sync_data' || action === 'import_data') {
        var result = {};
        Object.keys(tables).forEach(function (t) { result[t] = { status: 'success', deleted: 0, inserted: tables[t].length, count: tables[t].length }; });
        db.last_sync = now();
        save();
        return json({ success: true, message: 'Synchronization completed successfully (demo: simulated)', data: result });
      }
      if (action === 'export_cloud_data') {
        var exported = {};
        Object.keys(tables).forEach(function (t) { exported[t] = { exists: true, count: tables[t].length, rows: tables[t] }; });
        return json({ success: true, message: 'Cloud data exported successfully', export_date: now(), tables: exported });
      }
      return json({ success: false, message: 'Unknown action.' });
    },
  };

  /** Smallest valid one-page PDF with the measure's title and text (stands in for the TCPDF export). */
  function simplePdf(title, content) {
    function pdfText(s) { return String(s).replace(/[^\x20-\x7E]/g, ' ').replace(/([\\()])/g, '\\$1'); }
    var lines = [];
    String(content).split(/\n/).forEach(function (para) {
      var words = para.split(/\s+/), line = '';
      words.forEach(function (w) {
        if ((line + ' ' + w).length > 90) { lines.push(line); line = w; } else { line = line ? line + ' ' + w : w; }
      });
      lines.push(line);
    });
    var stream = 'BT /F1 14 Tf 50 790 Td (' + pdfText(title).slice(0, 80) + ') Tj /F1 10 Tf 0 -26 Td';
    lines.slice(0, 60).forEach(function (l) { stream += ' (' + pdfText(l) + ') Tj 0 -13 Td'; });
    stream += ' 0 -13 Td (Generated by the LGU2 portfolio demo) Tj ET';
    var objects = [
      '<< /Type /Catalog /Pages 2 0 R >>',
      '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
      '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
      '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
      '<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream',
    ];
    var pdf = '%PDF-1.4\n', offsets = [];
    objects.forEach(function (o, i) { offsets.push(pdf.length); pdf += (i + 1) + ' 0 obj\n' + o + '\nendobj\n'; });
    var xref = pdf.length;
    pdf += 'xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n';
    offsets.forEach(function (o) { pdf += String(o).padStart(10, '0') + ' 00000 n \n'; });
    pdf += 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF';
    return new Blob([pdf], { type: 'application/pdf' });
  }

  /** Which endpoint a URL targets: "x.php", or this page's ".php" name for "?action=..." calls. */
  function endpointFor(url) {
    var file = url.pathname.split('/').pop();
    if (/\.php$/.test(file)) return file;
    if (/\.html$/.test(file) && url.searchParams.has('action')) return file.replace(/\.html$/, '.php');
    return null;
  }

  function handle(url, method, body) {
    var endpoint = endpointFor(url);
    var route = routes[endpoint];
    if (!route) {
      return Promise.resolve(json({ success: false, demo: true, message: 'This action is not available in the static demo.' }));
    }
    return Promise.resolve(route({ url: url, method: (method || 'GET').toUpperCase(), body: body }));
  }

  // ------------------------------------------------------------------ fetch / XHR
  var realFetch = window.fetch;
  window.fetch = function (input, init) {
    var url = new URL(typeof input === 'string' ? input : (input && input.url) || '', location.href);
    if (url.origin === location.origin && endpointFor(url)) {
      return handle(url, (init && init.method) || (input && input.method), init && init.body);
    }
    return realFetch.apply(this, arguments);
  };

  var xhrOpen = XMLHttpRequest.prototype.open;
  var xhrSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, rawUrl) {
    var url = new URL(rawUrl, location.href);
    this._demo = url.origin === location.origin && endpointFor(url) ? { url: url, method: method } : null;
    return xhrOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function (body) {
    if (!this._demo) return xhrSend.apply(this, arguments);
    var xhr = this;
    handle(this._demo.url, this._demo.method, body).then(function (res) { return res.text(); }).then(function (text) {
      ['readyState', 'status', 'responseText', 'response'].forEach(function (k, i) {
        Object.defineProperty(xhr, k, { configurable: true, value: [4, 200, text, text][i] });
      });
      if (xhr.onreadystatechange) xhr.onreadystatechange();
      if (xhr.onload) xhr.onload();
      xhr.dispatchEvent(new Event('load'));
    });
  };

  // ------------------------------------------------------------------ form posts the server used to handle
  var formHandlers = {
    sendTask: function (form) {
      var data = new FormData(form);
      var m = measureById(data.get('m9_SC_ID'));
      if (!m) return toast('Measure not found.', true);
      m.measure_status = 'In Review for Docketing';
      m.checking_remarks_pending = data.get('checking_remarks_pending') || m.checking_remarks_pending;
      m.checking_remarks_approved = data.get('checking_remarks_approved') || m.checking_remarks_approved;
      m.checking_notes = data.get('checking_notes') || m.checking_notes;
      m.checked_by = data.get('checked_by') || me().fullname;
      m.datetime_submitted = now();
      m.sync_status = 'synced';
      if (!m.docket_no) m.docket_no = 'DKT-' + today().slice(0, 4) + '-' + String(m.m9_SC_ID).padStart(3, '0');
      db.docketing.push({
        m6_MD_ID: nextId(db.docketing, 'm6_MD_ID'), m6_MD_Code: 'MD-' + String(db.docketing.length + 1).padStart(3, '0'),
        m9_SC_ID: m.m9_SC_ID, date_created: m.date_created, measure_type: String(m.measure_type).toLowerCase(), measure_title: m.measure_title,
        measure_content: m.measure_content, introducers: m.introducers, measure_status: 'pending', checking_remarks: 'Complete requirements',
        checking_notes: m.checking_notes, checked_by: m.checked_by, datetime_submitted: m.datetime_submitted, docket_no: m.docket_no,
        MFL_Name: 'Records Section', MFL_Feedback: 'Received from the Research Section',
      });
      logActivity('submit', "Measure '" + m.measure_title + "' submitted for review (ID: " + m.m9_SC_ID + ')');
      save();
      flashNext('Task successfully updated and sent to Records. (Demo: kept in your browser.)');
      location.href = location.pathname + '?tab=pending-measures';
    },
    retrieveTask: function (form) {
      var m = measureById(new FormData(form).get('m9_SC_ID'));
      if (!m) return toast('Measure not found.', true);
      m.measure_status = 'Draft';
      m.datetime_submitted = null;
      logActivity('update', "Measure '" + m.measure_title + "' retrieved back to Incoming Task");
      save();
      flashNext('Measure retrieved back to Incoming Task.');
      location.href = location.pathname + '?tab=incoming';
    },
    createAccount: function (form) {
      var data = new FormData(form);
      var username = String(data.get('username') || '').trim();
      var email = String(data.get('email') || '').trim().toLowerCase();
      if (!username || !email || !data.get('fullname')) return toast('Please fill in every field.', true);
      if (db.users.some(function (u) { return u.username === username || u.email === email; })) {
        return toast('Username or email already exists. Please choose different credentials.', true);
      }
      db.users.push({ id: nextId(db.users, 'id'), fullname: data.get('fullname'), username: username, email: email,
        user_type: data.get('user_type') || 'research', avatar_url: 'assets/img/default-avatar.png', date_created: now(), archived: 0 });
      logActivity('create', 'Created account ' + username);
      save();
      flashNext('New account created successfully! It now appears in the Accounts List.');
      location.href = 'accounts_list.html';
    },
    saveProfile: function (form) {
      var data = new FormData(form);
      var user = me();
      user.username = data.get('username') || user.username;
      logActivity('update', 'Updated profile settings');
      save();
      toast('Settings updated successfully. (Demo: the password is not changed.)');
    },
    saveDocket: function (form) {
      var data = new FormData(form);
      var d = db.docketing.filter(function (r) { return String(r.m6_MD_ID) === String(data.get('measure_id')); })[0];
      if (d) { d.docket_no = data.get('docket_number'); d.measure_status = 'docketed'; }
      logActivity('update', 'Docketed measure as ' + data.get('docket_number'));
      save();
      toast('Docket saved. (Demo: kept in your browser.)');
      var modal = form.closest('.modal');
      if (modal && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
    },
  };

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return; // GET forms navigate normally
    e.preventDefault();
    var submitter = e.submitter;
    if (form.querySelector('[name="retrieve_task"]')) return formHandlers.retrieveTask(form);
    if ((submitter && submitter.name === 'send_task') || form.querySelector('[name="send_task"]')) return formHandlers.sendTask(form);
    if (page === 'create_account') return formHandlers.createAccount(form);
    if (page === 'profile') return formHandlers.saveProfile(form);
    if (form.querySelector('[name="docket_number"]')) return formHandlers.saveDocket(form);
    toast('Saved. (Demo: kept in your browser.)');
  }, true);

  // ------------------------------------------------------------------ redraw server-rendered pages
  function setTemplateRow(row, m, headers) {
    var clone = row.cloneNode(true);
    var oldJson = null;
    clone.querySelectorAll('*').forEach(function (el) {
      ['data-measure', 'data-measure-send'].forEach(function (a) {
        if (!oldJson && el.hasAttribute(a)) { try { oldJson = JSON.parse(el.getAttribute(a)); } catch (e) { /* ignore */ } }
      });
    });
    var oldId = oldJson ? String(oldJson.m9_SC_ID) : null;
    var oldTitle = oldJson ? oldJson.measure_title : null;
    var idPattern = oldId ? new RegExp('(^|[^0-9])' + oldId + '(?![0-9])', 'g') : null;

    // Attributes: the record JSON, the id in onclick/ids, the title in onclick arguments
    clone.querySelectorAll('*').forEach(function (el) {
      Array.prototype.slice.call(el.attributes).forEach(function (attr) {
        if (/^data-measure/.test(attr.name) && attr.value.charAt(0) === '{') {
          el.setAttribute(attr.name, JSON.stringify(m));
          return;
        }
        var v = attr.value;
        if (oldTitle && v.indexOf(oldTitle) >= 0) v = v.split(oldTitle).join(String(m.measure_title).replace(/"/g, '\\"'));
        if (idPattern) v = v.replace(idPattern, '$1' + m.m9_SC_ID);
        if (v !== attr.value) el.setAttribute(attr.name, v);
      });
    });

    // Cells, by column heading
    Array.prototype.forEach.call(clone.children, function (td, i) {
      var head = headers[i] || '';
      var cut = function (s, n) {
        s = String(s || '');
        return esc(s.slice(0, n)) + (s.length > n ? "<span style='color: #6c757d;'>...</span>" : '');
      };
      switch (head) {
        case 'Date Created': td.textContent = m.date_created || ''; break;
        case 'Title': {
          var title = String(m.measure_title || '');
          td.innerHTML = '<strong>' + esc(title.slice(0, 50)) + '</strong>' + (title.length > 50 ? "<span style='color: #6c757d;'>...</span>" : '');
          break;
        }
        case 'Introducers': td.innerHTML = cut(m.introducers, 30); break;
        case 'Measure Type': td.textContent = m.measure_type || ''; break;
        case 'Date & Time Submitted': td.textContent = m.datetime_submitted || ''; break;
        case 'Docket No.': td.textContent = m.docket_no || ''; break;
        case 'SP No.': td.textContent = m.sp_no || ''; break;
        case 'Checking Remarks in Pending Measures': td.innerHTML = cut(m.checking_remarks_pending, 40); break;
        case 'Checking Remarks in Approved Measures': td.innerHTML = cut(m.checking_remarks_approved, 40); break;
        case 'Measure Status': {
          var span = td.querySelector('span');
          if (span) span.textContent = m.measure_status; else td.textContent = m.measure_status;
          break;
        }
      }
    });

    // Completed reviews show a filled icon
    var icon = clone.querySelector('.review-icon');
    if (icon) {
      var count = db.reviews.filter(function (r) { return String(r.m9_SC_ID) === String(m.m9_SC_ID); }).length;
      icon.classList.toggle('completed', count === 3);
    }
    return clone;
  }

  function renderDraftMeasures() {
    var tabs = [
      { id: 'incoming', match: function (m) { return m.measure_status === 'Draft'; }, sort: 'date_created' },
      { id: 'pending-measures', match: function (m) { return m.measure_status === 'In Review for Docketing'; } },
      { id: 'ordinances', match: function (m) { return m.measure_type === 'Ordinance' && READINGS.indexOf(m.measure_status) >= 0; } },
      { id: 'resolutions', match: function (m) { return m.measure_type === 'Resolution' && READINGS.indexOf(m.measure_status) >= 0; } },
      { id: 'enacted-ordinances', match: function (m) { return m.measure_type === 'Ordinance' && m.measure_status === 'Enacted'; } },
      { id: 'enacted-resolutions', match: function (m) { return m.measure_type === 'Resolution' && m.measure_status === 'Enacted'; } },
    ];
    var activeTab = params.get('tab') || 'incoming';
    var search = (params.get('search') || '').trim().toLowerCase();
    var category = params.get('category') || '';

    tabs.forEach(function (tab) {
      var tableBox = document.getElementById(tab.id + '-table');
      if (!tableBox) return;
      var headers = Array.prototype.map.call(tableBox.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
      var tbody = tableBox.querySelector('tbody');
      var template = Array.prototype.filter.call(tbody.rows, function (r) { return r.cells.length === headers.length; })[0];
      if (!template) return;

      var isActive = tab.id === activeTab;
      var rows = db.measures.filter(tab.match).filter(function (m) {
        if (!isActive) return true;
        var titleOk = !search || search.split(/\s+/).some(function (t) { return String(m.measure_title).toLowerCase().indexOf(t) >= 0; });
        return titleOk && (!category || m.category === category);
      });
      rows.sort(function (a, b) {
        var key = tab.sort || 'datetime_submitted';
        return String(b[key] || b.date_created).localeCompare(String(a[key] || a.date_created));
      });

      var pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
      var current = isActive ? Math.min(pages, Math.max(1, parseInt(params.get('page') || '1', 10))) : 1;
      var shown = rows.slice((current - 1) * PER_PAGE, current * PER_PAGE);

      tbody.innerHTML = '';
      if (!shown.length) {
        tbody.innerHTML = '<tr><td colspan="' + headers.length + '">No records found.</td></tr>';
      }
      shown.forEach(function (m) { tbody.appendChild(setTemplateRow(template, m, headers)); });

      // Pagination under this tab's table
      var pager = tableBox.nextElementSibling;
      while (pager && !pager.classList.contains('pagination-container')) pager = pager.nextElementSibling;
      if (pager) {
        var link = function (n) {
          var q = new URLSearchParams({ tab: tab.id, page: n });
          if (isActive && search) q.set('search', params.get('search'));
          if (isActive && category) q.set('category', category);
          return '?' + q.toString();
        };
        var html = '<span>Page ' + current + ' of ' + pages + '</span><nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
        html += '<li class="page-item ' + (current <= 1 ? 'disabled' : '') + '"><a class="page-link" href="' + link(current - 1) + '" aria-label="Previous"><span aria-hidden="true">&laquo;</span></a></li>';
        for (var i = 1; i <= pages; i++) {
          html += '<li class="page-item ' + (i === current ? 'active' : '') + '"><a class="page-link" href="' + link(i) + '">' + i + '</a></li>';
        }
        html += '<li class="page-item ' + (current >= pages ? 'disabled' : '') + '"><a class="page-link" href="' + link(current + 1) + '" aria-label="Next"><span aria-hidden="true">&raquo;</span></a></li></ul></nav>';
        pager.innerHTML = html;
      }

      // Keep the filter form showing what is applied
      var pane = document.getElementById(tab.id);
      if (pane && isActive) {
        var s = pane.querySelector('input[name="search"]');
        var c = pane.querySelector('select[name="category"]');
        if (s) s.value = params.get('search') || '';
        if (c) c.value = category;
      }
    });

    // Open the tab the URL asks for (Bootstrap loads at the end of the page)
    if (params.get('tab')) {
      window.addEventListener('load', function () {
        var btn = document.getElementById(activeTab + '-tab');
        if (btn && window.bootstrap) window.bootstrap.Tab.getOrCreateInstance(btn).show();
      });
    }
  }

  function renderAccounts() {
    var grid = document.querySelector('.accounts-grid');
    if (!grid) return;
    var template = grid.querySelector('.account-card');
    if (!template) return;
    template = template.cloneNode(true);

    var view = params.get('view') === 'archived' ? 'archived' : 'active';
    var search = (params.get('search') || '').trim().toLowerCase();
    var users = db.users.filter(function (u) { return (+u.archived ? 'archived' : 'active') === view; })
      .filter(function (u) {
        return !search || [u.fullname, u.username, u.email, u.user_type].some(function (v) { return String(v || '').toLowerCase().indexOf(search) >= 0; });
      })
      .sort(function (a, b) { return String(b.date_created).localeCompare(String(a.date_created)); });

    grid.innerHTML = '';
    users.forEach(function (u) {
      var card = template.cloneNode(true);
      var img = card.querySelector('.avatar-container img');
      if (img && /^data:/.test(u.avatar_url || '')) img.src = u.avatar_url;
      card.querySelector('.username').textContent = u.fullname || u.username;
      card.querySelector('.user-type').textContent = u.user_type;
      card.querySelector('.email').textContent = '📧 ' + u.email;
      var created = new Date(String(u.date_created).replace(' ', 'T'));
      card.querySelector('.date-created').textContent = '📅 Created: ' + created.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
        ' at ' + created.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
      var name = esc(String(u.username).replace(/'/g, "\\'"));
      card.querySelector('.card-actions').innerHTML = view === 'archived'
        ? '<button class="action-btn restore-btn" onclick="restoreUser(' + u.id + ', \'' + name + '\')">♻️ Restore</button>' +
          '<button class="action-btn delete-btn" onclick="deleteUser(' + u.id + ', \'' + name + '\')">🗑️ Delete</button>'
        : '<button class="action-btn edit-btn" onclick="editUser(' + u.id + ')">✏️ Edit</button>' +
          '<button class="action-btn archive-btn" onclick="archiveUser(' + u.id + ', \'' + name + '\')">📦 Archive</button>';
      grid.appendChild(card);
    });
    if (!users.length) {
      grid.innerHTML = '<div class="empty-state"><div class="empty-state-icon">👤</div><h3>' +
        (search ? 'No accounts found' : view === 'archived' ? 'No archived accounts' : 'No accounts yet') + '</h3></div>';
    }

    var pagination = document.querySelector('.pagination');
    if (pagination) pagination.style.display = 'none';
    var total = document.querySelector('.stats-info span');
    if (total) total.textContent = '📊 Total: ' + users.length + ' accounts';
    document.querySelectorAll('.toggle-btn').forEach(function (a) {
      a.classList.toggle('active', (a.getAttribute('href') || '').indexOf('view=' + view) >= 0);
    });
    var searchInput = document.querySelector('.search-container input[name="search"]');
    if (searchInput) searchInput.value = params.get('search') || '';
    var viewInput = document.querySelector('.search-container input[name="view"]');
    if (viewInput) viewInput.value = view;
  }

  function renderDashboard() {
    var count = function (fn) { return db.measures.filter(fn).length; };
    var draft = count(function (m) { return m.measure_status === 'Draft'; });
    var pending = count(function (m) { return m.measure_status === 'In Review for Docketing'; });
    var ord = count(function (m) { return m.measure_type === 'Ordinance' && READINGS.indexOf(m.measure_status) >= 0; });
    var res = count(function (m) { return m.measure_type === 'Resolution' && READINGS.indexOf(m.measure_status) >= 0; });
    var eOrd = count(function (m) { return m.measure_type === 'Ordinance' && m.measure_status === 'Enacted'; });
    var eRes = count(function (m) { return m.measure_type === 'Resolution' && m.measure_status === 'Enacted'; });
    var values = [draft, pending, ord, res, eOrd, eRes, draft + pending + ord + res, db.measures.length];
    document.querySelectorAll('.dashboard-count').forEach(function (el, i) {
      if (i < values.length) el.textContent = values[i];
    });
  }

  // Search boxes on pages the server used to filter: filter the rows already on the page
  function filterRowsBySearch() {
    var q = (params.get('search') || '').trim().toLowerCase();
    if (!q) return;
    document.querySelectorAll('table tbody tr').forEach(function (tr) {
      tr.style.display = tr.textContent.toLowerCase().indexOf(q) >= 0 ? '' : 'none';
    });
    var input = document.querySelector('input[name="search"]');
    if (input) input.value = params.get('search');
  }

  function decorateBanner() {
    var banner = document.querySelector('.demo-banner');
    if (!banner) return;
    banner.innerHTML = '<i class="fas fa-circle-info me-1"></i> Portfolio demo with sample data: your changes last until you close this tab. ' +
      '<a href="#" style="color: inherit; font-weight: 600; text-decoration: underline;" onclick="resetLguDemo(); return false;">Reset demo</a>';
  }

  document.addEventListener('DOMContentLoaded', function () {
    decorateBanner();
    if (page === 'draftmeasurestask') renderDraftMeasures();
    else if (page === 'accounts_list') renderAccounts();
    else if (page === 'dashboard') renderDashboard();
    else filterRowsBySearch();

    var flash = null;
    try { flash = sessionStorage.getItem(FLASH_KEY); sessionStorage.removeItem(FLASH_KEY); } catch (e) { /* ignore */ }
    if (flash) toast(flash);
  });
})();
