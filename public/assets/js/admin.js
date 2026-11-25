import { $, STATUS, api, badge, baht, busy, confirmBox, dateTime, dialog, empty, failed, field, get, h, loading, loginForm, patch, post, queueNo, setCsrf, toast, today } from './core.js';

const app = $('#app');
let me = null;
const TABS = [['overview', 'ภาพรวม'], ['orders', 'ออเดอร์'], ['shops', 'ร้าน'], ['courts', 'โรงอาหาร'], ['users', 'ผู้ใช้และสิทธิ์'], ['reports', 'รายงาน'], ['audit', 'บันทึกการใช้งาน'], ['settings', 'ระบบ']];

async function start() {
  app.replaceChildren(loading());
  try {
    me = await get('/me?realm=admin');
  } catch (e) {
    return app.replaceChildren(e.status === 401 ? loginForm('admin', 'เข้าสู่ระบบผู้ดูแล', start) : failed(e, start));
  }
  setCsrf(me.csrf);
  return render();
}

function render() {
  const tab = TABS.find(([k]) => location.hash === '#/' + k)?.[0] || 'overview';
  const main = h('main.main', {}, loading());
  app.replaceChildren(h('div.desk', {}, h('nav.side', { 'aria-label': 'เมนูหลัก' }, h('div.brand', {}, 'Q-im'), TABS.map(([k, label]) => h('a', { href: '#/' + k, 'aria-current': k === tab ? 'page' : null }, label)),
    h('div.bottom', {}, h('button.btn.ghost', { type: 'button', onclick: (e) => busy(e.currentTarget, async () => { await post('/auth/logout?realm=admin'); location.reload(); }) }, 'ออกจากระบบ'))), main));
  const load = async () => {
    try {
      await PAGES[tab](main);
    } catch (e) {
      if (e.status === 401) return start();
      main.replaceChildren(failed(e, load));
    }
    return undefined;
  };
  load();
}

const table = (heads, rows) => h('div.tablewrap', {}, h('table', {}, h('thead', {}, h('tr', {}, heads.map((t) => h('th' + (t.startsWith('#') ? '.num' : ''), {}, t.replace('#', ''))))), h('tbody', {}, rows)));
const ghost = (label, onclick, cls = '') => h('button.btn.ghost' + cls, { type: 'button', onclick }, label);
const onOff = (active, yes = 'ใช้งาน', no = 'ปิด') => h('span.badge.' + (active ? 'open' : 'bad'), {}, active ? yes : no);

/** Search + filters + pagination around one list endpoint. */
async function listing(main, title, path, controls, draw, actions = []) {
  let page = 1;
  const box = h('div');
  const search = h('input', { type: 'search', 'aria-label': 'ค้นหา', placeholder: 'ค้นหา', maxlength: 100 });
  const reload = async () => {
    const query = new URLSearchParams({ page, q: search.value.trim(), ...Object.fromEntries(controls.map((c) => [c.name, c.value])) });
    const { data, meta } = await api('GET', `${path}?${query}`);
    const pages = Math.max(1, Math.ceil(meta.total / meta.per_page));
    const turn = (d) => (e) => busy(e.currentTarget, async () => { page += d; await reload(); });
    box.replaceChildren(data.length ? draw(data, reload) : empty('ไม่พบข้อมูล'), h('div.row.end', {}, `ทั้งหมด ${meta.total} รายการ`, h('button.btn.ghost', { type: 'button', disabled: page <= 1, onclick: turn(-1) }, 'ก่อนหน้า'), `${page} / ${pages}`, h('button.btn.ghost', { type: 'button', disabled: page >= pages, onclick: turn(1) }, 'ถัดไป')));
  };
  await reload();
  main.replaceChildren(h('div.row.between', {}, h('h1', {}, title), h('div.row', {}, actions.map((a) => a(reload)))),
    h('form.filters', { onsubmit: (e) => { e.preventDefault(); page = 1; busy(null, reload); } }, search, controls, h('button.btn', { type: 'submit' }, 'ค้นหา')), box);
}

/** Dialog form; save() returns when the server accepted the change. */
const form = (title, fields, save, label = 'บันทึก') => dialog(title, (close) => {
  const button = h('button.btn', { type: 'submit' }, label);
  return h('form', { onsubmit: (e) => { e.preventDefault(); busy(button, async () => { await save(); close(true); toast('บันทึกแล้ว'); }); } }, fields, h('div.row.end', {}, button));
});

