import { $, STATUS, api, badge, baht, busy, confirmBox, dateTime, dialog, empty, eta, failed, get, h, loading, picture, poll, post, put, queueNo, setCsrf, time, toast } from './core.js';

const app = $('#app');
let config = { liff_id: null, dev_login: false };
let me = null;
let cart = null;
let stop = () => {};
let turn = 0;

// ---- Identity ---------------------------------------------------------------

async function loadMe() {
  try {
    me = await get('/me');
    setCsrf(me.csrf);
    cart = await get('/cart');
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
  return (await dialog('เข้าสู่ระบบทดสอบ', (close) => [
    h('p.notice', {}, 'โหมดพัฒนาเท่านั้น ใช้แทน LINE Login'),
    ...['CUSTOMER_1', 'CUSTOMER_2'].map((c) => h('p', {}, h('button.btn.block', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => {
      await post('/auth/dev', { customer: c });
      await loadMe();
      close(true);
    }) }, 'Demo ' + c))),
  ])) === true;
}

// ---- Layout -----------------------------------------------------------------

const ICONS = {
  home: 'M3 11 12 4l9 7v9h-6v-6H9v6H3z',
  search: 'M10.5 4a6.5 6.5 0 1 0 4 11.6L20 21l1-1-5.4-5.5A6.5 6.5 0 0 0 10.500 4m0 2a4.500 4.500 0 1 1 0 9 4.500 4.500 0 0 1 0-9',
  orders: 'M6 3h12v18l-3-2-3 2-3-2-3 2zm3 5h6v2H9zm0 4h6v2H9z',
  profile: 'M12 4a4 4 0 1 1 0 8 4 4 0 0 1 0-8m-8 16c0-4 4-6 8-6s8 2 8 6z',
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
  const routes = { '': home, search, shop, cart: cartPage, orders, order, profile, help };
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

const priceRange = (s) => (s.min_price === null ? '' : s.min_price === s.max_price ? baht(s.min_price) : `${baht(s.min_price)} – ${baht(s.max_price)}`);
const openBadge = (s) => h('span.badge.' + (s.is_open ? 'open' : 'bad'), {}, s.is_open ? 'เปิด' : 'ปิด');
const shopCard = (s) => h('a.item' + (s.is_open ? '' : '.off'), { href: `#/shop/${s.id}` }, picture(s.image_url, s.name), h('div.grow', {},
  h('div.row.between', {}, h('h3', {}, s.name), openBadge(s)), s.description ? h('div.muted', {}, s.description) : null,
  h('div', {}, priceRange(s)), s.is_open ? h('div.muted', {}, `รอ ${s.waiting} คิว · ${eta(s.eta_minutes)}`) : null,
  s.matched_menus?.length ? h('div.muted', {}, 'พบ: ', s.matched_menus.map((m) => m.name + (m.available ? '' : ' (หมด)')).join(', ')) : null));

async function home(alive) {
  let category = '';
  const list = h('div.list');
  const draw = async () => {
    const shops = await get('/shops' + (category ? `?category=${encodeURIComponent(category)}` : ''));
    list.replaceChildren(...(shops.length ? shops.map(shopCard) : [empty('ยังไม่มีร้านในหมวดนี้')]));
  };
  const categories = await get('/categories');
  await draw();
  if (!alive()) return;
  const chips = h('div.chips', { role: 'group', 'aria-label': 'หมวดหมู่' });
  const drawChips = () => chips.replaceChildren(...['', ...categories].map((c) => h('button.chip', { type: 'button', 'aria-pressed': String(c === category), onclick: (e) => busy(e.currentTarget, async () => { category = c; await draw(); drawChips(); }) }, c || 'ทั้งหมด')));
  drawChips();
  frame('home', [
    h('header.topbar', {}, h('div.brand', {}, 'Q-im'), h('a.btn.ghost', { href: '#/search' }, 'ค้นหาร้านหรือเมนู')),
    h('section.banner', {}, h('strong', {}, 'สั่งก่อน ไม่ต้องยืนรอ'), 'เดินไปรับเมื่ออาหารเสร็จ'),
    chips, list,
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
      const shops = await get(`/shops?q=${encodeURIComponent(text)}${open.checked ? '&open=1' : ''}`);
      if (n === seq) results.replaceChildren(...(shops.length ? shops.map(shopCard) : [empty('ไม่พบร้านหรือเมนูนี้')]));
    } catch (e) {
      if (n === seq) results.replaceChildren(failed(e, run));
    }
    return undefined;
  };
  input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 350); });
  open.addEventListener('change', run);
  if (!alive()) return;
  frame('search', [header('ค้นหา'), input, h('label.check', {}, open, 'เฉพาะร้านที่เปิดอยู่'), results]);
  run();
  input.focus();
}

