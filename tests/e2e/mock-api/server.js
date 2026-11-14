'use strict';

/**
 * Mock Quant Content API (v1) and Quant portal (OAuth) for end-to-end tests.
 *
 * No dependencies. Everything is kept in memory.
 *
 * Inspection endpoints (no auth):
 *   GET    /_mock/requests?path=&method=   recorded requests (newest last)
 *   DELETE /_mock/requests                 clear requests AND state
 *   GET    /_mock/state                    published, files, redirects, search, facets, unpublished
 *   POST   /_mock/fail {path,status,times} fail the next N requests to path
 *   POST   /_mock/platform {mode,custom_ranking,facets} "aws" makes the project
 *                                         a QuantGov (Typesense) search project;
 *                                         facets replaces the registered facets
 */

const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const PORT = parseInt(process.env.PORT || '4000', 10);
const AUTH = {
  token: process.env.MOCK_TOKEN || 'test-token-123',
  customer: process.env.MOCK_CUSTOMER || 'test-org',
  project: process.env.MOCK_PROJECT || 'test-project',
};
const LOCAL_HOSTS = ['localhost', 'localhost:8088', 'wordpress', 'wordpress:80', '127.0.0.1', '127.0.0.1:8088'];

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------

function freshState() {
  return {
    published: {},      // url -> { content, published, info, search_record, headers, proxy_override, content_timestamp, revision_number, md5, updated_at }
    files: {},          // url -> { md5, size, uploaded_at }
    redirects: [],      // [{ url, redirect_url, redirect_http_code, published }]
    searchRecords: {},  // url -> record
    facets: [],         // [string]
    unpublished: [],    // [url]
    purges: [],         // [{ url, cacheKeys, soft }]
    platform: { mode: '', custom_ranking: [] },
  };
}

let state = freshState();
let requests = [];
const failures = []; // { path, status, times }

function md5(buf) {
  return crypto.createHash('md5').update(buf).digest('hex');
}

function nowIso() {
  return new Date().toISOString();
}

// ---------------------------------------------------------------------------
// Request helpers
// ---------------------------------------------------------------------------

function readBody(req) {
  return new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', () => resolve(Buffer.concat(chunks)));
  });
}

function send(res, status, body, headers = {}) {
  const payload = typeof body === 'string' ? body : JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', ...headers });
  res.end(payload);
}

function header(req, name) {
  const v = req.headers[name.toLowerCase()];
  return Array.isArray(v) ? v[0] : v;
}

function parseJson(buf) {
  if (!buf || buf.length === 0) return null;
  try {
    return JSON.parse(buf.toString('utf8'));
  } catch (e) {
    return { _unparsed: buf.toString('utf8').slice(0, 500) };
  }
}

function parseForm(buf) {
  const out = {};
  for (const [k, v] of new URLSearchParams(buf.toString('utf8'))) out[k] = v;
  return out;
}

/**
 * Minimal multipart/form-data parser. Returns { fields: {name: string}, files: {name: {filename, data}} }.
 */
function parseMultipart(buf, contentType) {
  const m = /boundary=(?:"([^"]+)"|([^;]+))/i.exec(contentType || '');
  if (!m) return { fields: {}, files: {} };
  const boundary = Buffer.from('--' + (m[1] || m[2]).trim());
  const result = { fields: {}, files: {} };

  let start = buf.indexOf(boundary);
  while (start !== -1) {
    start += boundary.length;
    if (buf.slice(start, start + 2).toString() === '--') break; // closing boundary
    if (buf.slice(start, start + 2).toString() === '\r\n') start += 2;
    const headerEnd = buf.indexOf('\r\n\r\n', start);
    if (headerEnd === -1) break;
    const rawHeaders = buf.slice(start, headerEnd).toString('utf8');
    const next = buf.indexOf(boundary, headerEnd + 4);
    if (next === -1) break;
    let data = buf.slice(headerEnd + 4, next);
    if (data.slice(-2).toString() === '\r\n') data = data.slice(0, -2);

    const nameMatch = /name="([^"]*)"/i.exec(rawHeaders);
    const fileMatch = /filename="([^"]*)"/i.exec(rawHeaders);
    const name = nameMatch ? nameMatch[1] : 'unnamed';
    if (fileMatch) {
      result.files[name] = { filename: fileMatch[1], data };
    } else {
      result.fields[name] = data.toString('utf8');
    }
    start = next;
  }
  return result;
}

