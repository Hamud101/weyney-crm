/* Weyney CRM front-end — original layout (icon rail + two-column call card),
   with the disposition buttons and single-focus panel kept from the rebuild.
   One file, no framework, no build step: it has to be editable over SSH. */
(function () {
  'use strict';

  var app  = document.getElementById('app');
  var CSRF = app.dataset.csrf;
  var S = { view: 'dash', stage: 'new', queue: [], i: 0, boot: null, sheet: null };

  var ICON = {
    call:  '<svg viewBox="0 0 24 24"><path d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.2.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg>',
    due:   '<svg viewBox="0 0 24 24"><path d="M7 1v2h10V1h2v2h1a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H4a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h1V1zm14 9H3v10a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1zm-4.6 2.3 1.5 1.5-5.7 5.7-3.4-3.4 1.5-1.5 1.9 1.9z"/></svg>',
    soon:  '<svg viewBox="0 0 24 24"><path d="M3 4h2.5v2.5H3zm5 .25h13v2H8zM3 10.75h2.5v2.5H3zm5 .25h13v2H8zM3 17.5h2.5V20H3zm5 .25h13v2H8z"/></svg>',
    dash:  '<svg viewBox="0 0 24 24"><path d="M3 3h8v8H3zm10 0h8v5h-8zM3 13h8v8H3zm10-3h8v11h-8z"/></svg>',
    cal:'<svg viewBox="0 0 24 24"><path d="M7 1v2h10V1h2v2h1a3 3 0 0 1 3 3v14a3 3 0 0 1-3 3H4a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h1V1zm14 9H3v10a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1zM7 12h4v4H7z"/></svg>',
    clients:'<svg viewBox="0 0 24 24"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-3.3 0-7 1.7-7 4v3h14v-3c0-2.3-3.7-4-7-4zm8.5-2a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm.5 2c-.9 0-1.9.2-2.7.5 1.4.9 2.2 2.1 2.2 3.5v3h5v-3c0-2.1-2.6-4-4.5-4z"/></svg>',
    gear:  '<svg viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm0 6a2 2 0 1 1 0-4 2 2 0 0 1 0 4zm8.5-2c0-.5 0-1-.1-1.4l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-2.4-1.4L15.2 2h-4l-.4 2.6c-.9.3-1.7.8-2.4 1.4l-2.4-1-2 3.4 2 1.6a8.6 8.6 0 0 0 0 2.8l-2 1.6 2 3.4 2.4-1c.7.6 1.5 1.1 2.4 1.4l.4 2.6h4l.4-2.6c.9-.3 1.7-.8 2.4-1.4l2.4 1 2-3.4-2-1.6c.1-.4.1-.9.1-1.4z"/></svg>'
  };

  /* The URL carries the view so a refresh lands where you were, and the browser
     back button works. Queue position rides in sessionStorage instead — it is
     per-tab and shouldn't be in a shareable link. */
  function readHash() {
    var h = (location.hash || '').replace(/^#\/?/, '');
    if (!h) return null;
    var p = h.split('/');
    var v = p[0];
    if (v === 'call')   return { view: 'call', stage: p[1] || 'new' };
    if (v === 'lead' && p[1]) return { view: 'detail', id: p[1] };
    if (['dash', 'cal', 'clients'].indexOf(v) >= 0) return { view: v };
    return null;
  }
  function writeHash() {
    var h = S.view === 'call'   ? 'call/' + S.stage
          : S.view === 'detail' ? 'lead/' + (S.detail ? S.detail.id : (S.detailId || ''))
          : S.view;
    if (('#' + h) !== location.hash) {
      history.replaceState(null, '', '#' + h);
    }
  }
  function savePos() {
    try { sessionStorage.setItem('crm_pos', JSON.stringify({ stage: S.stage, i: S.i })); } catch (e) {}
  }
  function loadPos() {
    try { return JSON.parse(sessionStorage.getItem('crm_pos') || 'null'); } catch (e) { return null; }
  }

  function api(action, body, qs) {
    var opts = { headers: { 'X-CSRF': CSRF } };
    var url = '/crm/api.php?a=' + action;
    if (body) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    } else if (qs) {
      url += '&' + new URLSearchParams(qs).toString();
    }
    return fetch(url, opts).then(function (r) {
      if (r.status === 401) { location.href = '/crm/'; throw new Error('auth'); }
      return r.json();
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  /* Google Voice takes an E.164 number on its call endpoint. Clicking the
     number itself dials — a separate "Call now" button was saying it twice. */
  function dialHref(p) {
    var d = String(p).replace(/\D/g, '');
    if (d.length === 10) d = '1' + d;
    return 'https://voice.google.com/u/0/calls?a=nc,%2B' + d;
  }
  function prettyPhone(p) {
    var d = String(p).replace(/\D/g, '');
    if (d.length === 10) return d.slice(0,3) + '-' + d.slice(3,6) + '-' + d.slice(6);
    if (d.length === 11 && d[0] === '1') return d.slice(1,4) + '-' + d.slice(4,7) + '-' + d.slice(7);
    return p;
  }
  function when(ts) {
    var d = new Date(ts * 1000), now = new Date();
    var t = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (d.toDateString() === now.toDateString()) return 'Today ' + t;
    if (new Date(now.getTime() + 864e5).toDateString() === d.toDateString()) return 'Tomorrow ' + t;
    return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + ' ' + t;
  }
  /* Recent notes read better as "2h ago"; anything older is easier to place
     with a real date, so old history stops being a wall of "43d ago". */
  function stamp(ts) {
    var s = Math.floor(Date.now() / 1000) - ts, d = new Date(ts * 1000);
    var t = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (s < 3600)  return Math.max(1, Math.floor(s / 60)) + ' min ago';
    if (s < 86400) return Math.floor(s / 3600) + ' hours ago, ' + t;
    if (s < 6 * 86400) return d.toLocaleDateString([], { weekday: 'long' }) + ', ' + t;
    return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' + t;
  }
  function toast(m) {
    var t = document.createElement('div');
    t.className = 'toast'; t.textContent = m;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('on'); });
    setTimeout(function () { t.classList.remove('on'); setTimeout(function(){ t.remove(); }, 300); }, 2200);
  }

  var LINKICON = {
    web:'<svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm6.9 6h-2.9a15 15 0 0 0-1.3-3.4A8 8 0 0 1 18.9 8zM12 4c.8 1.1 1.4 2.5 1.8 4h-3.6c.4-1.5 1-2.9 1.8-4zM4.3 14a8 8 0 0 1 0-4h3.3a17 17 0 0 0 0 4zm.8 2h2.9c.3 1.2.7 2.4 1.3 3.4A8 8 0 0 1 5.1 16zm2.9-8H5.1a8 8 0 0 1 4.2-3.4A15 15 0 0 0 8 8zM12 20c-.8-1.1-1.4-2.5-1.8-4h3.6c-.4 1.5-1 2.9-1.8 4zm2.2-6H9.8a15 15 0 0 1 0-4h4.4a15 15 0 0 1 0 4zm.5 5.4c.6-1 1-2.2 1.3-3.4h2.9a8 8 0 0 1-4.2 3.4zM16.4 14a17 17 0 0 0 0-4h3.3a8 8 0 0 1 0 4z"/></svg>',
    facebook:'<svg viewBox="0 0 24 24"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z"/></svg>',
    instagram:'<svg viewBox="0 0 24 24"><path d="M12 2.2c3.2 0 3.6 0 4.9.1 1.2.1 1.8.2 2.2.4.6.2 1 .5 1.4.9s.7.8.9 1.4c.2.4.4 1 .4 2.2.1 1.3.1 1.7.1 4.9s0 3.6-.1 4.9c-.1 1.2-.2 1.8-.4 2.2-.2.6-.5 1-.9 1.4s-.8.7-1.4.9c-.4.2-1 .4-2.2.4-1.3.1-1.7.1-4.9.1s-3.6 0-4.9-.1c-1.2-.1-1.8-.2-2.2-.4-.6-.2-1-.5-1.4-.9s-.7-.8-.9-1.4c-.2-.4-.4-1-.4-2.2C2.2 15.6 2.2 15.2 2.2 12s0-3.6.1-4.9c.1-1.2.2-1.8.4-2.2.2-.6.5-1 .9-1.4s.8-.7 1.4-.9c.4-.2 1-.4 2.2-.4C8.4 2.2 8.8 2.2 12 2.2zm0 3.2A6.6 6.6 0 1 0 12 18.6 6.6 6.6 0 0 0 12 5.4zm0 10.9a4.3 4.3 0 1 1 0-8.6 4.3 4.3 0 0 1 0 8.6zm6.9-11.1a1.5 1.5 0 1 1-3.1 0 1.5 1.5 0 0 1 3.1 0z"/></svg>',
    linkedin:'<svg viewBox="0 0 24 24"><path d="M4.98 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-.95 1.83-1.95 3.75-1.95C20.5 8.75 21 11 21 14.1V21h-4v-6.1c0-1.45-.03-3.3-2-3.3-2 0-2.3 1.57-2.3 3.2V21H9z"/></svg>',
    email:'<svg viewBox="0 0 24 24"><path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5z"/></svg>',
    map:'<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>',
    search:'<svg viewBox="0 0 24 24"><path d="M15.5 14h-.8l-.3-.3a6.5 6.5 0 1 0-.7.7l.3.3v.8l5 5 1.5-1.5zm-6 0a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9z"/></svg>'
  };

  LINKICON.yelp = LINKICON.web;   // a Yelp listing is still a page on the web

  function boot()      { return api('bootstrap').then(function (b) { S.boot = b; }); }
  function loadQueue() {
    return api('queue', null, { stage: S.stage }).then(function (r) {
      S.queue = r.leads || []; S.nextUp = r.next_up || [];
      S.stageTotal = r.stage_total || (r.leads || []).length; S.i = 0;
    });
  }

  /* ---------- chrome ---------- */
  function rail() {
    var dueN = (S.boot.due || []).length;
    function b(id, label) {
      return '<button class="rbtn ' + (S.view === id ? 'on' : '') + '" data-tab="' + id + '" title="' + label + '">' +
             ICON[id] + (id === 'due' && dueN ? '<span class="dot">' + dueN + '</span>' : '') + '</button>';
    }
    return '<div class="rail">' +
      '<a class="logo" href="#dash" title="Weyney Media">' +
        '<img src="/crm/assets/weyney-logo.svg" alt="Weyney Media"></a>' +
      b('dash', 'Dashboard') + b('call', 'Call queue') + b('clients', 'Clients') + b('cal', 'Calendar') +
      '<div class="spacer"></div>' +
      '<a class="rbtn ' + (S.boot.calendar_connected ? '' : 'alert') + '" href="/crm/oauth_google.php" ' +
        'title="' + (S.boot.calendar_connected ? 'Calendar connected' : 'Calendar NOT connected') + '">' +
        ICON.gear + '</a>' +
      '<a class="rbtn" href="/crm/?logout=1" title="Sign out">' +
        '<svg viewBox="0 0 24 24"><path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5zm7.6 4-1.4 1.4L18.2 11H9v2h9.2l-2 2.6L17.6 17 22 12z"/></svg>' +
      '</a></div>';
  }

  /* The top bar used to sit empty. It now carries where you are in the queue. */
  function head() {
    var pct = S.queue.length ? Math.round((S.i / S.queue.length) * 100) : 0;
    var titles = { dash:'Dashboard', call:'Call queue', cal:'Calendar', clients:'Clients' };
    var ctx = '<b>' + (titles[S.view] || 'Dashboard') + '</b>';
    if (S.view === 'call' && S.queue.length) {
      ctx += ' <span class="dim">lead ' + Math.min(S.i + 1, S.queue.length) +
             ' of ' + (S.stageTotal || S.queue.length) + ' in ' +
             esc((S.boot.stages[S.stage] || {}).label || S.stage) + '</span>';
    }
    return '<div class="head">' +
      '<span class="appname">Weyney Media</span>' +
      '<span class="ctx">' + ctx + '</span>' +
      '<span class="grow"></span>' +
      (S.view === 'call' && S.queue.length ? '<span class="headbar"><i style="width:' + pct + '%"></i></span>' : '') +
      /* Only on the Calendar view — it was noise everywhere else. */
      (S.view === 'clients'
        ? '<button class="addpill" data-newlead="1">+ Add client</button>'
        : '') +
      (S.view === 'cal'
        ? '<a class="calpill ' + (S.boot.calendar_connected ? 'on' : 'off') + '" ' +
          'href="/crm/oauth_google.php' + (S.boot.calendar_connected ? '' : '?start=1') + '">' +
          (S.boot.calendar_connected ? 'Calendar connected' : 'Connect Google Calendar') + '</a>'
        : '') +
      '</div>';
  }

  function stageStrip() {
    var st = S.boot.stages, out = '<div class="stages">';
    Object.keys(st).forEach(function (k) {
      if (!st[k].count && k !== S.stage) return;
      out += '<button data-stage="' + k + '" class="' + (S.stage === k ? 'on' : '') + '">' +
             esc(st[k].label) + '<i>' + st[k].count + '</i></button>';
    });
    return out + '</div>';
  }

  /* ---------- views ---------- */
  function viewCall() {
    var out = stageStrip();
    if (!S.queue.length) return out + '<div class="panel"><div class="empty"><b>Nothing queued here</b>Pick another stage above.</div></div>';
    if (S.i >= S.queue.length) return out + '<div class="panel"><div class="empty"><b>Queue cleared</b>' + S.queue.length + ' worked in this stage.</div></div>';

    var l = S.queue[S.i];
    var hist = (l.acts || []).map(function (a) {
      return '<div class="row"><time>' + stamp(a.ts) + '</time><p>' + esc(a.body || a.type) + '</p></div>';
    }).join('') || '<div class="row" style="color:var(--faint)">Nothing logged yet.</div>';

    return out + '<div class="panel calls"><div class="qcard">' +
      '<div class="qleft">' +
        '<span class="tag">' + esc((S.boot.stages[l.stage] || {}).label || l.stage) + '</span>' +
        '<div class="qwho" style="margin-top:9px">' + esc(l.name) + '</div>' +
        '<div class="qmeta">' + esc(l.city || '—') +
          (l.contact ? ' · <b>' + esc(l.contact) + '</b>' : '') +
          (l.attempts > 0 ? ' · ' + l.attempts + ' attempt' + (l.attempts > 1 ? 's' : '') : '') + '</div>' +
        (l.phone
          ? '<div class="phone"><a href="' + dialHref(l.phone) + '" target="_blank" rel="noopener" ' +
            'title="Dial with Google Voice">' + esc(prettyPhone(l.phone)) + '</a>' +
            '<span class="dialhint">click to dial</span></div>'
          : '<div class="phone nonum">No number on file' +
            '<span class="dialhint">add one in Log what happened</span></div>') +
        '<div class="qdial">' +
          '<button class="btn btn-p big" data-email="1"' +
            (l.email ? '' : ' disabled title="No email on this lead"') + '>Email</button>' +
          '<button class="btn tiny" data-skip="1" title="Move on without recording an outcome">Skip</button>' +
        '</div>' +
        '<div class="sect" style="margin-top:20px">After the call</div>' +
        '<div class="dgrid">' +
          '<button class="disp info wide" data-sched="callback">Schedule Follow-up</button>' +
          '<button class="disp" data-out="no_pickup">No pick-up</button>' +
          '<button class="disp bad" data-out="not_interested">Not interested</button>' +
          '<button class="disp demo wide" data-sched="demo">Schedule a demo</button>' +
        '</div>' +
        (function () {
          var nxt = S.queue.slice(S.i + 1, S.i + 6);
          if (!nxt.length) return '';
          return '<div class="lookahead"><div class="sect">Next up in this queue</div>' +
            nxt.map(function (n, idx) {
              return '<div class="lrow"><span class="lidx">' + (S.i + idx + 2) + '</span>' +
                '<span class="ln">' + esc(n.name) + '</span>' +
                '<span class="lc">' + esc(n.city || '') + '</span></div>';
            }).join('') + '</div>';
        }()) +
        logPanel(l) +
      '</div>' +
      '<div class="qright">' +
        whoPanel(l) +
        '<div class="notebox">' +
          '<div class="sect">Log what happened</div>' +
          '<input id="nb-contact" class="nbfield" placeholder="Who did you speak to?" value="' + esc(l.contact) + '">' +
          '<input id="nb-email" class="nbfield" type="email" placeholder="Their email" value="' + esc(l.email) + '">' +
          '<input id="nb-phone" class="nbfield" placeholder="Their phone" value="' + esc(l.phone) + '">' +
          '<textarea id="nb" rows="3" placeholder="What was said, who to ask for, when to try again…"></textarea>' +
          '<button class="btn btn-p" data-note="1">Save</button>' +
        '</div>' +
        helpPanel(l) +
      '</div></div></div>';
  }

  /* What we know = standing facts. The log is a separate, collapsed thing —
     you read the profile before dialling and the history only when you need it. */
  /* Split deliberately: the note box sits between these two so the thing you
     type into is near the middle of the screen, not at the end of a scroll. */
  function whoPanel(l) {
    var contacts = [];
    if (l.contact) contacts.push(['Contact', esc(l.contact)]);
    if (l.phone)   contacts.push(['Phone', '<a href="' + dialHref(l.phone) + '" target="_blank">' +
                                  esc(prettyPhone(l.phone)) + '</a>']);
    if (l.email)   contacts.push(['Email', '<a href="mailto:' + esc(l.email) + '">' + esc(l.email) + '</a>']);
    if (l.city)    contacts.push(['Where', esc(l.city)]);

    return '<div class="know">' +
      '<div class="sect">Who they are</div>' +
      (contacts.length
        ? '<dl class="facts">' + contacts.map(function (c) {
            return '<dt>' + c[0] + '</dt><dd>' + c[1] + '</dd>';
          }).join('') + '</dl>'
        : '<div class="muted">No contact details yet.</div>') +
      (l.email_guess
        ? '<div class="guess">Found in the notes: <b>' + esc(l.email_guess) + '</b>' +
          '<button class="editlink" data-useemail="' + esc(l.email_guess) + '">use it</button></div>'
        : '') +
      ((l.links || []).length
        ? '<div class="links">' + l.links.map(function (k) {
            return '<a class="lnk ' + k.kind + '" href="' + esc(k.url) + '" target="_blank" rel="noopener">' +
              (LINKICON[k.kind] || LINKICON.search) + '<span>' + esc(k.label) + '</span></a>';
          }).join('') + '</div>'
        : '') +
      '</div>';
  }

  function helpPanel(l) {
    var pains = (l.pain_points || '').split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
    var opp   = (l.opportunity || '').trim();
    return '<div class="know">' +
      '<div class="sect2 first">Where we help<button class="editlink" data-editprofile="1">edit</button></div>' +
      (opp ? '<div class="opp">' + esc(opp) + '</div>'
           : '<div class="muted">Nothing yet — click edit.</div>') +
      '<div class="sect2">Pain points</div>' +
      (pains.length
        ? '<ul class="pains">' + pains.map(function (x) {
            return '<li>' + esc(x.replace(/^[-•*]\s*/, '')) + '</li>';
          }).join('') + '</ul>'
        : '<div class="muted">Nothing recorded.</div>') +
      '</div>';
  }

  function logPanel(l) {
    var acts = l.acts || [];
    return '<div class="logwrap' + (S.showLog ? ' open' : '') + '">' +
      '<button class="logtoggle" data-log="1"><i>▸</i> Activity (' + acts.length + ')</button>' +
      '<div class="hist">' + (acts.length
        ? acts.map(function (a) {
            return '<div class="row"><time>' + stamp(a.ts) + '</time><p>' +
                   esc(a.body || a.type) + '</p></div>';
          }).join('')
        : '<div class="row" style="color:var(--faint)">Nothing logged yet.</div>') + '</div></div>';
  }

  function statTile(label, value, sub) {
    return '<div class="card"><div class="lbl">' + esc(label) + '</div>' +
           '<div class="num">' + value + '</div>' +
           (sub ? '<div class="sub">' + sub + '</div>' : '') + '</div>';
  }

  function viewDash() {
    var d = S.stats; if (!d) return '<div class="panel"><div class="empty">Loading…</div></div>';
    var max = Math.max.apply(null, d.funnel.map(function (f) { return f.n; }).concat([1]));

    var cards = '<div class="cards">' +
      statTile('Leads', d.total, d.untouched + ' not yet worked') +
      statTile('Calls logged', d.calls, d.calls_today + ' today · ' + d.calls_week + ' this week') +
      statTile('Connect rate', d.connect_rate + '<span class="pc">%</span>',
               d.reached + ' of ' + d.worked + ' attempted') +
      statTile('Demos booked', d.demos, d.demo_rate + '% of those reached') +
      statTile('Scheduled', d.scheduled, 'callbacks and demos ahead') +
      statTile('Won', d.won, d.lost + ' lost') +
      '</div>';

    /* Funnel: magnitude, so length carries the value and every bar is the same
       accent hue. Colour here would imply the stages differ in kind. */
    var funnel = '<div class="panel"><div class="phead">Conversion funnel</div><div class="funnel">' +
      d.funnel.map(function (f, i) {
        var prev = i ? d.funnel[i-1].n : f.n;
        var drop = (i && prev > 0) ? Math.round(f.n / prev * 100) : null;
        return '<div class="fstep"><span class="fl">' + esc(f.label) + '</span>' +
          '<span class="ftrack"><i class="fbar" style="width:' +
            Math.max(f.n / max * 100, f.n ? 1.5 : 0) + '%"></i></span>' +
          '<span class="fnum">' + f.n + (drop !== null ? '<em>' + drop + '%</em>' : '') + '</span></div>';
      }).join('') + '</div></div>';

    var omax = Math.max.apply(null, d.outcomes.map(function (c) { return +c.c; }).concat([1]));
    var totalCalls = d.outcomes.reduce(function (a, c) { return a + (+c.c); }, 0);
    var outcomes = '<div class="panel"><div class="phead">What happens when you dial</div><div class="bars">' +
      (totalCalls === 0
        ? '<div class="barsempty">No dials logged yet. This is the number that tells you ' +
          'whether the list or the pitch needs work.</div>'
        : d.outcomes.map(function (c) {
            return '<div class="brow"><span class="bl">' + esc(c.k) + '</span>' +
              '<span class="btrack"><i class="bfill" style="width:' + (c.c / omax * 100) + '%"></i></span>' +
              '<span class="bn">' + c.c + '</span></div>';
          }).join('')) + '</div></div>';

    /* Dials per day against the target. Column form because this is change over
       time; the target line is what makes a bar mean something. */
    var dmax = Math.max.apply(null, (d.daily || []).map(function (x) { return x.c; }).concat([d.target || 100]));
    var anyDials = (d.daily || []).some(function (x) { return x.c > 0; });
    var daily = '<div class="panel"><div class="phead">Dials per day ' +
      '<span class="pnote">target ' + (d.target || 100) + '</span></div>' +
      (anyDials
        ? '<div class="cols"><div class="colwrap">' +
            d.daily.map(function (x) {
              return '<div class="col' + (x.today ? ' today' : '') + '" title="' + esc(x.d) + ': ' + x.c + '">' +
                '<i style="height:' + Math.max(x.c / dmax * 100, x.c ? 2 : 0) + '%"></i>' +
                '<span>' + esc(x.d.split(' ')[0]) + '</span></div>';
            }).join('') +
            '<div class="targetline" style="bottom:' + ((d.target || 100) / dmax * 100) + '%"></div>' +
          '</div></div>'
        : '<div class="bars"><div class="barsempty">Two weeks of dials will show here, ' +
          'with a line at your ' + (d.target || 100) + '-a-day target.</div></div>') +
      '</div>';

    var nudge = d.calls === 0
      ? '<div class="panel nudge"><b>No calls logged yet.</b> These fill in as you work the queue — ' +
        'connect rate and demo rate need real outcomes before they mean anything.</div>'
      : '';

    var hrs = d.by_hour || [];
    var hmax = Math.max.apply(null, hrs.map(function (h) { return h.total; }).concat([1]));
    var besttime = '<div class="panel"><div class="phead">Best time to call</div><div class="bars">' +
      (!hrs.length
        ? '<div class="barsempty">Connect rate by hour appears once you have dialled ' +
          'across a few different times of day.</div>'
        : hrs.map(function (h) {
            var lbl = ((h.h % 12) || 12) + (h.h < 12 ? 'am' : 'pm');
            return '<div class="brow"><span class="bl">' + lbl + '</span>' +
              '<span class="btrack"><i class="bfill" style="width:' + (h.total / hmax * 100) + '%"></i></span>' +
              '<span class="bn">' + h.rate + '%</span></div>';
          }).join('')) + '</div></div>';

    var stale = '<div class="panel"><div class="phead">Going cold</div><div class="bars">' +
      (!(d.stale || []).length
        ? '<div class="barsempty">Leads you have spoken to but not touched recently ' +
          'will surface here so none of them rot.</div>'
        : d.stale.map(function (x) {
            return '<div class="brow stale"><span class="bl">' + esc(x.name) + '</span>' +
              '<span class="bn">' + (x.last_ts ? stamp(x.last_ts) : 'never') + '</span></div>';
          }).join('')) + '</div></div>';

    var sched = '<div class="panel"><div class="phead">Due today &amp; overdue</div>' +
      ((d.today_calls || []).length
        ? '<div class="list flush">' + d.today_calls.map(function (e) {
            var over = e.starts_at < Math.floor(Date.now() / 1000);
            return '<div class="srow"><span class="sw' + (over ? ' over' : '') + '">' +
              when(e.starts_at) + '</span><span class="sn">' + esc(e.name) + '</span>' +
              '<span class="tag">' + (e.kind === 'demo' ? 'Demo' : 'Call back') + '</span>' +
              '<button class="donebtn" data-complete="' + e.id + '" title="Mark this as done">Done</button></div>';
          }).join('') + '</div>'
        : '<div class="bars"><div class="barsempty">Nothing due today. Everything scheduled ' +
          'lives in Calendar and on your Google Calendar.</div></div>') + '</div>';

    return cards + nudge + daily + '<div style="margin-top:14px">' + sched + '</div>' +
      '<div class="split" style="margin-top:14px">' + funnel + outcomes + '</div>' +
      '<div class="split" style="margin-top:14px">' + besttime + stale + '</div>';
  }

  function viewCal() {
    var evs = S.cal;
    if (!evs) return '<div class="panel"><div class="empty">Loading…</div></div>';
    if (!evs.length) return '<div class="panel"><div class="empty"><b>Nothing scheduled</b>' +
      'Callbacks and demos you book appear here.</div></div>';

    var days = {}, order = [];
    evs.forEach(function (e) {
      var d = new Date(e.starts_at * 1000);
      var k = d.toDateString();
      if (!days[k]) { days[k] = []; order.push(k); }
      days[k].push(e);
    });
    var todayKey = new Date().toDateString();
    var now = Math.floor(Date.now() / 1000);

    return order.map(function (k) {
      var d = new Date(k), isToday = k === todayKey;
      var past = d < new Date(todayKey);
      return '<div class="panel calday' + (isToday ? ' istoday' : '') + '">' +
        '<div class="phead">' + (isToday ? 'Today · ' : '') +
          d.toLocaleDateString([], { weekday:'long', day:'numeric', month:'long' }) +
          '<span class="pnote">' + days[k].length + (days[k].length === 1 ? ' call' : ' calls') + '</span></div>' +
        days[k].map(function (e) {
          var over = e.starts_at < now && e.status === 'scheduled';
          return '<div class="crow" data-openlead="' + esc(e.lead_id) + '">' +
            '<span class="ct' + (over ? ' over' : '') + '">' +
              new Date(e.starts_at * 1000).toLocaleTimeString([], { hour:'numeric', minute:'2-digit' }) +
            '</span>' +
            '<span class="cn">' + esc(e.name) + '</span>' +
            '<span class="tag">' + (e.kind === 'demo' ? 'Demo' : 'Call back') + '</span>' +
            (e.meet_link ? '<a class="meet" href="' + esc(e.meet_link) + '" target="_blank" rel="noopener" ' +
              'onclick="event.stopPropagation()" title="Join the meeting">Google Meet</a>' : '') +
            (e.gcal_event_id ? '<span class="synced" title="On your Google Calendar">✓</span>' : '') +
            (e.status === 'scheduled'
              ? '<button class="donebtn" data-complete="' + e.id + '" title="Mark this call as done">Done</button>'
              : '<span class="donetag">✓ done</span>') +
            '</div>';
        }).join('') + '</div>';
    }).join('');
  }

  /* Full record for one lead — the thing a client row opens. */
  function viewDetail() {
    var l = S.detail;
    if (!l) return '<div class="panel"><div class="empty">Loading…</div></div>';
    var hist = (l.acts || []).map(function (a) {
      return '<div class="row"><time>' + stamp(a.ts) + '</time><p>' + esc(a.body || a.type) + '</p></div>';
    }).join('') || '<div class="row" style="color:var(--faint)">Nothing logged yet.</div>';
    var evs = (l.events || []).filter(function (e) { return e.status === 'scheduled'; });

    return '<button class="backlink" data-back="1">← Back to clients</button>' +
      '<div class="panel calls"><div class="qcard">' +
      '<div class="qleft">' +
        '<span class="tag">' + esc((S.boot.stages[l.stage] || {}).label || l.stage) + '</span>' +
        '<div class="qwho" style="margin-top:9px">' + esc(l.name) + '</div>' +
        '<div class="qmeta">' + esc(l.city || '—') +
          (l.contact ? ' · <b>' + esc(l.contact) + '</b>' : '') + '</div>' +
        (l.phone
          ? '<div class="phone"><a href="' + dialHref(l.phone) + '" target="_blank" rel="noopener" ' +
            'title="Dial with Google Voice">' + esc(prettyPhone(l.phone)) + '</a>' +
            '<span class="dialhint">click to dial</span></div>'
          : '<div class="phone nonum">No number on file' +
            '<span class="dialhint">add one in Log what happened</span></div>') +
        '<div class="qdial">' +
          '<button class="btn btn-p big" data-demail="1"' +
            (l.email ? '' : ' disabled title="No email on this lead"') + '>Email</button>' +
        '</div>' +
        '<div class="sect" style="margin-top:20px">Pipeline stage</div>' +
        '<select id="d-stage" class="nbfield stagesel">' + stageOptions(l.stage) + '</select>' +
        '<div class="sect" style="margin-top:20px">Schedule</div>' +
        '<div class="dgrid">' +
          '<button class="disp info wide" data-dsched="callback">Schedule Follow-up</button>' +
          '<button class="disp demo wide" data-dsched="demo">Schedule a demo</button>' +
        '</div>' +
        (evs.length
          ? '<div class="lookahead"><div class="sect">Booked</div>' + evs.map(function (e) {
              return '<div class="lrow"><span class="lw">' + when(e.starts_at) + '</span>' +
                '<span class="ln">' + (e.kind === 'demo' ? 'Demo' : 'Call back') + '</span></div>';
            }).join('') + '</div>'
          : '') +
        logPanel(l) +
      '</div>' +
      '<div class="qright">' +
        whoPanel(l) +
        '<div class="notebox">' +
          '<div class="sect">Log what happened</div>' +
          '<input id="nb-contact" class="nbfield" placeholder="Who did you speak to?" value="' + esc(l.contact) + '">' +
          '<input id="nb-email" class="nbfield" type="email" placeholder="Their email" value="' + esc(l.email) + '">' +
          '<input id="nb-phone" class="nbfield" placeholder="Their phone" value="' + esc(l.phone) + '">' +
          '<textarea id="nb" rows="3" placeholder="What was said…"></textarea>' +
          '<button class="btn btn-p" data-dnote="1">Save</button>' +
        '</div>' +
        helpPanel(l) +
      '</div></div></div>';
  }

  /* Grouped by where they actually are. "Clients" covering everyone from
     answered-once to paying was the whole problem — the headings do the
     demarcating so the tag doesn't have to. */
  var CLIENT_GROUPS = [
    { key:'won',      label:'Clients',        sub:'Closed and won',            stages:['won'] },
    { key:'proposal', label:'Proposal out',   sub:'Quoted, awaiting an answer', stages:['proposal'] },
    { key:'held',     label:'Demo held',      sub:'Pitched, needs a next step', stages:['demo_done'] },
    { key:'booked',   label:'Demo scheduled', sub:'On the calendar',            stages:['demo_set'] },
    { key:'noshow',   label:'Demo no-show',   sub:'Missed it — rebook',         stages:['demo_noshow'] },
    { key:'talking',  label:'In conversation',sub:'Reached, nothing booked yet', stages:['contacted'] },
    { key:'nurture',  label:'Nurture',        sub:'Not now, worth keeping warm', stages:['nurture'] }
  ];

  function viewClients() {
    var c = S.clients;
    if (!c) return '<div class="panel"><div class="empty">Loading…</div></div>';
    if (!c.length) return '<div class="panel"><div class="empty"><b>Nobody here yet</b>' +
      'Leads arrive once you have spoken to them and booked something.</div></div>';

    return CLIENT_GROUPS.map(function (g) {
      var rows = c.filter(function (l) { return g.stages.indexOf(l.stage) >= 0; });
      if (!rows.length) return '';
      return '<div class="cgroup ' + g.key + '">' +
        '<div class="ghead"><b>' + esc(g.label) + '</b>' +
          '<span class="gsub">' + esc(g.sub) + '</span>' +
          '<span class="gcount">' + rows.length + '</span></div>' +
        '<div class="list">' + rows.map(function (l) {
          var next = l.next_at
            ? '<span class="nx">' + when(l.next_at) + '</span>'
            : '<span class="nx none">nothing booked' +
              (l.last_ts ? ' · ' + stamp(l.last_ts) : '') + '</span>';
          return '<div class="item click" data-openlead="' + esc(l.id) + '">' +
            '<div style="min-width:0"><div class="nm">' + esc(l.name) + '</div>' +
            '<div class="sub">' + esc(prettyPhone(l.phone)) +
              (l.contact ? ' · ' + esc(l.contact) : '') +
              (l.city ? ' · ' + esc(l.city) : '') + '</div></div>' +
            '<div class="when">' + next + '</div></div>';
        }).join('') + '</div></div>';
    }).join('') || '<div class="panel"><div class="empty"><b>Nobody here yet</b>' +
      'Leads arrive once you have spoken to them.</div></div>';
  }

  function evItem(e) {
    var over = e.starts_at < Math.floor(Date.now() / 1000);
    return '<div class="item">' +
      '<div><div class="nm">' + esc(e.name) + '</div>' +
        '<div class="sub">' + esc(prettyPhone(e.phone)) + (e.city ? ' · ' + esc(e.city) : '') + '</div></div>' +
      '<span class="tag">' + (e.kind === 'demo' ? 'Booked call' : 'Callback') + '</span>' +
      '<div class="when' + (over ? ' over' : '') + '">' + when(e.starts_at) + '</div></div>';
  }
  function viewDue() {
    var d = S.boot.due || [];
    return d.length ? '<div class="list">' + d.map(evItem).join('') + '</div>'
      : '<div class="panel"><div class="empty"><b>Nothing due</b>No callbacks scheduled for today.</div></div>';
  }
  function viewSoon() {
    var u = S.boot.upcoming || [];
    return u.length ? '<div class="list">' + u.map(evItem).join('') + '</div>'
      : '<div class="panel"><div class="empty"><b>Nothing upcoming</b>Scheduled callbacks appear here.</div></div>';
  }

  function stageOptions(sel) {
    return Object.keys(S.boot.stages).map(function (k) {
      return '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' +
             esc(S.boot.stages[k].label) + '</option>';
    }).join('');
  }

  function sheet() {
    var k = S.sheet.kind;
    if (k === 'profile') {
      var l = S.sheet.lead;
      return '<div class="sheet-bg" data-close="1"><div class="sheet">' +
        '<h3>What we know</h3><div class="who">' + esc(l.name) + '</div>' +
        '<label>Pain points <span class="opt">— one per line</span></label>' +
        '<textarea id="pf-pain" rows="7" placeholder="Phone users can\'t zoom in\nInvisible on Google for autism searches">' +
          esc(l.pain_points || '') + '</textarea>' +
        '<label>Where we help</label>' +
        '<textarea id="pf-opp" rows="3" placeholder="Rebuild on a maintained platform; fix accessibility; local SEO">' +
          esc(l.opportunity || '') + '</textarea>' +
        '<label>Website</label><input id="pf-web" placeholder="theircompany.com" value="' +
          esc(l.website || '') + '">' +
        '<label>Social profiles <span class="opt">— only ones that exist</span></label>' +
        '<input id="pf-social" placeholder="facebook.com/theirpage" value="' + esc(l.socials || '') + '">' +
        '<div class="acts"><button class="cancel" data-close="1">Cancel</button>' +
        '<button class="go" data-pfsave="1">Save</button></div></div></div>';
    }
    if (k === 'newlead') {
      return '<div class="sheet-bg" data-close="1"><div class="sheet">' +
        '<h3>Add a client</h3>' +
        '<div class="who">Someone who came in outside the cold list</div>' +
        '<label>Business name</label><input id="nl-name" placeholder="Caring Hands Autism" autofocus>' +
        '<label>Contact</label><input id="nl-contact" placeholder="Who you deal with">' +
        '<label>Phone</label><input id="nl-phone" placeholder="612-555-0123">' +
        '<label>Email</label><input id="nl-email" type="email" placeholder="them@company.com">' +
        '<label>City</label><input id="nl-city" placeholder="Minneapolis">' +
        '<label>Website</label><input id="nl-web" placeholder="theircompany.com">' +
        '<label>Social profiles <span class="opt">— paste URLs, comma separated</span></label>' +
        '<input id="nl-social" placeholder="facebook.com/theirpage, instagram.com/theirpage">' +
        '<label>Where are they in the pipeline?</label>' +
        '<select id="nl-stage" class="nbfield">' + stageOptions('contacted') + '</select>' +
        '<label>How did they come in?</label>' +
        '<input id="nl-source" placeholder="Referral, inbound, event…">' +
        '<div class="acts"><button class="cancel" data-close="1">Cancel</button>' +
        '<button class="go" data-newsave="1">Add</button></div></div></div>';
    }
    if (k === 'email') {
      var l = S.sheet.lead;
      return '<div class="sheet-bg" data-close="1"><div class="sheet">' +
        '<h3>Email</h3><div class="who">' + esc(l.name) + ' · ' + esc(l.email) + '</div>' +
        '<label>Subject</label><input id="em-subj" value="Quick question about ' + esc(l.name) + '">' +
        '<label>Message</label><textarea id="em-body" rows="7"></textarea>' +
        '<div class="acts"><button class="cancel" data-close="1">Cancel</button>' +
        '<button class="go" data-send="1">Send</button></div></div></div>';
    }
    return '<div class="sheet-bg" data-close="1"><div class="sheet">' +
      '<h3>' + (k === 'demo' ? 'Schedule a demo' : 'Set a callback') + '</h3>' +
      '<div class="who">' + esc(S.sheet.lead.name) + '</div>' +
      '<div class="quick">' +
        '<button data-quick="3h">In 3 hours</button><button data-quick="tom9">Tomorrow 9am</button>' +
        '<button data-quick="tom2">Tomorrow 2pm</button><button data-quick="week">Next week</button>' +
      '</div>' +
      schedField() +
      (k === 'demo'
        ? '<label>Invite them <span class="opt">— sends a Google Meet link</span></label>' +
          '<input type="email" id="sch-email" placeholder="their@email.com" value="' +
            esc(S.sheet.lead.email || '') + '">' +
          '<div class="hint">' + (S.sheet.lead.email
            ? 'They will get a calendar invite with a Meet link.'
            : 'No email on this lead yet — add one to send an invite, or leave blank to just block the time.') +
          '</div>'
        : '') +
      '<label>Note</label><textarea id="sch-note" rows="2" placeholder="What to cover"></textarea>' +
      '<div class="acts"><button class="cancel" data-close="1">Cancel</button>' +
      '<button class="go" data-save="1">Schedule</button></div></div></div>';
  }
  /* Server wants 'YYYY-MM-DD HH:MM' in local time. */
  function schedWhenStr() {
    var d = new Date(S.sheet.dt), p = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
           ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function snap5(ms) {
    var d = new Date(ms); d.setSeconds(0, 0);
    d.setMinutes(Math.round(d.getMinutes() / 5) * 5);
    return d.getTime();
  }
  /* A schedule sheet carries its own picker state: the chosen instant, which
     month the calendar shows, and whether that calendar is open. */
  function schedSheet(lead, kind, from) {
    var d = new Date(Date.now() + 864e5); d.setHours(9, 0, 0, 0);
    return { lead: lead, kind: kind, from: from, dt: snap5(d.getTime()),
             calMonth: new Date(d.getFullYear(), d.getMonth(), 1).getTime(), calOpen: false };
  }
  function fmtRead(d) {
    return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + ' · ' +
           d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }
  var WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
  /* A picker we draw ourselves. The native datetime popup floats over the sheet
     and has no confirm button inside it, so a Done placed under the field ended
     up stranded behind the popup — this keeps the calendar, the clock and Done
     together in one panel you can actually reach. */
  function schedField() {
    var d = new Date(S.sheet.dt);
    return '<label>Date &amp; time</label>' +
      '<button type="button" class="pickfield' + (S.sheet.calOpen ? ' open' : '') + '" data-picktoggle="1">' +
        ICON.cal + '<span>' + esc(fmtRead(d)) + '</span>' +
        '<em>' + (S.sheet.calOpen ? 'Close' : 'Change') + '</em></button>' +
      (S.sheet.calOpen ? datepicker(d) : '');
  }
  function datepicker(sel) {
    var view = new Date(S.sheet.calMonth), y = view.getFullYear(), m = view.getMonth();
    var startDow = new Date(y, m, 1).getDay(), daysIn = new Date(y, m + 1, 0).getDate();
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var grid = WEEKDAYS.map(function (w) { return '<span class="pdh">' + w + '</span>'; }).join('');
    for (var i = 0; i < startDow; i++) grid += '<span class="pd empty"></span>';
    for (var day = 1; day <= daysIn; day++) {
      var cur = new Date(y, m, day);
      grid += '<button type="button" class="pd' +
        (cur.toDateString() === sel.toDateString() ? ' sel' : '') +
        (cur.toDateString() === today.toDateString() ? ' today' : '') +
        (cur < today ? ' past' : '') + '" data-day="' + day + '">' + day + '</button>';
    }
    var h12 = sel.getHours() % 12 || 12, ap = sel.getHours() < 12 ? 'AM' : 'PM';
    var hrs = '', mins = '';
    for (var h = 1; h <= 12; h++) hrs += '<option' + (h === h12 ? ' selected' : '') + '>' + h + '</option>';
    for (var mn = 0; mn < 60; mn += 5) {
      mins += '<option value="' + mn + '"' + (mn === sel.getMinutes() ? ' selected' : '') + '>' +
              String(mn).padStart(2, '0') + '</option>';
    }
    return '<div class="picker">' +
      '<div class="pnav"><button type="button" class="pmo" data-mo="-1">‹</button>' +
        '<b>' + view.toLocaleDateString([], { month: 'long', year: 'numeric' }) + '</b>' +
        '<button type="button" class="pmo" data-mo="1">›</button></div>' +
      '<div class="pgrid">' + grid + '</div>' +
      '<div class="ptime"><span class="ptl">Time</span>' +
        '<select id="pk-h">' + hrs + '</select><i>:</i><select id="pk-m">' + mins + '</select>' +
        '<div class="pap"><button type="button" class="' + (ap === 'AM' ? 'on' : '') + '" data-ap="AM">AM</button>' +
          '<button type="button" class="' + (ap === 'PM' ? 'on' : '') + '" data-ap="PM">PM</button></div></div>' +
      '<button type="button" class="pdone" data-pickdone="1">Done</button></div>';
  }

  function render() {
    if (!S.boot) return;
    app.innerHTML = rail() +
      '<div class="main">' + head() +
        '<div class="body"><div class="wrap">' +
          (S.view === 'call' ? viewCall() :
           S.view === 'cal' ? viewCal() :
           S.view === 'detail' ? viewDetail() :
           S.view === 'clients' ? viewClients() : viewDash()) +
        '</div></div>' +
      '</div>' + (S.sheet ? sheet() : '');
    wire();
    writeHash();
  }

  function wire() {
    app.querySelectorAll('[data-tab]').forEach(function (el) {
      el.onclick = function () { go({ view: el.dataset.tab }); };
    });
    app.querySelectorAll('[data-stage]').forEach(function (el) {
      el.onclick = function () { go({ view: 'call', stage: el.dataset.stage }); };
    });
    app.querySelectorAll('[data-out]').forEach(function (el) {
      el.onclick = function () { disposition(el.dataset.out); };
    });
    app.querySelectorAll('[data-skip]').forEach(function (el) {
      el.onclick = function () { S.i++; savePos(); render(); };
    });
    app.querySelectorAll('[data-sched]').forEach(function (el) {
      el.onclick = function () {
        S.sheet = schedSheet(S.queue[S.i], el.dataset.sched);
        render();
      };
    });
    app.querySelectorAll('[data-email]').forEach(function (el) {
      el.onclick = function () { S.sheet = { lead: S.queue[S.i], kind: 'email' }; render(); };
    });
    document.querySelectorAll('[data-close]').forEach(function (el) {
      el.onclick = function (e) { if (e.target !== el) return; S.sheet = null; render(); };
    });
    document.querySelectorAll('[data-quick]').forEach(function (el) {
      el.onclick = function () {
        var d, k = el.dataset.quick;
        if (k === '3h')   d = new Date(Date.now() + 3 * 36e5);
        if (k === 'tom9') { d = new Date(Date.now() + 864e5); d.setHours(9, 0, 0, 0); }
        if (k === 'tom2') { d = new Date(Date.now() + 864e5); d.setHours(14, 0, 0, 0); }
        if (k === 'week') { d = new Date(Date.now() + 7 * 864e5); d.setHours(9, 0, 0, 0); }
        S.sheet.dt = snap5(d.getTime());
        S.sheet.calMonth = new Date(d.getFullYear(), d.getMonth(), 1).getTime();
        render();
      };
    });
    // Custom date/time picker — open/close, pick a day, page months, set time.
    var ptog = document.querySelector('[data-picktoggle]');
    if (ptog) ptog.onclick = function () { S.sheet.calOpen = !S.sheet.calOpen; render(); };
    app.querySelectorAll('[data-day]').forEach(function (el) {
      el.onclick = function () {
        var d = new Date(S.sheet.dt), v = new Date(S.sheet.calMonth);
        d.setFullYear(v.getFullYear(), v.getMonth(), +el.dataset.day);
        S.sheet.dt = d.getTime(); render();
      };
    });
    app.querySelectorAll('[data-mo]').forEach(function (el) {
      el.onclick = function () {
        var v = new Date(S.sheet.calMonth); v.setMonth(v.getMonth() + (+el.dataset.mo));
        S.sheet.calMonth = v.getTime(); render();
      };
    });
    app.querySelectorAll('[data-ap]').forEach(function (el) {
      el.onclick = function () {
        var d = new Date(S.sheet.dt), h = d.getHours();
        if (el.dataset.ap === 'AM' && h >= 12) d.setHours(h - 12);
        if (el.dataset.ap === 'PM' && h < 12)  d.setHours(h + 12);
        S.sheet.dt = d.getTime(); render();
      };
    });
    var pkh = document.getElementById('pk-h'), pkm = document.getElementById('pk-m');
    function pkTime() {
      var d = new Date(S.sheet.dt), ap = d.getHours() < 12 ? 'AM' : 'PM';
      d.setHours((+pkh.value % 12) + (ap === 'PM' ? 12 : 0), +pkm.value);
      S.sheet.dt = d.getTime(); render();
    }
    if (pkh) pkh.onchange = pkTime;
    if (pkm) pkm.onchange = pkTime;
    var pdone = document.querySelector('[data-pickdone]');
    if (pdone) pdone.onclick = function () { S.sheet.calOpen = false; render(); };
    var nb = document.getElementById('nb');
    if (nb) nb.oninput = function () {
      var em = document.getElementById('nb-email');
      if (!em || em.value.trim()) return;                 // never overwrite
      var m = nb.value.match(/[\w.+-]+@[\w-]+\.[\w.]{2,}/);
      if (m) { em.value = m[0].replace(/[.,;]$/, ''); em.classList.add('autofilled'); }
    };
    app.querySelectorAll('[data-note]').forEach(function (el) {
      el.onclick = function () {
        var box = document.getElementById('nb');
        var body = (box.value || '').trim();
        var contact = (document.getElementById('nb-contact').value || '').trim();
        var email   = (document.getElementById('nb-email').value || '').trim();
        if (!body && !contact && !email) return toast('Nothing to save');
        var ph = document.getElementById('nb-phone');
        api('note', { id: S.queue[S.i].id, body: body, contact: contact, email: email,
                      phone: ph ? ph.value.trim() : '' }).then(function (r) {
          if (r.error) return toast(r.error);
          box.value = '';
          S.queue[S.i].contact = r.contact; S.queue[S.i].email = r.email;
          toast('Saved');
          // refresh just this lead's history without losing your place
          return api('lead', null, { id: S.queue[S.i].id }).then(function (l) {
            S.queue[S.i].acts = l.acts || []; render();
          });
        });
      };
    });
    app.querySelectorAll('[data-openlead]').forEach(function (el) {
      el.onclick = function () { go({ view: 'detail', id: el.dataset.openlead }); };
    });
    app.querySelectorAll('[data-complete]').forEach(function (el) {
      el.onclick = function (e) {
        e.stopPropagation();   // calendar rows open the lead on click; this must not
        api('complete_event', { event_id: +el.dataset.complete }).then(function (r) {
          if (r.error) return toast(r.error);
          toast('Marked done');
          return boot().then(function () {
            if (S.view === 'cal') return api('calendar').then(function (x) { S.cal = x.events; render(); });
            return api('stats').then(function (x) { S.stats = x; render(); });
          });
        });
      };
    });
    app.querySelectorAll('[data-back]').forEach(function (el) {
      el.onclick = function () { go({ view: 'clients' }); };
    });
    app.querySelectorAll('[data-dsched]').forEach(function (el) {
      el.onclick = function () {
        S.sheet = schedSheet(S.detail, el.dataset.dsched, 'detail');
        render();
      };
    });
    app.querySelectorAll('[data-demail]').forEach(function (el) {
      el.onclick = function () { S.sheet = { lead: S.detail, kind: 'email', from: 'detail' }; render(); };
    });
    app.querySelectorAll('[data-dnote]').forEach(function (el) {
      el.onclick = function () {
        var body = (document.getElementById('nb').value || '').trim();
        api('note', { id: S.detail.id, body: body,
                      contact: document.getElementById('nb-contact').value.trim(),
                      email: document.getElementById('nb-email').value.trim(),
                      phone: document.getElementById('nb-phone').value.trim() })
          .then(function (r) {
            if (r.error) return toast(r.error);
            toast('Saved');
            return api('lead', null, { id: S.detail.id }).then(function (l) { S.detail = l; render(); });
          });
      };
    });
    app.querySelectorAll('[data-log]').forEach(function (el) {
      el.onclick = function () {
        S.showLog = !S.showLog;
        el.parentNode.classList.toggle('open', S.showLog);   // no re-render
      };
    });
    app.querySelectorAll('[data-useemail]').forEach(function (el) {
      el.onclick = function () {
        var l = S.view === 'detail' ? S.detail : S.queue[S.i];
        api('note', { id: l.id, email: el.dataset.useemail, body: '' }).then(function (r) {
          if (r.error) return toast(r.error);
          toast('Email saved');
          if (S.view === 'detail') return go({ view: 'detail', id: l.id });
          return loadQueue().then(render);
        });
      };
    });
    app.querySelectorAll('[data-editprofile]').forEach(function (el) {
      el.onclick = function () {
        S.sheet = { kind: 'profile', lead: S.view === 'detail' ? S.detail : S.queue[S.i] };
        render();
      };
    });
    var pf = document.querySelector('[data-pfsave]');
    if (pf) pf.onclick = function () {
      var l = S.sheet.lead;
      api('profile', { id: l.id,
                       pain_points: document.getElementById('pf-pain').value,
                       opportunity: document.getElementById('pf-opp').value,
                       website:     document.getElementById('pf-web').value.trim(),
                       socials:     document.getElementById('pf-social').value.trim() })
        .then(function (r) {
          if (r.error) return toast(r.error);
          toast('Saved'); S.sheet = null;
          if (S.view === 'detail') return go({ view: 'detail', id: l.id });
          return loadQueue().then(render);
        });
    };
    app.querySelectorAll('[data-newlead]').forEach(function (el) {
      el.onclick = function () { S.sheet = { kind: 'newlead' }; render(); };
    });
    var ns = document.querySelector('[data-newsave]');
    if (ns) ns.onclick = function () {
      var name = (document.getElementById('nl-name').value || '').trim();
      if (!name) return toast('A business name is required');
      api('new_lead', {
        name: name,
        contact: document.getElementById('nl-contact').value.trim(),
        phone:   document.getElementById('nl-phone').value.trim(),
        email:   document.getElementById('nl-email').value.trim(),
        city:    document.getElementById('nl-city').value.trim(),
        website: document.getElementById('nl-web').value.trim(),
        socials: document.getElementById('nl-social').value.trim(),
        stage:   document.getElementById('nl-stage').value,
        source:  document.getElementById('nl-source').value.trim()
      }).then(function (r) {
        if (r.error) {
          if (r.lead) { toast(name + ' is already on the list'); S.sheet = null;
                        return go({ view: 'detail', id: r.lead.id }); }
          return toast(r.error);
        }
        toast('Added');
        S.sheet = null;
        return go({ view: 'detail', id: r.id });
      });
    };
    var ds = document.getElementById('d-stage');
    if (ds) ds.onchange = function () {
      api('set_stage', { id: S.detail.id, stage: ds.value }).then(function (r) {
        if (r.error) return toast(r.error);
        toast('Moved to ' + S.boot.stages[r.stage].label);
        return boot().then(function () {
          return api('lead', null, { id: S.detail.id })
                   .then(function (l) { S.detail = l; render(); });
        });
      });
    };
    var save = document.querySelector('[data-save]'); if (save) save.onclick = doSchedule;
    var send = document.querySelector('[data-send]'); if (send) send.onclick = doEmail;
  }

  function disposition(o) {
    var l = S.queue[S.i]; if (!l) return;
    api('disposition', { id: l.id, outcome: o }).then(function (r) {
      if (r.error) return toast(r.error);
      toast('Saved — ' + o.replace('_', ' '));
      S.i++; savePos(); return boot().then(render);
    });
  }
  function doSchedule() {
    var l = S.sheet.lead, k = S.sheet.kind;
    if (!S.sheet.dt) return toast('Pick a time');
    var em = document.getElementById('sch-email');
    api('schedule', { id: l.id, kind: k, when: schedWhenStr(),
                      notes: document.getElementById('sch-note').value || '',
                      invite_email: em ? (em.value || '').trim() : '' })
      .then(function (r) {
        if (r.error) return toast(r.error);
        var bits = [];
        if (r.calendar_synced) bits.push('calendar');
        if (r.trello_carded)   bits.push('Trello');
        if (r.invited)         bits.push('invite sent');
        toast((k === 'demo' ? 'Demo booked' : 'Callback set') + (bits.length ? ' → ' + bits.join(' + ') : ''));
        if (r.meet_link) toast('Meet link created');
        if (r.calendar_error) toast('Calendar: ' + r.calendar_error);
        var fromDetail = S.sheet && S.sheet.from === 'detail';
        S.sheet = null;
        if (fromDetail) {
          return api('lead', null, { id: l.id }).then(function (x) { S.detail = x; render(); });
        }
        S.i++; savePos(); return boot().then(render);
      });
  }
  function doEmail() {
    var l = S.sheet.lead;
    api('email', { id: l.id, subject: document.getElementById('em-subj').value,
                   body: document.getElementById('em-body').value })
      .then(function (r) {
        if (r.error) return toast(r.error);
        toast('Sent to ' + r.to);
        S.sheet = null; return loadQueue().then(render);
      });
  }

  /* Single entry point for changing view: sets state, paints immediately so the
     UI never hangs, then loads that view's data. */
  function go(target, opts) {
    opts = opts || {};
    S.view = target.view;
    if (target.stage && target.stage !== S.stage) { S.stage = target.stage; S.queue = []; S.i = 0; }
    if (target.view === 'detail') { S.detailId = target.id; if (!opts.keep) S.detail = null; }
    render();

    if (target.view === 'dash')    return api('stats').then(function (r) { S.stats = r; render(); });
    if (target.view === 'clients') return api('clients').then(function (r) { S.clients = r.clients; render(); });
    if (target.view === 'cal')     return api('calendar').then(function (r) { S.cal = r.events; render(); });
    if (target.view === 'detail')  return api('lead', null, { id: target.id })
                                     .then(function (l) { S.detail = l; render(); });
    if (target.view === 'call') {
      return loadQueue().then(function () {
        var pos = loadPos();
        if (pos && pos.stage === S.stage && pos.i < S.queue.length) S.i = pos.i;
        render();
      });
    }
    return Promise.resolve();
  }

  window.addEventListener('hashchange', function () {
    var r = readHash();
    if (r && r.view !== S.view) go(r);
  });

  var start = readHash() || { view: 'dash' };
  if (start.view === 'call' && !start.stage) {
    var p = loadPos(); if (p) start.stage = p.stage;
  }

  boot()
    .then(function () { return api('stats').then(function (r) { S.stats = r; }); })
    .then(function () { return go(start); })
    .catch(function (e) {
    app.innerHTML = '<div class="boot">Failed to load — ' + esc(e.message) + '</div>';
  });
})();