async function overview(main) {
  const o = await get('/admin/overview');
  const stat = (label, value) => h('div.card.stat', {}, h('span.muted', {}, label), h('b', {}, value));
  main.replaceChildren(h('h1', {}, 'ภาพรวมวันนี้'), o.ready_too_long ? h('p.notice', {}, `มี ${o.ready_too_long} ออเดอร์พร้อมรับเกิน ${o.ready_alert_minutes} นาที`) : '',
    h('div.stats', {}, stat('ยอดขายที่ชำระแล้ว', baht(o.today.sales)), stat('ออเดอร์ใหม่', o.today.placed), stat('สำเร็จ', o.today.completed), stat('ปฏิเสธ', o.today.rejected), stat('ยกเลิก', o.today.cancelled), stat('ร้านเปิดรับ', `${o.shops_open} / ${o.shops_active}`), stat('ลูกค้า', o.customers)),
    h('h2', {}, 'ออเดอร์ที่ยังเปิดอยู่'), h('div.stats', {}, ['PENDING', 'ACCEPTED', 'PREPARING', 'READY'].map((s) => stat(STATUS[s][0], o.active_orders[s] || 0))));
}

const orders = (main) => {
  const status = h('select', { name: 'status', 'aria-label': 'สถานะ' }, h('option', { value: '' }, 'ทุกสถานะ'), Object.keys(STATUS).map((s) => h('option', { value: s }, STATUS[s][0])));
  return listing(main, 'ออเดอร์', '/admin/orders', [status], (data) => table(['เวลา', 'ออเดอร์', 'ร้าน', 'ลูกค้า', 'คิว', 'สถานะ', '#ยอด', ''], data.map((o) => h('tr', {}, h('td', {}, dateTime(o.created_at)), h('td', {}, o.order_no), h('td', {}, o.shop.name), h('td', {}, o.customer_name), h('td', {}, o.queue ? queueNo(o.queue.number) : '–'), h('td', {}, badge(o.status)), h('td.num', {}, baht(o.total)), h('td', {}, ghost('ดู', (e) => busy(e.currentTarget, () => orderDetail(o.id))))))));
};

async function orderDetail(id) {
  const o = await get(`/admin/orders/${id}`);
  await dialog(o.order_no, () => [
    h('p', {}, badge(o.status), ' ', o.payment_status === 'PAID' ? 'ชำระแล้ว' : 'ยังไม่ชำระ', ' · ', o.shop.name),
    h('ul', {}, o.items.map((i) => h('li', {}, `${i.quantity} × ${i.name} ${i.options.map((x) => x.name).join(', ')}`, i.note ? ` (${i.note})` : '', ' ', baht(i.line_total)))),
    h('h3', {}, 'ประวัติสถานะ'), h('ul', {}, o.logs.map((l) => h('li', {}, `${dateTime(l.created_at)} ${STATUS[l.to_status][0]} โดย ${l.actor || 'ระบบ'}`, l.reason ? ` — ${l.reason}` : ''))),
    h('h3', {}, 'การแจ้งเตือน'), o.notifications.length ? h('ul', {}, o.notifications.map((n) => h('li', {}, `${n.event_type}: ${n.status} (${n.attempts} ครั้ง) ${n.last_error || ''}`))) : h('p.muted', {}, 'ไม่มี'),
  ]);
}

async function shops(main) {
  const courts = await get('/admin/food-courts');
  const edit = async (shop, reload) => {
    const name = h('input', { required: true, maxlength: 150, value: shop?.name || '' });
    const court = h('select', {}, courts.map((c) => h('option', { value: c.id, selected: c.id === shop?.food_court_id }, c.name)));
    const active = h('input', { type: 'checkbox', checked: shop ? Boolean(shop.active) : true });
    const saved = await form(shop ? 'แก้ไขร้าน' : 'เพิ่มร้าน', [field('ชื่อร้าน', name), field('โรงอาหาร', court), h('label.check', {}, active, 'อนุมัติให้ใช้งาน')], () => {
      const body = { name: name.value, food_court_id: Number(court.value), active: active.checked };
      return shop ? patch(`/admin/shops/${shop.id}`, body) : post('/admin/shops', body);
    });
    if (saved) await reload();
  };
  const members = async (shop, reload) => {
    const login = h('input', { required: true, maxlength: 100, autocomplete: 'off' });
    const saved = await form(`สมาชิกร้าน ${shop.name}`, [
      shop.members.length ? h('ul', {}, shop.members.map((m) => h('li', {}, `${m.display_name} (${m.login_name || '-'}) `, ghost('ถอดสิทธิ์', async (e) => {
        const item = e.currentTarget.closest('li');
        if (await confirmBox('ถอดสิทธิ์?', `${m.display_name} จะเข้าร้านนี้ไม่ได้ทันที`, 'ถอดสิทธิ์', true)) await busy(null, async () => { await api('DELETE', `/admin/shops/${shop.id}/members/${m.id}`); item.remove(); await reload(); });
      }, '.danger')))) : h('p.muted', {}, 'ยังไม่มีสมาชิก'), field('เพิ่มสมาชิกด้วยชื่อผู้ใช้ร้านค้า', login)], () => post(`/admin/shops/${shop.id}/members`, { login: login.value.trim() }), 'เพิ่ม');
    if (saved) await reload();
  };
  await listing(main, 'ร้าน', '/admin/shops', [], (data, reload) => table(['ร้าน', 'โรงอาหาร', 'สถานะ', 'รับออเดอร์', '#ออเดอร์ค้าง', '#ส่งมอบไม่ใช้รหัส', 'สมาชิก', ''], data.map((s) => h('tr', {}, h('td', {}, s.name), h('td', {}, s.food_court), h('td', {}, onOff(s.active)), h('td', {}, onOff(s.accepting_orders, 'เปิด', 'หยุด')), h('td.num', {}, s.active_orders), h('td.num', {}, s.pickup_overrides), h('td', {}, s.members.map((m) => m.display_name).join(', ') || '–'), h('td', {}, h('div.row', {}, ghost('แก้ไข', () => edit(s, reload)), ghost('สมาชิก', () => members(s, reload))))))),
    [(reload) => h('button.btn', { type: 'button', onclick: () => edit(null, reload) }, 'เพิ่มร้าน')]);
}