async function shop(alive, id) {
  const [s, data] = await Promise.all([get(`/shops/${id}`), get(`/shops/${id}/menus`)]);
  if (!alive()) return;
  const status = h('p');
  const drawStatus = (x) => status.replaceChildren(openBadge(x), ' ', x.is_open ? `รอ ${x.waiting} คิว · ${eta(x.eta_minutes)}` : 'ยังไม่รับออเดอร์ตอนนี้');
  drawStatus(s);
  const menuCard = (m) => h('button.item' + (m.available && s.is_open ? '' : '.off'), { type: 'button', disabled: !m.available, onclick: () => foodDetail(s, m) }, picture(m.image_url, m.name), h('div.grow', {}, h('h3', {}, m.name), m.description ? h('div.muted', {}, m.description) : null, h('div.row.between', {}, h('span.price', {}, baht(m.price)), m.available ? null : h('span.badge.bad', {}, 'หมด'))));
  const groups = [...data.categories, { id: null, name: 'อื่น ๆ' }].map((c) => [c, data.menus.filter((m) => m.category_id === c.id)]).filter(([, l]) => l.length);
  const render = () => frame('home', [header(s.name, '#/'), picture(s.image_url, s.name, 'hero'), h('div.stack', { style: 'margin-top:.8rem' }, s.description ? h('p', {}, s.description) : null, h('p.muted', {}, s.food_court, s.location ? ` · ${s.location}` : ''), status,
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
    } }, picture(m.image_url, m.name, 'hero'), h('p', {}, h('span.price', {}, baht(m.price)), ' ', m.description || ''),
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
    h('label.field', {}, h('span', {}, 'หมายเหตุถึงร้าน'), note),
    h('div.row.between', { style: 'margin-bottom:1rem' }, h('span', {}, 'จำนวน'), h('div.stepper', {}, h('button.icon', { type: 'button', 'aria-label': 'ลดจำนวน', onclick: () => step(-1) }, '−'), count, h('button.icon', { type: 'button', 'aria-label': 'เพิ่มจำนวน', onclick: () => step(1) }, '+'))), button);
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
      h('div.list', {}, cart.items.map((i, n) => h('div.item', {}, h('div.grow', {}, h('h3', {}, i.name || 'เมนูที่ไม่มีแล้ว'), i.options.length ? h('div.muted', {}, i.options.map((o) => o.name).join(', ')) : null, i.note ? h('div.muted', {}, 'หมายเหตุ: ', i.note) : null, i.problem ? h('p.notice.bad', {}, i.problem) : null,
        h('div.row.between', {}, h('div.stepper', {}, h('button.icon', { type: 'button', 'aria-label': `ลดจำนวน ${i.name}`, onclick: replaceQty(n, i.quantity - 1) }, '−'), h('b', {}, i.quantity), h('button.icon', { type: 'button', 'aria-label': `เพิ่มจำนวน ${i.name}`, disabled: i.quantity >= 20, onclick: replaceQty(n, i.quantity + 1) }, '+')), h('span.price', {}, baht(i.line_total))),
        h('button.btn.danger.ghost', { type: 'button', style: 'margin-top:.5rem', onclick: replaceQty(n, 0) }, 'ลบ'))))),
      h('section.card', { style: 'margin-top:1rem' }, h('div.row.between', {}, h('span', {}, 'วิธีรับ'), h('b', {}, 'รับเองที่ร้าน')), h('div.row.between', {}, h('span', {}, 'วิธีชำระ'), h('b', {}, 'ชำระที่ร้านตอนรับ')), h('div.total', {}, h('span', {}, 'ยอดรวม'), h('span', {}, baht(cart.total)))),
      h('p', { style: 'margin-top:1rem' }, h('button.btn.block', { type: 'button', disabled: blocked, onclick: (e) => checkout(e.currentTarget, draw) }, blocked ? 'ยังสั่งไม่ได้' : `ยืนยันออเดอร์ ${baht(cart.total)}`)),
      h('a.btn.ghost.block', { href: `#/shop/${cart.shop.id}` }, 'เพิ่มเมนูอื่น')], true);
  };
  draw();
  return undefined;
}

