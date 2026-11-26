import { $, METHOD, STATUS, api, badge, baht, busy, changes, confirmBox, dateTime, dialog, empty, eta, failed, get, h, loading, picture, poll, post, put, queueNo, setCsrf, setPoll, time, toast, vendor } from './core.js';
import { INFO } from './info.js';

const app = $('#app');
let config = { liff_id: null, dev_login: false };
let me = null;
let cart = null;
let stop = () => {};
let turn = 0;

// ---- Notifications ----------------------------------------------------------

let unread = 0;
const bellText = () => (unread ? `แจ้งเตือน ${unread > 99 ? '99+' : unread}` : 'แจ้งเตือน');
const bell = () => h('a.btn.ghost.bell' + (unread ? '.has' : ''), { href: '#/notifications', 'data-bell': true }, icon('bell'), h('span', {}, bellText()));
function setUnread(n) {
  unread = n;
  for (const el of document.querySelectorAll('[data-bell]')) {
    el.classList.toggle('has', n > 0);
    el.lastChild.textContent = bellText();
  }
}
async function countUnread() {
  if (!me) return setUnread(0);
  try {
    return setUnread((await get('/notifications/count')).unread);
  } catch (e) {
    if (e.status !== 401) throw e;
    return setUnread(0);
  }
}

async function notifications(alive) {
  if (!me) return needLogin('profile');
  const list = h('div.list', { 'aria-live': 'polite' });
  const more = h('div.row.end');
  const changed = changes();
  let page = 1;
  const draw = async () => {
    const { data, meta } = await api('GET', `/notifications?page=${page}`);
    setUnread(meta.unread);
    if (!changed([page, data, meta])) return;
    list.replaceChildren(...(data.length ? data.map((n) => h('a.item' + (n.read ? '' : '.unread'), { href: `#/order/${n.order_id}`, onclick: async (e) => {
      e.preventDefault();
      if (!n.read) await post(`/notifications/${n.id}/read`).catch(() => {});
      countUnread().catch(() => {});
      location.hash = `#/order/${n.order_id}`;
    } }, h('div.grow', {}, n.read ? null : h('span.badge.wait', {}, 'ใหม่'), h('p', { style: 'white-space:pre-line' }, n.text), h('div.muted', {}, dateTime(n.created_at))))) : [empty('ยังไม่มีการแจ้งเตือน')]));
    const pages = Math.ceil(meta.total / meta.per_page);
    const go = (d) => (e) => busy(e.currentTarget, async () => { page += d; await draw(); });
    more.replaceChildren(...(pages > 1 ? [h('button.btn.ghost', { type: 'button', disabled: page <= 1, onclick: go(-1) }, 'ก่อนหน้า'), `${page} / ${pages}`, h('button.btn.ghost', { type: 'button', disabled: page >= pages, onclick: go(1) }, 'ถัดไป')] : []));
  };
  await draw();
  if (!alive()) return undefined;
  const all = h('button.btn.ghost', { type: 'button', onclick: () => busy(all, async () => { await post('/notifications/read-all'); await draw(); }) }, 'อ่านแล้วทั้งหมด');
  frame('orders', [header('การแจ้งเตือน', '#/orders'), h('div.row.end', {}, all), list, more]);
  stop = poll(draw, 10000);
  return undefined;
}

// ---- Identity ---------------------------------------------------------------

async function loadMe() {
  try {
    me = await get('/me');
    setCsrf(me.csrf);
    cart = await get('/cart');
    countUnread().catch(() => {});
  } catch (e) {
    if (e.status !== 401) throw e;
    me = null;
    cart = null;
  }
}

async function boot() {
  app.replaceChildren(loading());
  try {
    config = await get('/config');
    setPoll(config.poll_seconds);
    await loadMe();
    if (config.liff_id) {
      // Loaded only when LINE is configured; the site still works without it.
      const liff = await import('./liff.js');
      const token = await liff.initLiff(config.liff_id);
      if (token) {
        // Always confirm who is using LINE now; an older session may belong to someone else.
        try {
          await post('/auth/line', { id_token: token });
          await loadMe();
        } catch (e) {
          if (!me && e.code === 'INVALID_IDENTITY') liff.renew();
          else if (!me) throw e;
        }
      }
    }
  } catch (e) {
    if (!me) toast(e.message, 'bad');
  }
  addEventListener('hashchange', route);
  // Only while a bell is on the screen, so other pages send no extra requests.
  poll(async () => { if (document.querySelector('[data-bell]')) await countUnread(); }, 10000);
  route();
}

/** Resolves true when signed in. */
async function requireLogin() {
  if (me) return true;
  if (config.liff_id) {
    const { login } = await import('./liff.js');
    login();
    return false;
  }
  if (!config.dev_login) {
    toast('กรุณาเปิดจากเมนูใน LINE', 'bad');
    return false;
  }
  return (await dialog('เข้าสู่ระบบสาธิต', (close) => demoLogin(() => close(true)))) === true;
}

// Both need the server to run with APP_ENV=development; /auth/dev does not exist otherwise.
let demoCode = ''; // kept for this page only, so switching customers does not ask again
const demoLogin = (done) => h('section.card.stack', {}, h('p.notice', {}, 'โหมดสาธิต ใช้แทน LINE Login แตะเพื่อเข้าเป็นลูกค้า'),
  config.demo_code_required ? h('label.field', {}, h('span', {}, 'รหัสเข้าชมการสาธิต'), h('input', { type: 'password', name: 'access_code', autocomplete: 'off', maxlength: 200, value: demoCode, oninput: (e) => { demoCode = e.target.value; } })) : null,
  Object.entries(config.demo_customers || {}).map(([key, name]) => h('button.btn.block', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => {
    await post('/auth/dev', config.demo_code_required ? { customer: key, access_code: demoCode.trim() } : { customer: key });
    await loadMe();
    await done();
  }) }, `${name} (ลูกค้าสาธิต)`)));
const demoBar = () => (config.dev_login ? h('aside.demobar', { 'aria-label': 'โหมดสาธิต' }, h('strong', {}, 'โหมดสาธิต'), h('span', {}, me ? me.display_name : 'ไม่ใช่ LINE จริง'), me ? h('a', { href: '#/messages' }, 'จำลองข้อความ LINE') : null) : '');

// ---- Layout -----------------------------------------------------------------

