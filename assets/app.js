// ── Theme ─────────────────────────────────────────────────────────
(function() {
  const saved = localStorage.getItem('theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
})();

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme') || 'light';
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('theme', next);
}


const api = {
  async request(url, method='GET', data, signal) {
    const options={method,signal,credentials:'include'};
    if(data!==undefined){options.headers={'Content-Type':'application/json'};options.body=JSON.stringify(data);}
    const response=await fetch(url,options);
    const text=await response.text();
    let result;
    try {result=JSON.parse(text);} catch(e) {
      result={ok:false,error:'Сервер вернул некорректный ответ (HTTP '+response.status+'). Ответы остались в браузере.'};
    }
    if(!result || typeof result!=='object')result={ok:false,error:'Некорректный ответ сервера'};
    result.http_status=response.status;
    if(!response.ok)result.ok=false;
    if(response.status===401 || response.status===403)window.dispatchEvent(new CustomEvent('lms-access-denied',{detail:{status:response.status,url}}));
    return result;
  },
  get(url){return this.request(url);},
  post(url,data){return this.request(url,'POST',data);},
  put(url,data){return this.request(url,'PUT',data);},
  delete(url){return this.request(url,'DELETE');},
};

// ── Toast ────────────────────────────────────────────────────────
function toast(msg, type = 'ok') {
  let el = document.getElementById('toast');
  if (!el) { el = document.createElement('div'); el.id = 'toast'; el.className = 'toast'; document.body.appendChild(el); }
  el.textContent = msg;
  el.className = 'toast show' + (type === 'err' ? ' err' : '');
  clearTimeout(el._t);
  el._t = setTimeout(() => el.classList.remove('show'), 3000);
}

// ── Form helpers ──────────────────────────────────────────────────
function setErr(fieldId, msg) {
  const f = document.getElementById('f-' + fieldId);
  if (!f) return;
  f.classList.add('has-err');
  const e = f.querySelector('.field-err');
  if (e && msg) e.textContent = msg;
}
function clrErr(fieldId) {
  const f = document.getElementById('f-' + fieldId);
  if (f) f.classList.remove('has-err');
}
function clrAllErr() {
  document.querySelectorAll('.has-err').forEach(f => f.classList.remove('has-err'));
}
function val(id) {
  const el = document.getElementById(id);
  return el ? el.value.trim() : '';
}

// ── Nav helper ────────────────────────────────────────────────────
function showPage(pageId, clickedNavItem) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  const pg = document.getElementById('page-' + pageId);
  if (pg) pg.classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  if (clickedNavItem) clickedNavItem.classList.add('active');
}

// ── Screen switch ─────────────────────────────────────────────────
function goScreen(id) {
  document.querySelectorAll('.auth-screen, .app-screen').forEach(s => s.classList.remove('active'));
  const s = document.getElementById('screen-' + id);
  if (s) s.classList.add('active');
}

// ── Register step indicator ───────────────────────────────────────
let _rStep = 1;
function setRegStep(n) {
  _rStep = n;
  [1, 2, 3].forEach(i => {
    const panel = document.getElementById('rp' + i);
    const dot   = document.getElementById('rsd' + i);
    const lbl   = document.getElementById('rsl' + i);
    if (panel) panel.classList.toggle('active', i === n);
    if (dot) dot.className = 'rs-dot' + (i < n ? ' rs-done' : i === n ? ' rs-active' : '');
    if (lbl) lbl.className = (i < n ? 'rs-done' : i === n ? 'rs-active' : '');
  });
}

// ── Avatar initials ───────────────────────────────────────────────
function initials(fname, lname) {
  return ((fname || '')[0] + (lname || '')[0]).toUpperCase();
}

// ── Modal ─────────────────────────────────────────────────────────
function addModalCloseButton(modal) {
  if (!modal || modal.querySelector('.modal-close-btn')) return;
  const head = document.createElement('div');
  head.className = 'modal-head';
  head.innerHTML = '<button type="button" class="modal-close-btn" aria-label="Закрыть">×</button>';
  modal.insertBefore(head, modal.firstChild);
}
function ensureModalCloseButton(overlay) {
  if (!overlay) return;
  const modal = overlay.querySelector('.modal');
  if (modal) addModalCloseButton(modal);
}
function stopModalMedia(modal) {
  if (!modal) return;
  modal.querySelectorAll('iframe,video').forEach(el => {
    if (el.tagName === 'IFRAME') {
      if (el.src) {
        el.removeAttribute('src');
      }
    } else if (el.tagName === 'VIDEO') {
      el.pause && el.pause();
    }
  });
}
function openModal(id) {
  const m = document.getElementById(id);
  if (m) {
    ensureModalCloseButton(m);
    m.classList.add('open');
  }
}
function closeModal(id, force = false) {
  const m = document.getElementById(id);
  if (m) {
    if (!force && m.dataset.confirmClose === 'true' &&
        !window.confirm('Вы действительно хотите выйти? Текущий прогресс теста будет потерян.')) return false;
    stopModalMedia(m);
    m.classList.remove('open');
    if (m.dataset.autoRemove === 'true') {
      setTimeout(() => m.remove(), 140);
    }
    return true;
  }
  return false;
}
// Close on overlay click
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-overlay')) {
    closeModal(e.target.id);
  }
});
document.addEventListener('click', e => {
  const btn = e.target.closest('.modal-close-btn');
  if (!btn) return;
  const overlay = btn.closest('.modal-overlay');
  if (overlay) closeModal(overlay.id);
});
