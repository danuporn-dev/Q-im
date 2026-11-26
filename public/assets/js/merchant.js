import { $, api, askReason, badge, baht, busy, changes, dateTime, dialog, empty, failed, field, get, h, loading, loginForm, patch, picture, poll, post, queueNo, setCsrf, setPoll, time, toast, today, vendor } from './core.js';

const app = $('#app');
let me = null;
let shopId = null;
let stop = () => {};

const TABS = [['orders', 'ออเดอร์'], ['history', 'ประวัติ'], ['menus', 'เมนู'], ['settings', 'ตั้งค่าร้าน'], ['reports', 'รายงาน']];
const current = () => TABS.find(([k]) => location.hash === '#/' + k)?.[0] || 'orders';

async function start() {
  app.replaceChildren(loading());
  try {
    me = await get('/me?realm=merchant');
    setPoll((await get('/config')).poll_seconds);
  } catch (e) {
    if (e.status !== 401) return app.replaceChildren(failed(e, start));
    return app.replaceChildren(loginForm('merchant', 'เข้าสู่ระบบร้านค้า', start));
  }
  setCsrf(me.csrf);
  if (!me.shops.length) return app.replaceChildren(empty('บัญชีนี้ยังไม่มีร้าน กรุณาติดต่อผู้ดูแล', logoutButton()));
  if (!me.shops.some((s) => s.id === shopId)) shopId = me.shops[0].id;
  render();
}

const logoutButton = () => h('button.btn.ghost', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => { stop(); await post('/auth/logout?realm=merchant'); location.reload(); }) }, 'ออกจากระบบ');

function render() {
  stop();
  stop = () => {};
  const tab = current();
  const main = h('main.main', {}, loading());
  const picker = me.shops.length > 1
    ? h('select', { 'aria-label': 'เลือกร้าน', onchange: (e) => { shopId = Number(e.target.value); render(); } }, me.shops.map((s) => h('option', { value: s.id, selected: s.id === shopId }, s.name)))
    : h('strong', {}, me.shops[0].name);
  app.replaceChildren(h('div.desk', {}, h('nav.side', { 'aria-label': 'เมนูหลัก' }, h('div.brand', {}, 'Q-im'), picker,
    TABS.map(([k, label]) => h('a', { href: '#/' + k, 'aria-current': k === tab ? 'page' : null }, label)), h('div.bottom', {}, logoutButton())), main));
  const page = { orders, history, menus, settings, reports }[tab];
  const load = async () => {
    try {
      await page(main);
    } catch (e) {
      if (e.status === 401) return start();
      main.replaceChildren(failed(e, load));
    }
    return undefined;
  };
  load();
}

// ---- Orders -----------------------------------------------------------------