const ICONS = {
  home: 'M3 11 12 4l9 7v9h-6v-6H9v6H3z',
  search: 'M10.5 4a6.5 6.5 0 1 0 4 11.6L20 21l1-1-5.4-5.5A6.5 6.5 0 0 0 10.500 4m0 2a4.500 4.500 0 1 1 0 9 4.500 4.500 0 0 1 0-9',
  orders: 'M6 3h12v18l-3-2-3 2-3-2-3 2zm3 5h6v2H9zm0 4h6v2H9z',
  profile: 'M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8m-8 16c0-4 4-6 8-6s8 2 8 6z',
  bag: 'M7 7V6a5 5 0 0 1 10 0v1h3l-1.2 14H5.2L4 7zm2 0h6V6a3 3 0 0 0-6 0z',
  bell: 'M12 3a6 6 0 0 0-6 6v4l-2 3v1h16v-1l-2-3V9a6 6 0 0 0-6-6m-2 16a2 2 0 0 0 4 0z',
  clock: 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20m0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16m-1 3v6.4l4.8 2.9 1-1.7-3.8-2.3V7z',
  star: 'M12 2.5l2.9 6.2 6.8.7-5.1 4.6 1.4 6.7L12 17.3l-6 3.4 1.4-6.7-5.1-4.6 6.8-.7z',
};
function icon(name) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('fill', 'currentColor');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS(ns, 'path');
  path.setAttribute('d', ICONS[name]);
  svg.append(path);
  return svg;
}

const TABS = [['home', '#/', 'หน้าหลัก'], ['search', '#/search', 'ค้นหา'], ['orders', '#/orders', 'ออเดอร์'], ['profile', '#/profile', 'โปรไฟล์']];

function frame(tab, content, hideCart = false) {
  const showCart = !hideCart && cart?.count > 0;
  document.body.classList.toggle('has-cart', showCart);
  app.replaceChildren(
    demoBar(),
    h('main.shell', {}, content),
    showCart ? h('div.cartbar', {}, h('a.btn', { href: '#/cart' }, h('span', {}, `ตะกร้า ${cart.count} รายการ`), h('span', {}, baht(cart.total)))) : '',
    h('nav.tabbar', { 'aria-label': 'เมนูหลัก' }, TABS.map(([k, href, label]) => h('a', { href, 'aria-current': k === tab ? 'page' : null }, icon(k), label))),
  );
}

const header = (title, back) => h('header.topbar', {}, h('div.row', {}, back ? h('a.icon', { href: back, 'aria-label': 'กลับ', style: 'display:grid;place-items:center;text-decoration:none;font-size:1.5rem' }, '‹') : null, h('h1', {}, title)));

async function route() {
  stop();
  stop = () => {};
  const mine = ++turn;
  const [, page = '', id] = (location.hash || '#/').split('?')[0].split('/');
  const routes = { '': home, search, shop, cart: cartPage, orders, order, profile, help, privacy, faq, about, favorites, reviews, notifications, ...(config.dev_login ? { messages } : {}) };
  const show = routes[page] || home;
  const alive = () => mine === turn;
  const load = async () => {
    frame(page === '' || page === 'shop' ? 'home' : page === 'order' ? 'orders' : page, loading(), true);
    try {
      await show(alive, id);
    } catch (e) {
      if (!alive()) return;
      if (e.status === 401) { me = null; cart = null; }
      frame('', failed(e, load), true);
    }
    scrollTo(0, 0);
  };
  load();
}

// ---- Discovery --------------------------------------------------------------

const cartButton = () => h('a.icon.cartlink', { href: '#/cart', 'aria-label': `ตะกร้า ${cart?.count || 0} รายการ` }, icon('bag'), cart?.count ? h('span.count', { 'aria-hidden': 'true' }, cart.count > 99 ? '99+' : cart.count) : null);
const longDate = () => new Date().toLocaleDateString('th-TH', { timeZone: 'Asia/Bangkok', day: 'numeric', month: 'long', year: 'numeric' });
/** Crowd level as three labelled steps with a marker; the level is also written out. */
const crowdMeter = (level) => h('div.meter', { role: 'img', 'aria-label': CROWD[level] ? `ความหนาแน่น${CROWD[level][0]}` : 'ยังประเมินความหนาแน่นไม่ได้' },
  ['LOW', 'MEDIUM', 'HIGH'].map((k) => h('span.seg.' + k.toLowerCase() + (k === level ? '.on' : ''), { 'aria-hidden': 'true' }, CROWD[k][0])));
const meta = (name, text) => h('span.meta', {}, icon(name), h('span', {}, text));
const priceRange = (s) => (s.min_price === null ? '' : s.min_price === s.max_price ? baht(s.min_price) : `${baht(s.min_price)} – ${baht(s.max_price)}`);
const openBadge = (s) => h('span.badge.' + (s.is_open ? 'open' : 'bad'), {}, s.is_open ? 'เปิด' : 'ปิด');
// Text and a shape, so the level does not depend on colour alone.
const CROWD = { LOW: ['ต่ำ', 'open', '○'], MEDIUM: ['ปานกลาง', 'wait', '◐'], HIGH: ['สูง', 'bad', '●'] };
const crowdBadge = (level) => (CROWD[level] ? h('span.badge.' + CROWD[level][1], {}, h('span', { 'aria-hidden': 'true' }, CROWD[level][2]), ` ความหนาแน่น${CROWD[level][0]}`) : null);
// The chosen food court is only a convenience of this browser.
const court = {
  get: () => { try { return Number(localStorage.getItem('qim_court')) || 0; } catch (e) { return 0; } },
  set: (id) => { try { if (id) localStorage.setItem('qim_court', String(id)); else localStorage.removeItem('qim_court'); } catch (e) { /* private mode */ } },
};
const courtQuery = () => (court.get() ? `food_court_id=${court.get()}` : '');
const ratingText = (s) => (s.review_count ? `★ ${Number(s.rating).toFixed(1)} (${s.review_count} รีวิว)` : 'ยังไม่มีรีวิว');
const stars = (n) => h('span', { role: 'img', 'aria-label': `${n} จาก 5 ดาว` }, '★'.repeat(n) + '☆'.repeat(5 - n));

// ---- Favourites -------------------------------------------------------------

let favs = null; // { shops: Set, menus: Set } of the signed-in customer
async function loadFavs() {
  if (!me) { favs = null; return; }
  const data = await get('/favorites');
  favs = { shops: new Set(data.shops.map((x) => x.id)), menus: new Set(data.menus.map((x) => x.id)) };
}
function heart(kind, id) {
  const button = h('button.btn.ghost', { type: 'button', onclick: () => busy(button, async () => {
    if (!(await requireLogin())) return;
    if (!favs) await loadFavs();
    const on = favs[kind].has(id);
    await api(on ? 'DELETE' : 'PUT', `/favorites/${kind}/${id}`);
    if (on) favs[kind].delete(id); else favs[kind].add(id);
    draw();
  }) });
  const draw = () => {
    const on = Boolean(favs?.[kind].has(id));
    button.setAttribute('aria-pressed', String(on));
    button.textContent = on ? '♥ อยู่ในรายการโปรด' : '♡ เพิ่มในรายการโปรด';
  };
  draw();
  return button;
}

