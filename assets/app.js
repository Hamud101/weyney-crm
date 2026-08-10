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
    /* A funnel: the pipeline, narrowing. Reads as "not clients yet". */
    leads:'<svg viewBox="0 0 24 24"><path d="M2.4 3.5A1 1 0 0 1 3.3 3h17.4a1 1 0 0 1 .78 1.63L14.5 13.2V20a1 1 0 0 1-1.45.9l-3-1.5a1 1 0 0 1-.55-.9v-5.3L2.52 4.63a1 1 0 0 1-.12-1.13z"/></svg>',
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
    if (['dash', 'cal', 'clients', 'leads'].indexOf(v) >= 0) return { view: v };
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
  function loadLeads() {
    return api('pipeline').then(function (r) {
      S.leads = r.leads || []; S.leadTotal = r.total || S.leads.length;
    });
  }
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
    /* The logo IS the dashboard button — it always went to #dash, so a second
       grid icon doing the same thing was just taking up a slot. It carries the
       active state like any other tab now. */
    return '<div class="rail">' +
      '<a class="logo' + (S.view === 'dash' ? ' on' : '') + '" href="#dash" ' +
        'data-tab="dash" title="Dashboard">' +
        '<img src="/crm/assets/weyney-logo.svg" alt="Weyney Media"></a>' +
      b('call', 'Call queue') + b('leads', 'Leads') + b('clients', 'Clients') + b('cal', 'Calendar') +
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
    var titles = { dash:'Dashboard', call:'Call queue', cal:'Calendar',
                   clients:'Clients', leads:'Leads' };
    var ctx = '<b>' + (titles[S.view] || 'Dashboard') + '</b>';
    if (S.view === 'call' && S.queue.length) {
      ctx += ' <span class="dim">lead ' + Math.min(S.i + 1, S.queue.length) +
             ' of ' + (S.stageTotal || S.queue.length) + ' in ' +
             esc((S.boot.stages[S.stage] || {}).label || S.stage) + '</span>';
    }
    if (S.view === 'leads' && S.leads) {
      ctx += ' <span class="dim">' + S.leads.length +
             (S.leadTotal > S.leads.length ? ' of ' + S.leadTotal : '') +
             ' not signed yet</span>';
    }
    if (S.view === 'clients' && S.clients) {
      ctx += ' <span class="dim">' + S.clients.length + ' signed</span>';
    }
    return '<div class="head">' +
      '<span class="appname">Weyney Media</span>' +
      '<span class="ctx">' + ctx + '</span>' +
      '<span class="grow"></span>' +
      (S.view === 'call' && S.queue.length ? '<span class="headbar"><i style="width:' + pct + '%"></i></span>' : '') +
      /* Same slot on both list views, but they add different things: a client
         starts at Won, a lead starts wherever the conversation actually is. */
      (S.view === 'clients'
        ? '<button class="addpill" data-newlead="won">+ Add client</button>'
        : '') +
      (S.view === 'leads'
        ? '<button class="addpill" data-newlead="contacted">+ Add lead</button>'
        : '') +
      /* Only on the Calendar view — it was noise everywhere else. */
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
            return '<div class="srow click" data-editevent="' + e.id + '"><span class="sw' + (over ? ' over' : '') + '">' +
              when(e.starts_at) + '</span><span class="sn">' + esc(e.name) + '</span>' +
              '<span class="tag">' + (e.kind === 'demo' ? 'Demo' : 'Call back') + '</span>' +
              (e.kind === 'demo' && e.meet_link
                ? '<a class="meet" href="' + esc(e.meet_link) + '" target="_blank" rel="noopener" ' +
                  'onclick="event.stopPropagation()" title="Join the meeting">Google Meet</a>'
                : '') +
              '<button class="donebtn" data-complete="' + e.id + '" title="Mark this as done">Done</button></div>';
          }).join('') + '</div>'
        : '<div class="bars"><div class="barsempty">Nothing due today. Everything scheduled ' +
          'lives in Calendar and on your Google Calendar.</div></div>') + '</div>';

    /* Upcoming demos with their Meet link right on the dashboard, so joining a
       call never means digging through the calendar first. */
    var demos = d.upcoming_demos || [];
    var demopanel = demos.length
      ? '<div class="panel"><div class="phead">Upcoming demos</div><div class="list flush">' +
          demos.map(function (e) {
            return '<div class="srow click" data-editevent="' + e.id + '"><span class="sw">' + when(e.starts_at) + '</span>' +
              '<span class="sn">' + esc(e.name) + '</span>' +
              (e.meet_link
                ? '<a class="meet" href="' + esc(e.meet_link) + '" target="_blank" rel="noopener" ' +
                  'onclick="event.stopPropagation()" title="Join the meeting">Google Meet</a>'
                : '<span class="nolink">no Meet link</span>') +
              '<button class="donebtn" data-complete="' + e.id + '" title="Mark this demo as done">Done</button>' +
            '</div>';
          }).join('') + '</div></div>'
      : '';

    return cards + nudge + daily + '<div style="margin-top:14px">' + sched + '</div>' +
      (demopanel ? '<div style="margin-top:14px">' + demopanel + '</div>' : '') +
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

    var back = { leads:'leads', clients:'clients', dash:'the dashboard' }[S.from] || 'clients';
    return '<button class="backlink" data-back="1">← Back to ' + back + '</button>' +
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
          '<button class="btn big" data-dproposal="1" ' +
            'title="Set the packages they agreed to">Proposal</button>' +
        '</div>' +
        proposalPanel(l) +
        '<div class="sect" style="margin-top:20px">Pipeline stage</div>' +
        '<select id="d-stage" class="nbfield stagesel">' + stageOptions(l.stage) + '</select>' +
        '<div class="sect" style="margin-top:20px">Schedule</div>' +
        '<div class="dgrid">' +
          '<button class="disp info wide" data-dsched="callback">Schedule Follow-up</button>' +
          '<button class="disp demo wide" data-dsched="demo">Schedule a demo</button>' +
        '</div>' +
        (evs.length
          ? '<div class="lookahead"><div class="sect">Booked <span class="hinttxt">— tap to edit</span></div>' +
            evs.map(function (e) {
              return '<div class="lrow click" data-editevent="' + e.id + '"><span class="lw">' + when(e.starts_at) + '</span>' +
                '<span class="ln">' + (e.kind === 'demo' ? 'Demo' : 'Call back') + '</span>' +
                (e.meet_link ? '<a class="meet" href="' + esc(e.meet_link) + '" target="_blank" rel="noopener" ' +
                  'onclick="event.stopPropagation()" title="Join the meeting">Google Meet</a>' : '') +
                '<span class="editcue">Edit ›</span></div>';
            }).join('') + '</div>'
          : '') +
        logPanel(l) +
      '</div>' +
      '<div class="qright">' +
        whoPanel(l) +
        docsPanel(l) +
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

  /* The nine catalogue services, each tickable on its own. This replaced three
     fixed tier buttons: a tier is a guess at what someone wants, and every real
     deal so far has been a subset with its own price. Ticking is also what the
     agreement needs — each checked line becomes a bullet in Section 1 and a fee
     line in Section 3.

     `amount` is a starting number from documents/service-catalogue-and-business-plan.md
     and is editable on every line. `kind` is what the fee IS, not when it is
     billed — prepaying twelve months of a monthly fee is a separate switch. */
  var SERVICES = [
    { id:'website',   label:'Website build',        amount:500, kind:'once',
      desc:'Design and build a custom multi-page website — Home, About, Services and Contact — built around the Client’s existing logo and brand, with a contact form.' },
    { id:'hosting',   label:'Hosting',              amount:75,  kind:'monthly',
      desc:'Hosting the website on Weyney Media’s infrastructure, including uptime monitoring, regular backups, and SSL.' },
    { id:'refsheet',  label:'Provider referral sheet', amount:0, kind:'once',
      desc:'A one-page referral sheet, print and PDF, written for case managers.' },
    { id:'refpage',   label:'Case-manager referral page', amount:0, kind:'monthly',
      desc:'A /refer page built for referring professionals, kept separate from the main site.' },
    { id:'capacity',  label:'Monthly capacity update', amount:0, kind:'monthly',
      desc:'Current openings published where referrers can see them, updated monthly.' },
    { id:'gbp',       label:'Google Business Profile', amount:0, kind:'monthly',
      desc:'Setting up and maintaining the Google Business Profile panel.' },
    { id:'recruiting',label:'Recruiting campaign',  amount:500, kind:'once',
      desc:'Filling open clinical positions, run as a marketing campaign.' },
    { id:'multiling', label:'Multilingual delivery',amount:350, kind:'once',
      desc:'Site and print materials in the languages the Client’s families actually speak.' },
    { id:'tracking',  label:'Referral source tracking', amount:0, kind:'monthly',
      desc:'A record of where referrals come from and what happens to them.' },
    { id:'documents', label:'Documents and handbooks', amount:600, kind:'once',
      desc:'Design and layout for material the Client is required to produce anyway.' }
  ];
  function serviceById(id) {
    for (var i = 0; i < SERVICES.length; i++) if (SERVICES[i].id === id) return SERVICES[i];
    return null;
  }

  function readProposal(l) {
    if (!l || !l.proposal) return null;
    try { return JSON.parse(l.proposal); } catch (e) { return null; }
  }
  function money(n) { return '$' + (+n || 0).toLocaleString('en-US'); }
  /* The render is queued for a cron worker, so the sheet waits rather than
     getting an answer back from the click. Two seconds apart, giving up after
     about two minutes — long enough for a once-a-minute cron plus the render,
     short enough that a broken worker is obvious instead of silent. */
  function pollProposal(leadId, tries) {
    if (!S.sheet || S.sheet.kind !== 'proposal' || !S.sheet.building) return;
    if (tries > 60) {
      S.sheet.building = false;
      S.sheet.buildErr = 'Nothing came back after two minutes. Is the render ' +
                         'worker scheduled in hPanel?';
      return render();
    }
    api('proposal_job', null, { id: leadId }).then(function (r) {
      if (!S.sheet || S.sheet.kind !== 'proposal') return;
      var j = r.job;
      if (j && j.status === 'done' && r.document) {
        S.sheet.building = false;
        S.sheet.madeDoc = r.document;
        toast('Proposal ready');
        render();
        return api('lead', null, { id: leadId })
                 .then(function (x) { S.detail = x; render(); });
      }
      if (j && j.status === 'failed') {
        S.sheet.building = false;
        S.sheet.buildErr = j.error || 'the render failed';
        return render();
      }
      setTimeout(function () { pollProposal(leadId, tries + 1); }, 2000);
    });
  }

  function todayISO() {
    var d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') +
           '-' + String(d.getDate()).padStart(2, '0');
  }

  /* Due on signing = every one-off line, plus the monthly lines either prepaid
     for the whole term or just the first month. Mirrors the server exactly, so
     the sheet can never show a figure the document contradicts. */
  function proposalTotal(p) {
    if (!p) return 0;
    var t = 0, term = +p.term_months || 12;
    (p.lines || []).forEach(function (l) {
      var a = +l.amount || 0;
      t += l.kind === 'monthly' ? (p.prepaid ? a * term : a) : a;
    });
    return t;
  }
  function monthlyTotal(p) {
    var t = 0;
    ((p && p.lines) || []).forEach(function (l) {
      if (l.kind === 'monthly') t += +l.amount || 0;
    });
    return t;
  }

  /* On the record: what they agreed, at a glance. Absent until one is set, so
     an untouched lead doesn't carry an empty pricing box. */
  function proposalPanel(l) {
    var p = readProposal(l);
    if (!p || !(p.lines || []).length) return '';
    return '<div class="prop">' +
      '<div class="sect">Agreed package ' +
        '<span class="hinttxt">— tap Proposal to change</span></div>' +
      '<div class="propbox">' +
        '<dl class="facts">' +
          (p.lines || []).map(function (li) {
            return '<dt>' + esc(li.label) + '</dt><dd>' + money(li.amount) +
                   (li.kind === 'monthly'
                     ? '/mo' + (p.prepaid ? ' · ' + (p.term_months || 12) + ' mo prepaid' : '')
                     : ' one-time') + '</dd>';
          }).join('') +
        '</dl>' +
        '<div class="proptotal"><span>Due on signing</span><b>' +
          money(proposalTotal(p)) + '</b></div>' +
        (p.notes ? '<p class="propnote">' + esc(p.notes) + '</p>' : '') +
      '</div></div>';
  }

  /* Documents held for this client: the proposal you sent, the agreement that
     came back. Kept on the record rather than in the shared attachments folder,
     because each one belongs to exactly one lead. */
  function docsPanel(l) {
    var docs = l.documents || [];
    return '<div class="docs">' +
      '<div class="sect">Documents' +
        '<span class="hinttxt">— PDF, PNG or JPEG, up to 20 MB</span></div>' +
      (docs.length
        ? '<div class="doclist">' + docs.map(function (d) {
            return '<div class="docrow">' +
              '<a class="docname" href="/crm/doc.php?id=' + esc(d.id) + '" target="_blank" ' +
                'rel="noopener" title="Open this document">' + esc(d.name) + '</a>' +
              '<span class="docmeta">' + docSize(d.size) +
                (d.sent_at ? ' · sent ' + stamp(d.sent_at) : ' · not sent') + '</span>' +
              '<a class="docdl" href="/crm/doc.php?id=' + esc(d.id) + '&dl=1" ' +
                'title="Download">↓</a>' +
              '<button class="docdel" data-docdel="' + esc(d.id) + '" ' +
                'title="Remove this document">×</button>' +
              '</div>';
          }).join('') + '</div>'
        : '<p class="hint">Nothing on file yet.</p>') +
      '<label class="docadd">' +
        '<input type="file" id="doc-file" accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg">' +
        '<span>+ Add a document</span></label>' +
      '</div>';
  }

  /* Clients are the ones who signed — nothing else. Cards, not rows: a handful
     of accounts stretched across a full-width row is mostly empty space, and
     unlike a lead there is no queue position to scan down. Each card carries
     the details you actually reach for mid-call, so the record only has to be
     opened when you want the history. */
  /* Nobody has uploaded a logo file, and pulling a favicon would post every
     client's domain to a third party from inside a private CRM — so the mark is
     drawn from the name itself: initials, plus a hue hashed off the full name so
     the same client always gets the same tile. Low saturation keeps it inside
     the near-monochrome palette rather than turning the page into confetti. */
  var MONO_SKIP = { the:1, and:1, of:1, llc:1, inc:1, 'inc.':1, co:1, ltd:1, group:1 };
  function monogram(name) {
    var words = String(name).replace(/[^\w\s&-]/g, ' ').trim().split(/\s+/);
    var use = words.filter(function (w) { return !MONO_SKIP[w.toLowerCase()]; });
    if (!use.length) use = words;
    /* One-word names ("Hoops4Unity") take their first two letters — a single
       letter is not enough to tell two clients apart at a glance. */
    var ini = use.length > 1
      ? (use[0] || '').charAt(0) + (use[1] || '').charAt(0)
      : (use[0] || '').slice(0, 2);
    var h = 0;
    for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
    return '<span class="ccmark" aria-hidden="true" style="background:hsl(' + h +
           ' 32% 91%);color:hsl(' + h + ' 42% 28%)">' + esc(ini.toUpperCase()) + '</span>';
  }

  function clientCard(l) {
    var fact = function (k, v) {
      return v ? '<dt>' + k + '</dt><dd>' + v + '</dd>' : '';
    };
    return '<div class="ccard click" data-openlead="' + esc(l.id) + '">' +
      '<div class="cctop">' + monogram(l.name) +
        '<div class="ccid"><div class="ccname">' + esc(l.name) + '</div>' +
        (l.city ? '<div class="ccwhere">' + esc(l.city) + '</div>' : '') + '</div>' +
      '</div>' +
      '<dl class="facts">' +
        fact('Contact', esc(l.contact)) +
        fact('Phone', l.phone
          ? '<a href="' + dialHref(l.phone) + '" target="_blank" rel="noopener" ' +
            'onclick="event.stopPropagation()" title="Dial with Google Voice">' +
            esc(prettyPhone(l.phone)) + '</a>'
          : '') +
        fact('Email', l.email
          ? '<a href="mailto:' + esc(l.email) + '" onclick="event.stopPropagation()">' +
            esc(l.email) + '</a>'
          : '') +
        fact('Website', l.website
          ? '<a href="https://' + esc(l.website) + '" target="_blank" rel="noopener" ' +
            'onclick="event.stopPropagation()">' + esc(l.website) + '</a>'
          : '') +
      '</dl>' +
      '<div class="ccfoot">' +
        (l.next_at
          ? '<span class="nx">' + when(l.next_at) + '</span>'
          : '<span class="nx none">nothing booked</span>') +
        (l.last_ts ? '<span class="cclast">last touched ' + stamp(l.last_ts) + '</span>' : '') +
      '</div></div>';
  }

  function viewClients() {
    var c = S.clients;
    if (!c) return '<div class="panel"><div class="empty">Loading…</div></div>';
    if (!c.length) return '<div class="panel"><div class="empty"><b>No clients yet</b>' +
      'A lead becomes a client when they sign — move them to Won and they appear here.</div></div>';
    return '<div class="cgroup won">' +
      '<div class="ghead"><b>Clients</b>' +
        '<span class="gsub">Signed and paying</span>' +
        '<span class="gcount">' + c.length + '</span></div>' +
      '<div class="cgrid">' + c.map(clientCard).join('') + '</div></div>';
  }

  /* Leads: everyone who has not signed. `tone` picks the badge colour from the
     status tokens already in the palette — warm for something slipping, blue
     for something in flight, grey for cold. */
  var LEAD_GROUPS = [
    { key:'proposal', label:'Proposal out',   tone:'info',  stages:['proposal'] },
    { key:'held',     label:'Demo held',      tone:'ok',    stages:['demo_done'] },
    { key:'booked',   label:'Demo scheduled', tone:'ok',    stages:['demo_set'] },
    { key:'noshow',   label:'Demo no-show',   tone:'warn',  stages:['demo_noshow'] },
    { key:'talking',  label:'In conversation',tone:'ok',    stages:['contacted'] },
    { key:'vm',       label:'Voicemail',      tone:'info',  stages:['voicemail'] },
    { key:'noanswer', label:'No answer',      tone:'grey',  stages:['attempting'] },
    { key:'nurture',  label:'Nurture',        tone:'grey',  stages:['nurture'] },
    { key:'cold',     label:'Not called yet', tone:'grey',  stages:['new'] },
    { key:'lost',     label:'Lost',           tone:'bad',   stages:['lost'] }
  ];
  function groupFor(stage) {
    for (var i = 0; i < LEAD_GROUPS.length; i++) {
      if (LEAD_GROUPS[i].stages.indexOf(stage) >= 0) return LEAD_GROUPS[i];
    }
    return { key:'', label:stage, tone:'grey' };
  }
  /* LEAD_GROUPS is already ordered closest-to-signing first, so its index is
     the sort key: no second list to keep in step with the first. */
  function depth(stage) {
    var i = LEAD_GROUPS.indexOf(groupFor(stage));
    return i < 0 ? LEAD_GROUPS.length : i;
  }

  /* One dropdown instead of a strip of ten pills. The strip made you read the
     whole pipeline before you could pick out of it, and it wrapped badly. */
  function leadPicker() {
    var opts = '<option value=""' + (S.leadFilter ? '' : ' selected') + '>' +
               'All leads (' + S.leads.length + ')</option>';
    LEAD_GROUPS.forEach(function (g) {
      var n = S.leads.filter(function (l) { return g.stages.indexOf(l.stage) >= 0; }).length;
      if (!n && S.leadFilter !== g.key) return;
      opts += '<option value="' + g.key + '"' + (S.leadFilter === g.key ? ' selected' : '') +
              '>' + esc(g.label) + ' (' + n + ')</option>';
    });
    return '<div class="lbar">' +
      '<label class="lbl" for="l-cat">Show</label>' +
      '<select id="l-cat" class="lpick" data-lstage="1">' + opts + '</select>' +
      '<span class="grow"></span>' +
      (S.leadTotal > S.leads.length
        ? '<span class="capnote">' + S.leads.length + ' most recently touched of ' +
          S.leadTotal + '</span>'
        : '') +
      '</div>';
  }

  /* One continuous table, sorted by how far along each lead is, with the stage
     as a badge on the row. Ten stacked stage sections meant ten headings, ten
     restarts, and no way to compare two leads in different stages — this gives
     a single scan path down the page and keeps the distinction visible. */
  function viewLeads() {
    if (!S.leads) return '<div class="panel"><div class="empty">Loading…</div></div>';
    if (!S.leads.length) return '<div class="panel"><div class="empty"><b>No leads</b>' +
      'Import a call list, or add one by hand with + Add lead.</div></div>';

    var rows = S.leads.filter(function (l) {
      return !S.leadFilter || groupFor(l.stage).key === S.leadFilter;
    });
    /* The server already sorted by last touched; a stable sort by depth keeps
       that as the tie-break inside each stage. */
    rows = rows.slice().sort(function (a, b) { return depth(a.stage) - depth(b.stage); });
    var shown = rows.slice(0, 300);

    if (!rows.length) {
      return leadPicker() + '<div class="panel"><div class="empty"><b>Nothing here</b>' +
        'No leads in that category — pick another, or All leads.</div></div>';
    }

    return leadPicker() +
      '<div class="panel ltwrap"><table class="ltable"><thead><tr>' +
        '<th>Business</th><th>Stage</th><th>Contact</th><th>Phone</th>' +
        '<th>Next / last touched</th><th class="thmove">Move to</th>' +
      '</tr></thead><tbody>' +
      shown.map(function (l) {
        var g = groupFor(l.stage);
        var next = l.next_at
          ? '<span class="nx">' + when(l.next_at) + '</span>'
          /* A bare date in this column reads as something upcoming. Only a
             booked event is; everything else is history, so say so. */
          : '<span class="nx none">' +
            (l.last_ts ? 'touched ' + stamp(l.last_ts) : 'never touched') + '</span>';
        return '<tr class="click" data-openlead="' + esc(l.id) + '">' +
          '<td class="ltname"><b>' + esc(l.name) + '</b>' +
            (l.city ? '<span>' + esc(l.city) + '</span>' : '') + '</td>' +
          '<td><span class="sbadge ' + g.tone + '">' + esc(g.label) + '</span></td>' +
          '<td class="ltdim">' + (l.contact ? esc(l.contact) : '—') + '</td>' +
          '<td>' + (l.phone
            ? '<a href="' + dialHref(l.phone) + '" target="_blank" rel="noopener" ' +
              'onclick="event.stopPropagation()" title="Dial with Google Voice">' +
              esc(prettyPhone(l.phone)) + '</a>'
            : '<span class="ltdim">—</span>') + '</td>' +
          '<td>' + next + '</td>' +
          '<td><select class="rowstage" data-move="' + esc(l.id) + '" ' +
            'title="Move this lead to another stage">' + stageOptions(l.stage) +
            '</select></td></tr>';
      }).join('') +
      '</tbody></table>' +
      (rows.length > shown.length
        ? '<p class="capnote">+' + (rows.length - shown.length) +
          ' more — work them from the call queue</p>'
        : '') +
      '</div>';
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
      var won = S.sheet.stage === 'won';
      return '<div class="sheet-bg" data-close="1"><div class="sheet">' +
        '<h3>' + (won ? 'Add a client' : 'Add a lead') + '</h3>' +
        '<div class="who">' + (won
          ? 'Someone already signed — they go straight to Clients'
          : 'Someone who came in outside the cold list') + '</div>' +
        '<label>Business name</label><input id="nl-name" placeholder="Caring Hands Autism" autofocus>' +
        '<label>Contact</label><input id="nl-contact" placeholder="Who you deal with">' +
        '<label>Phone</label><input id="nl-phone" placeholder="612-555-0123">' +
        '<label>Email</label><input id="nl-email" type="email" placeholder="them@company.com">' +
        '<label>City</label><input id="nl-city" placeholder="Minneapolis">' +
        '<label>Website</label><input id="nl-web" placeholder="theircompany.com">' +
        '<label>Social profiles <span class="opt">— paste URLs, comma separated</span></label>' +
        '<input id="nl-social" placeholder="facebook.com/theirpage, instagram.com/theirpage">' +
        '<label>Where are they in the pipeline?</label>' +
        '<select id="nl-stage" class="nbfield">' +
          stageOptions(S.sheet.stage || 'contacted') + '</select>' +
        '<label>How did they come in?</label>' +
        '<input id="nl-source" placeholder="Referral, inbound, event…">' +
        '<div class="acts"><button class="cancel" data-close="1">Cancel</button>' +
        '<button class="go" data-newsave="1">Add</button></div></div></div>';
    }
    if (k === 'proposal') {
      var pl = S.sheet.lead, p = S.sheet.p;
      var picked = {};
      (p.lines || []).forEach(function (li) { picked[li.id] = li; });

      /* One row per catalogue service. Ticking reveals its price and whether
         that price is one-off or monthly — an unticked line shows nothing, so
         the list stays readable at ten services. */
      var list = SERVICES.map(function (s) {
        var li = picked[s.id], on = !!li;
        return '<div class="svc' + (on ? ' on' : '') + '">' +
          '<label class="svchk"><input type="checkbox" data-svc="' + s.id + '"' +
            (on ? ' checked' : '') + '><span>' + esc(s.label) + '</span></label>' +
          (on
            ? '<input class="svamt" type="number" min="0" step="10" data-svc="' + s.id + '" ' +
                'value="' + (+li.amount || 0) + '">' +
              '<select class="svkind" data-svc="' + s.id + '">' +
                '<option value="once"' + (li.kind !== 'monthly' ? ' selected' : '') + '>one-time</option>' +
                '<option value="monthly"' + (li.kind === 'monthly' ? ' selected' : '') + '>per month</option>' +
              '</select>'
            : '<span class="svhint">' + money(s.amount) +
              (s.kind === 'monthly' ? '/mo' : '') + ' list</span>') +
          '</div>';
      }).join('');

      var mo = monthlyTotal(p);
      var doc = S.sheet.madeDoc;
      var due = proposalTotal(p);

      /* Two columns so all ten services are on screen at once — the whole point
         of ticking is comparing what is in and what is out, and that stops
         working the moment half the list is below the fold. Terms sit beside
         the list rather than under it for the same reason. */
      return '<div class="sheet-bg" data-close="1"><div class="sheet proposal">' +
        '<h3>Proposal</h3><div class="who">' + esc(pl.name) + '</div>' +
        '<div class="prcols">' +
          '<div class="prleft">' +
            '<label>What they are buying <span class="opt">— tick each one; every price is editable</span></label>' +
            '<div class="svclist">' + list + '</div>' +
          '</div>' +
          '<div class="prright">' +
            (mo
              ? '<label class="chk"><input type="checkbox" id="pr-prepaid"' +
                  (p.prepaid ? ' checked' : '') + '> Prepay the monthly fees up front</label>' +
                (p.prepaid
                  ? '<div><label>Term <span class="opt">— months prepaid</span></label>' +
                    '<input id="pr-term" type="number" min="1" max="60" value="' +
                    (+p.term_months || 12) + '"></div>'
                  : '')
              : '<p class="hint">Tick a per-month service to set a prepaid term.</p>') +
            '<div class="pgrid">' +
              '<div><label>Change fee <span class="opt">— per request</span></label>' +
                '<input id="pr-changefee" type="number" min="0" step="5" value="' +
                (p.change_fee == null ? 50 : +p.change_fee) + '"></div>' +
              '<div><label>Agreement date</label>' +
                '<input id="pr-date" type="date" value="' + esc(p.date || todayISO()) + '"></div>' +
            '</div>' +
            '<label>Notes <span class="opt">— appears under the fees in the agreement</span></label>' +
            '<textarea id="pr-notes" rows="4" placeholder="No monthly maintenance plan is included. Changes after launch are billed individually.">' +
              esc(p.notes || '') + '</textarea>' +
            (S.sheet.building
              ? '<div class="attach">Building the PDF… the renderer runs on a ' +
                'schedule, so this takes up to a minute.</div>'
              : S.sheet.buildErr
              ? '<div class="attach missing">' + esc(S.sheet.buildErr) + '</div>'
              : doc
              ? '<div class="attach">Generated <b>' + esc(doc.name) + '</b> — ' +
                'on the record and in the email sheet. ' +
                '<a href="/crm/doc.php?id=' + esc(doc.id) + '" target="_blank" rel="noopener">Open it</a></div>'
              : '') +
          '</div>' +
        '</div>' +
        /* Pinned: the running total is the reason to be in this sheet, so it
           must not scroll away while you are ticking things. */
        '<div class="prfoot">' +
          '<div class="prsum">' +
            '<span class="prsumlab">Due on signing</span>' +
            '<b class="prsumval">' + money(due) + '</b>' +
            (mo
              ? '<span class="prsummo">' + (p.prepaid
                  ? 'includes ' + money(mo) + '/mo × ' + (p.term_months || 12)
                  : 'then ' + money(mo) + '/mo') + '</span>'
              : '') +
          '</div>' +
          '<div class="acts"><button class="cancel" data-close="1">Close</button>' +
          '<button class="btn" data-prsave="1">Save only</button>' +
          '<button class="go" data-prgen="1"' +
            (!(p.lines || []).length || S.sheet.building
              ? ' disabled title="Tick at least one service"' : '') +
            '>' + (S.sheet.building ? 'Building…' : 'Save &amp; generate PDF') +
            '</button></div>' +
        '</div></div></div>';
    }
    if (k === 'email') {
      var l = S.sheet.lead;
      return '<div class="sheet-bg" data-close="1"><div class="sheet email">' +
        '<h3>Email</h3><div class="who">' + esc(l.name) + ' · ' + esc(l.email) + '</div>' +
        tplPicker() + docPicker() +
        '<label>Subject</label><input id="em-subj" value="' + esc(S.sheet.subj || '') + '">' +
        '<label>Message</label><textarea id="em-body" rows="8">' + esc(S.sheet.body || '') + '</textarea>' +
        attachLine() +
        '<div class="acts"><button class="cancel" data-close="1">Cancel</button>' +
        '<button class="go" data-send="1">Send</button></div></div></div>';
    }
    /* While the calendar is open it takes over the sheet: just the date, the
       time and Done. The invite, the note and Schedule only reappear once a
       time is set, so the picker is never competing with the rest of the form. */
    var isEdit = !!S.sheet.editId;
    var noun = k === 'demo' ? 'demo' : 'callback';
    var body = S.sheet.cancelling
      ? cancelConfirm()
      : S.sheet.confirming
      ? confirmInvite()
      : S.sheet.calOpen
      ? schedField()
      : '<div class="quick">' +
          '<button data-quick="3h">In 3 hours</button><button data-quick="tom9">Tomorrow 9am</button>' +
          '<button data-quick="tom2">Tomorrow 2pm</button><button data-quick="week">Next week</button>' +
        '</div>' +
        schedField() +
        (k === 'demo'
          ? '<label>Invite them <span class="opt">— sends a Google Meet link</span></label>' +
            '<input type="email" id="sch-email" placeholder="their@email.com" value="' +
              esc(S.sheet.email || '') + '">' +
            '<div class="hint">' + (S.sheet.email
              ? 'They will get a calendar invite with a Meet link.'
              : 'No email on this lead yet — add one to send an invite, or leave blank to just block the time.') +
            '</div>'
          : '') +
        '<label>Note</label><textarea id="sch-note" rows="2" placeholder="What to cover">' +
          esc(S.sheet.note || '') + '</textarea>' +
        (isEdit ? '<button class="quietbad" data-askcancel="1">Cancel this ' + noun + '</button>' : '') +
        '<div class="acts"><button class="cancel" data-close="1">' + (isEdit ? 'Close' : 'Cancel') + '</button>' +
        '<button class="go" data-save="1">' + (isEdit ? 'Save changes' : 'Schedule') + '</button></div>';
    return '<div class="sheet-bg" data-close="1"><div class="sheet' + (S.sheet.calOpen ? ' picking' : '') + '">' +
      '<h3>' + (S.sheet.cancelling ? 'Cancel this ' + noun + '?'
               : S.sheet.confirming ? (isEdit ? 'Update the Meet invite?' : 'Send Google Meet invite?')
               : isEdit ? 'Edit ' + noun
               : k === 'demo' ? 'Schedule a demo' : 'Set a callback') + '</h3>' +
      '<div class="who">' + esc(S.sheet.lead.name) + '</div>' +
      body + '</div></div>';
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
             calMonth: new Date(d.getFullYear(), d.getMonth(), 1).getTime(), calOpen: false,
             email: lead.email || '', note: '' };
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
  /* Last look before a Meet invite goes out: exactly who gets emailed, and when. */
  function confirmInvite() {
    var d = new Date(S.sheet.dt);
    var emails = (S.sheet.email || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var isEdit = !!S.sheet.editId;
    var msg = isEdit
      ? 'You changed a demo with a guest invited. Email them the updated time?'
      : 'A Google Meet link will be created and a calendar invite emailed to:';
    // On an edit you can save the change without pinging anyone — same choice
    // Google Calendar offers (Send / Don't send).
    var acts = isEdit
      ? '<div class="acts three"><button class="cancel" data-unconfirm="1">Back</button>' +
        '<button class="cancel" data-savequiet="1">Don\'t send</button>' +
        '<button class="go" data-sendinvite="1">Send</button></div>'
      : '<div class="acts"><button class="cancel" data-unconfirm="1">Back</button>' +
        '<button class="go" data-sendinvite="1">Send invite</button></div>';
    return '<div class="confirmbox">' +
      '<p class="cmsg">' + msg + '</p>' +
      '<ul class="clist">' + emails.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>' +
      '<p class="cwhen">' + esc(fmtRead(d)) + '</p></div>' + acts;
  }
  /* Confirm before pulling a booked event off the calendar. */
  function cancelConfirm() {
    var k = S.sheet.kind, noun = k === 'demo' ? 'demo' : 'callback';
    var tellsAttendee = k === 'demo' && S.sheet.email;
    return '<div class="confirmbox">' +
      '<p class="cmsg">This removes the ' + noun + ' from Google Calendar' +
        (tellsAttendee ? ' and emails a cancellation to:' : '.') + '</p>' +
      (tellsAttendee ? '<ul class="clist"><li>' + esc(S.sheet.email) + '</li></ul>' : '') +
      '</div>' +
      '<div class="acts"><button class="cancel" data-uncancel="1">Keep it</button>' +
      '<button class="go danger" data-docancel="1">Cancel ' + noun + '</button></div>';
  }

  /* Email templates. The subject and body live in sheet state rather than only
     in the DOM, because picking a template repaints both fields — and anything
     typed before the picker arrives has to survive that repaint.
     The server fills the placeholders: the client never guesses a contact name
     or a company for a prospect. */
  function emailSheet(lead, from) {
    var s = { lead: lead, kind: 'email', from: from, tpl: '', tpls: null,
              doc: '', docs: (lead.documents || []).slice(),
              subj: 'Quick question about ' + lead.name, body: '' };
    api('templates', null, { id: lead.id }).then(function (r) {
      if (S.sheet !== s) return;          // closed, or another sheet took over
      s.tpls = (r && r.templates) || [];
      render();
    });
    /* The call queue's lead objects don't carry documents; the detail view's
       do. Fetch either way so the sheet is the same from both. */
    api('docs', null, { id: lead.id }).then(function (r) {
      if (S.sheet !== s) return;
      s.docs = (r && r.documents) || [];
      render();
    });
    return s;
  }
  function tplFor(id) {
    var found = null;
    (S.sheet.tpls || []).forEach(function (t) { if (t.id === id) found = t; });
    return found;
  }
  function tplPicker() {
    var tpls = S.sheet.tpls;
    if (!tpls || !tpls.length) return '';
    return '<label>Template</label><div class="tpls">' +
      '<button class="tpl' + (S.sheet.tpl || S.sheet.doc ? '' : ' on') + '" data-tpl="">Blank' +
        '<span>Write it yourself — nothing attached</span></button>' +
      tpls.map(function (t) {
        return '<button class="tpl' + (S.sheet.tpl === t.id ? ' on' : '') +
          '" data-tpl="' + esc(t.id) + '">' + esc(t.name) +
          '<span>' + esc(t.blurb) + '</span></button>';
      }).join('') + '</div>';
  }

  /* This lead's own documents — the proposal, the signed agreement. Separate
     from templates because they are not interchangeable: a template is the same
     PDF for everyone, one of these belongs to this client only. Picking one
     clears the template, since the server takes one attachment or the other. */
  function docPicker() {
    var docs = S.sheet.docs || [];
    if (!docs.length) {
      return '<label>Attach a document</label>' +
        '<p class="hint">Nothing on file for ' + esc(S.sheet.lead.name) + ' yet — ' +
        'add one from their record, under Documents.</p>';
    }
    return '<label>Attach a document <span class="opt">— on file for this client</span></label>' +
      '<div class="tpls">' + docs.map(function (d) {
        return '<button class="tpl' + (S.sheet.doc === d.id ? ' on' : '') +
          '" data-doc="' + esc(d.id) + '">' + esc(d.name) +
          '<span>' + docSize(d.size) +
          (d.sent_at ? ' · already sent ' + stamp(d.sent_at) : ' · not sent yet') +
          '</span></button>';
      }).join('') + '</div>';
  }
  function docSize(n) {
    n = +n || 0;
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB'
         : n >= 1024    ? Math.round(n / 1024) + ' KB' : n + ' B';
  }

  /* What is going out with the message, said plainly — a PDF the sender can't
     see in the box is worth spelling out. */
  function attachLine() {
    if (S.sheet.doc) {
      var d = null;
      (S.sheet.docs || []).forEach(function (x) { if (x.id === S.sheet.doc) d = x; });
      return d ? '<div class="attach">Attaching <b>' + esc(d.name) + '</b>' +
        (d.name !== d.send_name
          ? ' <span class="opt">— sent as ' + esc(d.send_name) + '</span>'
          : '') + '</div>' : '';
    }
    var t = S.sheet.tpl ? tplFor(S.sheet.tpl) : null;
    if (!t) return '';
    return t.ready
      ? '<div class="attach">Attaching <b>' + esc(t.attachment) + '</b></div>'
      : '<div class="attach missing"><b>' + esc(t.attachment) + '</b> is not on the ' +
        'server — deploy the attachments folder before sending this one.</div>';
  }

  function render() {
    if (!S.boot) return;
    app.innerHTML = rail() +
      '<div class="main">' + head() +
        '<div class="body"><div class="wrap">' +
          (S.view === 'call' ? viewCall() :
           S.view === 'cal' ? viewCal() :
           S.view === 'detail' ? viewDetail() :
           S.view === 'leads' ? viewLeads() :
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
      el.onclick = function () { S.sheet = emailSheet(S.queue[S.i]); render(); };
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
    // Keep the note and invite in state so opening the calendar doesn't wipe them.
    var schEmail = document.getElementById('sch-email');
    if (schEmail) schEmail.oninput = function () { S.sheet.email = schEmail.value; };
    var schNote = document.getElementById('sch-note');
    if (schNote) schNote.oninput = function () { S.sheet.note = schNote.value; };
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
    app.querySelectorAll('[data-editevent]').forEach(function (el) {
      el.onclick = function (e) { e.stopPropagation(); openEditEvent(+el.dataset.editevent); };
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
      el.onclick = function () { go({ view: S.from || 'clients' }); };
    });
    var lcat = document.getElementById('l-cat');
    if (lcat) lcat.onchange = function () { S.leadFilter = lcat.value; render(); };
    /* Move a lead without opening it — the one edit this list needs, because
       "they signed" is the change you make most often and it belongs next to
       the name. Moving to Won drops them off this list and into Clients. */
    app.querySelectorAll('[data-move]').forEach(function (el) {
      el.onclick = function (e) { e.stopPropagation(); };   // don't open the record
      el.onchange = function () {
        var id = el.dataset.move, stage = el.value;
        var lead = S.leads.filter(function (l) { return l.id === id; })[0];
        api('set_stage', { id: id, stage: stage }).then(function (r) {
          if (r.error) return toast(r.error);
          var label = (S.boot.stages[r.stage] || {}).label || r.stage;
          toast((lead ? lead.name : 'Lead') + ' → ' + label);
          return boot().then(function () { return loadLeads().then(render); });
        });
      };
    });
    app.querySelectorAll('[data-dsched]').forEach(function (el) {
      el.onclick = function () {
        S.sheet = schedSheet(S.detail, el.dataset.dsched, 'detail');
        render();
      };
    });
    app.querySelectorAll('[data-demail]').forEach(function (el) {
      el.onclick = function () { S.sheet = emailSheet(S.detail, 'detail'); render(); };
    });
    /* ---- proposal sheet ---- */
    app.querySelectorAll('[data-dproposal]').forEach(function (el) {
      el.onclick = function () {
        var p = readProposal(S.detail) ||
          { lines:[], term_months:12, prepaid:false, change_fee:50,
            date:todayISO(), notes:'' };
        S.sheet = { kind:'proposal', lead:S.detail, p:p, madeDoc:null };
        render();
      };
    });
    /* Read every field back into S.sheet.p before any re-render, so the running
       total is right and nothing typed is lost when a tick redraws the sheet. */
    function prRead() {
      var p = S.sheet.p, g = function (id) { return document.getElementById(id); };
      if (g('pr-term'))      p.term_months = +g('pr-term').value || 12;
      if (g('pr-notes'))     p.notes       = g('pr-notes').value;
      if (g('pr-changefee')) p.change_fee  = +g('pr-changefee').value || 0;
      if (g('pr-date'))      p.date        = g('pr-date').value;
      (p.lines || []).forEach(function (li) {
        var amt = document.querySelector('.svamt[data-svc="' + li.id + '"]');
        var knd = document.querySelector('.svkind[data-svc="' + li.id + '"]');
        if (amt) li.amount = +amt.value || 0;
        if (knd) li.kind   = knd.value;
      });
    }
    app.querySelectorAll('[data-svc]').forEach(function (el) {
      if (el.type === 'checkbox') {
        el.onchange = function () {
          prRead();
          var p = S.sheet.p, id = el.dataset.svc;
          if (el.checked) {
            var s = serviceById(id);
            /* Keep catalogue order however they are ticked — the agreement
               reads website-then-hosting, not click order. */
            p.lines = (p.lines || []).concat([
              { id:s.id, label:s.label, amount:s.amount, kind:s.kind, desc:s.desc }
            ]).sort(function (a, b) {
              return SERVICES.indexOf(serviceById(a.id)) - SERVICES.indexOf(serviceById(b.id));
            });
          } else {
            p.lines = (p.lines || []).filter(function (x) { return x.id !== id; });
          }
          render();
        };
      } else {
        el.oninput  = function () { prRead(); render(); };
        el.onchange = function () { prRead(); render(); };
      }
    });
    var prTerm = document.getElementById('pr-term');
    if (prTerm) prTerm.oninput = function () { prRead(); render(); };
    var prPre = document.getElementById('pr-prepaid');
    if (prPre) prPre.onchange = function () {
      prRead();
      S.sheet.p.prepaid = prPre.checked;
      if (S.sheet.p.prepaid && !S.sheet.p.term_months) S.sheet.p.term_months = 12;
      render();
    };

    function prSaveThen(next) {
      prRead();
      var leadId = S.sheet.lead.id;
      return api('save_proposal', { id: leadId, proposal: S.sheet.p }).then(function (r) {
        if (r.error) { toast(r.error); return null; }
        return next ? next(leadId, r) : r;
      });
    }
    var prSave = document.querySelector('[data-prsave]');
    if (prSave) prSave.onclick = function () {
      prSaveThen(function (leadId, r) {
        toast('Saved — ' + money(r.total) + ' due on signing');
        S.sheet = null;
        return api('lead', null, { id: leadId }).then(function (x) { S.detail = x; render(); });
      });
    };
    var prGen = document.querySelector('[data-prgen]');
    if (prGen) prGen.onclick = function () {
      prGen.disabled = true;
      prSaveThen(function (leadId) {
        return api('generate_proposal', { id: leadId }).then(function (g) {
          if (g.error) { prGen.disabled = false; return toast(g.error); }
          if (S.sheet && S.sheet.kind === 'proposal') {
            S.sheet.building = true; S.sheet.buildErr = null;
          }
          render();
          pollProposal(leadId, 0);
        });
      });
    };

    /* Upload goes as multipart, so it cannot use api() — that always sends
       JSON. The CSRF header is the same either way. */
    var df = document.getElementById('doc-file');
    if (df) df.onchange = function () {
      var f = df.files && df.files[0];
      if (!f) return;
      var fd = new FormData();
      fd.append('id', S.detail.id);
      fd.append('file', f);
      toast('Uploading ' + f.name + '…');
      fetch('/crm/api.php?a=upload_doc', {
        method: 'POST', headers: { 'X-CSRF': CSRF }, body: fd
      }).then(function (r) { return r.json(); }).then(function (r) {
        if (r.error) return toast(r.error);
        toast('Added ' + r.document.name);
        S.detail.documents = r.documents;
        render();
      }).catch(function (e) { toast('Upload failed — ' + e.message); });
    };
    app.querySelectorAll('[data-docdel]').forEach(function (el) {
      el.onclick = function () {
        api('delete_doc', { document: el.dataset.docdel }).then(function (r) {
          if (r.error) return toast(r.error);
          toast('Removed');
          S.detail.documents = r.documents;
          render();
        });
      };
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
      el.onclick = function () {
        S.sheet = { kind: 'newlead', stage: el.dataset.newlead || 'contacted' };
        render();
      };
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
    var sendInv = document.querySelector('[data-sendinvite]');
    if (sendInv) sendInv.onclick = function () { commitSchedule(true); };
    var saveq = document.querySelector('[data-savequiet]');
    if (saveq) saveq.onclick = function () { commitSchedule(false); };
    var unconf = document.querySelector('[data-unconfirm]');
    if (unconf) unconf.onclick = function () { S.sheet.confirming = false; render(); };
    var askc = document.querySelector('[data-askcancel]');
    if (askc) askc.onclick = function () { S.sheet.cancelling = true; render(); };
    var unc = document.querySelector('[data-uncancel]');
    if (unc) unc.onclick = function () { S.sheet.cancelling = false; render(); };
    var docx = document.querySelector('[data-docancel]');
    if (docx) docx.onclick = doCancelEvent;
    document.querySelectorAll('[data-tpl]').forEach(function (el) {
      el.onclick = function () {
        var t = el.dataset.tpl ? tplFor(el.dataset.tpl) : null;
        S.sheet.tpl  = t ? t.id : '';
        S.sheet.doc  = '';                  // one attachment, not both
        S.sheet.subj = t ? t.subject : 'Quick question about ' + S.sheet.lead.name;
        S.sheet.body = t ? t.body : '';
        render();
      };
    });
    /* Picking a document keeps whatever is already typed — unlike a template it
       carries no wording of its own, and the covering note is usually the part
       worth keeping. Clicking the chosen one again clears it. */
    document.querySelectorAll('[data-doc]').forEach(function (el) {
      el.onclick = function () {
        var id = el.dataset.doc;
        S.sheet.doc = (S.sheet.doc === id) ? '' : id;
        if (S.sheet.doc) S.sheet.tpl = '';
        var subj = document.getElementById('em-subj');
        var body = document.getElementById('em-body');
        if (subj) S.sheet.subj = subj.value;
        if (body) S.sheet.body = body.value;
        render();
      };
    });
    var esub = document.getElementById('em-subj');
    if (esub) esub.oninput = function () { S.sheet.subj = esub.value; };
    var ebod = document.getElementById('em-body');
    if (ebod) ebod.oninput = function () { S.sheet.body = ebod.value; };
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
    if (!S.sheet.dt) return toast('Pick a time');
    // A demo that will email an invite gets a confirmation step first, showing
    // exactly who the Google Meet invite goes to.
    if (S.sheet.kind === 'demo' && (S.sheet.email || '').trim() && !S.sheet.confirming) {
      S.sheet.confirming = true; render(); return;
    }
    commitSchedule();
  }
  function commitSchedule(notify) {
    var l = S.sheet.lead, k = S.sheet.kind, edit = S.sheet.editId;
    var email = k === 'demo' ? (S.sheet.email || '').trim() : '';
    var payload = edit
      ? { event_id: edit, when: schedWhenStr(), notes: S.sheet.note || '',
          invite_email: email, notify: notify !== false }
      : { id: l.id, kind: k, when: schedWhenStr(), notes: S.sheet.note || '', invite_email: email };
    api(edit ? 'update_event' : 'schedule', payload).then(function (r) {
      if (r.error) { S.sheet.confirming = false; render(); return toast(r.error); }
      var bits = [];
      if (r.calendar_synced) bits.push('calendar');
      if (r.trello_carded)   bits.push('Trello');
      if (r.invited)         bits.push(edit ? 'invite updated' : 'invite sent');
      else if (edit && email && notify === false) bits.push('no email sent');
      var verb = edit ? (k === 'demo' ? 'Demo updated' : 'Callback updated')
                      : (k === 'demo' ? 'Demo booked' : 'Callback set');
      toast(verb + (bits.length ? ' → ' + bits.join(' + ') : ''));
      if (r.calendar_error) toast('Calendar: ' + r.calendar_error);
      var from = S.sheet.from, wasEdit = !!edit;
      S.sheet = null;
      return refreshAfterEvent(from, wasEdit, l.id);
    });
  }
  function doCancelEvent() {
    var from = S.sheet.from, leadId = S.sheet.lead.id;
    api('cancel_event', { event_id: S.sheet.editId }).then(function (r) {
      if (r.error) { S.sheet.cancelling = false; render(); return toast(r.error); }
      toast('Cancelled');
      S.sheet = null;
      return refreshAfterEvent(from, true, leadId);
    });
  }
  /* After booking/editing/cancelling, reload whichever view you came from. */
  function refreshAfterEvent(from, wasEdit, leadId) {
    if (from === 'detail') return api('lead', null, { id: leadId }).then(function (x) { S.detail = x; render(); });
    if (from === 'cal')    return boot().then(function () { return api('calendar').then(function (x) { S.cal = x.events; render(); }); });
    if (from === 'dash')   return boot().then(function () { return api('stats').then(function (x) { S.stats = x; render(); }); });
    if (!wasEdit) { S.i++; savePos(); }   // a fresh booking from the call queue
    return boot().then(render);
  }
  /* Open the edit sheet for a booked event, from any view. */
  function openEditEvent(id) {
    api('event', null, { id: id }).then(function (ev) {
      if (ev.error) return toast(ev.error);
      var d = new Date(ev.starts_at * 1000);
      S.sheet = {
        editId: ev.id, kind: ev.kind,
        lead: { id: ev.lead_id, name: ev.name, email: ev.email || '' },
        from: S.view === 'detail' ? 'detail' : S.view === 'cal' ? 'cal' : 'dash',
        dt: snap5(d.getTime()),
        calMonth: new Date(d.getFullYear(), d.getMonth(), 1).getTime(),
        calOpen: false, confirming: false, cancelling: false,
        email: ev.invite_email || ev.email || '', note: ev.notes || '', meet_link: ev.meet_link || ''
      };
      render();
    });
  }
  function doEmail() {
    var l = S.sheet.lead, from = S.sheet.from;
    var t = S.sheet.tpl ? tplFor(S.sheet.tpl) : null;
    if (t && !t.ready) return toast('That PDF is missing on the server');
    api('email', { id: l.id, subject: document.getElementById('em-subj').value,
                   body: document.getElementById('em-body').value,
                   template: S.sheet.tpl || '',
                   document: S.sheet.doc || '' })
      .then(function (r) {
        if (r.error) return toast(r.error);
        /* The copy in Sent is how sending gets verified in the mail client, so
           say when there won't be one — otherwise an empty Sent folder reads as
           an email that never went. */
        toast((r.attached ? 'Sent to ' + r.to + ' with ' + r.attached : 'Sent to ' + r.to)
              + (r.filed ? '' : ' — no copy in Sent'));
        S.sheet = null;
        /* Sending from a record has to refresh that record — the log gained an
           entry and the document now has a sent date. The queue reload was
           always wrong here; it just wasn't visible until documents existed. */
        if (from === 'detail') {
          return api('lead', null, { id: l.id }).then(function (x) { S.detail = x; render(); });
        }
        return loadQueue().then(render);
      });
  }

  /* Single entry point for changing view: sets state, paints immediately so the
     UI never hangs, then loads that view's data. */
  function go(target, opts) {
    opts = opts || {};
    /* Remember which list a record was opened from, so the back link goes back
       where you came from rather than always to Clients. */
    if (target.view === 'detail' && S.view !== 'detail') S.from = S.view;
    S.view = target.view;
    if (target.stage && target.stage !== S.stage) { S.stage = target.stage; S.queue = []; S.i = 0; }
    if (target.view === 'detail') { S.detailId = target.id; if (!opts.keep) S.detail = null; }
    render();

    if (target.view === 'dash')    return api('stats').then(function (r) { S.stats = r; render(); });
    if (target.view === 'clients') return api('clients').then(function (r) { S.clients = r.clients; render(); });
    if (target.view === 'leads')   return loadLeads().then(render);
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