let known = null;
async function orders(main) {
  const status = h('div.row');
  const board = h('div.board');
  const changed = changes();
  const draw = async () => {
    const [list, shop] = await Promise.all([get(`/merchant/shops/${shopId}/orders?scope=active`), get(`/merchant/shops/${shopId}/settings`)]);
    // Drawing unchanged cards again could swallow a click; the clock part keeps waiting times current.
    if (!changed([list, shop, Math.floor(Date.now() / 15000)])) return;
    const pending = list.filter((o) => o.status === 'PENDING');
    if (known && pending.some((o) => !known.has(o.id))) { toast('มีออเดอร์ใหม่'); beep(); }
    known = new Set(list.map((o) => o.id));
    document.title = pending.length ? `(${pending.length}) Q-im ร้านค้า` : 'Q-im ร้านค้า';
    status.replaceChildren(
      h('span.badge.' + (shop.is_open ? 'open' : 'bad'), {}, shop.is_open ? 'เปิดรับออเดอร์' : shop.accepting_orders ? 'นอกเวลาเปิด' : 'หยุดรับออเดอร์'),
      h('button.btn' + (shop.accepting_orders ? '.ghost' : ''), { type: 'button', onclick: (e) => busy(e.currentTarget, async () => { await patch(`/merchant/shops/${shopId}/settings`, { accepting_orders: !shop.accepting_orders }); await draw(); }) }, shop.accepting_orders ? 'หยุดรับชั่วคราว' : 'เปิดรับออเดอร์'),
    );
    // Three taps (D31): รับออเดอร์ puts the order straight into กำลังทำ; ACCEPTED only remains on older orders.
    board.replaceChildren(...[[['PENDING'], 'ใหม่'], [['ACCEPTED', 'PREPARING'], 'กำลังทำ'], [['READY'], 'พร้อมรับ']].map(([states, label]) => {
      const items = list.filter((o) => states.includes(o.status));
      return h('section.col', { 'aria-label': label }, h('h2', {}, label, h('span', {}, items.length)), items.length ? h('div.list', {}, items.map((o) => card(o, draw, shop.ready_alert_minutes))) : h('p.muted', {}, 'ไม่มีออเดอร์'));
    }));
  };
  await draw();
  main.replaceChildren(h('div.row.between', {}, h('h1', {}, 'ออเดอร์'), status), board);
  stop = poll(draw, 6000);
}

function beep() {
  try {
    const ctx = new AudioContext();
    const osc = ctx.createOscillator();
    osc.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.25);
  } catch (e) { /* sound is optional */ }
}

const lines = (o) => h('ul', {}, o.items.map((i) => h('li', {}, `${i.quantity} × ${i.name}`, i.options.length ? h('div.muted', {}, i.options.map((x) => x.name).join(', ')) : null, i.note ? h('div', {}, 'หมายเหตุ: ', i.note) : null)));

function card(o, refresh, readyAlert) {
  const act = (action, body) => async (e) => {
    const button = e?.currentTarget;
    await busy(button, async () => {
      try {
        await post(`/merchant/orders/${o.id}/${action}`, body);
      } finally {
        await refresh();
      }
    });
  };
  const button = (label, onclick, cls = '') => h('button.btn' + cls, { type: 'button', onclick }, label);
  const reasoned = (action, title, yes, presets) => async (e) => {
    const target = e.currentTarget;
    const reason = await askReason(title, 'เหตุผล (ลูกค้าจะเห็น)', yes, presets);
    if (reason) await act(action, { reason })({ currentTarget: target });
  };
  const waited = Math.floor((Date.now() - new Date(o.created_at)) / 60000);
  const cooking = () => [button('อาหารพร้อม', act('ready')), button('ยกเลิก', reasoned('cancel', 'ยกเลิกออเดอร์ที่กำลังทำ', 'ยกเลิกออเดอร์', ['วัตถุดิบหมด', 'อุปกรณ์ขัดข้อง', 'ลูกค้าขอยกเลิก']), '.danger.ghost')];
  const buttons = {
    PENDING: () => [button('รับออเดอร์', act('accept')), button('ปฏิเสธ', reasoned('reject', 'ปฏิเสธออเดอร์', 'ปฏิเสธ', ['วัตถุดิบหมด', 'ร้านกำลังจะปิด', 'ออเดอร์เต็ม']), '.danger.ghost')],
    ACCEPTED: cooking,
    PREPARING: cooking,
    READY: () => [button('ส่งมอบ', (e) => pickup(o, refresh, e.currentTarget)),
      o.can_no_show ? button('ลูกค้าไม่มารับ', reasoned('cancel', 'ยกเลิกเพราะลูกค้าไม่มารับ', 'ยกเลิกออเดอร์', ['ลูกค้าไม่มารับภายในเวลา', 'ติดต่อลูกค้าไม่ได้']), '.danger.ghost') : null],
  }[o.status]();
  const readyFor = o.ready_at ? Math.floor((Date.now() - new Date(o.ready_at)) / 60000) : 0;
  const late = (o.status === 'PENDING' && waited >= 5) || (o.status === 'READY' && readyFor >= readyAlert);
  return h('article.ocard' + (late ? '.late' : ''), {},
    h('div.row.between', {}, h('span.q', {}, o.queue ? queueNo(o.queue.number) : 'ใหม่'), h('span.muted', {}, `สั่ง ${time(o.created_at)}`)),
    o.queue && o.queue.business_date !== today() ? h('div.muted', {}, 'คิววันที่ ', o.queue.business_date) : null,
    late ? h('p.notice', {}, o.status === 'PENDING' ? `รอยืนยันมา ${waited} นาที` : `พร้อมรับมา ${readyFor} นาที กรุณาตรวจสอบ`) : null,
    lines(o),
    h('div.row.between', {}, h('span.muted', {}, o.order_no), h('b.price', {}, baht(o.total))),
    o.status === 'READY' ? h('p', {}, o.payment_status === 'PAID' ? '✓ ชำระแล้ว' : 'ยังไม่ชำระ') : null,
    h('div.row', {}, buttons));
}

