/* ExamLegacy — front-end configuration.
   NOTE: The Firebase *web* config below is PUBLIC by design (it is not a
   server secret; access is protected by Firebase Security Rules + authorized
   domains). All privileged secrets (Gemini, Cashfree, MySQL, SMTP, service
   account) live ONLY on the server. Fill these in from your Firebase project. */
window.EL = window.EL || {};

EL.API_BASE = '/api';

EL.FIREBASE_CONFIG = {
  apiKey: "YOUR_FIREBASE_WEB_API_KEY",
  authDomain: "YOUR_PROJECT.firebaseapp.com",
  projectId: "YOUR_PROJECT_ID",
  storageBucket: "YOUR_PROJECT.appspot.com",
  messagingSenderId: "000000000000",
  appId: "1:000000000000:web:abcdef"
};

EL.APP_NAME = 'ExamLegacy';
EL.CURRENCY = 'INR';

/* Format integer paise as a localized currency string. */
EL.money = function (paise, currency) {
  currency = currency || EL.CURRENCY || 'INR';
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format((paise || 0) / 100);
  } catch (e) {
    return '₹' + ((paise || 0) / 100).toFixed(2);
  }
};

/* Escape user/DB content before injecting into innerHTML. */
EL.esc = function (s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
};

EL.bytes = function (n) {
  n = n || 0;
  var u = ['B', 'KB', 'MB', 'GB'], i = 0;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
};

EL.qs = function (sel, root) { return (root || document).querySelector(sel); };
EL.qsa = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