async function favorites(alive) {
  if (!me) return needLogin('profile');
  const box = h('div.stack');
  const draw = async () => {
    const data = await get('/favorites');
    const remove = (kind, id) => h('button.btn.danger.ghost', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => { await api('DELETE', `/favorites/${kind}/${id}`); await draw(); }) }, 'เอาออก');
    const row = (kind, x, title, note, href) => h('div.item' + (note ? '.off' : ''), {}, picture(x.image_url, x.name), h('div.grow', {}, h('h3', {}, href ? h('a', { href }, title) : title), note ? h('p.muted', {}, note) : null, remove(kind, x.id)));
    box.replaceChildren(
      h('h2', {}, 'ร้านโปรด'), data.shops.length ? h('div.list', {}, data.shops.map((s) => row('shops', s, s.name, !s.listed ? 'ร้านนี้ไม่เปิดให้บริการแล้ว' : s.is_open ? '' : 'ปิดอยู่ตอนนี้', s.listed ? `#/shop/${s.id}` : null))) : empty('ยังไม่มีร้านโปรด'),
      h('h2', {}, 'เมนูโปรด'), data.menus.length ? h('div.list', {}, data.menus.map((m) => row('menus', m, `${m.name} · ${m.shop.name}${m.price === null ? '' : ` · ${baht(m.price)}`}`, !m.listed ? 'เมนูนี้เลิกขายแล้ว' : m.available ? '' : 'หมดชั่วคราว', m.listed ? `#/shop/${m.shop.id}` : null))) : empty('ยังไม่มีเมนูโปรด'),
    );
  };
  await draw();
  if (!alive()) return undefined;
  frame('profile', [header('รายการโปรดของฉัน', '#/profile'), box]);
  return undefined;
}

// ---- Reviews ----------------------------------------------------------------

async function reviews(alive, id) {
  const [shopData, first] = await Promise.all([get(`/shops/${id}`), api('GET', `/shops/${id}/reviews`)]);
  const list = h('div.list');
  const more = h('div.row.end');
  let page = 1;
  const draw = ({ data, meta }) => {
    list.replaceChildren(...(data.length ? data.map((r) => h('article.card', {}, h('div.row.between', {}, stars(r.rating), h('span.muted', {}, r.date)), r.comment ? h('p', {}, r.comment) : null)) : [empty('ยังไม่มีรีวิว')]));
    const pages = Math.ceil(meta.total / meta.per_page);
    const go = (d) => (e) => busy(e.currentTarget, async () => { page += d; draw(await api('GET', `/shops/${id}/reviews?page=${page}`)); });
    more.replaceChildren(...(pages > 1 ? [h('button.btn.ghost', { type: 'button', disabled: page <= 1, onclick: go(-1) }, 'ก่อนหน้า'), `${page} / ${pages}`, h('button.btn.ghost', { type: 'button', disabled: page >= pages, onclick: go(1) }, 'ถัดไป')] : []));
  };
  draw(first);
  if (!alive()) return;
  frame('home', [header(`รีวิว ${shopData.name}`, `#/shop/${id}`), h('p', {}, ratingText(shopData)), h('p.muted', {}, 'รีวิวจากผู้ที่สั่งและรับอาหารแล้ว ไม่แสดงชื่อผู้เขียน'), list, more]);
}

function reviewSection(o, redraw) {
  if (o.review) {
    return h('section.card', {}, h('h2', {}, 'รีวิวของคุณ'), stars(o.review.rating), o.review.comment ? h('p', {}, o.review.comment) : null, o.review.hidden ? h('p.muted', {}, 'ผู้ดูแลซ่อนรีวิวนี้') : null);
  }
  const rating = h('select', { name: 'rating', required: true }, h('option', { value: '' }, 'เลือกคะแนน'), [5, 4, 3, 2, 1].map((n) => h('option', { value: n }, `${n} ดาว ${'★'.repeat(n)}`)));
  const comment = h('textarea', { name: 'comment', maxlength: 500, rows: 3 });
  const button = h('button.btn.block', { type: 'submit' }, 'ส่งรีวิว');
  return h('section.card', {}, h('h2', {}, 'ให้คะแนนร้าน'), h('form', { onsubmit: (e) => {
    e.preventDefault();
    busy(button, async () => {
      try { await post(`/orders/${o.id}/review`, { rating: Number(rating.value), comment: comment.value }); toast('ขอบคุณสำหรับรีวิว'); } finally { await redraw(); }
    });
  } }, h('label.field', {}, h('span', {}, 'คะแนน'), rating), h('label.field', {}, h('span', {}, 'ความคิดเห็น (ไม่บังคับ ไม่เกิน 500 ตัวอักษร)'), comment), h('p.muted', {}, 'รีวิวแสดงโดยไม่ระบุชื่อ ส่งแล้วแก้ไขไม่ได้ อย่าใส่ข้อมูลส่วนตัว'), button));
}

/** A stall: large picture on the home page, compact row in search results. */
const shopCard = (s) => h('a.item.stall' + (s.matched_menus ? '.compact' : '') + (s.is_open ? '' : '.off'), { href: `#/shop/${s.id}` }, picture(s.image_url, s.name, s.matched_menus ? 'pic' : 'cover'), h('div.grow', {},
  h('div.row.between', {}, h('h3', {}, s.name), openBadge(s)), s.description ? h('div.muted', {}, s.description) : null,
  h('div.metas', {}, meta('star', s.review_count ? `${Number(s.rating).toFixed(1)} (${s.review_count})` : 'ยังไม่มีรีวิว'), s.is_open ? meta('clock', eta(s.eta_minutes)) : null, h('span.price', {}, priceRange(s))),
  s.is_open ? h('div.row', {}, h('span.muted', {}, `รอ ${s.waiting} คิว`), crowdBadge(s.crowd)) : null,
  s.matched_menus?.length ? h('div.found', {}, h('span.muted', {}, 'พบ: '), s.matched_menus.map((m) => h('span.chip.mini', {}, m.name + (m.available ? '' : ' (หมด)')))) : null));