/**
 * Hand-over: scan the customer's QR (or type the full order number), then, if the order is not paid yet,
 * the shop confirms the money with its own tap. Nothing marks an order paid without that tap (D32).
 * Camera first; a photo, a USB scanner or typing when there is no camera.
 */
async function pickup(o, refresh, button) {
  let stopCamera = () => {};
  let sending = false;
  let verified = null; // a body that passed the QR or reason check and now waits for the money
  let wrong = '';
  const paid = o.payment_status === 'PAID';
  const done = await dialog(`ส่งมอบ ${o.queue ? queueNo(o.queue.number) : ''}`, (close) => {
    const scanArea = h('div');
    const payArea = h('div', { hidden: true });
    const reasonBox = h('details', {});
    const send = async (body, control) => {
      if (sending) return;
      sending = true;
      try {
        await busy(control, async () => {
          try {
            await post(`/merchant/orders/${o.id}/complete`, body);
            close(true);
          } catch (e) {
            if (e.code === 'PAYMENT_REQUIRED') return askMoney(body);
            if (e.code === 'PICKUP_QR_LOCKED') lockScan(e.message);
            throw e;
          }
          return undefined;
        });
      } finally {
        sending = false;
      }
    };
    const askMoney = (body) => {
      verified = body;
      stopCamera();
      scanArea.hidden = true;
      const confirm = h('button.btn.block', { type: 'button', onclick: (e) => send({ ...verified, payment_received: true }, e.currentTarget) }, `ได้รับเงินแล้ว ${baht(o.total)}`);
      payArea.replaceChildren(
        h('p.notice', {}, body.pickup_qr ? '✓ QR ถูกต้อง' : 'ส่งมอบโดยไม่สแกน QR (บันทึกเหตุผลแล้วเมื่อยืนยัน)'),
        h('p', {}, 'เก็บเงินจากลูกค้า ', h('b.price', {}, baht(o.total)), ' แล้วกดยืนยัน'),
        confirm,
        h('p.muted', {}, 'ยังไม่ได้รับเงิน: ปิดหน้าต่างนี้ ออเดอร์ยังพร้อมรับเหมือนเดิม'),
      );
      payArea.hidden = false;
      confirm.focus();
    };
    const lockScan = (message) => {
      stopCamera();
      scanArea.replaceChildren(h('p.notice.bad', { role: 'alert' }, message));
      reasonBox.open = true;
    };
    const scanned = async (text) => {
      if (verified || text === wrong) return; // the same wrong code stays in front of the camera
      await send({ pickup_qr: text.trim() }, null);
      if (!verified) wrong = text;
    };
    const note = h('p.muted', { role: 'status' }, 'กำลังเปิดกล้อง…');
    const video = h('video.scan', { playsinline: true, muted: true, 'aria-label': 'ภาพจากกล้อง' });
    video.muted = true;
    const photo = h('input', { type: 'file', accept: 'image/*', capture: 'environment', onchange: () => busy(photo, async () => {
      const text = photo.files[0] ? await readPhoto(photo.files[0]) : null;
      photo.value = '';
      if (!text) throw new Error('ไม่พบ QR ในรูป ลองถ่ายใหม่ให้ชัดและเต็มกรอบ');
      wrong = '';
      await scanned(text);
    }) });
    const typed = h('input', { name: 'pickup_qr', maxlength: 32, autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false' });
    const typedButton = h('button.btn.ghost', { type: 'submit' }, 'ตรวจ');
    const reason = h('input', { maxlength: 300 });
    const reasonButton = h('button.btn.ghost', { type: 'submit' }, 'ส่งมอบโดยไม่สแกน');
    camera(video, scanned).then((stop) => {
      stopCamera = stop;
      if (verified || scanArea.hidden) stop();
      else note.textContent = 'หันกล้องไปที่ QR บนมือถือลูกค้า';
    }).catch((e) => { video.hidden = true; note.textContent = e.message; typed.focus(); });
    scanArea.append(
      video, note,
      field('ไม่มีกล้อง: ถ่ายรูป QR', photo),
      h('form', { onsubmit: (e) => { e.preventDefault(); if (typed.value.trim()) send({ pickup_qr: typed.value.trim() }, typedButton); } },
        field('เครื่องสแกน หรือพิมพ์เลขออเดอร์เต็มจากหน้าจอลูกค้า', h('div.row', {}, h('div.grow', {}, typed), typedButton))),
    );
    reasonBox.append(h('summary', {}, 'ลูกค้าแสดง QR ไม่ได้'), h('form', { onsubmit: (e) => { e.preventDefault(); send({ override_reason: reason.value.trim() }, reasonButton); } },
      field('เหตุผลและวิธีที่ตรวจสอบ', reason, 'ระบบบันทึกไว้ให้ผู้ดูแลตรวจสอบ'), h('div.row.end', {}, reasonButton)));
    return [
      h('p.notice', {}, paid ? '✓ ชำระแล้ว สแกน QR เพื่อส่งมอบ' : h('span', {}, 'ยอด ', h('b', {}, baht(o.total)), ' สแกน QR ก่อน แล้วยืนยันรับเงิน')),
      scanArea, payArea, h('div', {}, reasonBox),
    ];
  });
  stopCamera();
  if (done) toast('ส่งมอบเรียบร้อย');
  await busy(button, refresh);
}

/** One reader for camera frames and photos: BarcodeDetector where the browser has it, jsQR otherwise. */
let reader = null;
function qrReader() {
  reader ??= (async () => {
    if ('BarcodeDetector' in window && (await BarcodeDetector.getSupportedFormats()).includes('qr_code')) {
      const detector = new BarcodeDetector({ formats: ['qr_code'] });
      return async (source) => (await detector.detect(source))[0]?.rawValue || null;
    }
    const jsQR = await vendor('jsQR.min.js', 'jsQR');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    return async (source) => {
      const w = source.videoWidth || source.width;
      const ht = source.videoHeight || source.height;
      if (!w || !ht) return null;
      const scale = Math.min(1, 800 / Math.max(w, ht));
      canvas.width = Math.round(w * scale);
      canvas.height = Math.round(ht * scale);
      ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
      const { data, width, height } = ctx.getImageData(0, 0, canvas.width, canvas.height);
      return jsQR(data, width, height)?.data || null;
    };
  })().catch((e) => { reader = null; throw e; });
  return reader;
}

async function readPhoto(file) {
  const read = await qrReader();
  const bitmap = await createImageBitmap(file);
  try { return await read(bitmap); } finally { bitmap.close(); }
}

/** Starts the back camera and calls found(text) for each QR seen; resolves with stop(). */
async function camera(video, found) {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('เบราว์เซอร์นี้เปิดกล้องไม่ได้ (กล้องต้องใช้ HTTPS) ใช้รูปถ่ายหรือพิมพ์เลขออเดอร์แทน');
  const read = await qrReader();
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
  } catch (e) {
    throw new Error('เปิดกล้องไม่ได้ ตรวจสิทธิ์กล้อง หรือใช้รูปถ่ายหรือพิมพ์เลขออเดอร์แทน');
  }
  let stopped = false;
  const stop = () => { stopped = true; stream.getTracks().forEach((t) => t.stop()); };
  video.srcObject = stream;
  await video.play().catch(() => {});
  const tick = async () => {
    if (!video.isConnected) stop(); // the dialog closed, perhaps while the camera was starting
    if (stopped) return;
    try {
      const text = video.readyState >= 2 ? await read(video) : null;
      if (text && !stopped) await found(text);
    } catch (e) { /* a frame that cannot be read is skipped */ }
    if (!stopped) setTimeout(tick, 250);
  };
  tick();
  return stop;
}

async function history(main, page = 1) {
  const { data, meta } = await api('GET', `/merchant/shops/${shopId}/orders?scope=closed&page=${page}`);
  const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
  main.replaceChildren(h('h1', {}, 'ประวัติออเดอร์'), data.length ? h('div.tablewrap', {}, h('table', {},
    h('thead', {}, h('tr', {}, ['เวลา', 'คิว', 'ออเดอร์', 'รายการ', 'สถานะ', 'ยอด'].map((t, i) => h('th' + (i === 5 ? '.num' : ''), {}, t)))),
    h('tbody', {}, data.map((o) => h('tr', {}, h('td', {}, dateTime(o.created_at)), h('td', {}, o.queue ? queueNo(o.queue.number) : '–'), h('td', {}, o.order_no), h('td', {}, lines(o)), h('td', {}, badge(o.status), o.reason ? h('div.muted', {}, o.reason) : null), h('td.num', {}, baht(o.total))))))) : empty('ยังไม่มีประวัติ'),
  h('div.row.end', {}, h('button.btn.ghost', { type: 'button', disabled: page <= 1, onclick: () => history(main, page - 1) }, 'ก่อนหน้า'), `หน้า ${page} / ${pages}`, h('button.btn.ghost', { type: 'button', disabled: page >= pages, onclick: () => history(main, page + 1) }, 'ถัดไป')));
}

// ---- Menus ------------------------------------------------------------------

async function menus(main) {
  const data = await get(`/merchant/shops/${shopId}/menus`);
  const reload = () => menus(main);
  const section = (category) => {
    const list = data.menus.filter((m) => m.category_id === (category?.id ?? null));
    if (!category && !list.length) return null;
    return h('section.stack', {}, h('div.row', {}, h('h2', {}, category ? category.name : 'ไม่มีหมวดหมู่', category && !category.active ? ' (ซ่อน)' : ''), category ? h('button.btn.ghost', { type: 'button', onclick: () => editCategory(category, reload) }, 'แก้หมวด') : null),
      h('div.grid', {}, list.map((m) => h('div.item' + (m.active && m.available ? '' : '.off'), {}, picture(m.image_url, m.name), h('div.grow', {}, h('h3', {}, m.name), h('span.price', {}, baht(m.price)), h('div.muted', {}, !m.active ? 'เลิกขาย' : m.available ? 'พร้อมขาย' : 'หมด'),
        h('div.row', {}, m.active ? h('button.btn.ghost', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => { await patch(`/merchant/menus/${m.id}`, { available: !m.available }); await reload(); }) }, m.available ? 'แจ้งหมด' : 'มีแล้ว') : null, h('button.btn.ghost', { type: 'button', onclick: () => editMenu(m, data.categories, reload) }, 'แก้ไข')))))));
  };
  main.replaceChildren(h('div.row.between', {}, h('h1', {}, 'เมนู'), h('div.row', {}, h('button.btn.ghost', { type: 'button', onclick: () => editCategory(null, reload) }, 'เพิ่มหมวด'), h('button.btn', { type: 'button', onclick: () => editMenu(null, data.categories, reload) }, 'เพิ่มเมนู'))),
    h('div.stack', {}, data.categories.map(section), section(null)), data.menus.length ? '' : empty('ยังไม่มีเมนู'));
}