// ---------------------------------------------------------------------------
// Recording and failure injection
// ---------------------------------------------------------------------------

function record(req, url, parsedBody) {
  const entry = {
    method: req.method,
    path: url.pathname,
    query: Object.fromEntries(url.searchParams),
    headers: req.headers,
    body: parsedBody,
    timestamp: nowIso(),
  };
  requests.push(entry);
  console.log(`[mock] ${req.method} ${url.pathname}${url.search}`);
  return entry;
}

function takeFailure(pathname) {
  const idx = failures.findIndex((f) => f.path === pathname && f.times > 0);
  if (idx === -1) return null;
  failures[idx].times -= 1;
  const f = failures[idx];
  if (f.times === 0) failures.splice(idx, 1);
  return f;
}

function isAuthorized(req) {
  return (
    header(req, 'Quant-Token') === AUTH.token &&
    header(req, 'Quant-Customer') === AUTH.customer &&
    header(req, 'Quant-Project') === AUTH.project
  );
}

// ---------------------------------------------------------------------------
// Attachment discovery (mirrors what the real API returns for find_attachments)
// ---------------------------------------------------------------------------

function normalizeLocal(href) {
  if (!href) return null;
  let candidate = href.trim();
  if (candidate.startsWith('//')) return null;
  if (candidate.startsWith('/')) return candidate;
  const m = /^https?:\/\/([^/]+)(\/.*)$/i.exec(candidate);
  if (m && LOCAL_HOSTS.includes(m[1].toLowerCase())) return m[2];
  return null;
}

function stripQuery(p) {
  const i = p.indexOf('?');
  return i === -1 ? p : p.slice(0, i);
}

function attachmentEntry(found) {
  const path = stripQuery(normalizeLocal(found));
  const entry = { path, original_path: found, full_path: found };
  const file = state.files[path];
  if (file) entry.existing_md5 = file.md5;
  return entry;
}