async function courts(main) {
  const data = await get('/admin/food-courts');
  const edit = async (c) => {
    const name = h('input', { required: true, maxlength: 150, value: c?.name || '' });
    const place = h('input', { maxlength: 255, value: c?.location || '' });
    const active = h('input', { type: 'checkbox', checked: c ? Boolean(c.active) : true });
    const saved = await form(c ? 'แก้ไขโรงอาหาร' : 'เพิ่มโรงอาหาร', [field('ชื่อ', name), field('ที่ตั้ง', place), h('label.check', {}, active, 'ใช้งาน')], () => {
      const body = { name: name.value, location: place.value, active: active.checked };
      return c ? patch(`/admin/food-courts/${c.id}`, body) : post('/admin/food-courts', body);
    });
    if (saved) await courts(main);
  };
  main.replaceChildren(h('div.row.between', {}, h('h1', {}, 'โรงอาหาร'), h('button.btn', { type: 'button', onclick: () => edit(null) }, 'เพิ่มโรงอาหาร')), h('div', { style: 'margin-top:.9rem' }, table(['ชื่อ', 'ที่ตั้ง', '#ร้าน', 'สถานะ', ''], data.map((c) => h('tr', {}, h('td', {}, c.name), h('td', {}, c.location || '–'), h('td.num', {}, c.shops), h('td', {}, onOff(c.active)), h('td', {}, ghost('แก้ไข', () => edit(c))))))));
}

const ROLES = { customer: 'ลูกค้า', merchant: 'ร้านค้า', admin: 'ผู้ดูแล' };
function users(main) {
  const role = h('select', { name: 'role', 'aria-label': 'สิทธิ์' }, h('option', { value: '' }, 'ทุกสิทธิ์'), Object.entries(ROLES).map(([k, v]) => h('option', { value: k }, v)));
  const password = () => h('input', { type: 'password', minlength: 12, maxlength: 72, autocomplete: 'new-password' });
  const create = async (reload) => {
    const kind = h('select', {}, ['merchant', 'admin'].map((k) => h('option', { value: k }, ROLES[k])));
    const name = h('input', { required: true, maxlength: 150 });
    const login = h('input', { required: true, minlength: 4, maxlength: 100, autocomplete: 'off' });
    const pass = password();
    pass.required = true;
    if (await form('เพิ่มบัญชี', [field('ประเภท', kind), field('ชื่อ', name), field('ชื่อผู้ใช้', login), field('รหัสผ่าน', pass, 'อย่างน้อย 12 ตัวอักษร')], () => post('/admin/users', { role: kind.value, display_name: name.value, login: login.value, password: pass.value }))) await reload();
  };
  const edit = async (u, reload) => {
    const boxes = Object.keys(ROLES).map((k) => [k, h('input', { type: 'checkbox', checked: u.roles.includes(k) })]);
    const active = h('input', { type: 'checkbox', checked: u.active });
    const pass = password();
    if (await form(u.display_name, [h('fieldset', {}, h('legend', {}, 'สิทธิ์'), boxes.map(([k, box]) => h('label.check', {}, box, ROLES[k]))), h('label.check', {}, active, 'เปิดใช้งานบัญชี'), u.login_name ? field('ตั้งรหัสผ่านใหม่', pass, 'เว้นว่างถ้าไม่เปลี่ยน ผู้ใช้จะถูกออกจากระบบ') : null], () => patch(`/admin/users/${u.id}`, { roles: boxes.filter(([, b]) => b.checked).map(([k]) => k), active: active.checked, ...(pass.value ? { password: pass.value } : {}) }))) await reload();
  };
  return listing(main, 'ผู้ใช้และสิทธิ์', '/admin/users', [role], (data, reload) => table(['ชื่อ', 'ชื่อผู้ใช้', 'สิทธิ์', 'LINE', 'สถานะ', 'สร้างเมื่อ', ''], data.map((u) => h('tr', {}, h('td', {}, u.display_name), h('td', {}, u.login_name || '–'), h('td', {}, u.roles.map((r) => ROLES[r]).join(', ')), h('td', {}, u.has_line ? 'เชื่อมแล้ว' : '–'), h('td', {}, onOff(u.active)), h('td', {}, dateTime(u.created_at)), h('td', {}, ghost('แก้ไข', () => edit(u, reload)))))),
    [(reload) => h('button.btn', { type: 'button', onclick: () => create(reload) }, 'เพิ่มบัญชี')]);
}

