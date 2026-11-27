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
export const METHOD = { PAY_AT_STORE: 'ชำระที่ร้าน (เงินสด)', PROMPTPAY: 'PromptPay', STRIPE_PROMPTPAY: 'PromptPay ออนไลน์' };
export const queueNo = (n) => 'Q-' + String(n).padStart(3, '0');

export const STATUS = {
  AWAITING_PAYMENT: ['รอชำระเงิน', 'wait', '฿'],
  PENDING: ['รอร้านยืนยัน', 'wait', '…'],
  ACCEPTED: ['รอทำอาหาร', 'info', '≡'],
  PREPARING: ['กำลังทำ', 'info', '♨'],
  READY: ['พร้อมรับ', 'ready', '✓'],
  COMPLETED: ['รับแล้ว', 'done', '✓'],
  REJECTED: ['ร้านปฏิเสธ', 'bad', '✕'],
  CANCELLED: ['ยกเลิกแล้ว', 'bad', '✕'],
};
export const badge = (status) => h('span.badge.' + (STATUS[status]?.[1] || 'done'), {}, h('span', { 'aria-hidden': 'true' }, STATUS[status]?.[2] || ''), ' ', STATUS[status]?.[0] || status);

const ART = new URL('../images/art/', import.meta.url).href;
const ART_KINDS = ['rice', 'noodle', 'fried', 'drink', 'dessert', 'other', 'shop'];
/** Classes shown large: the full picture; the others get the 480 px thumbnail. */
const LARGE = /^(cover|hero|preview)\b/;
/**
 * Addresses that failed, or showed nothing for SLOW ms after first coming into view: the drawn picture is used
 * instead from then on. Counted per address, not per element: a page that redraws while polling replaces its
 * images, and a lazy image taken out of the page never finishes, so a slow address would otherwise stay empty.
 */
const unreachable = new Set();
const loaded = new Set();
const firstSeen = new Map();
const SLOW = 8000;
const inView = typeof IntersectionObserver === 'function' ? new IntersectionObserver((entries) => {
  for (const { target, isIntersecting } of entries) {
    if (isIntersecting) {
      inView.unobserve(target);
      target.dispatchEvent(new Event('qim:visible'));
    }
  }
}) : null;

/**
 * The picture of a menu or a shop, with its name as alt text. Without a picture, or when it cannot load, the drawn
 * picture of its kind (art: FoodArt::KINDS or 'shop') stands in, so there is never an empty box or a broken image.
 * Without a kind (a person's avatar) the first letter does. The size is set by the CSS of cls before anything loads.
 */
export function picture(url, name, cls = 'pic', art) {
  const label = name || '';
  const lazy = cls.startsWith('hero') ? 'eager' : 'lazy';
  const letter = () => h('div.' + cls + '.tile', { 'aria-hidden': 'true', style: `--hue:${[...label].reduce((a, c) => (a * 31 + c.codePointAt(0)) % 360, 7)}` }, [...(label || '?').replace(/^[เแโใไ]/, '')][0] || '?');
  const drawn = () => {
    if (!art) return letter();
    const img = h('img.' + cls + '.art', { src: `${ART}${ART_KINDS.includes(art) ? art : 'other'}.svg`, alt: label, width: 400, height: 300, loading: lazy, decoding: 'async' });
    img.addEventListener('error', () => img.replaceWith(letter()), { once: true });
    return img;
  };
  if (!url || unreachable.has(url)) return drawn();
  const large = LARGE.test(cls);
  const src = url.startsWith('image:') ? `${API}/images/${url.slice(6)}${large ? '' : '/thumb'}` : url;
  const img = h('img.' + cls, { src, alt: label, width: large ? 1200 : 480, height: large ? 900 : 360, loading: lazy, decoding: 'async' });
  const giveUp = () => {
    unreachable.add(url);
    if (img.isConnected) img.replaceWith(drawn());
  };
  img.addEventListener('error', giveUp, { once: true });
  img.addEventListener('load', () => loaded.add(url), { once: true });
  img.addEventListener('qim:visible', () => {
    if (loaded.has(url)) return;
    if (!firstSeen.has(url)) firstSeen.set(url, Date.now());
    setTimeout(() => { if (!loaded.has(url) && !(img.complete && img.naturalWidth)) giveUp(); }, Math.max(0, SLOW - (Date.now() - firstSeen.get(url))));
  }, { once: true });
  inView?.observe(img);
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

/** Loads a classic script from assets/js/vendor once; resolves with the global it defines. */
const scripts = {};
export function vendor(file, global) {
  scripts[file] ??= new Promise((resolve, reject) => {
    const el = h('script', { src: new URL('./vendor/' + file, import.meta.url).href });
    el.addEventListener('load', () => resolve(window[global]));
    el.addEventListener('error', () => { delete scripts[file]; el.remove(); reject(new Error('โหลดไม่สำเร็จ ลองใหม่อีกครั้ง')); });
    document.head.append(el);
  });
  return scripts[file];
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

// ---- Online payments to give back (D42), shared by the shop and admin pages ----

export const refundHelp = () => h('p.muted', {}, 'เงินที่ลูกค้าจ่ายออนไลน์แล้วต้องคืน ระบบไม่คืนเงินให้เอง: เปิด Stripe Dashboard › Payments ค้นหารหัส Stripe ด้านล่าง แล้วกด Refund (PromptPay คืนเข้าบัญชีธนาคาร Stripe จะส่งอีเมลขอเลขบัญชีจากลูกค้า) เสร็จแล้วกด "ทำเครื่องหมายคืนเงินแล้ว"');

export function refundRow(r, done) {
  return h('div.item', {}, h('div.grow', {}, h('h3', {}, `${r.order_no} · ${baht(r.amount)}`),
    r.problem ? h('p.notice', {}, r.problem) : null, r.reason ? h('div.muted', {}, r.reason) : null,
    h('div', {}, 'รหัส Stripe: ', h('code', {}, r.intent_id)),
    r.refund_due_at ? (r.refunded_at ? h('span.badge.done', {}, `คืนแล้ว ${dateTime(r.refunded_at)}`) : h('span.badge.bad', {}, `รอคืนเงิน ตั้งแต่ ${dateTime(r.refund_due_at)}`)) : null,
    r.refund_note ? h('div.muted', {}, 'บันทึก: ', r.refund_note) : null),
  r.refund_due_at && !r.refunded_at ? h('button.btn.ghost', { type: 'button', onclick: async (e) => {
    const button = e.currentTarget;
    const note = await askReason('ยืนยันว่าคืนเงินแล้ว', 'บันทึก เช่น เลขอ้างอิงการคืนเงินใน Stripe', 'คืนเงินแล้ว');
    if (note) await busy(button, () => done(note));
  } }, 'ทำเครื่องหมายคืนเงินแล้ว') : null);
}
