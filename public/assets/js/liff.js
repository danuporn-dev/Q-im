// LINE Front-end Framework glue, loaded only when LIFF_ID is configured.
// Nothing here may change the URL before liff.init() resolves.
const SDK = 'https://static.line-scdn.net/liff/edge/versions/2.31.1/sdk.js';

const loadSdk = () => new Promise((resolve, reject) => {
  const script = document.createElement('script');
  script.src = SDK;
  script.charset = 'utf-8';
  script.onload = resolve;
  script.onerror = () => reject(new Error('โหลด LINE ไม่สำเร็จ ยังดูร้านและเมนูได้ตามปกติ'));
  document.head.append(script);
});

/** Returns an ID token, or null when the visitor is not signed in to LINE. */
export async function initLiff(liffId) {
  await loadSdk();
  await window.liff.init({ liffId });
  return window.liff.isLoggedIn() ? window.liff.getIDToken() : null;
}

export function login() {
  if (!window.liff) return;
  if (window.liff.isInClient()) location.reload();
  else window.liff.login({ redirectUri: location.href });
}

export function logout() {
  if (window.liff?.isLoggedIn() && !window.liff.isInClient()) window.liff.logout();
}

/** A cached ID token can be expired: sign out of LIFF so that the next login gets a new one. */
export function renew() {
  if (!window.liff) return;
  // Once per tab, so a wrong channel setting cannot cause a reload loop.
  try {
    if (sessionStorage.getItem('qim_renewed')) return;
    sessionStorage.setItem('qim_renewed', '1');
  } catch (e) {
    return;
  }
  window.liff.logout();
  login();
}
