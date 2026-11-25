// Shared browser helpers. Text is always inserted with textContent, never as HTML.

const API = new URL('../../api/v1', import.meta.url).href;

export class ApiError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details || {};
  }
}

let csrf = '';
export const setCsrf = (token) => { csrf = token || ''; };

/** Returns {data, meta}; throws ApiError. Network failures have status 0. */
export async function api(method, path, body) {
  const headers = { Accept: 'application/json' };
  const init = { method, headers, credentials: 'same-origin', cache: 'no-store' };
  if (method !== 'GET') {
    headers['X-CSRF-Token'] = csrf;
    if (body instanceof FormData) {
      init.body = body;
    } else {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body ?? {});
    }
  }
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), 20000);
  init.signal = abort.signal;
  let response;
  let json;
  try {
    response = await fetch(API + path, init);
    json = await response.json();
  } catch (e) {
    throw new ApiError(response && response.status >= 500 ? response.status : 0, 'NETWORK', 'เชื่อมต่อไม่ได้ ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่');
  } finally {
    clearTimeout(timer);
  }
  if (!response.ok) {
    const err = json.error || {};
    throw new ApiError(response.status, err.code || 'ERROR', err.message || 'เกิดข้อผิดพลาด', err.details);
  }
  return json;
}
export const get = async (path) => (await api('GET', path)).data;
export const post = async (path, body) => (await api('POST', path, body)).data;
export const put = async (path, body) => (await api('PUT', path, body)).data;
export const patch = async (path, body) => (await api('PATCH', path, body)).data;

/** h('div.card', {onclick}, children...) */
export function h(spec, props, ...children) {
  if (props instanceof Node || typeof props !== 'object' || props === null || Array.isArray(props)) {
    if (props !== null && props !== undefined) children.unshift(props);
    props = {};
  }
  const [tag, ...classes] = spec.split('.');
  const el = document.createElement(tag || 'div');
  if (classes.length) el.className = classes.join(' ');
  for (const [key, value] of Object.entries(props)) {
    if (value === undefined || value === null || value === false) continue;
    if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
    else if (['value', 'checked', 'disabled', 'selected'].includes(key)) el[key] = value;
    else el.setAttribute(key, value === true ? '' : String(value));
  }
  el.append(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false).map((c) => (c instanceof Node ? c : String(c))));
  return el;
}