async function editCategory(category, reload) {
  const saved = await dialog(category ? 'แก้หมวดหมู่' : 'เพิ่มหมวดหมู่', (close) => {
    const name = h('input', { required: true, maxlength: 100, value: category?.name || '' });
    const order = h('input', { type: 'number', min: 0, max: 9999, value: category?.sort_order ?? 0 });
    const active = h('input', { type: 'checkbox', checked: category ? Boolean(category.active) : true });
    const button = h('button.btn', { type: 'submit' }, 'บันทึก');
    return h('form', { onsubmit: (e) => { e.preventDefault(); busy(button, async () => {
      const body = { name: name.value, sort_order: Number(order.value), active: active.checked };
      await (category ? patch(`/merchant/shops/${shopId}/categories/${category.id}`, body) : post(`/merchant/shops/${shopId}/categories`, body));
      close(true);
    }); } }, field('ชื่อ', name), field('ลำดับ', order), h('label.check', {}, active, 'แสดงหมวดนี้'), h('div.row.end', {}, button));
  });
  if (saved) await reload();
}

async function upload(file) {
  const form = new FormData();
  form.append('image', file);
  return (await post(`/merchant/shops/${shopId}/images`, form)).image_url;
}

function imageField(initial, name) {
  let url = initial;
  const preview = h('div', {}, picture(url, name));
  const input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', onchange: () => busy(input, async () => { if (input.files[0]) { url = await upload(input.files[0]); preview.replaceChildren(picture(url, name)); } }) });
  return { el: h('div.field', {}, preview, field('รูปภาพ', input, 'JPEG, PNG หรือ WebP ไม่เกิน 5 MB')), get: () => url };
}

