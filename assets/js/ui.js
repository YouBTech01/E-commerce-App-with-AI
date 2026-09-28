/* ExamLegacy — UI toolkit: theme, toasts, sheets, router, shared components. */
window.EL = window.EL || {};

EL.ui = (function () {
  /* ---------------- Theme (light / dark / system) ---------------- */
  var THEME_KEY = 'el_theme';
  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }
  function applyTheme() {
    var pref = localStorage.getItem(THEME_KEY) || 'system';
    var dark = pref === 'dark' || (pref === 'system' && systemPrefersDark());
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  }
  function setTheme(pref) { localStorage.setItem(THEME_KEY, pref); applyTheme(); }
  function getTheme() { return localStorage.getItem(THEME_KEY) || 'system'; }
  if (window.matchMedia) {
    try { window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (getTheme() === 'system') applyTheme();
    }); } catch (e) {}
  }

  /* ---------------- Toasts ---------------- */
  function toast(msg, type) {
    var host = EL.qs('.toasts');
    if (!host) { host = document.createElement('div'); host.className = 'toasts'; document.body.appendChild(host); }
    var t = document.createElement('div');
    t.className = 'toast' + (type ? ' ' + type : '');
    t.textContent = msg;
    host.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; t.style.transform = 'translateY(8px)'; }, 2400);
    setTimeout(function () { t.remove(); }, 2800);
  }

  /* ---------------- Bottom sheet ---------------- */
  function sheet(opts) {
    var scrim = document.createElement('div'); scrim.className = 'scrim';
    var el = document.createElement('div'); el.className = 'sheet'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true');
    el.innerHTML =
      '<div class="grab"></div>' +
      (opts.title ? '<h3>' + EL.esc(opts.title) + '</h3>' : '') +
      (opts.sub ? '<div class="sub">' + EL.esc(opts.sub) + '</div>' : '') +
      '<div class="sheet-body"></div>' +
      (opts.actions ? '<div class="sheet-actions mt-16"></div>' : '');
    var body = EL.qs('.sheet-body', el);
    if (typeof opts.body === 'string') body.innerHTML = opts.body;
    else if (opts.body) body.appendChild(opts.body);

    if (opts.actions) {
      var actHost = EL.qs('.sheet-actions', el);
      opts.actions.forEach(function (a) {
        var b = document.createElement('button');
        b.className = 'btn block ' + (a.variant || '');
        b.textContent = a.label;
        b.addEventListener('click', function () { var r = a.onClick && a.onClick(close); if (a.keepOpen !== true) close(); return r; });
        actHost.appendChild(b);
      });
    }
    function close() {
      el.classList.remove('show'); scrim.classList.remove('show');
      setTimeout(function () { el.remove(); scrim.remove(); }, 320);
    }
    scrim.addEventListener('click', function () { if (opts.dismissOnScrim !== false) close(); });
    document.body.appendChild(scrim); document.body.appendChild(el);
    requestAnimationFrame(function () { scrim.classList.add('show'); el.classList.add('show'); });
    return { el: el, close: close, body: body };
  }

  function confirm(opts) {
    return new Promise(function (resolve) {
      var s = sheet({
        title: opts.title || 'Are you sure?',
        sub: opts.message || '',
        actions: [
          { label: opts.cancelText || 'Cancel', variant: 'ghost', onClick: function () { resolve(false); } },
          { label: opts.confirmText || 'Confirm', variant: opts.danger ? 'danger' : '', onClick: function () { resolve(true); } }
        ]
      });
      void s;
    });
  }

  /* ---------------- Icons (Font Awesome) ---------------- */
  function icon(name, cls) { return '<i class="' + (cls ? cls + ' ' : '') + 'fas fa-' + name + '"></i>'; }

  /* ---------------- States ---------------- */
  function loading(n) {
    var out = '';
    for (var i = 0; i < (n || 3); i++) out += '<div class="skel thumb"></div>';
    return '<div class="grid cols-2">' + out + '</div>';
  }
  function empty(opts) {
    return '<div class="empty"><div class="ic">' + icon(opts.icon || 'inbox') + '</div>' +
      '<h3>' + EL.esc(opts.title || 'Nothing here') + '</h3>' +
      (opts.text ? '<p>' + EL.esc(opts.text) + '</p>' : '') +
      (opts.actionLabel ? '<button class="btn soft" data-act="' + EL.esc(opts.action || '') + '">' + EL.esc(opts.actionLabel) + '</button>' : '') +
      '</div>';
  }
  function errorBox(msg) { return '<div class="alert err">' + icon('circle-exclamation') + '<div>' + EL.esc(msg) + '</div></div>'; }

  /* ---------------- Mini markdown (AI answers) ----------------
     Escapes HTML FIRST, then applies a safe subset: headings, bold/italic,
     inline code, fenced code blocks, bullet & numbered lists, line breaks. */
  function md(src) {
    var escd = EL.esc(String(src == null ? '' : src));
    var blocks = [];
    // pull out fenced code blocks so they are never re-processed
    escd = escd.replace(/```([\s\S]*?)```/g, function (_, code) {
      blocks.push('<pre><code>' + code.replace(/^\n+|\n+$/g, '') + '</code></pre>');
      return '\u0000CB' + (blocks.length - 1) + '\u0000';
    });
    var lines = escd.split('\n');
    var out = [];
    var inList = false;
    var listType = '';
    function closeList() { if (inList) { out.push('</' + listType + '>'); inList = false; listType = ''; } }
    for (var i = 0; i < lines.length; i++) {
      var ln = lines[i];
      var m = ln.match(/^\u0000CB(\d+)\u0000$/);
      if (m) { closeList(); out.push(blocks[+m[1]]); continue; }
      var h = ln.match(/^(#{1,4})\s+(.*)$/);
      if (h) { closeList(); var lvl = h[1].length + 2; out.push('<h' + lvl + '>' + inline(h[2]) + '</h' + lvl + '>'); continue; }
      var li = ln.match(/^\s*[-*]\s+(.*)$/);
      if (li) { if (!inList || listType !== 'ul') { closeList(); out.push('<ul>'); inList = true; listType = 'ul'; } out.push('<li>' + inline(li[1]) + '</li>'); continue; }
      var ol = ln.match(/^\s*\d+[.)]\s+(.*)$/);
      if (ol) { if (!inList || listType !== 'ol') { closeList(); out.push('<ol>'); inList = true; listType = 'ol'; } out.push('<li>' + inline(ol[1]) + '</li>'); continue; }
      closeList();
      if (ln.trim() === '') { out.push(''); continue; }
      out.push('<p>' + inline(ln) + '</p>');
    }
    closeList();
    var html = out.join('\n');
    return html.replace(/\u0000CB(\d+)\u0000/g, function (_, n) { return blocks[+n]; });
    function inline(t) {
      return t
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[\s(])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>')
        .replace(/(^|[\s(])_([^_\s][^_]*)_/g, '$1<em>$2</em>');
    }
  }

  /* ---------------- Router (hash based) ---------------- */
  var routes = {};
  var current = { name: 'home', params: {} };
  var afterHooks = [];

  function def(name, handler, opts) { routes[name] = { handler: handler, opts: opts || {} }; }
  function navigate(name, params) {
    var hash = '#/' + name;
    if (params && typeof params === 'object') {
      var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
      if (q) hash += '?' + q;
    }
    if (location.hash === hash) { dispatch(); } else { location.hash = hash; }
  }
  function parseHash() {
    var h = location.hash.replace(/^#\/?/, '');
    var qi = h.indexOf('?');
    var name = qi >= 0 ? h.slice(0, qi) : h;
    var params = {};
    if (qi >= 0) {
      h.slice(qi + 1).split('&').forEach(function (kv) {
        if (!kv) return; var p = kv.split('='); params[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || '');
      });
    }
    return { name: name || 'home', params: params };
  }
  function dispatch() {
    var r = parseHash();
    if (!routes[r.name]) r.name = 'home';
    current = r;
    var def_ = routes[r.name];
    var content = EL.qs('#content');
    window.scrollTo(0, 0);
    if (content) { content.scrollTop = 0; } /* anti-gravity: #content is the scroller */
    if (content) content.innerHTML = '';
    afterHooks = [];
    try { def_.handler(content, r.params); } catch (e) { console.error(e); if (content) content.innerHTML = errorBox(e.message || 'Failed to load'); }
    // Blueprint rule #2: every dispatch plays the .screen-enter view transition.
    playScreenEnter(content);
    // Staggered entrance for the route's top-level blocks (skipped under
    // prefers-reduced-motion via CSS).
    if (content) {
      Array.prototype.forEach.call(content.children, function (el, i) {
        el.style.animationDelay = Math.min(i * 45, 260) + 'ms';
        el.classList.add('rise-in');
      });
    }
    renderNav(r.name);
  }
  function onAfter(cb) { afterHooks.push(cb); }
  function start() { window.addEventListener('hashchange', dispatch); dispatch(); }

  /* Blueprint §4.1 — the 240ms view transition on every route change. */
  function playScreenEnter(el) {
    if (!el) return;
    el.classList.remove('screen-enter');
    void el.offsetWidth; // force reflow so the animation restarts
    el.classList.add('screen-enter');
  }

  /* ---------------- Navigation chrome ---------------- */
  var NAV = [
    { name: 'home', label: 'Home', icon: 'home' },
    { name: 'store', label: 'Store', icon: 'store' },
    { name: 'study', label: 'Study AI', icon: 'robot' },
    { name: 'vault', label: 'Vault', icon: 'vault' },
    { name: 'account', label: 'Account', icon: 'user' }
  ];
  function renderNav(active) {
    var nav = EL.qs('#bottomnav');
    if (nav) {
      var unread = (EL.state && EL.state.unread) || 0;
      nav.innerHTML = NAV.map(function (n) {
        var isActive = active === n.name;
        var badge = (n.name === 'account' && unread > 0) ? '<span class="badge">' + unread + '</span>' : '';
        return '<button class="navitem ' + (isActive ? 'active' : '') + '" data-nav="' + n.name + '">' +
          icon(n.icon) + '<span>' + n.label + '</span>' + badge + '</button>';
      }).join('');
      EL.qsa('[data-nav]', nav).forEach(function (b) {
        b.addEventListener('click', function () { navigate(b.getAttribute('data-nav')); });
      });
    }
    var rail = EL.qs('#siderail');
    if (rail) {
      EL.qsa('[data-nav]', rail).forEach(function (b) {
        b.classList.toggle('active', b.getAttribute('data-nav') === active);
      });
    }
  }

  /* ---------------- AI typing indicator (blueprint rule #4) ---------------- */
  function aiTyping(label) {
    return '<div class="msg ai"><img class="avatar" src="/assets/img/mark.svg" alt="">' +
      '<div class="bubble typing" role="status" aria-label="' + EL.esc(label || 'Thinking') + '">' +
      '<i></i><i></i><i></i><span>' + EL.esc(label || 'Thinking…') + '</span></div></div>';
  }

  /* ---------------- Celebration modal (blueprint §4.6) ---------------- */
  function celebration(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var ov = document.createElement('div');
      ov.className = 'celebration-overlay';
      ov.innerHTML =
        '<div class="celebration-card" role="dialog" aria-live="polite">' +
          '<div class="celebration-check"><i class="fas fa-check"></i></div>' +
          '<h3>' + EL.esc(opts.title || 'Payment successful!') + '</h3>' +
          '<p>' + EL.esc(opts.message || 'Your purchase is confirmed and unlocked.') + '</p>' +
          '<button class="btn block" data-done>' + EL.esc(opts.buttonLabel || 'Continue') + '</button>' +
        '</div>';
      function done() {
        ov.style.opacity = '0';
        ov.style.transition = 'opacity .2s ease';
        setTimeout(function () { if (ov.parentNode) ov.parentNode.removeChild(ov); }, 220);
        resolve(true);
      }
      ov.addEventListener('click', function (e) { if (e.target === ov) done(); });
      var btn = ov.querySelector('[data-done]');
      if (btn) btn.addEventListener('click', done);
      document.body.appendChild(ov);
      if (btn) btn.focus();
    });
  }

  applyTheme();
  return {
    theme: { get: getTheme, set: setTheme, apply: applyTheme },
    toast: toast, sheet: sheet, confirm: confirm, icon: icon,
    loading: loading, empty: empty, errorBox: errorBox, md: md,
    aiTyping: aiTyping, celebration: celebration,
    def: def, navigate: navigate, dispatch: dispatch, start: start,
    onAfter: onAfter, current: function () { return current; }, NAV: NAV
  };
})();
