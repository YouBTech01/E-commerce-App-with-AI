/* ExamLegacy — API client.
   Attaches the Firebase ID token (Authorization: Bearer) to every request.
   Never trusts the client for authorization; the server re-verifies. */
window.EL = window.EL || {};

EL.api = (function () {
  async function authHeader(extra) {
    var headers = Object.assign({ 'Content-Type': 'application/json' }, extra || {});
    var token = await EL.auth.currentToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;
    return headers;
  }

  async function request(method, path, body, extraHeaders) {
    var opts = { method: method, headers: await authHeader(extraHeaders) };
    if (body !== undefined && body !== null && method !== 'GET') {
      opts.body = typeof body === 'string' ? body : JSON.stringify(body);
    }
    var res = await fetch(EL.API_BASE + path, opts);
    var text = await res.text();
    var data = null;
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { ok: false, error: { message: 'Bad response' } }; }
    if (!res.ok || data.ok === false) {
      var msg = (data && data.error && data.error.message) || ('Request failed (' + res.status + ')');
      var err = new Error(msg);
      err.status = res.status;
      err.code = data && data.error && data.error.code;
      throw err;
    }
    return data.data !== undefined ? data.data : data;
  }

  return {
    get: function (p, h) { return request('GET', p, null, h); },
    post: function (p, b, h) { return request('POST', p, b === undefined ? {} : b, h); },
    patch: function (p, b, h) { return request('PATCH', p, b, h); },
    del: function (p, h) { return request('DELETE', p, null, h); },

    /* Multipart upload (user study files / admin PDF + cover). */
    upload: async function (path, file, fieldName) {
      var fd = new FormData();
      fd.append(fieldName || 'file', file);
      var token = await EL.auth.currentToken();
      var headers = {};
      if (token) headers['Authorization'] = 'Bearer ' + token;
      var res = await fetch(EL.API_BASE + path, { method: 'POST', headers: headers, body: fd });
      var data = await res.json().catch(function () { return { ok: false }; });
      if (!res.ok || data.ok === false) {
        throw new Error((data.error && data.error.message) || 'Upload failed');
      }
      return data.data;
    }
  };
})();