async function editMenu(menu, categories, reload) {
  const saved = await dialog(menu ? 'แก้ไขเมนู' : 'เพิ่มเมนู', (close) => {
    const name = h('input', { required: true, maxlength: 150, value: menu?.name || '' });
    const price = h('input', { required: true, inputmode: 'decimal', value: menu?.price || '' });
    const prep = h('input', { type: 'number', min: 1, max: 240, value: menu?.prep_minutes ?? '' });
    const category = h('select', {}, h('option', { value: '' }, 'ไม่มีหมวดหมู่'), categories.map((c) => h('option', { value: c.id, selected: c.id === menu?.category_id }, c.name)));
    const description = h('textarea', { rows: 2, maxlength: 2000, value: menu?.description || '' });
    const active = h('input', { type: 'checkbox', checked: menu ? menu.active : true });
    const image = imageField(menu?.image_url ?? null, menu?.name || 'เมนู');
    const groupBox = h('div');
    const groups = (menu?.option_groups || []).filter((g) => g.active).map((g) => ({ ...g, options: g.options.filter((o) => o.active).map((o) => ({ ...o })) }));
    const drawGroups = () => groupBox.replaceChildren(...groups.map((g, gi) => h('fieldset', {}, h('legend', {}, 'กลุ่มตัวเลือก'),
      field('ชื่อกลุ่ม', h('input', { value: g.name, maxlength: 100, required: true, oninput: (e) => { g.name = e.target.value; } })),
      h('div.row', {}, h('div.grow', {}, field('เลือกอย่างน้อย', h('input', { type: 'number', min: 0, max: 30, value: g.min_select, oninput: (e) => { g.min_select = Number(e.target.value); } }))), h('div.grow', {}, field('สูงสุด', h('input', { type: 'number', min: 1, max: 30, value: g.max_select, oninput: (e) => { g.max_select = Number(e.target.value); } })))),
      g.options.map((o, oi) => h('div.row', {}, h('input.grow', { 'aria-label': 'ชื่อตัวเลือก', value: o.name, maxlength: 100, required: true, oninput: (e) => { o.name = e.target.value; } }), h('input', { 'aria-label': 'ราคาเพิ่ม', style: 'width:90px', inputmode: 'decimal', value: o.price_delta, oninput: (e) => { o.price_delta = e.target.value; } }), h('button.icon', { type: 'button', 'aria-label': 'ลบตัวเลือก', onclick: () => { g.options.splice(oi, 1); drawGroups(); } }, '✕'))),
      h('div.row', {}, h('button.btn.ghost', { type: 'button', onclick: () => { g.options.push({ name: '', price_delta: '0' }); drawGroups(); } }, 'เพิ่มตัวเลือก'), h('button.btn.danger.ghost', { type: 'button', onclick: () => { groups.splice(gi, 1); drawGroups(); } }, 'ลบกลุ่ม')))));
    drawGroups();
    const button = h('button.btn', { type: 'submit' }, 'บันทึก');
    return h('form', { onsubmit: (e) => { e.preventDefault(); busy(button, async () => {
      const body = { name: name.value, price: price.value, prep_minutes: prep.value === '' ? null : Number(prep.value), category_id: category.value === '' ? null : Number(category.value), description: description.value, image_url: image.get(), active: active.checked,
        option_groups: groups.map((g) => ({ id: g.id, name: g.name, min_select: g.min_select, max_select: g.max_select, options: g.options.map((o) => ({ id: o.id, name: o.name, price_delta: String(o.price_delta) })) })) };
      await (menu ? patch(`/merchant/menus/${menu.id}`, body) : post(`/merchant/shops/${shopId}/menus`, body));
      close(true);
    }); } }, field('ชื่อเมนู', name), h('div.row', {}, h('div.grow', {}, field('ราคา (บาท)', price)), h('div.grow', {}, field('เวลาทำ (นาที)', prep))), field('หมวดหมู่', category), field('รายละเอียด', description), image.el, groupBox,
    h('button.btn.ghost', { type: 'button', onclick: () => { groups.push({ name: '', min_select: 0, max_select: 1, options: [{ name: '', price_delta: '0' }] }); drawGroups(); } }, 'เพิ่มกลุ่มตัวเลือก'),
    h('label.check', {}, active, 'ขายเมนูนี้'), h('div.row.end', {}, button));
  });
  if (saved) { toast('บันทึกแล้ว'); await reload(); }
}