export const $ = (selector, root = document) => root.querySelector(selector);
export const baht = (value) => '฿' + Number(value).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const zone = { timeZone: 'Asia/Bangkok' };
export const time = (iso) => (iso ? new Date(iso).toLocaleTimeString('th-TH', { ...zone, hour: '2-digit', minute: '2-digit' }) : '');
export const dateTime = (iso) => (iso ? new Date(iso).toLocaleString('th-TH', { ...zone, day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '');
export const today = () => new Intl.DateTimeFormat('en-CA', zone).format(new Date());
export const eta = (minutes) => (minutes === null || minutes === undefined ? 'ยังประเมินเวลาไม่ได้' : `ประมาณ ${minutes} นาที`);
export const queueNo = (n) => 'Q-' + String(n).padStart(3, '0');

export const STATUS = {
  PENDING: ['รอร้านยืนยัน', 'wait', '…'],
  ACCEPTED: ['รอทำอาหาร', 'info', '≡'],
  PREPARING: ['กำลังทำ', 'info', '♨'],
  READY: ['พร้อมรับ', 'ready', '✓'],
  COMPLETED: ['รับแล้ว', 'done', '✓'],
  REJECTED: ['ร้านปฏิเสธ', 'bad', '✕'],
  CANCELLED: ['ยกเลิกแล้ว', 'bad', '✕'],
};
export const badge = (status) => h('span.badge.' + (STATUS[status]?.[1] || 'done'), {}, h('span', { 'aria-hidden': 'true' }, STATUS[status]?.[2] || ''), ' ', STATUS[status]?.[0] || status);

export function picture(url, name, cls = 'pic') {
  const tile = () => h('div.' + cls + '.tile', { 'aria-hidden': 'true', style: `--hue:${[...(name || '')].reduce((a, c) => (a * 31 + c.codePointAt(0)) % 360, 7)}` }, [...(name || '?').replace(/^[เแโใไ]/, '')][0] || '?');
  if (!url) return tile();
  const src = url.startsWith('image:') ? `${API}/images/${url.slice(6)}` : url;
  const img = h('img.' + cls, { src, alt: '', loading: 'lazy' });
  img.addEventListener('error', () => img.replaceWith(tile()), { once: true });
  return img;
}

export function toast(message, kind = '') {
  let box = $('#toasts');
  if (!box) {
    box = h('div', { id: 'toasts', role: 'status', 'aria-live': 'polite' });
    document.body.append(box);
  }
  const item = h('div.toast' + (kind ? '.' + kind : ''), {}, message);
  box.append(item);
  setTimeout(() => item.remove(), 4500);
}

/** Modal dialog. build(close) returns the content; resolves with the value given to close(). */
export function dialog(title, build) {
  return new Promise((resolve) => {
    const el = h('dialog', { 'aria-label': title });
    let result;
    const close = (value) => { result = value; el.close(); };
    el.append(h('div.dialog-head', {}, h('h2', {}, title), h('button.icon', { type: 'button', 'aria-label': 'ปิด', onclick: () => close() }, '✕')), h('div.dialog-body', {}, build(close)));
    el.addEventListener('close', () => { el.remove(); resolve(result); });
    document.body.append(el);
    el.showModal();
  });
}

export const confirmBox = (title, text, yes = 'ยืนยัน', danger = false) => dialog(title, (close) => [
  h('p', {}, text),
  h('div.row.end', {}, h('button.btn.ghost', { type: 'button', onclick: () => close(false) }, 'ไม่ใช่'), h('button.btn' + (danger ? '.danger' : ''), { type: 'button', onclick: () => close(true) }, yes)),
]);

export const askReason = (title, label, yes, presets = []) => dialog(title, (close) => {
  const input = h('textarea', { id: 'reason', maxlength: 500, rows: 3, required: true });
  return h('form', { onsubmit: (e) => { e.preventDefault(); if (input.value.trim()) close(input.value.trim()); } },
    presets.length ? h('div.chips', {}, presets.map((p) => h('button.chip', { type: 'button', onclick: () => { input.value = p; } }, p))) : null,
    h('label', { for: 'reason' }, label), input,
    h('div.row.end', {}, h('button.btn', { type: 'submit' }, yes)));
});

/** Runs an action while its button is disabled, and shows errors as a toast. */
export async function busy(button, action) {
  if (button?.disabled) return undefined;
  if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
  try {
    return await action();
  } catch (e) {
    toast(e.message || 'เกิดข้อผิดพลาด', 'bad');
    if (!(e instanceof ApiError)) console.error(e);
    return undefined;
  } finally {
    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
  }
}

export const loading = () => h('div.state', { role: 'status' }, h('div.spinner', { 'aria-hidden': 'true' }), 'กำลังโหลด…');
export const empty = (text, ...extra) => h('div.state', {}, h('p', {}, text), extra);
export const failed = (e, retry) => h('div.state', { role: 'alert' }, h('p', {}, e.message), h('button.btn.ghost', { type: 'button', onclick: retry }, 'ลองใหม่'));

// POLL_SECONDS of the server; null keeps the interval of each page.
let pollEvery = null;
export const setPoll = (seconds) => { pollEvery = seconds ? seconds * 1000 : null; };

/** The returned function tells whether a value differs from the last one, so unchanged data is not drawn again. */
export function changes() {
  let last;
  return (value) => {
    const now = JSON.stringify(value);
    const changed = now !== last;
    last = now;
    return changed;
  };
}

/** Polls while the page is visible; backs off on errors. Returns stop(). */
export function poll(task, interval = 7000) {
  const every = pollEvery ?? interval;
  let stopped = false;
  let delay = every;
  let timer = 0;
  let running = false;
  const run = async () => {
    clearTimeout(timer);
    if (stopped || document.hidden || running) return;
    running = true;
    try {
      await task();
      delay = every;
    } catch (e) {
      delay = Math.min(delay * 2, 60000);
    }
    running = false;
    if (!stopped) timer = setTimeout(run, delay);
  };
  const wake = () => { if (!document.hidden) run(); };
  document.addEventListener('visibilitychange', wake);
  timer = setTimeout(run, every);
  return () => { stopped = true; clearTimeout(timer); document.removeEventListener('visibilitychange', wake); };
}

export const field = (label, input, hint) => h('label.field', {}, h('span', {}, label), input, hint ? h('small', {}, hint) : null);

/** Login form shared by the merchant and admin pages. */
export function loginForm(realm, title, done) {
  const login = h('input', { name: 'login', autocomplete: 'username', required: true, maxlength: 100 });
  const password = h('input', { name: 'password', type: 'password', autocomplete: 'current-password', required: true, maxlength: 200 });
  const button = h('button.btn.block', { type: 'submit' }, 'เข้าสู่ระบบ');
  return h('main.login', {}, h('form.card', {
    onsubmit: (e) => {
      e.preventDefault();
      busy(button, async () => {
        const r = await post('/auth/login', { realm, login: login.value, password: password.value });
        setCsrf(r.csrf);
        done();
      });
    },
  }, h('div.brand', {}, 'Q-im'), h('h1', {}, title), field('ชื่อผู้ใช้', login), field('รหัสผ่าน', password), button));
}