function discoverAttachments(html) {
  const out = { js: [], css: [], media: { images: [], documents: [], video: [] } };
  const seen = new Set();
  const push = (list, found) => {
    const local = normalizeLocal(found);
    if (!local || seen.has(local)) return;
    seen.add(local);
    list.push(attachmentEntry(found));
  };

  for (const m of html.matchAll(/<script[^>]+src=["']([^"']+)["']/gi)) push(out.js, m[1]);
  for (const m of html.matchAll(/<link[^>]+href=["']([^"']+)["'][^>]*>/gi)) {
    const tag = m[0];
    if (/rel=["'][^"']*stylesheet/i.test(tag) || /\.css(\?|$)/i.test(m[1])) push(out.css, m[1]);
  }
  for (const m of html.matchAll(/<img[^>]+src=["']([^"']+)["']/gi)) push(out.media.images, m[1]);
  for (const m of html.matchAll(/<a[^>]+href=["']([^"']+\.(?:pdf|docx?|xlsx?|pptx?|zip))["']/gi)) push(out.media.documents, m[1]);
  for (const m of html.matchAll(/<(?:video|source)[^>]+src=["']([^"']+\.(?:mp4|webm|ogg))["']/gi)) push(out.media.video, m[1]);
  return out;
}

// ---------------------------------------------------------------------------
// Content API handlers
// ---------------------------------------------------------------------------

function handlePing(req, res) {
  send(res, 200, {
    project: { name: AUTH.project },
    config: {
      search_enabled: true,
      ...(state.platform.mode ? { platform_mode: state.platform.mode } : {}),
      search_config: { algolia: { custom_ranking: state.platform.custom_ranking } },
      search_index: {
        algolia_application_id: 'APPID',
        algolia_read_key: 'READKEY',
        algolia_index: 'test-index',
      },
    },
  });
}

function handlePublishContent(req, res, body) {
  if (!body || typeof body.url !== 'string') {
    return send(res, 400, { error: true, errorMsg: 'url is required' });
  }
  const url = body.url;
  const previous = state.published[url];
  const revision = previous ? previous.revision_number + 1 : 1;
  const content = typeof body.content === 'string' ? body.content : '';
  const entry = {
    url,
    content,
    published: body.published !== false,
    info: body.info || null,
    search_record: body.search_record || null,
    headers: body.headers || null,
    proxy_override: body.proxy_override ?? null,
    content_timestamp: body.content_timestamp ?? null,
    find_attachments: body.find_attachments ?? null,
    revision_number: revision,
    md5: md5(content),
    updated_at: nowIso(),
  };
  state.published[url] = entry;
  state.unpublished = state.unpublished.filter((u) => u !== url);
  if (body.search_record && !body.search_record.skip) {
    state.searchRecords[body.search_record.url || url] = body.search_record;
  }
  send(res, 200, {
    url,
    md5: entry.md5,
    published: entry.published,
    revision_number: revision,
    byte_length: Buffer.byteLength(content),
    attachments: discoverAttachments(content),
  });
}

function handleUploadFile(req, res, raw) {
  const fileUrl = header(req, 'Quant-File-Url');
  if (!fileUrl) return send(res, 400, { error: true, errorMsg: 'Quant-File-Url header is required' });
  const parsed = parseMultipart(raw, header(req, 'content-type'));
  const file = parsed.files.data || Object.values(parsed.files)[0];
  if (!file) return send(res, 400, { error: true, errorMsg: 'multipart field "data" is required' });
  const digest = md5(file.data);
  const existing = state.files[fileUrl];
  if (existing && existing.md5 === digest) {
    // The real API answers 400 "MD5 already matches" for unchanged files.
    return send(res, 400, { error: true, errorMsg: 'MD5 already matches existing file.' });
  }
  state.files[fileUrl] = { md5: digest, size: file.data.length, filename: file.filename, uploaded_at: nowIso() };
  send(res, 200, { url: fileUrl, md5: digest, byte_length: file.data.length });
}

function handleUnpublish(req, res) {
  const url = header(req, 'Quant-Url');
  if (!url) return send(res, 400, { error: true, errorMsg: 'Quant-Url header is required' });
  if (state.published[url]) state.published[url].published = false;
  if (!state.unpublished.includes(url)) state.unpublished.push(url);
  send(res, 200, { url, published: false });
}

function handleRedirect(req, res, body) {
  if (!body || !body.url || !body.redirect_url) {
    return send(res, 400, { error: true, errorMsg: 'url and redirect_url are required' });
  }
  const entry = {
    url: body.url,
    redirect_url: body.redirect_url,
    redirect_http_code: body.redirect_http_code || 301,
    published: body.published !== false,
  };
  state.redirects = state.redirects.filter((r) => r.url !== entry.url);
  state.redirects.push(entry);
  send(res, 200, entry);
}

function handlePurge(req, res) {
  const url = header(req, 'Quant-Url') || null;
  const cacheKeys = header(req, 'Cache-Keys') || null;
  state.purges.push({ url, cacheKeys, soft: header(req, 'Soft-Purge') === 'true' });
  send(res, 200, { success: true });
}

function handleSearchRecords(req, res, body) {
  const records = Array.isArray(body) ? body : body ? [body] : [];
  let count = 0;
  for (const r of records) {
    if (!r || r.skip) continue;
    state.searchRecords[r.url || `record-${count}`] = r;
    count += 1;
  }
  send(res, 200, { count });
}

function handleSearchClear(req, res) {
  state.searchRecords = {};
  send(res, 200, { success: true });
}

function handleSearchFacet(req, res, body) {
  const keys = Array.isArray(body) ? body : [];
  state.facets = Array.from(new Set([...state.facets, ...keys]));
  send(res, 200, { success: true, attributesForFaceting: state.facets });
}

function handleSearchStatus(req, res) {
  send(res, 200, {
    index: {
      entries: Object.keys(state.searchRecords).length,
      dataSize: 1234,
      fileSize: 2345,
      numberOfPendingTasks: 0,
      name: 'test-index',
      updatedAt: nowIso(),
    },
    settings: { attributesForFaceting: state.facets },
    ...(state.platform.mode === 'aws'
      ? { readOnlyKey: { value: 'TS-READ-KEY', collections: ['test-index'], actions: ['documents:search'] } }
      : {}),
  });
}

function handleUrlMeta(req, res, body) {
  const urls = (body && (body['Quant-Url'] || body.urls)) || [];
  const list = Array.isArray(urls) ? urls : [urls];
  const records = [];
  for (const u of list) {
    const p = state.published[u];
    if (!p) continue;
    const ts = Math.floor(new Date(p.updated_at).getTime() / 1000);
    records.push({
      meta: {
        url: u,
        published: p.published,
        revision_count: p.revision_number,
        content_timestamp: p.content_timestamp || ts,
        date_timestamp: ts,
      },
    });
  }
  send(res, 200, { global_meta: { records } });
}

async function routeContentApi(req, res, url, raw) {
  const ct = header(req, 'content-type') || '';
  const isMultipart = /multipart\/form-data/i.test(ct);
  const body = isMultipart ? { multipart: true, fileUrl: header(req, 'Quant-File-Url'), size: raw.length } : parseJson(raw);
  record(req, url, body);

  const fail = takeFailure(url.pathname);
  if (fail) return send(res, fail.status, { error: true, errorMsg: 'Simulated failure' });

  if (!isAuthorized(req)) return send(res, 403, { error: true, errorMsg: 'Invalid credentials' });

  const p = url.pathname.replace(/\/+$/, '') || '/v1';
  const m = req.method;

  if (m === 'GET' && p === '/v1/ping') return handlePing(req, res);
  if (m === 'POST' && p === '/v1') return isMultipart ? handleUploadFile(req, res, raw) : handlePublishContent(req, res, body);
  if (m === 'PATCH' && p === '/v1/unpublish') return handleUnpublish(req, res);
  if (m === 'POST' && p === '/v1/redirect') return handleRedirect(req, res, body);
  if (m === 'POST' && p === '/v1/purge') return handlePurge(req, res);
  if (m === 'POST' && p === '/v1/search') return handleSearchRecords(req, res, body);
  if (m === 'DELETE' && p === '/v1/search/all') return handleSearchClear(req, res);
  if (m === 'POST' && p === '/v1/search/facet') return handleSearchFacet(req, res, body);
  if (m === 'GET' && p === '/v1/search') return handleSearchStatus(req, res);
  if (m === 'POST' && p === '/v1/url-meta') return handleUrlMeta(req, res, body);

  send(res, 404, { error: true, errorMsg: `No mock route for ${m} ${p}` });
}

// ---------------------------------------------------------------------------
// Portal / OAuth mock
// ---------------------------------------------------------------------------

// The project that POST /api/oauth/token/project limited the login to, or null.
let oauthLimit = null;

function hasBearer(req) {
  return header(req, 'authorization') === 'Bearer mock-access';
}

function routePortal(req, res, url, raw) {
  const body = /x-www-form-urlencoded/i.test(header(req, 'content-type') || '') ? parseForm(raw) : parseJson(raw);
  record(req, url, body);
  const p = url.pathname.replace(/\/+$/, '');
  const m = req.method;

  if (m === 'GET' && p === '/oauth/authorize') {
    const redirect = url.searchParams.get('redirect_uri');
    const stateParam = url.searchParams.get('state') || '';
    if (!redirect) return send(res, 400, { error: 'redirect_uri required' });
    const sep = redirect.includes('?') ? '&' : '?';
    const location = `${redirect}${sep}code=mock-code&state=${encodeURIComponent(stateParam)}`;
    res.writeHead(302, { Location: location });
    return res.end();
  }
  if (m === 'POST' && p === '/oauth/token') {
    if (body && body.code && body.code !== 'mock-code') return send(res, 400, { error: 'invalid_grant' });
    // A new login is not limited to a project (portal OAuthTokenProjectController).
    oauthLimit = null;
    return send(res, 200, { access_token: 'mock-access', token_type: 'Bearer', expires_in: 86400, scope: 'full_access' });
  }
  if (m === 'GET' && p === '/api/oauth/user') {
    if (!hasBearer(req)) return send(res, 401, { error: 'unauthorized' });
    return send(res, 200, {
      id: 1,
      email: 'e2e@example.com',
      name: 'E2E User',
      organizations: [{ id: 1, name: 'Test Org', machine_name: 'test-org' }],
    });
  }
  if (m === 'POST' && p === '/api/oauth/token/project') {
    if (!hasBearer(req)) return send(res, 401, { error: 'unauthorized' });
    const wanted = `${body && body.organization}/${body && body.project}`;
    if (oauthLimit && oauthLimit !== wanted) return send(res, 409, { error: true, code: 'TOKEN_ALREADY_LIMITED' });
    oauthLimit = wanted;
    return send(res, 200, { organization: body.organization, project: body.project, projects: [1] });
  }
  if (m === 'POST' && p === '/api/oauth/revoke') {
    if (!hasBearer(req)) return send(res, 401, { error: 'unauthorized' });
    oauthLimit = null;
    return send(res, 200, { revoked: true });
  }
  if (m === 'GET' && p === '/api/v2/organizations/test-org/projects') {
    if (!hasBearer(req)) return send(res, 401, { error: 'unauthorized' });
    // A login limited to its project cannot list projects (portal TokenIsValid, PROJECT_LEVEL_ONLY).
    if (oauthLimit) return send(res, 403, { error: true, code: 'PROJECT_LEVEL_ONLY' });
    return send(res, 200, { data: [{ name: 'Test Project', machine_name: 'test-project' }] });
  }
  if (m === 'GET' && p === '/api/v2/organizations/test-org/projects/test-project') {
    if (!hasBearer(req)) return send(res, 401, { error: 'unauthorized' });
    const data = { name: 'Test Project', machine_name: 'test-project' };
    if (url.searchParams.get('with_token') === '1') data.write_token = AUTH.token;
    return send(res, 200, { data });
  }
  return false;
}

// ---------------------------------------------------------------------------
// Inspection
// ---------------------------------------------------------------------------

function routeInspection(req, res, url, raw) {
  const p = url.pathname.replace(/\/+$/, '');
  const m = req.method;

  if (m === 'GET' && p === '/_mock/requests') {
    const path = url.searchParams.get('path');
    const method = url.searchParams.get('method');
    const list = requests.filter((r) => (!path || r.path === path) && (!method || r.method === method.toUpperCase()));
    return send(res, 200, list);
  }
  if (m === 'DELETE' && p === '/_mock/requests') {
    requests = [];
    state = freshState();
    oauthLimit = null;
    failures.length = 0;
    return send(res, 200, { success: true });
  }
  if (m === 'GET' && p === '/_mock/state') return send(res, 200, state);
  if (m === 'POST' && p === '/_mock/fail') {
    const body = parseJson(raw) || {};
    if (!body.path) return send(res, 400, { error: 'path required' });
    failures.push({ path: body.path, status: body.status || 500, times: body.times || 1 });
    return send(res, 200, { success: true, failures });
  }
  if (m === 'POST' && p === '/_mock/platform') {
    const body = parseJson(raw) || {};
    state.platform = { mode: String(body.mode || ''), custom_ranking: Array.isArray(body.custom_ranking) ? body.custom_ranking : [] };
    if (Array.isArray(body.facets)) state.facets = body.facets;
    return send(res, 200, { success: true, platform: state.platform });
  }
  if (m === 'GET' && p === '/_mock/health') return send(res, 200, { ok: true });
  return false;
}

// ---------------------------------------------------------------------------
// Server
// ---------------------------------------------------------------------------

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const raw = await readBody(req);
  try {
    if (url.pathname.startsWith('/_mock/')) {
      if (routeInspection(req, res, url, raw) !== false) return;
    }
    if (url.pathname === '/v1' || url.pathname.startsWith('/v1/')) {
      return await routeContentApi(req, res, url, raw);
    }
    if (routePortal(req, res, url, raw) !== false) return;
    record(req, url, parseJson(raw));
    send(res, 404, { error: true, errorMsg: `No mock route for ${req.method} ${url.pathname}` });
  } catch (err) {
    console.error('[mock] error', err);
    send(res, 500, { error: true, errorMsg: String(err && err.message) });
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`[mock] Quant mock API listening on :${PORT} (customer=${AUTH.customer} project=${AUTH.project})`);
});