// ---- Settings ---------------------------------------------------------------

const DAYS = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'];
async function settings(main) {
  const s = await get(`/merchant/shops/${shopId}/settings`);
  const name = h('input', { required: true, maxlength: 150, value: s.name });
  const description = h('textarea', { rows: 2, maxlength: 2000, value: s.description || '' });
  const accepting = h('input', { type: 'checkbox', checked: s.accepting_orders });
  const number = (value, max) => h('input', { type: 'number', min: 1, max, value: value ?? '' });
  const capacity = number(s.capacity, 100);
  const avg = number(s.avg_prep_minutes, 240);
  const max = number(s.max_active_orders, 1000);
  const image = imageField(s.image_url, s.name);
  const hours = s.hours.map((x) => ({ ...x }));
  const box = h('div');
  const drawHours = () => box.replaceChildren(...hours.map((x, i) => h('div.row', { style: 'margin-bottom:.5rem' },
    h('select', { 'aria-label': 'วัน', style: 'width:130px', onchange: (e) => { x.day_of_week = Number(e.target.value); } }, DAYS.map((d, n) => h('option', { value: n + 1, selected: n + 1 === x.day_of_week }, d))),
    h('input', { type: 'time', 'aria-label': 'เปิด', style: 'width:130px', value: x.open_time, required: true, oninput: (e) => { x.open_time = e.target.value; } }), 'ถึง',
    h('input', { type: 'time', 'aria-label': 'ปิด', style: 'width:130px', value: x.close_time, required: true, oninput: (e) => { x.close_time = e.target.value; } }),
    h('button.icon', { type: 'button', 'aria-label': 'ลบช่วงเวลา', onclick: () => { hours.splice(i, 1); drawHours(); } }, '✕'))), hours.length ? '' : h('p.muted', {}, 'ไม่กำหนดเวลา = รับได้ตลอดเมื่อเปิดรับออเดอร์'));
  drawHours();
  const button = h('button.btn', { type: 'submit' }, 'บันทึก');
  const num = (el) => (el.value === '' ? null : Number(el.value));
  main.replaceChildren(h('h1', {}, 'ตั้งค่าร้าน'), h('form.card', { style: 'max-width:680px', onsubmit: (e) => { e.preventDefault(); busy(button, async () => {
    await patch(`/merchant/shops/${shopId}/settings`, { name: name.value, description: description.value, image_url: image.get(), accepting_orders: accepting.checked, capacity: num(capacity), avg_prep_minutes: num(avg), max_active_orders: num(max), hours: hours.map(({ day_of_week, open_time, close_time }) => ({ day_of_week, open_time, close_time })) });
    toast('บันทึกแล้ว');
  }); } }, h('label.check', {}, accepting, 'เปิดรับออเดอร์'), field('ชื่อร้าน', name), field('รายละเอียด', description), image.el,
  field('ทำพร้อมกันได้ (ออเดอร์)', capacity, 'ใช้คำนวณเวลารอ'), field('เวลาทำเฉลี่ย (นาที)', avg), field('ออเดอร์ค้างสูงสุด', max, 'เกินจำนวนนี้ระบบไม่รับออเดอร์ใหม่ เว้นว่าง = ไม่จำกัด'),
  h('h2', {}, 'เวลาเปิดปิด'), box, h('button.btn.ghost', { type: 'button', onclick: () => { hours.push({ day_of_week: 1, open_time: '08:00', close_time: '17:00' }); drawHours(); } }, 'เพิ่มช่วงเวลา'), h('div.row.end', {}, button)));
}