async function home(alive) {
  let category = '';
  const list = h('div.list');
  const changed = changes();
  const courtBox = h('label.pick');
  const crowdCard = h('section.crowdcard', { 'aria-live': 'polite' });
  const courtsChanged = changes();
  const drawCourts = (courts) => {
    if (courts.length && court.get() && !courts.some((c) => c.id === court.get())) court.set(0); // closed or removed
    const chosen = courts.find((c) => c.id === court.get());
    const select = h('select', { name: 'food_court', onchange: (e) => busy(e.currentTarget, async () => { court.set(Number(e.target.value)); courtsChanged(null); await draw(); }) },
      h('option', { value: 0 }, 'ทุกโรงอาหาร'), courts.map((c) => h('option', { value: c.id, selected: c.id === court.get() }, `${c.name}${CROWD[c.crowd] ? ` · ${CROWD[c.crowd][2]} ${CROWD[c.crowd][0]}` : ''}`)));
    courtBox.replaceChildren(h('span.eyebrow', {}, 'รับอาหารที่'), select);
    crowdCard.replaceChildren(h('div.row.between', {}, h('span.muted', {}, longDate()), chosen ? h('span.muted', {}, `เปิด ${chosen.open_shops} จาก ${chosen.shops} ร้าน`) : null),
      h('h2', {}, 'ความหนาแน่น', chosen ? ` · ${chosen.name}` : ''),
      // replaceChildren is the DOM method: it takes nodes, not arrays.
      ...(chosen ? [crowdMeter(chosen.crowd), h('p', {}, crowdBadge(chosen.crowd) || h('span.muted', {}, 'ยังประเมินไม่ได้'), chosen.location ? h('span.muted', {}, ` ${chosen.location}`) : null)]
        : [h('p.muted', {}, 'เลือกโรงอาหารด้านบนเพื่อดูความหนาแน่นรวม แต่ละร้านแสดงระดับของตัวเองด้านล่าง')]));
  };
  const draw = async () => {
    const courts = await get('/food-courts');
    if (courtsChanged([courts, court.get()])) drawCourts(courts);
    const query = [courtQuery(), category ? `category=${encodeURIComponent(category)}` : ''].filter(Boolean).join('&');
    const shops = await get('/shops' + (query ? `?${query}` : ''));
    if (changed(shops)) list.replaceChildren(...(shops.length ? shops.map(shopCard) : [empty('ยังไม่มีร้านในหมวดนี้')]));
  };
  const categories = await get('/categories');
  await draw();
  if (!alive()) return;
  const chips = h('div.chips', { role: 'group', 'aria-label': 'หมวดหมู่' });
  const drawChips = () => chips.replaceChildren(...['', ...categories].map((c) => h('button.chip', { type: 'button', 'aria-pressed': String(c === category), onclick: (e) => busy(e.currentTarget, async () => { category = c; await draw(); drawChips(); }) }, c || 'ทั้งหมด')));
  drawChips();
  frame('home', [
    h('header.topbar.homebar', {}, courtBox, h('div.row', {}, me ? bell() : null, me ? cartButton() : null)),
    h('p.hello', {}, me ? ['สวัสดี ', h('b', {}, me.display_name)] : h('b.brand', {}, 'Q-im')),
    h('p.banner', {}, h('strong', {}, 'สั่งก่อน ไม่ต้องยืนรอ'), ' เดินไปรับเมื่ออาหารเสร็จ'),
    h('a.searchbar', { href: '#/search' }, icon('search'), h('span', {}, 'ค้นหาเมนูหรือร้าน')),
    !me && config.dev_login && !config.liff_id ? demoLogin(route) : null,
    crowdCard,
    h('h2.section', {}, 'หมวดหมู่'), chips,
    h('h2.section', {}, 'ร้านในโรงอาหาร'), list,
    h('p.muted', { style: 'margin-top:1rem' }, infoLinks()),
  ]);
  stop = poll(draw, 10000);
}

async function search(alive) {
  const results = h('div.list', { 'aria-live': 'polite' });
  const input = h('input', { type: 'search', 'aria-label': 'ค้นหาร้านหรือเมนู', placeholder: 'เช่น กะเพรา', maxlength: 100 });
  const open = h('input', { type: 'checkbox' });
  let timer = 0;
  let seq = 0;
  const run = async () => {
    const n = ++seq;
    const text = input.value.trim();
    if (!text) return results.replaceChildren(empty('พิมพ์ชื่อร้านหรือเมนูที่อยากกิน'));
    results.replaceChildren(loading());
    try {
      const shops = await get(`/shops?q=${encodeURIComponent(text)}${open.checked ? '&open=1' : ''}${courtQuery() ? `&${courtQuery()}` : ''}`);
      if (n === seq) results.replaceChildren(...(shops.length ? shops.map(shopCard) : [empty('ไม่พบร้านหรือเมนูนี้')]));
    } catch (e) {
      if (n === seq) results.replaceChildren(failed(e, run));
    }
    return undefined;
  };
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 350); });
  open.addEventListener('change', run);
  if (!alive()) return;
  frame('search', [header('ค้นหา', '#/'), h('label.searchfield', {}, icon('search'), input), h('label.check', {}, open, 'เฉพาะร้านที่เปิดอยู่'), h('h2.section', {}, 'ผลการค้นหา'), results]);
  run();
  input.focus();
}

async function shop(alive, id) {
  const [s, data] = await Promise.all([get(`/shops/${id}`), get(`/shops/${id}/menus`), loadFavs()]);
  if (!alive()) return;
  const status = h('p');
  const drawStatus = (x) => status.replaceChildren(openBadge(x), ' ', x.is_open ? `รอ ${x.waiting} คิว · ${eta(x.eta_minutes)} ` : 'ยังไม่รับออเดอร์ตอนนี้', x.is_open && x.crowd ? crowdBadge(x.crowd) : '');
  drawStatus(s);
  const menuCard = (m) => h('button.item' + (m.available && s.is_open ? '' : '.off'), { type: 'button', disabled: !m.available, onclick: () => foodDetail(s, m) }, picture(m.image_url, m.name), h('div.grow', {}, h('h3', {}, m.name), m.description ? h('div.muted', {}, m.description) : null, h('div.row.between', {}, h('span.price', {}, baht(m.price)), m.available ? null : h('span.badge.bad', {}, 'หมด'))));
  const groups = [...data.categories, { id: null, name: 'อื่น ๆ' }].map((c) => [c, data.menus.filter((m) => m.category_id === c.id)]).filter(([, l]) => l.length);
  const render = () => frame('home', [header(s.name, '#/'), picture(s.image_url, s.name, 'hero'), h('div.stack', { style: 'margin-top:.8rem' }, s.description ? h('p', {}, s.description) : null, h('p.muted', {}, s.food_court, s.location ? ` · ${s.location}` : ''), status,
    h('div.row.between', {}, h('a', { href: `#/reviews/${s.id}`, style: 'display:inline-flex;min-height:44px;align-items:center' }, ratingText(s)), heart('shops', s.id)),
    groups.length ? groups.map(([c, l]) => h('section', {}, h('h2', {}, c.name), h('div.list', {}, l.map(menuCard)))) : empty('ร้านนี้ยังไม่มีเมนู'))]);
  render();
  shopView = { id: s.id, render };
  stop = poll(async () => drawStatus(await get(`/shops/${id}`)), 10000);
}
let shopView = null;

