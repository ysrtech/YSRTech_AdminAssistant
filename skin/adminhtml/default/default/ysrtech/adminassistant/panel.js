/* AI Assistant panel: a button in the admin header, a drawer with the chat.
   Plain JavaScript; the admin's Prototype is neither needed nor touched. */
(function () {
  'use strict';

  var root = document.getElementById('ysraa');
  var cfgEl = document.getElementById('ysraa-config');
  if (!root || !cfgEl || root.dataset.ready) { return; }
  root.dataset.ready = '1';

  var cfg = JSON.parse(cfgEl.textContent || '{}');
  var log = root.querySelector('.ysraa-log');
  var form = root.querySelector('.ysraa-ask');
  var input = form.querySelector('textarea');
  var send = form.querySelector('.ysraa-send');
  var contextEl = form.querySelector('.ysraa-context');
  var historyEl = root.querySelector('.ysraa-history');
  var historyList = root.querySelector('.ysraa-history-list');

  var store = {
    get: function (k) { try { return window.sessionStorage.getItem('ysraa_' + k); } catch (e) { return null; } },
    set: function (k, v) { try { v === null ? window.sessionStorage.removeItem('ysraa_' + k) : window.sessionStorage.setItem('ysraa_' + k, v); } catch (e) { /* private mode */ } }
  };

  var conversationId = parseInt(store.get('conversation') || '0', 10) || null;
  var useContext = cfg.page && cfg.page.type && cfg.page.type !== 'page';
  var busy = false;
  var controller = null;
  var loaded = false;

  /* ---------- the trigger, in place of the header's search box ---------- */

  // A field like the search box it replaces: type and press Enter to ask,
  // or press the icon to open the drawer. Ctrl/Cmd+K jumps to it.
  var trigger = document.createElement('form');
  trigger.className = 'ysraa-trigger';
  trigger.setAttribute('role', 'search');
  trigger.innerHTML = '<button type="button" class="ysraa-trigger-open" aria-controls="ysraa" title="' + esc(label('Open the AI Assistant')) + '">' +
    '<span class="ysraa-spark" aria-hidden="true"></span></button>' +
    '<input type="text" autocomplete="off" aria-label="' + esc(label('Ask the AI Assistant')) + '" placeholder="' +
    esc(cfg.enabled ? label('Ask AI about the store…') : label('AI Assistant is switched off')) + '" />' +
    '<kbd>' + (/Mac|iPhone|iPad/.test(navigator.platform || '') ? '⌘K' : 'Ctrl K') + '</kbd>';
  var triggerInput = trigger.querySelector('input');

  var search = document.getElementById('global_search');
  var searchBox = search ? (search.closest('fieldset') || search.parentNode) : null;
  var header = document.querySelector('.header-right');
  if (searchBox && !cfg.enabled) {
    // Switched off: the search box stays until the assistant is on; only the
    // icon is added beside it, for the notice and the way to the settings
    trigger.classList.add('compact');
    searchBox.classList.add('ysraa-trigger-beside');
    searchBox.appendChild(trigger);
  } else if (searchBox) {
    // Take the search box's place, so the trigger sits exactly where it was
    Array.prototype.forEach.call(searchBox.children, function (child) { child.style.display = 'none'; });
    searchBox.classList.add('ysraa-trigger-slot');
    searchBox.appendChild(trigger);
  } else if (header) {
    // No search box (switched off, or not in this role): the same spot
    var slot = document.createElement('fieldset');
    slot.className = 'ysraa-trigger-slot';
    slot.appendChild(trigger);
    header.appendChild(slot);
  } else {
    trigger.classList.add('floating');
    document.body.appendChild(trigger);
  }

  trigger.querySelector('.ysraa-trigger-open').addEventListener('click', function () { root.hidden ? open() : close(); });
  trigger.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = triggerInput.value.trim();
    triggerInput.value = '';
    triggerInput.blur();
    open().then(function () {
      if (text && cfg.enabled) { ask(text); } else if (text) { input.value = text; }
    });
  });

  root.querySelectorAll('[data-close]').forEach(function (el) { el.addEventListener('click', close); });
  root.querySelector('[data-new]').addEventListener('click', newChat);
  root.querySelector('[data-history]').addEventListener('click', toggleHistory);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !root.hidden) { close(); }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      root.hidden ? triggerInput.focus() : input.focus();
    }
  });

  var ready = null;

  /** Open the drawer; resolves once an earlier chat it restores has loaded */
  function open() {
    root.hidden = false;
    document.documentElement.classList.add('ysraa-open');
    store.set('open', '1');
    if (!loaded) {
      loaded = true;
      // While switched off, only the notice: not a chat remembered from before
      ready = conversationId && cfg.enabled ? loadConversation(conversationId) : (showWelcome(), Promise.resolve());
    }
    showContext();
    setTimeout(function () { input.focus(); }, 30);
    return ready || Promise.resolve();
  }

  function close() {
    root.hidden = true;
    document.documentElement.classList.remove('ysraa-open');
    store.set('open', null);
  }

  if (store.get('open') === '1') { open(); }

  /* ---------- welcome, context, history ---------- */

  function showWelcome() {
    log.innerHTML = '';
    var w = el('div', 'ysraa-welcome');
    if (!cfg.enabled) {
      w.appendChild(el('p', 'ysraa-off', cfg.off_message));
      if (cfg.settings) {
        var a = el('a', 'ysraa-chip', label('Open the AI Assistant settings'));
        a.href = cfg.settings;
        w.appendChild(a);
      }
      log.appendChild(w);
      input.disabled = true;
      send.disabled = true;
      return;
    }
    w.appendChild(el('h3', '', (cfg.user ? 'Hi ' + cfg.user + '. ' : '') + label('What would you like to know?')));
    w.appendChild(el('p', '', label('I read live store data: orders, customers, stock, sales, campaigns and logs. I can\'t change anything.')));
    var list = el('div', 'ysraa-suggest');
    (cfg.suggestions || []).forEach(function (s) {
      var b = el('button', 'ysraa-chip', s);
      b.type = 'button';
      b.addEventListener('click', function () { ask(s); });
      list.appendChild(b);
    });
    w.appendChild(list);
    log.appendChild(w);
  }

  function showContext() {
    if (!useContext || !cfg.enabled) { contextEl.hidden = true; return; }
    var names = { order: 'this order', invoice: 'this invoice', shipment: 'this shipment', creditmemo: 'this credit memo', customer: 'this customer', product: 'this product', category: 'this category', campaign: 'this campaign', segment: 'this segment' };
    contextEl.innerHTML = '';
    contextEl.appendChild(el('span', '', label('Knows you are looking at') + ' ' + label(names[cfg.page.type] || 'this page')));
    var x = el('button', 'ysraa-x', '×');
    x.type = 'button';
    x.title = label('Ask without this page');
    x.addEventListener('click', function () { useContext = false; showContext(); });
    contextEl.appendChild(x);
    contextEl.hidden = false;
  }

  function newChat() {
    if (busy && controller) { controller.abort(); }
    conversationId = null;
    store.set('conversation', null);
    historyEl.hidden = true;
    useContext = cfg.page && cfg.page.type && cfg.page.type !== 'page';
    showWelcome();
    showContext();
    input.focus();
  }

  function toggleHistory() {
    if (!historyEl.hidden) { historyEl.hidden = true; return; }
    historyList.innerHTML = '<li class="ysraa-muted">' + esc(label('Loading…')) + '</li>';
    historyEl.hidden = false;
    fetch(cfg.urls.conversations, { credentials: 'same-origin' }).then(json).then(function (d) {
      historyList.innerHTML = '';
      if (!d.conversations || !d.conversations.length) {
        historyList.appendChild(el('li', 'ysraa-muted', label('No earlier chats.')));
        return;
      }
      d.conversations.forEach(function (c) {
        var li = el('li');
        var b = el('button', 'ysraa-hist-open');
        b.type = 'button';
        b.appendChild(el('span', 't', c.title || '…'));
        b.appendChild(el('small', '', c.updated || ''));
        b.addEventListener('click', function () { historyEl.hidden = true; loadConversation(c.id); });
        var del = el('button', 'ysraa-x', '×');
        del.type = 'button';
        del.title = label('Delete this chat');
        del.addEventListener('click', function () {
          var f = new FormData();
          f.append('form_key', cfg.form_key);
          f.append('id', c.id);
          fetch(cfg.urls.delete, { method: 'POST', body: f, credentials: 'same-origin' }).then(function () {
            li.remove();
            if (conversationId === c.id) { newChat(); }
          });
        });
        li.appendChild(b);
        li.appendChild(del);
        historyList.appendChild(li);
      });
    }).catch(function () { historyList.innerHTML = '<li class="ysraa-muted">' + esc(label('Could not load earlier chats.')) + '</li>'; });
  }

  function loadConversation(id) {
    log.innerHTML = '';
    return fetch(cfg.urls.conversation + (cfg.urls.conversation.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(json)
      .then(function (d) {
        if (!d.messages) { newChat(); return; }
        conversationId = id;
        store.set('conversation', String(id));
        var steps = null;
        d.messages.forEach(function (m) {
          if (m.role === 'user') { addQuestion(m.text); steps = null; }
          else if (m.role === 'tool') { steps = steps || addSteps(); addStep(steps, m.name, m.summary, true); }
          else { renderInto(addAnswer(), m.text); }
        });
        if (!d.messages.length) { showWelcome(); }
        scrollDown(true);
      })
      .catch(function () { newChat(); });
  }

  /* ---------- asking ---------- */

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) { if (controller) { controller.abort(); } return; }
    ask(input.value);
  });

  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });
  input.addEventListener('input', grow);

  function grow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  }

  function ask(text) {
    text = (text || '').trim();
    if (!text || busy || !cfg.enabled) { return; }
    var welcome = log.querySelector('.ysraa-welcome');
    if (welcome) { welcome.remove(); }
    historyEl.hidden = true;

    addQuestion(text);
    input.value = '';
    grow();

    var steps = null;
    var answer = null;
    var buffer = '';
    var pending = false;
    var thinking = el('div', 'ysraa-thinking', '');
    thinking.innerHTML = '<i></i><i></i><i></i>';
    log.appendChild(thinking);
    scrollDown(true);

    var body = new FormData();
    body.append('form_key', cfg.form_key);
    body.append('question', text);
    if (conversationId) { body.append('conversation_id', conversationId); }
    if (useContext) {
      body.append('page_type', cfg.page.type);
      body.append('page_id', cfg.page.id);
    }
    body.append('page', (cfg.page && cfg.page.page) || '');

    setBusy(true);
    controller = window.AbortController ? new AbortController() : null;

    function paint() {
      pending = false;
      if (answer) { renderInto(answer, buffer, true); scrollDown(); }
    }

    function onEvent(name, data) {
      if (name === 'conversation') {
        conversationId = data.id;
        store.set('conversation', String(data.id));
      } else if (name === 'text') {
        if (thinking.parentNode) { thinking.remove(); }
        if (!answer) { answer = addAnswer(); }
        buffer += data.delta;
        if (!pending) { pending = true; window.requestAnimationFrame(paint); }
      } else if (name === 'tool') {
        if (thinking.parentNode) { thinking.remove(); }
        // Text the model wrote before deciding to look something up stays where it was
        if (answer) { renderInto(answer, buffer); answer = null; buffer = ''; }
        steps = steps || addSteps();
        addStep(steps, data.name, data.summary, false);
        log.appendChild(thinking);
        scrollDown();
      } else if (name === 'tool_done') {
        finishStep(steps, data.name, data.ok, data.rows);
      } else if (name === 'error') {
        if (thinking.parentNode) { thinking.remove(); }
        log.appendChild(el('div', 'ysraa-error', data.message || label('Something went wrong.')));
        scrollDown();
      } else if (name === 'done') {
        if (thinking.parentNode) { thinking.remove(); }
        if (answer) { renderInto(answer, buffer); }
      }
    }

    fetch(cfg.urls.chat, { method: 'POST', body: body, credentials: 'same-origin', signal: controller ? controller.signal : undefined })
      .then(function (res) {
        var type = res.headers.get('Content-Type') || '';
        if (type.indexOf('text/event-stream') === -1) {
          return res.text().then(function (t) {
            var msg = label('The assistant could not answer.');
            try { msg = JSON.parse(t).error || msg; } catch (e) { if (res.status === 403 || /login/i.test(t)) { msg = label('Your admin session has ended. Reload the page and sign in again.'); } }
            onEvent('error', { message: msg });
          });
        }
        return readStream(res, onEvent);
      })
      .catch(function (err) {
        if (err && err.name === 'AbortError') {
          onEvent('error', { message: label('Stopped.') });
        } else {
          onEvent('error', { message: label('The connection was lost before the answer finished.') });
        }
      })
      .then(function () {
        if (thinking.parentNode) { thinking.remove(); }
        if (answer) { renderInto(answer, buffer); }
        root.querySelectorAll('.ysraa-step.run').forEach(function (s) { s.classList.remove('run'); });
        setBusy(false);
        controller = null;
        input.focus();
      });
  }

  function readStream(res, onEvent) {
    if (!res.body || !res.body.getReader) {
      return res.text().then(function (t) { parseEvents(t + '\n\n', onEvent); });
    }
    var reader = res.body.getReader();
    var decoder = new TextDecoder();
    var carry = '';
    function pump() {
      return reader.read().then(function (r) {
        if (r.done) { parseEvents(carry + '\n\n', onEvent); return; }
        carry = parseEvents(carry + decoder.decode(r.value, { stream: true }), onEvent);
        return pump();
      });
    }
    return pump();
  }

  /** Dispatch the complete events in text; return what is left over. */
  function parseEvents(text, onEvent) {
    var parts = text.split('\n\n');
    var rest = parts.pop();
    parts.forEach(function (block) {
      var name = 'message';
      var data = '';
      block.split('\n').forEach(function (line) {
        if (line.indexOf('event:') === 0) { name = line.slice(6).trim(); }
        else if (line.indexOf('data:') === 0) { data += line.slice(5).trim(); }
      });
      if (!data) { return; }
      try { onEvent(name, JSON.parse(data)); } catch (e) { /* a broken event is skipped */ }
    });
    return rest;
  }

  function setBusy(on) {
    busy = on;
    form.classList.toggle('busy', on);
    send.setAttribute('aria-label', on ? label('Stop') : label('Ask'));
  }

  /* ---------- the log ---------- */

  function addQuestion(text) {
    var q = el('div', 'ysraa-q');
    q.appendChild(el('div', 'ysraa-bubble', text));
    log.appendChild(q);
  }

  function addAnswer() {
    var a = el('div', 'ysraa-a');
    log.appendChild(a);
    return a;
  }

  function addSteps() {
    var s = el('div', 'ysraa-steps');
    log.appendChild(s);
    return s;
  }

  function addStep(steps, name, summary, done) {
    var s = el('div', 'ysraa-step' + (done ? ' ok' : ' run'));
    s.dataset.name = name;
    s.appendChild(el('span', 'dot'));
    s.appendChild(el('span', 'l', (cfg.labels && cfg.labels[name]) || name));
    if (summary) { s.appendChild(el('span', 'd', summary)); }
    steps.appendChild(s);
  }

  function finishStep(steps, name, ok, rows) {
    if (!steps) { return; }
    var s = steps.querySelector('.ysraa-step.run[data-name="' + name + '"]');
    if (!s) { return; }
    s.classList.remove('run');
    s.classList.add(ok ? 'ok' : 'bad');
    if (ok && typeof rows === 'number') { s.appendChild(el('span', 'n', rows === 1 ? label('1 row') : rows + ' ' + label('rows'))); }
  }

  function scrollDown(force) {
    var near = log.scrollHeight - log.scrollTop - log.clientHeight < 120;
    if (force || near) { log.scrollTop = log.scrollHeight; }
  }

  /* ---------- Markdown, safely: everything is escaped first ---------- */

  function renderInto(node, text, streaming) {
    node.innerHTML = markdown(text || '') + (streaming ? '<span class="ysraa-caret"></span>' : '');
  }

  function markdown(src) {
    var lines = esc(src).replace(/\r/g, '').split('\n');
    var out = [];
    var i = 0;
    while (i < lines.length) {
      var line = lines[i];
      if (/^```/.test(line)) {
        var code = [];
        i++;
        while (i < lines.length && !/^```/.test(lines[i])) { code.push(lines[i]); i++; }
        i++;
        out.push('<pre><code>' + code.join('\n') + '</code></pre>');
        continue;
      }
      if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?[\s:\-|]+\|?\s*$/.test(lines[i + 1])) {
        var head = cells(line);
        i += 2;
        var rows = [];
        while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(cells(lines[i])); i++; }
        out.push('<div class="ysraa-table"><table><thead><tr>' + head.map(function (c) { return '<th>' + inline(c) + '</th>'; }).join('') + '</tr></thead><tbody>' +
          rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + inline(c) + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>');
        continue;
      }
      if (/^&gt;\s?/.test(line)) {
        var quote = [];
        while (i < lines.length && /^&gt;\s?/.test(lines[i])) { quote.push(inline(lines[i].replace(/^&gt;\s?/, ''))); i++; }
        out.push('<blockquote>' + quote.join('<br>') + '</blockquote>');
        continue;
      }
      var h = /^(#{1,4})\s+(.*)$/.exec(line);
      if (h) { out.push('<h4>' + inline(h[2]) + '</h4>'); i++; continue; }
      if (/^\s*([-*•]|\d+[.)])\s+/.test(line)) {
        var ordered = /^\s*\d+[.)]\s+/.test(line);
        var items = [];
        while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) {
          items.push('<li>' + inline(lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, '')) + '</li>');
          i++;
        }
        out.push((ordered ? '<ol>' : '<ul>') + items.join('') + (ordered ? '</ol>' : '</ul>'));
        continue;
      }
      if (/^\s*$/.test(line)) { i++; continue; }
      var para = [line];
      i++;
      while (i < lines.length && !/^\s*$/.test(lines[i]) && !/^&gt;|^```|^#{1,4}\s|^\s*([-*•]|\d+[.)])\s+|^\s*\|.*\|\s*$/.test(lines[i])) { para.push(lines[i]); i++; }
      out.push('<p>' + para.map(inline).join('<br>') + '</p>');
    }
    return out.join('');
  }

  function cells(row) {
    return row.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); });
  }

  /* Text here is already escaped, so the patterns below can only add the
     few tags they name. Links go only to http(s) or this admin's own paths. */
  function inline(s) {
    var codes = [];
    s = s.replace(/`([^`]+)`/g, function (m, c) { codes.push(c); return '\u0000' + (codes.length - 1) + '\u0000'; });
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, text, url) {
      var raw = url.replace(/&amp;/g, '&');
      if (!/^(https?:\/\/|\/)/i.test(raw) || /^\/\//.test(raw)) { return text; }
      var same = raw.charAt(0) === '/' || raw.indexOf(window.location.origin + '/') === 0;
      return '<a href="' + url + '"' + (same ? '' : ' target="_blank" rel="noopener noreferrer nofollow"') + '>' + text + '</a>';
    });
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[\s(])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
    s = s.replace(/\u0000(\d+)\u0000/g, function (m, n) { return '<code>' + codes[+n] + '</code>'; });
    return s;
  }

  /* ---------- helpers ---------- */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = text; }
    return n;
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function json(r) { return r.json(); }

  function label(s) { return s; }
})();