// ---- Reports ----------------------------------------------------------------

async function reports(main, from = today(), to = today()) {
  const r = await get(`/merchant/shops/${shopId}/reports?from=${from}&to=${to}`);
  const a = h('input', { type: 'date', value: from, max: today() });
  const b = h('input', { type: 'date', value: to, max: today() });
  const stat = (label, value) => h('div.card.stat', {}, h('span.muted', {}, label), h('b', {}, value));
  main.replaceChildren(h('h1', {}, 'รายงานร้าน'), h('form.filters', { onsubmit: (e) => { e.preventDefault(); busy(null, () => reports(main, a.value, b.value)); } }, field('ตั้งแต่', a), field('ถึง', b), h('button.btn', { type: 'submit' }, 'ดูรายงาน')),
    h('div.stats', {}, stat('ยอดขายที่ชำระแล้ว', baht(r.sales)), stat('สำเร็จ', r.completed), stat('ปฏิเสธ', r.rejected), stat('ยกเลิก', r.cancelled), stat('เวลาทำเฉลี่ย', r.avg_prep_minutes === null ? '–' : `${r.avg_prep_minutes} นาที`)),
    h('h2', {}, 'เมนูขายดี'), r.top_menus.length ? h('div.tablewrap', {}, h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'เมนู'), h('th.num', {}, 'จำนวน'))), h('tbody', {}, r.top_menus.map((m) => h('tr', {}, h('td', {}, m.name), h('td.num', {}, m.quantity)))))) : empty('ไม่มียอดขายในช่วงนี้'));
}

addEventListener('hashchange', () => { if (me) render(); });
start();