function foodDetail(s, m) {
  return dialog(m.name, (close) => {
    let quantity = 1;
    const picked = new Map(m.option_groups.map((g) => [g.id, new Set(g.min_select > 0 && g.max_select === 1 ? [g.options[0]?.id] : [])]));
    const note = h('input', { maxlength: 255, placeholder: 'เช่น ไม่ใส่ผักชี' });
    const count = h('b', { 'aria-live': 'polite' }, '1');
    const button = h('button.btn.block', { type: 'submit', disabled: !s.is_open });
    const update = () => {
      count.textContent = String(quantity);
      let unit = Math.round(Number(m.price) * 100);
      for (const g of m.option_groups) for (const o of g.options) if (picked.get(g.id).has(o.id)) unit += Math.round(Number(o.price_delta) * 100);
      button.textContent = s.is_open ? `เพิ่มลงตะกร้า ${baht(unit * quantity / 100)}` : 'ร้านปิดรับออเดอร์';
    };
    const step = (d) => { quantity = Math.min(20, Math.max(1, quantity + d)); update(); };
    update();
    return h('form', { onsubmit: (e) => {
      e.preventDefault();
      for (const g of m.option_groups) {
        if (picked.get(g.id).size < g.min_select) return toast(`กรุณาเลือก ${g.name}`, 'bad');
      }
      return busy(button, async () => {
        if (!(await requireLogin())) return;
        await addToCart(s.id, { menu_id: m.id, quantity, note: note.value.trim() || null, option_ids: [...picked.values()].flatMap((x) => [...x]) });
        close(true);
        toast('เพิ่มลงตะกร้าแล้ว');
        if (shopView?.id === s.id && location.hash.startsWith('#/shop/')) shopView.render();
      });
    } }, picture(m.image_url, m.name, 'hero'),
    h('div.row.between.foodhead', {}, h('div.metas', {}, meta('star', s.review_count ? Number(s.rating).toFixed(1) : 'ยังไม่มีรีวิว'), m.prep_minutes ? meta('clock', `${m.prep_minutes} นาที`) : null), heart('menus', m.id)),
    m.description ? h('p.muted', {}, m.description) : null,
    m.option_groups.map((g) => h('fieldset', {}, h('legend', {}, g.name, g.min_select > 0 ? ' (จำเป็น)' : '', g.max_select > 1 ? ` เลือกได้ ${g.max_select}` : ''), g.options.map((o) => {
      const single = g.max_select === 1 && g.min_select === 1;
      const input = h('input', { type: single ? 'radio' : 'checkbox', name: `g${g.id}`, checked: picked.get(g.id).has(o.id), onchange: () => {
        const set = picked.get(g.id);
        if (single) set.clear();
        if (!input.checked) set.delete(o.id);
        else if (set.size >= g.max_select) { input.checked = false; toast(`เลือกได้ไม่เกิน ${g.max_select}`, 'bad'); } else set.add(o.id);
        update();
      } });
      return h('label.check', {}, input, h('span.grow', {}, o.name), Number(o.price_delta) ? h('span.muted', {}, '+' + baht(o.price_delta)) : null);
    }))),
    h('label.field', {}, h('span.eyebrow', {}, 'หมายเหตุถึงร้าน'), note),
    h('div.row.between.buyrow', {}, h('span.bigprice', {}, baht(m.price)), h('div.stepper', { role: 'group', 'aria-label': 'จำนวน' }, h('button.icon', { type: 'button', 'aria-label': 'ลดจำนวน', onclick: () => step(-1) }, '−'), count, h('button.icon', { type: 'button', 'aria-label': 'เพิ่มจำนวน', onclick: () => step(1) }, '+'))), button);
  });
}

// ---- Cart and checkout ------------------------------------------------------

const toInput = (i) => ({ menu_id: i.menu_id, quantity: i.quantity, note: i.note, option_ids: i.options.map((o) => o.id) });

async function addToCart(shopId, item) {
  const same = cart?.shop?.id === shopId;
  const items = same ? cart.items.map(toInput) : [];
  const twin = items.find((i) => i.menu_id === item.menu_id && i.note === item.note && String([...i.option_ids].sort()) === String([...item.option_ids].sort()));
  if (twin) twin.quantity = Math.min(20, twin.quantity + item.quantity);
  else items.push(item);
  try {
    cart = await put('/cart', { shop_id: shopId, items });
  } catch (e) {
    if (e.code !== 'CART_SHOP_CONFLICT') throw e;
    if (!(await confirmBox('เปลี่ยนร้าน?', `${e.message} ต้องการล้างตะกร้าและเริ่มใหม่กับร้านนี้หรือไม่`, 'ล้างและเริ่มใหม่', true))) throw new Error('ยังไม่ได้เพิ่มลงตะกร้า');
    cart = await put('/cart', { shop_id: shopId, items: [item], replace: true });
  }
}