const checkout = (button, redraw) => busy(button, async () => {
  try {
    const order = await post('/orders', { idempotency_key: checkoutKey(cart.quote), quote: cart.quote });
    forgetKey();
    cart = await get('/cart').catch(() => null);
    location.hash = `#/order/${order.id}`;
  } catch (e) {
    if (['PRICE_CHANGED', 'ITEM_UNAVAILABLE', 'SHOP_CLOSED', 'CART_EMPTY'].includes(e.code)) {
      forgetKey();
      cart = await get('/cart');
      redraw();
    }
    throw e;
  }
});

// ---- Orders -----------------------------------------------------------------

function needLogin(tab) {
  frame(tab, [header('เข้าสู่ระบบ'), empty('เข้าสู่ระบบเพื่อใช้งานส่วนนี้', h('button.btn', { type: 'button', onclick: async () => { if (await requireLogin()) route(); } }, config.liff_id ? 'เข้าสู่ระบบด้วย LINE' : 'เข้าสู่ระบบ'))], true);
}

async function orders(alive) {
  if (!me) return needLogin('orders');
  let scope = 'active';
  let page = 1;
  const list = h('div.list');
  const chips = h('div.chips', { role: 'group', 'aria-label': 'ประเภทออเดอร์' });
  const more = h('div.row.end');
  const draw = async () => {
    const { data, meta } = await api('GET', `/orders?scope=${scope}&page=${page}`);
    list.replaceChildren(...(data.length ? data.map((o) => h('a.item', { href: `#/order/${o.id}` }, h('div.grow', {}, h('div.row.between', {}, h('h3', {}, o.shop.name), badge(o.status)), h('div.muted', {}, dateTime(o.created_at), ' · ', o.order_no), h('div', {}, o.items.map((i) => `${i.quantity} × ${i.name}`).join(', ')), h('div.row.between', {}, o.queue ? h('b', {}, queueNo(o.queue.number)) : h('span'), h('span.price', {}, baht(o.total)))))) : [empty('ไม่มีออเดอร์ในส่วนนี้')]));
    const pages = Math.ceil(meta.total / meta.per_page);
    const turnPage = (d) => (e) => busy(e.currentTarget, async () => { page += d; await draw(); });
    more.replaceChildren(...(pages > 1 ? [h('button.btn.ghost', { type: 'button', disabled: page <= 1, onclick: turnPage(-1) }, 'ก่อนหน้า'), `${page} / ${pages}`, h('button.btn.ghost', { type: 'button', disabled: page >= pages, onclick: turnPage(1) }, 'ถัดไป')] : []));
  };
  const drawChips = () => chips.replaceChildren(...[['active', 'กำลังดำเนินการ'], ['completed', 'สำเร็จ'], ['cancelled', 'ยกเลิก/ปฏิเสธ']].map(([k, label]) => h('button.chip', { type: 'button', 'aria-pressed': String(k === scope), onclick: (e) => busy(e.currentTarget, async () => { scope = k; page = 1; drawChips(); await draw(); }) }, label)));
  await draw();
  if (!alive()) return undefined;
  drawChips();
  frame('orders', [header('ออเดอร์ของฉัน'), chips, list, more]);
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
    PREPARING: [null, 'ร้านกำลังทำอาหารของคุณ'],
    READY: [null, 'อาหารพร้อมแล้ว เดินไปรับได้เลย'],
    COMPLETED: [null, 'รับอาหารเรียบร้อย ขอให้อร่อย'],
    REJECTED: ['ร้านปฏิเสธออเดอร์', o.reason || ''],
    CANCELLED: ['ออเดอร์ถูกยกเลิก', o.reason || ''],
  }[o.status];
  const flow = ['PENDING', 'ACCEPTED', 'PREPARING', 'READY', 'COMPLETED'];
  const at = Object.fromEntries(o.timeline.map((t) => [t.status, t.at]));
  const steps = closed ? o.timeline.map((t) => t.status) : flow;
  return [
    h('section.ticket' + (closed ? '.bad' : o.status === 'READY' ? '.ready' : ''), { 'aria-live': 'polite' }, h('div', {}, o.shop.name), h('div.no', headline[0] ? { style: 'font-size:1.8rem' } : {}, headline[0] || (o.queue ? queueNo(o.queue.number) : '')), h('div', {}, headline[1]),
      ['ACCEPTED', 'PREPARING'].includes(o.status) ? h('b', {}, eta(o.eta_minutes)) : null),
    closed ? null : h('section.card', { style: 'text-align:center' }, h('div.muted', {}, 'เลขออเดอร์'), h('div.code', { style: 'font-size:1.25rem;font-weight:700' }, o.order_no), o.status === 'COMPLETED' ? null : [h('div.muted', {}, 'รหัสรับอาหาร แจ้งร้านตอนรับ'), h('div.price', { style: 'font-size:1.9rem;letter-spacing:.15em' }, o.pickup_code)]),
    h('section.card', {}, h('h2', {}, 'สถานะ'), h('ol.steps', {}, steps.map((s) => h('li' + (at[s] ? '.done' : ''), { 'aria-current': s === o.status ? 'step' : null }, STATUS[s][0], at[s] ? h('span.muted', {}, ' ', time(at[s])) : null)))),
    h('section.card', {}, h('h2', {}, 'รายการ'), o.items.map((i) => h('div.row.between', {}, h('span.grow', {}, `${i.quantity} × ${i.name}`, i.options.length ? h('div.muted', {}, i.options.map((x) => x.name).join(', ')) : null, i.note ? h('div.muted', {}, 'หมายเหตุ: ', i.note) : null), baht(i.line_total))), h('div.total', {}, h('span', {}, 'ยอดรวม'), h('span', {}, baht(o.total))), h('p.muted', {}, o.payment_status === 'PAID' ? '✓ ชำระแล้ว' : closed ? '' : 'ชำระที่ร้านตอนรับอาหาร')),
    o.can_cancel ? h('button.btn.danger.ghost.block', { type: 'button', onclick: async (e) => {
      const button = e.currentTarget;
      if (await confirmBox('ยกเลิกออเดอร์?', 'ยกเลิกแล้วต้องสั่งใหม่', 'ยกเลิกออเดอร์', true)) {
        await busy(button, async () => { try { await post(`/orders/${o.id}/cancel`); } finally { await redraw(); } });
      }
    } }, 'ยกเลิกออเดอร์') : ['ACCEPTED', 'PREPARING'].includes(o.status) ? h('p.muted', {}, 'ร้านรับออเดอร์แล้ว หากต้องการยกเลิกกรุณาติดต่อร้าน') : null,
  ].filter(Boolean);
}

