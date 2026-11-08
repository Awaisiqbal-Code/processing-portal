/* PMS Assistant: chat panel + voice input (recorded in the browser, transcribed by Gemini on the server). */
(function () {
  'use strict';
  var root = document.getElementById('pms-assistant');
  if (!root) return;
  var api = root.dataset.api, csrf = root.dataset.csrf, firstName = root.dataset.name || '';
  var chips = []; try { chips = JSON.parse(root.dataset.chips || '[]'); } catch (e) {}
  var fab = root.querySelector('.ai-fab'), panel = root.querySelector('.ai-panel'), msgs = root.querySelector('.ai-msgs');
  var form = root.querySelector('.ai-input'), ta = form.querySelector('textarea'), mic = form.querySelector('.ai-mic'), send = form.querySelector('.ai-send');
  var recBar = form.querySelector('.ai-rec'), recT = form.querySelector('.ai-rec-t'), recL = form.querySelector('.ai-rec-l');
  var loaded = false, busy = false;
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };

  function post(data) {
    var body = data instanceof FormData ? data : (function () { var f = new FormData(); Object.keys(data).forEach(function (k) { f.append(k, data[k]); }); return f; })();
    body.append('_csrf', csrf);
    return fetch(api, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent an unexpected reply (' + r.status + ').' }; }); })
      .catch(function () { return { ok: false, error: 'No connection. Please check your internet and try again.' }; });
  }

  // ---- safe, tiny markdown: **bold**, [text](internal link), bullet / numbered lists, line breaks
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function inline(s) {
    s = esc(s);
    s = s.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
    s = s.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>');
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, t, href) {
      var h = href.replace(/&amp;/g, '&');
      var internal = /^\/(?!\/)/.test(h) || /^(admin|officer|student)\//.test(h) || h.indexOf(location.origin + '/') === 0;
      return internal ? '<a href="' + esc(h) + '">' + t + '</a>' : t;
    });
    return s;
  }
  function md(text) {
    var lines = String(text).split(/\r?\n/), html = '', list = null;
    function close() { if (list) { html += '</' + list + '>'; list = null; } }
    lines.forEach(function (ln) {
      var b = ln.match(/^\s*[-*•]\s+(.*)$/), n = ln.match(/^\s*\d+[.)]\s+(.*)$/);
      if (b || n) { var want = b ? 'ul' : 'ol'; if (list !== want) { close(); html += '<' + want + '>'; list = want; } html += '<li>' + inline((b || n)[1]) + '</li>'; return; }
      close();
      if (/^\s*#{1,4}\s+/.test(ln)) { html += '<p><b>' + inline(ln.replace(/^\s*#{1,4}\s+/, '')) + '</b></p>'; return; }
      if (ln.trim() === '') return;
      html += '<p>' + inline(ln) + '</p>';
    });
    close();
    return html;
  }

  function bubble(role, text, extra) {
    var el = document.createElement('div');
    el.className = 'ai-msg ' + (role === 'user' ? 'me' : 'bot') + (extra ? ' ' + extra : '');
    if (role === 'user') el.textContent = text; else el.innerHTML = md(text);
    msgs.appendChild(el); msgs.scrollTop = msgs.scrollHeight; return el;
  }
  function welcome() {
    msgs.innerHTML = '';
    var w = document.createElement('div'); w.className = 'ai-welcome';
    w.innerHTML = '<div class="ai-wave">👋</div><b>Assalam o Alaikum' + (firstName ? ', ' + esc(firstName) : '') + '!</b><p>Students, pending kaam, applications, payments ya remarks — kuch bhi poochein, type kar ke ya 🎤 bol kar.</p>';
    var c = document.createElement('div'); c.className = 'ai-chips';
    chips.forEach(function (t) { var b = document.createElement('button'); b.type = 'button'; b.textContent = t; b.addEventListener('click', function () { ask(t); }); c.appendChild(b); });
    w.appendChild(c); msgs.appendChild(w);
  }
  function typing(on) {
    var t = msgs.querySelector('.ai-typing');
    if (on && !t) { t = document.createElement('div'); t.className = 'ai-msg bot ai-typing'; t.innerHTML = '<span></span><span></span><span></span>'; msgs.appendChild(t); msgs.scrollTop = msgs.scrollHeight; }
    if (!on && t) t.remove();
  }
  function setBusy(b) { busy = b; send.disabled = b; ta.disabled = b && !recording; }

  function ask(text) {
    text = (text || '').trim();
    if (!text || busy) return;
    var w = msgs.querySelector('.ai-welcome'); if (w) w.remove();
    bubble('user', text); ta.value = ''; autosize();
    setBusy(true); typing(true);
    post({ action: 'chat', message: text }).then(function (r) {
      typing(false); setBusy(false);
      if (r.ok) bubble('bot', r.reply); else bubble('bot', r.error || 'Something went wrong.', 'err');
      ta.focus();
    });
  }

  function open() {
    panel.hidden = false; root.classList.add('is-open'); fab.setAttribute('aria-expanded', 'true'); store.set('ai-open', '1');
    if (!loaded) {
      loaded = true; welcome();
      post({ action: 'history' }).then(function (r) {
        if (r.ok && r.messages && r.messages.length) { msgs.innerHTML = ''; r.messages.forEach(function (m) { bubble(m.role === 'user' ? 'user' : 'bot', m.text); }); }
      });
    }
    setTimeout(function () { ta.focus(); }, 60);
  }
  function close() { panel.hidden = true; root.classList.remove('is-open'); fab.setAttribute('aria-expanded', 'false'); store.set('ai-open', '0'); if (recording) stopRec(true); }
  fab.addEventListener('click', function () { panel.hidden ? open() : close(); });
  root.querySelector('[data-ai="close"]').addEventListener('click', close);
  root.querySelector('[data-ai="clear"]').addEventListener('click', function () { if (busy) return; post({ action: 'clear' }).then(function () { welcome(); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) close(); });

  function autosize() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 140) + 'px'; }
  ta.addEventListener('input', autosize);
  ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(ta.value); } });
  form.addEventListener('submit', function (e) { e.preventDefault(); ask(ta.value); });

  // ---- voice: record -> Gemini transcribes -> text in the box (edit if needed, then send)
  var recording = false, recorder = null, chunks = [], stream = null, t0 = 0, timer = null, cancelled = false;
  var MAX_SEC = 90;
  function pickMime() {
    var opts = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (var i = 0; i < opts.length; i++) if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(opts[i])) return opts[i];
    return '';
  }
  function startRec() {
    if (!navigator.mediaDevices || !window.MediaRecorder) { bubble('bot', 'Is browser mein voice recording support nahi hai. Chrome ya Edge use karein.', 'err'); return; }
    navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }).then(function (s) {
      stream = s; chunks = []; cancelled = false;
      var mime = pickMime(); recorder = mime ? new MediaRecorder(s, { mimeType: mime }) : new MediaRecorder(s);
      recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      recorder.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        if (cancelled) return;
        var blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
        transcribe(blob);
      };
      recorder.start(); recording = true; t0 = Date.now(); root.classList.add('is-rec'); recBar.hidden = false; recL.textContent = 'Listening… tap the mic to stop';
      mic.setAttribute('aria-label', 'Stop recording'); tick(); timer = setInterval(tick, 250);
    }).catch(function () { bubble('bot', 'Microphone ki ijazat nahi mili. Browser mein mic allow karein aur dobara koshish karein.', 'err'); });
  }
  function tick() {
    var s = Math.floor((Date.now() - t0) / 1000); recT.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    if (s >= MAX_SEC) stopRec(false);
  }
  function stopRec(cancel) {
    if (!recording) return; cancelled = !!cancel; recording = false; clearInterval(timer);
    root.classList.remove('is-rec'); mic.setAttribute('aria-label', 'Speak your question');
    if (cancel) recBar.hidden = true; else recL.textContent = 'Converting your voice to text…';
    try { recorder.stop(); } catch (e) {}
  }
  function transcribe(blob) {
    setBusy(true); root.classList.add('is-stt');
    var f = new FormData(); f.append('action', 'transcribe'); f.append('mime', blob.type || 'audio/webm'); f.append('audio', blob, 'voice.webm');
    post(f).then(function (r) {
      setBusy(false); root.classList.remove('is-stt'); recBar.hidden = true;
      if (r.ok && r.text) { ta.value = (ta.value ? ta.value.trim() + ' ' : '') + r.text; autosize(); ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); }
      else bubble('bot', r.error || 'Awaz samajh nahi aayi, dobara bolein.', 'err');
    });
  }
  mic.addEventListener('click', function () { if (busy && !recording) return; recording ? stopRec(false) : startRec(); });

  if (store.get('ai-open') === '1' && window.innerWidth > 900) open();
})();