// One key per quote, kept across reloads, so a retry after a lost response cannot order twice.
function checkoutKey(quote) {
  try {
    const saved = JSON.parse(sessionStorage.getItem('qim_checkout') || 'null');
    if (saved?.quote === quote) return saved.key;
  } catch (e) { /* fall through to a new key */ }
  const key = [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('');
  try { sessionStorage.setItem('qim_checkout', JSON.stringify({ quote, key })); } catch (e) { /* private mode */ }
  return key;
}
const forgetKey = () => { try { sessionStorage.removeItem('qim_checkout'); } catch (e) { /* private mode */ } };

async function cartPage(alive) {
  if (!me) return needLogin('cart');
  cart = await get('/cart');
  if (!alive()) return undefined;
  const change = (items) => async (e) => busy(e?.currentTarget, async () => {
    cart = await put('/cart', { shop_id: cart.shop?.id, items });
    draw();
  });
  const draw = () => {
    if (!cart.items.length) return frame('', [header('ตะกร้า', '#/'), empty('ยังไม่มีอาหารในตะกร้า', h('a.btn', { href: '#/' }, 'เลือกร้าน'))], true);
    const inputs = cart.items.map(toInput);
    const replaceQty = (n, q) => change(q < 1 ? inputs.filter((_, i) => i !== n) : inputs.map((x, i) => (i === n ? { ...x, quantity: q } : x)));
    const blocked = cart.has_problems || !cart.shop.is_open;
    return frame('', [header('ตะกร้าและยืนยันออเดอร์', `#/shop/${cart.shop.id}`), h('h2', {}, cart.shop.name),
      cart.shop.is_open ? null : h('p.notice.bad', {}, 'ร้านปิดรับออเดอร์อยู่ในขณะนี้'),
      h('div.list', {}, cart.items.map((i, n) => h('div.item.cartline', {}, picture(i.image_url, i.name || '?'), h('div.grow', {}, h('div.row.between', {}, h('h3', {}, i.name || 'เมนูที่ไม่มีแล้ว'), h('button.btn.danger.ghost.remove', { type: 'button', onclick: replaceQty(n, 0) }, 'ลบ')), i.options.length ? h('div.muted', {}, i.options.map((o) => o.name).join(', ')) : null, i.note ? h('div.muted', {}, 'หมายเหตุ: ', i.note) : null, i.problem ? h('p.notice.bad', {}, i.problem) : null,
        h('div.row.between', {}, h('span.price', {}, baht(i.line_total)), h('div.stepper', {}, h('button.icon', { type: 'button', 'aria-label': `ลดจำนวน ${i.name}`, onclick: replaceQty(n, i.quantity - 1) }, '−'), h('b', {}, i.quantity), h('button.icon', { type: 'button', 'aria-label': `เพิ่มจำนวน ${i.name}`, disabled: i.quantity >= 20, onclick: replaceQty(n, i.quantity + 1) }, '+'))))))),
      h('a.btn.ghost.block', { href: `#/shop/${cart.shop.id}`, style: 'margin-top:1rem' }, 'เพิ่มเมนูอื่น'),
      h('section.checkout', {}, h('div.row.between', {}, h('span.eyebrow', {}, 'วิธีรับ'), h('b', {}, 'รับเองที่ร้าน')), paymentChoice(), h('div.total', {}, h('span.eyebrow', {}, 'ยอดรวม'), h('span.bigprice', {}, baht(cart.total))),
        h('button.btn.block', { type: 'button', disabled: blocked, onclick: (e) => checkout(e.currentTarget, draw) }, blocked ? 'ยังสั่งไม่ได้' : `ยืนยันออเดอร์ ${baht(cart.total)}`))], true);
  };
  draw();
  return undefined;
}

let payWith = 'PAY_AT_STORE';
function paymentChoice() {
  if (!cart.shop.promptpay) {
    payWith = 'PAY_AT_STORE';
    return h('div.row.between', {}, h('span.eyebrow', {}, 'วิธีชำระ'), h('b', {}, 'ชำระที่ร้านตอนรับ'));
  }
  // Tiles as in the Figma payment screen; each is a real radio button with its own text.
  const option = (value, label, hint) => h('label.method', {}, h('input', { type: 'radio', name: 'payment_method', value, checked: payWith === value, onchange: () => { payWith = value; } }), h('span', {}, h('b', {}, label), h('span.muted', {}, hint)));
  return h('fieldset.methods', {}, h('legend', {}, 'วิธีชำระ'), option('PAY_AT_STORE', 'ชำระที่ร้านตอนรับ', 'จ่ายเงินสดกับร้าน'), option('PROMPTPAY', 'PromptPay', 'QR จะแสดงเมื่ออาหารพร้อม ร้านตรวจยอดแล้วยืนยันเอง'));
}

const checkout = (button, redraw) => busy(button, async () => {
  try {
    const order = await post('/orders', { idempotency_key: checkoutKey(cart.quote), quote: cart.quote, payment_method: cart.shop.promptpay ? payWith : 'PAY_AT_STORE' });
    forgetKey();
    cart = await get('/cart').catch(() => null);
    location.hash = `#/order/${order.id}`;
  } catch (e) {
    if (['PRICE_CHANGED', 'ITEM_UNAVAILABLE', 'SHOP_CLOSED', 'CART_EMPTY', 'PAYMENT_METHOD_UNAVAILABLE'].includes(e.code)) {
      forgetKey();
      cart = await get('/cart');
      redraw();
    }
    throw e;
  }
});

// ---- Orders -----------------------------------------------------------------

function needLogin(tab) {
  if (config.dev_login && !config.liff_id) return frame(tab, [header('เข้าสู่ระบบ'), demoLogin(route)], true);
  frame(tab, [header('เข้าสู่ระบบ'), empty('เข้าสู่ระบบเพื่อใช้งานส่วนนี้', h('button.btn', { type: 'button', onclick: async () => { if (await requireLogin()) route(); } }, config.liff_id ? 'เข้าสู่ระบบด้วย LINE' : 'เข้าสู่ระบบ'))], true);
  return undefined;
}

async function orders(alive) {
  if (!me) return needLogin('orders');
  let scope = 'active';
  let page = 1;
  const list = h('div.list');
  const chips = h('div.chips', { role: 'group', 'aria-label': 'ประเภทออเดอร์' });
  const more = h('div.row.end');
  const changed = changes();
  const draw = async () => {
    const { data, meta } = await api('GET', `/orders?scope=${scope}&page=${page}`);
    if (!changed([page, data, meta])) return;
    list.replaceChildren(...(data.length ? data.map((o) => h('a.item', { href: `#/order/${o.id}` }, h('div.grow', {}, h('div.row.between', {}, h('h3', {}, o.shop.name), badge(o.status)), h('div.muted', {}, dateTime(o.created_at), ' · ', o.order_no), h('div', {}, o.items.map((i) => `${i.quantity} × ${i.name}`).join(', ')), h('div.row.between', {}, o.queue ? h('b', {}, queueNo(o.queue.number)) : h('span'), h('span.price', {}, baht(o.total)))))) : [empty('ไม่มีออเดอร์ในส่วนนี้')]));
    const pages = Math.ceil(meta.total / meta.per_page);
    const turnPage = (d) => (e) => busy(e.currentTarget, async () => { page += d; await draw(); });
    more.replaceChildren(...(pages > 1 ? [h('button.btn.ghost', { type: 'button', disabled: page <= 1, onclick: turnPage(-1) }, 'ก่อนหน้า'), `${page} / ${pages}`, h('button.btn.ghost', { type: 'button', disabled: page >= pages, onclick: turnPage(1) }, 'ถัดไป')] : []));
  };
  const drawChips = () => chips.replaceChildren(...[['active', 'กำลังดำเนินการ'], ['completed', 'สำเร็จ'], ['cancelled', 'ยกเลิก/ปฏิเสธ']].map(([k, label]) => h('button.chip', { type: 'button', 'aria-pressed': String(k === scope), onclick: (e) => busy(e.currentTarget, async () => { scope = k; page = 1; drawChips(); await draw(); }) }, label)));
  await draw();
  if (!alive()) return undefined;
  drawChips();
  frame('orders', [header('ออเดอร์ของฉัน'), h('div.row.end', {}, bell()), chips, list, more]);
  stop = poll(draw, 10000);
  return undefined;
}

async function order(alive, id) {
  if (!me) return needLogin('orders');
  let last = '';
  const box = h('div.stack');
  const draw = async () => {
    const o = await get(`/orders/${id}`);
    if (!alive()) return;
    const now = JSON.stringify(o);
    if (now === last) return;
    if (last && JSON.parse(last).status !== o.status) toast(STATUS[o.status][0]);
    last = now;
    box.replaceChildren(...tracking(o, draw));
    if (['COMPLETED', 'REJECTED', 'CANCELLED'].includes(o.status)) stop();
  };
  await draw();
  if (!alive()) return undefined;
  frame('orders', [header('ติดตามออเดอร์', '#/orders'), box], true);
  if (!/"status":"(COMPLETED|REJECTED|CANCELLED)"/.test(last.slice(0, 120))) stop = poll(draw, 6000);
  return undefined;
}

function tracking(o, redraw) {
  const closed = ['REJECTED', 'CANCELLED'].includes(o.status);
  const headline = {
    PENDING: ['รอร้านยืนยัน', 'ระบบจะออกคิวให้เมื่อร้านรับออเดอร์'],
    ACCEPTED: [null, o.ahead === null ? '' : o.ahead ? `มี ${o.ahead} คิวก่อนหน้า` : 'ถึงคิวของคุณแล้ว'],
    PREPARING: [null, o.ahead ? `ร้านกำลังทำ มี ${o.ahead} คิวก่อนหน้า` : 'ร้านกำลังทำอาหารของคุณ'],
    READY: [null, 'อาหารพร้อมแล้ว เดินไปรับได้เลย'],
    COMPLETED: [null, 'รับอาหารเรียบร้อย ขอให้อร่อย'],
    REJECTED: ['ร้านปฏิเสธออเดอร์', o.reason || ''],
    CANCELLED: ['ออเดอร์ถูกยกเลิก', o.reason || ''],
  }[o.status];
  // Accepting starts cooking at once; ACCEPTED is shown only for orders accepted before that change.
  const flow = ['PENDING', ...(o.status === 'ACCEPTED' ? ['ACCEPTED'] : []), 'PREPARING', 'READY', 'COMPLETED'];
  const at = Object.fromEntries(o.timeline.map((t) => [t.status, t.at]));
  const steps = closed ? o.timeline.map((t) => t.status) : flow;
  return [
    h('section.ticket' + (closed ? '.bad' : o.status === 'READY' ? '.ready' : ''), { 'aria-live': 'polite' }, h('div.eyebrow', {}, 'สถานะออเดอร์'), h('div', {}, o.shop.name), h('div.no', headline[0] ? { style: 'font-size:1.8rem' } : {}, headline[0] || (o.queue ? queueNo(o.queue.number) : '')), h('div', {}, headline[1]),
      ['ACCEPTED', 'PREPARING'].includes(o.status) ? h('div.etabox', {}, h('b', {}, eta(o.eta_minutes)), h('span', {}, 'เวลาโดยประมาณก่อนรับได้')) : null),
    closed ? null : h('section.card', { style: 'text-align:center' },
      o.pickup?.qr ? [h('div', {}, h('b', {}, 'QR รับอาหาร'), h('div.muted', {}, 'ให้ร้านสแกนตอนรับอาหาร ใช้ได้ครั้งเดียว')), qrImage(o.pickup.qr, 'QR รับอาหาร')] : null,
      o.pickup?.expired ? h('p.notice', {}, 'QR หมดอายุแล้ว แจ้งเลขออเดอร์ด้านล่างกับร้าน') : null,
      o.pickup?.unavailable ? h('p.notice', { role: 'alert' }, 'แสดง QR รับอาหารไม่ได้ในขณะนี้ แจ้งเลขออเดอร์ด้านล่างกับร้าน') : null,
      ['PENDING', 'ACCEPTED', 'PREPARING'].includes(o.status) ? h('p.muted', {}, 'QR รับอาหารจะแสดงเมื่ออาหารพร้อม') : null,
      h('div.muted', {}, 'เลขออเดอร์'), h('div.code', { style: 'font-size:1.25rem;font-weight:700' }, o.order_no)),
    o.promptpay ? h('section.card', { style: 'text-align:center' }, h('h2', {}, 'จ่ายเงินด้วย PromptPay'), o.promptpay.payload
      ? [h('p.muted', {}, 'สแกนด้วยแอปธนาคาร ตรวจชื่อผู้รับและยอดก่อนโอน (QR นี้ไม่ใช่ QR รับอาหาร)'), qrImage(o.promptpay.payload, 'QR จ่ายเงิน PromptPay'), h('div.price', { style: 'font-size:1.5rem' }, baht(o.promptpay.amount)), h('p.notice', {}, 'รอร้านตรวจสอบยอด การสแกนยังไม่ถือว่าชำระแล้ว')]
      : h('p.notice', {}, 'ร้านปิดรับ PromptPay แล้ว กรุณาชำระที่ร้าน')) : null,
    h('section.card', {}, h('h2', {}, 'สถานะ'), h('ol.steps', {}, steps.map((s) => h('li' + (at[s] ? '.done' : ''), { 'aria-current': s === o.status ? 'step' : null }, STATUS[s][0], at[s] ? h('span.muted', {}, ' ', time(at[s])) : null)))),
    h('section.card', {}, h('h2', {}, 'รายการ'), o.items.map((i) => h('div.row.between', {}, h('span.grow', {}, `${i.quantity} × ${i.name}`, i.options.length ? h('div.muted', {}, i.options.map((x) => x.name).join(', ')) : null, i.note ? h('div.muted', {}, 'หมายเหตุ: ', i.note) : null), baht(i.line_total))), h('div.total', {}, h('span', {}, 'ยอดรวม'), h('span', {}, baht(o.total))), h('p.muted', {}, o.payment_status === 'PAID' ? `✓ ชำระแล้ว · ${METHOD[o.payment_method]}` : closed ? '' : o.payment_method === 'PROMPTPAY' ? 'เลือกจ่ายด้วย PromptPay เมื่ออาหารพร้อม' : 'ชำระที่ร้านตอนรับอาหาร')),
    o.status === 'COMPLETED' ? reviewSection(o, redraw) : null,
    o.can_cancel ? h('button.btn.danger.ghost.block', { type: 'button', onclick: async (e) => {
      const button = e.currentTarget;
      if (await confirmBox('ยกเลิกออเดอร์?', 'ยกเลิกแล้วต้องสั่งใหม่', 'ยกเลิกออเดอร์', true)) {
        await busy(button, async () => { try { await post(`/orders/${o.id}/cancel`); } finally { await redraw(); } });
      }
    } }, 'ยกเลิกออเดอร์') : ['ACCEPTED', 'PREPARING'].includes(o.status) ? h('p.muted', {}, 'ร้านรับออเดอร์แล้ว หากต้องการยกเลิกกรุณาติดต่อร้าน') : null,
  ].filter(Boolean);
}

/** Draws one QR code: the single-use pickup token, or the PromptPay text. */
const qrCache = new Map();
function qrImage(text, label) {
  const box = h('div.qr', { role: 'img', 'aria-label': label });
  const show = (src) => box.replaceChildren(h('img', { src, alt: '', width: 232, height: 232 }));
  if (qrCache.has(text)) {
    show(qrCache.get(text));
  } else {
    box.append(h('div.spinner', { 'aria-hidden': 'true' }));
    vendor('qrcode.js', 'qrcode').then((qrcode) => {
      const qr = qrcode(0, 'M');
      qr.addData(text);
      qr.make();
      // 232 px wide whatever the size of the code, so it scans from a phone held at arm's length.
      const cell = Math.max(2, Math.floor(232 / (qr.getModuleCount() + 8)));
      qrCache.set(text, qr.createDataURL(cell, cell * 4));
      show(qrCache.get(text));
    }).catch(() => box.replaceChildren(h('p.muted', {}, 'แสดง QR ไม่ได้ แจ้งเลขออเดอร์ด้านล่างกับร้าน')));
  }
  return box;
}

// ---- Profile ----------------------------------------------------------------

async function profile() {
  if (!me) return needLogin('profile');
  frame('profile', [header('โปรไฟล์'), h('section.card.stack', {}, h('div.row', {}, picture(me.avatar_url, me.display_name), h('h2', {}, me.display_name)), bell(), h('a.btn.ghost.block', { href: '#/favorites' }, 'รายการโปรดของฉัน'), h('a.btn.ghost.block', { href: '#/help' }, 'วิธีใช้งานและติดต่อ'),
    h('button.btn.ghost.block', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => {
      await post('/auth/logout');
      if (config.liff_id) { const { logout } = await import('./liff.js'); logout(); }
      me = null;
      cart = null;
      location.hash = '#/';
    }) }, 'ออกจากระบบ')),
  h('section.card', { style: 'margin-top:1rem' }, h('h2', {}, 'ความเป็นส่วนตัว'), h('p', {}, 'Q-im เก็บรหัสผู้ใช้ LINE ชื่อ และรูปโปรไฟล์ เพื่อระบุตัวตนและแจ้งสถานะออเดอร์ ร้านเห็นเพียงรายการอาหารและหมายเหตุที่คุณเขียน ประวัติออเดอร์เก็บไว้เพื่อการตรวจสอบและรายงาน'), h('p', {}, infoLinks()))]);
  return undefined;
}