// ---- Profile ----------------------------------------------------------------

async function profile() {
  if (!me) return needLogin('profile');
  frame('profile', [header('โปรไฟล์'), h('section.card.stack', {}, h('div.row', {}, picture(me.avatar_url, me.display_name), h('h2', {}, me.display_name)), h('a.btn.ghost.block', { href: '#/help' }, 'วิธีใช้งานและติดต่อ'),
    h('button.btn.ghost.block', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => {
      await post('/auth/logout');
      if (config.liff_id) { const { logout } = await import('./liff.js'); logout(); }
      me = null;
      cart = null;
      location.hash = '#/';
    }) }, 'ออกจากระบบ')),
  h('section.card', { style: 'margin-top:1rem' }, h('h2', {}, 'ความเป็นส่วนตัว'), h('p', {}, 'Q-im เก็บรหัสผู้ใช้ LINE ชื่อ และรูปโปรไฟล์ เพื่อระบุตัวตนและแจ้งสถานะออเดอร์ ร้านเห็นเพียงรายการอาหารและหมายเหตุที่คุณเขียน ประวัติออเดอร์เก็บไว้เพื่อการตรวจสอบและรายงาน'))]);
  return undefined;
}

async function help() {
  frame('profile', [header('วิธีใช้งาน', '#/profile'), h('section.card', {}, h('ol', {}, ['เลือกร้านและเมนู แล้วเพิ่มลงตะกร้า', 'ยืนยันออเดอร์ ยังไม่ต้องจ่ายเงิน', 'ร้านรับออเดอร์ ระบบออกหมายเลขคิวให้อัตโนมัติ', 'เมื่อสถานะเป็นพร้อมรับ เดินไปที่ร้าน', 'แจ้งรหัสรับอาหาร ชำระเงิน แล้วรับอาหาร'].map((t) => h('li', {}, t))), h('p.muted', {}, 'เวลารอเป็นค่าประมาณ ต้องการแก้ไขหรือยกเลิกหลังร้านรับออเดอร์ กรุณาติดต่อร้านโดยตรง'))]);
}

boot();