async function reports(main, from = today(), to = today()) {
  const r = await get(`/admin/reports?from=${from}&to=${to}`);
  const a = h('input', { type: 'date', value: from, max: today() });
  const b = h('input', { type: 'date', value: to, max: today() });
  const stat = (label, value) => h('div.card.stat', {}, h('span.muted', {}, label), h('b', {}, value));
  main.replaceChildren(h('h1', {}, 'รายงานรวม'), h('form.filters', { onsubmit: (e) => { e.preventDefault(); busy(null, () => reports(main, a.value, b.value)); } }, field('ตั้งแต่', a), field('ถึง', b), h('button.btn', { type: 'submit' }, 'ดูรายงาน')),
    h('div.stats', {}, stat('ยอดขายที่ชำระแล้ว', baht(r.sales)), stat('สั่ง', r.placed), stat('สำเร็จ', r.completed), stat('ปฏิเสธ', r.rejected), stat('ยกเลิก', r.cancelled), stat('เวลาทำเฉลี่ย', r.avg_prep_minutes === null ? '–' : `${r.avg_prep_minutes} นาที`)),
    r.by_shop.length ? h('div.stack', {}, h('h2', {}, 'ตามร้าน'), table(['ร้าน', '#ออเดอร์', '#ยอด'], r.by_shop.map((s) => h('tr', {}, h('td', {}, s.name), h('td.num', {}, s.orders), h('td.num', {}, baht(s.sales))))),
      h('h2', {}, 'ตามวัน'), table(['วันที่', '#ออเดอร์', '#ยอด'], r.by_day.map((d) => h('tr', {}, h('td', {}, d.day), h('td.num', {}, d.orders), h('td.num', {}, baht(d.sales)))))) : empty('ไม่มียอดขายในช่วงนี้'));
}

const AUDIT = { 'pickup.override': 'ส่งมอบไม่ใช้รหัส', 'order.no_show': 'ลูกค้าไม่มารับ', 'payment.confirm': 'ยืนยันชำระเงิน', 'auth.login': 'เข้าสู่ระบบ' };
const audit = (main) => listing(main, 'บันทึกการใช้งาน', '/admin/audit-logs', [h('select', { name: 'action', 'aria-label': 'ประเภท' }, h('option', { value: '' }, 'ทุกประเภท'), Object.entries(AUDIT).map(([k, v]) => h('option', { value: k }, v)))], (data) => table(['เวลา', 'ผู้ทำ', 'การกระทำ', 'ข้อมูล', 'รายละเอียด'], data.map((l) => h('tr', {}, h('td', {}, dateTime(l.created_at)), h('td', {}, l.actor || 'ระบบ'), h('td', {}, AUDIT[l.action] || l.action), h('td', {}, `${l.entity_type} ${l.entity_id ?? ''}`), h('td', {}, l.safe_metadata || '')))));

async function settings(main) {
  const s = await get('/admin/settings');
  const line = (label, value) => h('div.row.between', {}, h('span', {}, label), value);
  main.replaceChildren(h('h1', {}, 'ระบบ'), h('section.card.stack', { style: 'max-width:560px' }, line('สภาพแวดล้อม', s.environment), line('เขตเวลา', s.timezone), line('LINE Login / LIFF', onOff(s.line_login, 'ตั้งค่าแล้ว', 'ยังไม่ตั้งค่า')), line('LINE Messaging', onOff(s.line_messaging, 'ตั้งค่าแล้ว', 'ยังไม่ตั้งค่า')), line('LINE Webhook', onOff(s.line_webhook, 'ตั้งค่าแล้ว', 'ยังไม่ตั้งค่า')),
    h('h2', {}, 'การแจ้งเตือน'), ['PENDING', 'SENDING', 'SENT', 'FAILED'].map((k) => line(k, s.notifications[k] || 0)), h('p.muted', {}, 'ค่าระบบแก้ที่ไฟล์ .env บนเซิร์ฟเวอร์')));
}

const PAGES = { overview, orders, shops, courts, users, reports, audit, settings };
addEventListener('hashchange', () => { if (me) render(); });
start();