async function help() {
  frame('profile', [header('วิธีใช้งาน', '#/profile'), h('section.card', {}, h('ol', {}, ['เลือกร้านและเมนู แล้วเพิ่มลงตะกร้า', 'ยืนยันออเดอร์ ยังไม่ต้องจ่ายเงิน', 'ร้านรับออเดอร์ ระบบออกหมายเลขคิวให้อัตโนมัติ', 'เมื่อสถานะเป็นพร้อมรับ เดินไปที่ร้าน', 'เมื่อพร้อมรับ ให้ร้านสแกน QR รับอาหาร ชำระเงิน แล้วรับอาหาร'].map((t) => h('li', {}, t))), h('p.muted', {}, 'เวลารอเป็นค่าประมาณ ต้องการแก้ไขหรือยกเลิกหลังร้านรับออเดอร์ กรุณาติดต่อร้านโดยตรง')), h('p', {}, infoLinks())]);
}

const infoLinks = () => ['privacy', 'faq', 'about'].flatMap((k) => [h('a', { href: `#/${k}`, style: 'display:inline-flex;min-height:44px;align-items:center;margin-right:1rem' }, INFO[k][0])]);
const info = (key) => async () => frame('profile', [header(INFO[key][0], '#/profile'), h('div.stack', {}, INFO[key][1]()), h('p', {}, infoLinks())]);
const privacy = info('privacy');
const faq = info('faq');
const about = info('about');

/** Demo only: the outbox of this customer as a chat. Nothing is sent to LINE. */
async function messages(alive) {
  if (!me) return needLogin('orders');
  const box = h('div.chat', { 'aria-live': 'polite' });
  const changed = changes();
  const draw = async () => {
    const list = await get('/dev/messages');
    if (!changed(list)) return;
    box.replaceChildren(...(list.length ? list.map((m) => h('article.bubble', {}, h('p', {}, m.text), h('a', { href: `#/order/${m.order_id}` }, 'ดูออเดอร์'), h('div.muted', {}, time(m.created_at), ' · จำลอง ยังไม่ได้ส่ง'))) : [empty('ยังไม่มีข้อความ ระบบจะเตรียมข้อความเมื่อร้านรับออเดอร์และเมื่ออาหารพร้อม')]));
    if (alive()) scrollTo(0, document.body.scrollHeight);
  };
  await draw();
  if (!alive()) return undefined;
  frame('orders', [header('จำลองข้อความ LINE', '#/orders'), h('p.notice', {}, 'ภาพจำลองเพื่อการสาธิต ไม่ได้ส่งผ่าน LINE จริง แสดงข้อความที่ระบบเตรียมไว้ให้ลูกค้าเมื่อเชื่อมต่อ LINE แล้ว'), box], true);
  stop = poll(draw, 6000);
  return undefined;
}

boot();
