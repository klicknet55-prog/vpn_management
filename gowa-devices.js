/* =====================================================
   GoWA Device Manager — gowa-devices.js
   Frontend → PHP API (api/wa-account.php) → GoWA Server
   
   GoWA: https://github.com/aldinokemal/go-whatsapp-web-multidevice
   Auth: Basic Auth — handled server-side in api/gowa.php
   
   Data Isolation: setiap device punya database terpisah
   Independent Login: QR scan per device secara terpisah
   API URL: user mendapat URL kirim pesan dengan secret key
   ===================================================== */

'use strict';

// ─────────────────────────────────────────────────────
// BACKEND API — all GoWA calls go through PHP proxy
// Credentials (Basic Auth) are stored in api/config.php
// ─────────────────────────────────────────────────────
const API = window.GOWA_API || 'api/wa-account.php';

// ─────────────────────────────────────────────────────
// INTERNAL STATE
// ─────────────────────────────────────────────────────
let _devices         = [];
let _qrDeviceId      = null;
let _qrDeviceLabel   = null;
let _statusPollTimer = null;
let _pendingDeleteId = null;
let _abortController = null;
let _isAdmin         = false;
let _deviceSearchTerm = '';
let _deviceOwnerFilter = 'all';
let _deviceStatusFilter = 'all';
const _pageMode      = String(window.GOWA_PAGE_MODE || '').toLowerCase();
const _sessionUserId = Number(window.DAYNIGHT_SESSION?.userId || 0);

// ─────────────────────────────────────────────────────
// HTTP HELPER — calls our PHP backend
// ─────────────────────────────────────────────────────
async function apiFetch(method, params = {}, body = null, signal) {
    const url = new URL(API, window.location.href);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
    const opts = { method, headers: { 'Content-Type': 'application/json' }, signal };
    if (body !== null) opts.body = JSON.stringify(body);
    const res = await fetch(url.toString(), opts);
    const ct  = res.headers.get('content-type') || '';
    if (ct.startsWith('text/html') || ct.startsWith('image/')) return res;
    const data = await res.json();
    if (!res.ok && data.error) {
        const e = new Error(data.error);
        e.status = res.status;
        throw e;
    }
    return data;
}

// ─────────────────────────────────────────────────────
// LOAD DEVICES
// ─────────────────────────────────────────────────────
async function gowaLoadDevices() {
    setDeviceLoading(true);
    hideDeviceError();
    if (_abortController) _abortController.abort();
    _abortController = new AbortController();
    try {
        const res = await apiFetch('GET', {}, null, _abortController.signal);
        _isAdmin = Boolean(res.is_admin);
        _devices = Array.isArray(res.data) ? res.data : [];
        renderDeviceTable(getFilteredDevices(_devices));
        updateStats(_devices);
    } catch (err) {
        if (err.name === 'AbortError') return;
        showDeviceError(err.status === 401
            ? 'Unauthorized — periksa konfigurasi GoWA di api/config.php'
            : 'Gagal memuat device: ' + err.message);
        _devices = [];
        renderDeviceTable([]);
        updateStats([]);
    } finally {
        setDeviceLoading(false);
    }
}

function getFilteredDevices(devices) {
    if (_pageMode !== 'admin') return devices;
    const q = String(_deviceSearchTerm || '').trim().toLowerCase();
    const ownerFilter = String(_deviceOwnerFilter || 'all').toLowerCase();
    const statusFilter = String(_deviceStatusFilter || 'all').toLowerCase();

    return devices.filter(d => {
        const ownerId = Number(d.owner_user_id || 0);
        const status = String(d.status || 'unknown').toLowerCase();

        if (ownerFilter === 'mine' && ownerId !== _sessionUserId) return false;
        if (ownerFilter === 'others' && (ownerId <= 0 || ownerId === _sessionUserId)) return false;

        if (statusFilter === 'connected' && status !== 'connected') return false;
        if (statusFilter === 'disconnected' && status !== 'disconnected') return false;
        if (statusFilter === 'error' && status !== 'error') return false;
        if (statusFilter === 'pending' && !['pending', 'qr_ready', 'unknown'].includes(status)) return false;

        if (!q) return true;
        const haystack = [
            d.device_id,
            d.label,
            d.owner_name,
            d.owner_email,
            d.phone_jid,
            d.db_name,
            d.status,
        ].map(v => String(v || '').toLowerCase()).join(' ');
        return haystack.indexOf(q) !== -1;
    });
}

function applyDeviceSearch() {
    renderDeviceTable(getFilteredDevices(_devices));
}

// ─────────────────────────────────────────────────────
// RENDER TABLE
// ─────────────────────────────────────────────────────
function renderDeviceTable(devices) {
    const tbody   = document.getElementById('device-tbody');
    const empty   = document.getElementById('device-empty');
    const wrapper = document.getElementById('device-table-wrapper');
    if (!tbody) return;
    if (!devices.length) {
        tbody.innerHTML = '';
        if (empty)   empty.style.display   = 'block';
        if (wrapper) wrapper.style.display = 'none';
        return;
    }
    if (empty)   empty.style.display   = 'none';
    if (wrapper) wrapper.style.display = '';
    tbody.innerHTML = devices.map(d => deviceRow(d)).join('');
}

function deviceRow(d) {
    const id       = gowaEscapeAttr(d.device_id || '');
    const dbId     = String(d.id || '');
    const rawLabel = (d.label || '').trim();
    const labelText = gowaFriendlyLabel(rawLabel, d.device_id || '');
    const label    = gowaEscapeHtml(labelText || 'Unknown');
    const ownerName = gowaEscapeHtml(d.owner_name || (_isAdmin ? '-' : 'Saya'));
    const ownerEmail = d.owner_email ? gowaEscapeHtml(d.owner_email) : '';
    const ownerCell = ownerEmail
        ? '<div style="font-size:0.875rem;">' + ownerName + '</div><div style="font-size:0.75rem;color:var(--text-secondary);">' + ownerEmail + '</div>'
        : '<div style="font-size:0.875rem;">' + ownerName + '</div>';
    const phone    = gowaEscapeHtml(d.phone_jid || 'Belum terhubung');
    const dbName   = gowaEscapeHtml(d.db_name   || 'auto');
    const status   = (d.status || 'unknown').toLowerCase();
    const lastConn = d.connected_at || '';
    const initial  = (label.charAt(0) || 'W').toUpperCase();
    const lastConnStr = lastConn ? gowaEscapeHtml(new Date(lastConn).toLocaleString('id-ID')) : '—';
    const ownerLine = _isAdmin && d.owner_name
        ? '<div style="font-size:0.75rem;color:var(--text-secondary);margin-top:0.125rem;">Owner: '
            + gowaEscapeHtml(d.owner_name)
            + (d.owner_email ? ' (' + gowaEscapeHtml(d.owner_email) + ')' : '')
            + '</div>'
        : '';
    const isForeignUserDeviceOnAdminPage = _pageMode === 'admin'
        && _isAdmin
        && Number(d.owner_user_id || 0) > 0
        && Number(d.owner_user_id || 0) !== _sessionUserId;

    let primaryBtn = '';
    if (!isForeignUserDeviceOnAdminPage) {
        if (status === 'connected') {
            primaryBtn = '<button class="btn btn-secondary" style="padding:0.4rem 0.75rem;font-size:0.8125rem;"'
                + ' onclick="gowaDisconnectDevice(\'' + id + '\',\'' + gowaEscapeAttr(label) + '\')">Putuskan</button>';
        } else {
            primaryBtn = '<button class="btn btn-primary" style="padding:0.4rem 0.75rem;font-size:0.8125rem;"'
                + ' onclick="gowaOpenQRModal(\'' + id + '\',\'' + gowaEscapeAttr(label) + '\')">'
                + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">'
                + '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>'
                + '<rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>'
                + ' Scan QR</button>';
        }
    }
    const testBtn = (!isForeignUserDeviceOnAdminPage && status === 'connected')
        ? '<button class="btn" style="padding:0.4rem 0.625rem;font-size:0.8125rem;color:var(--accent);border:1px solid rgba(99,102,241,0.3);"'
          + ' title="Test kirim pesan" onclick="gowaOpenTestModal(\'' + id + '\',\'' + gowaEscapeAttr(label) + '\')">'
          + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>'
          + ' Test</button>'
        : '';
    const apiBtn = (!isForeignUserDeviceOnAdminPage && d.api_url)
        ? '<button class="btn" style="padding:0.4rem 0.625rem;font-size:0.8125rem;color:var(--success);border:1px solid rgba(34,197,94,0.3);"'
          + ' title="Lihat API URL" onclick="gowaShowApiUrl(\'' + id + '\')">'
          + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">'
          + '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
          + '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>'
          + ' API</button>'
        : '';

    return '<tr id="row-' + gowaEscapeAttr(id) + '">'
        + '<td><div style="width:36px;height:36px;border-radius:8px;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:0.875rem;">' + initial + '</div></td>'
        + '<td><div style="font-weight:500;color:var(--text-primary);">' + label + '</div>'
        + ownerLine
        + '</td>'
        + '<td>' + ownerCell + '</td>'
        + '<td><span style="font-size:0.875rem;">' + phone + '</span></td>'
        + '<td><div class="db-isolation-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>' + dbName + '</div></td>'
        + '<td>' + statusToBadge(status) + '</td>'
        + '<td style="font-size:0.8125rem;color:var(--text-secondary);">' + lastConnStr + '</td>'
        + '<td><div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">'
        + primaryBtn + testBtn + apiBtn
        + '<button class="btn" style="padding:0.4rem 0.625rem;font-size:0.8125rem;color:var(--danger);border:1px solid rgba(239,68,68,0.3);"'
        + ' title="Hapus device" onclick="gowaOpenDeleteModal(\'' + id + '\',\'' + dbId + '\',\'' + gowaEscapeAttr(label) + '\')">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>'
        + '</button></div></td></tr>';
}

function gowaFriendlyLabel(label, deviceId) {
    const normalized = String(label || '').trim();
    const id = String(deviceId || '').trim();
    const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

    if (!normalized) {
        return id ? ('Device ' + id.slice(0, 8)) : 'Unknown';
    }

    if (uuidRegex.test(normalized) || (id && normalized.toLowerCase() === id.toLowerCase())) {
        return id ? ('Device ' + id.slice(0, 8)) : 'Device';
    }

    return normalized;
}

function statusToBadge(status) {
    const map = {
        connected:    '<span class="badge badge-green">\u25cf Terhubung</span>',
        disconnected: '<span class="badge badge-red">\u25cf Terputus</span>',
        pending:      '<span class="badge badge-orange">\u25cf Menunggu QR</span>',
        qr_ready:     '<span class="badge badge-orange">\u25cf QR Siap</span>',
        error:        '<span class="badge badge-red">\u25cf Error</span>',
        unknown:      '<span class="badge">\u25cb Tidak Diketahui</span>',
    };
    return map[status] || ('<span class="badge">' + gowaEscapeHtml(status) + '</span>');
}

function updateStats(devices) {
    setText('stat-total',        devices.length);
    setText('stat-connected',    devices.filter(d => d.status === 'connected').length);
    setText('stat-disconnected', devices.filter(d => ['disconnected','error'].includes(d.status)).length);
    setText('stat-pending',      devices.filter(d => ['pending','qr_ready','unknown'].includes(d.status)).length);
}

// ─────────────────────────────────────────────────────
// CREATE DEVICE MODAL
// ─────────────────────────────────────────────────────
function gowaOpenCreateModal() {
    const labelEl   = document.getElementById('create-device-label');
    const webhookEl = document.getElementById('create-device-webhook');
    if (labelEl)   labelEl.value = '';
    if (webhookEl) webhookEl.value = '';
    hideEl('create-error');
    const btn = document.getElementById('btn-create-device');
    if (btn) { btn.disabled = false; btn.textContent = 'Buat Device'; }
    showModal('modal-create');
}

function gowaCloseCreateModal(e) {
    if (e && e.target.id !== 'modal-create') return;
    closeModal('modal-create');
}

async function gowaCreateDevice() {
    const labelEl   = document.getElementById('create-device-label');
    const webhookEl = document.getElementById('create-device-webhook');
    const btn       = document.getElementById('btn-create-device');
    hideEl('create-error');
    const label   = labelEl   ? labelEl.value.trim()   : '';
    const webhook = webhookEl ? webhookEl.value.trim() : '';
    if (!label) { showCreateError('Nama / label device wajib diisi.'); if (labelEl) labelEl.focus(); return; }
    if (btn) { btn.disabled = true; btn.textContent = 'Membuat...'; }
    try {
        const res = await apiFetch('POST', {}, { label, webhook });
        closeModal('modal-create');
        showToastMsg('Device "' + gowaEscapeHtml(label) + '" berhasil dibuat', 'success');
        if (res.api_url) showApiUrlModal(res.device_id, label, res.secret, res.api_url);
        await gowaLoadDevices();
        if (res.device_id) setTimeout(() => gowaOpenQRModal(res.device_id, label), res.api_url ? 200 : 400);
    } catch (err) {
        showCreateError(err.message || 'Gagal membuat device. Periksa konfigurasi di api/config.php');
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Buat Device'; }
    }
}

// ─────────────────────────────────────────────────────
// API URL MODAL
// ─────────────────────────────────────────────────────
function showApiUrlModal(deviceId, label, secret, apiUrl) {
    const modal = document.getElementById('modal-apiurl');
    if (!modal) { alert('API URL:\n\n' + decodeURIComponent(apiUrl)); return; }
    setText('apiurl-device-name', label);
    setText('apiurl-secret', secret);
    const urlEl = document.getElementById('apiurl-url');
    if (urlEl) urlEl.textContent = decodeURIComponent(apiUrl);
    const copyBtn = document.getElementById('btn-copy-apiurl');
    if (copyBtn) {
        copyBtn.onclick = () => {
            navigator.clipboard.writeText(decodeURIComponent(apiUrl))
                .then(() => { copyBtn.textContent = 'Tersalin!'; setTimeout(() => { copyBtn.textContent = 'Salin URL'; }, 2000); })
                .catch(() => {});
        };
    }
    showModal('modal-apiurl');
}

function gowaShowApiUrl(deviceId) {
    const d = _devices.find(x => x.device_id === deviceId);
    if (!d || !d.api_url) { showToastMsg('API URL tidak tersedia', 'warning'); return; }
    showApiUrlModal(d.device_id, d.label, d.secret, d.api_url);
}

function gowaCloseApiUrlModal(e) {
    if (e && e.target.id !== 'modal-apiurl') return;
    closeModal('modal-apiurl');
}

// ─────────────────────────────────────────────────────
// QR CODE MODAL — proxied via PHP (api/wa-account.php?action=qr)
// ─────────────────────────────────────────────────────
function gowaOpenQRModal(deviceId, label) {
    _qrDeviceId    = deviceId;
    _qrDeviceLabel = label;
    setText('qr-device-name', label || deviceId);
    setQRStatus('loading');
    hideEl('qr-success');
    showEl('qr-instructions');
    showModal('modal-qr');
    loadQRContent(deviceId);
    _statusPollTimer = setInterval(() => pollDeviceStatus(deviceId), 4000);
}

function loadQRContent(deviceId) {
    const frame = document.getElementById('qr-iframe');
    const img   = document.getElementById('qr-img');
    const proxyUrl = API + '?action=qr&device_id=' + encodeURIComponent(deviceId) + '&_t=' + Date.now();
    setQRStatus('loading');
    // Always use image proxy to avoid browser/frame blocking from external origins.
    if (img) {
        if (frame) { frame.src = 'about:blank'; frame.style.display = 'none'; }
        img.style.display = 'block';
        img.src = proxyUrl;
        img.onload  = () => setQRStatus('scanning');
        img.onerror = () => setQRStatus('error');
    } else if (frame) {
        frame.src = 'about:blank';
        frame.style.display = 'none';
        setQRStatus('error');
    }
}

function gowaCloseQRModal(e) {
    if (e && e.target.id !== 'modal-qr') return;
    gowaStopPolling();
    _qrDeviceId = null;
    closeModal('modal-qr');
    const frame = document.getElementById('qr-iframe');
    if (frame) { frame.src = 'about:blank'; frame.style.display = 'none'; }
    const img = document.getElementById('qr-img');
    if (img) { img.src = ''; img.style.display = 'none'; }
    gowaLoadDevices();
}

function gowaRefreshQR() {
    if (!_qrDeviceId) return;
    loadQRContent(_qrDeviceId);
}

async function pollDeviceStatus(deviceId) {
    if (!deviceId) return;
    try {
        const res = await apiFetch('GET', { action: 'status', device_id: deviceId });
        if (res.status === 'connected') {
            gowaStopPolling();
            onDeviceConnected(res.data);
        }
    } catch (_) { /* silent retry */ }
}

function onDeviceConnected(data) {
    setQRStatus('connected');
    const frame = document.getElementById('qr-iframe');
    const img   = document.getElementById('qr-img');
    if (frame) frame.style.display = 'none';
    if (img)   img.style.display   = 'none';
    hideEl('qr-instructions');
    showEl('qr-success');
    const phone = (data && (data.phone || data.jid)) || '';
    if (phone) {
        const el = document.getElementById('qr-success');
        if (el) el.innerHTML += '<p style="font-size:0.8125rem;color:var(--text-secondary);margin-top:0.25rem;">Nomor: ' + gowaEscapeHtml(phone) + '</p>';
    }
    showToastMsg('Device berhasil terhubung!', 'success');
    setTimeout(() => gowaLoadDevices(), 1500);
}

function gowaStopPolling() {
    clearInterval(_statusPollTimer);
    _statusPollTimer = null;
}

function setQRStatus(type) {
    const banner = document.getElementById('qr-status-banner');
    const text   = document.getElementById('qr-status-text');
    if (!banner || !text) return;
    banner.className = 'qr-status-banner';
    const map = {
        loading:   ['qr-status-waiting',  'Mengambil QR Code...'],
        scanning:  ['qr-status-scanning', 'Arahkan kamera WhatsApp ke QR di bawah'],
        connected: ['qr-status-connected','Terhubung ke WhatsApp!'],
        error:     ['qr-status-waiting',  'Gagal mengambil QR — coba Refresh'],
    };
    const [cls, msg] = map[type] || ['qr-status-waiting', '...'];
    banner.classList.add(cls);
    text.textContent = msg;
}

// ─────────────────────────────────────────────────────
// DISCONNECT DEVICE
// ─────────────────────────────────────────────────────
async function gowaDisconnectDevice(deviceId, label) {
    if (!confirm('Putuskan koneksi WhatsApp device "' + label + '"?')) return;
    try {
        await apiFetch('POST', { action: 'disconnect', device_id: deviceId });
        showToastMsg('Device "' + gowaEscapeHtml(label) + '" diputus', 'warning');
        await gowaLoadDevices();
    } catch (err) {
        showToastMsg('Gagal memutuskan: ' + err.message, 'error');
    }
}

// ─────────────────────────────────────────────────────
// DELETE DEVICE MODAL
// ─────────────────────────────────────────────────────
function gowaOpenDeleteModal(deviceId, dbId, label) {
    _pendingDeleteId = { deviceId, dbId };
    setText('delete-device-name', label || deviceId);
    showModal('modal-delete');
}

function gowaCloseDeleteModal(e) {
    if (e && e.target.id !== 'modal-delete') return;
    _pendingDeleteId = null;
    closeModal('modal-delete');
}

async function gowaConfirmDelete() {
    if (!_pendingDeleteId) return;
    const { deviceId, dbId } = _pendingDeleteId;
    const btn = document.getElementById('btn-confirm-delete');
    if (btn) btn.disabled = true;
    try {
        await apiFetch('DELETE', { device_id: deviceId, id: dbId });
        showToastMsg('Device berhasil dihapus', 'success');
        closeModal('modal-delete');
        _pendingDeleteId = null;
        await gowaLoadDevices();
    } catch (err) {
        showToastMsg('Gagal menghapus: ' + err.message, 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// ─────────────────────────────────────────────────────
// TEST WA MODAL
// ─────────────────────────────────────────────────────
let _testDeviceId = null;

function gowaOpenTestModal(deviceId, label) {
    _testDeviceId = deviceId;
    setText('test-device-name', label);
    const phoneEl = document.getElementById('test-phone');
    const msgEl   = document.getElementById('test-message');
    const resEl   = document.getElementById('test-result');
    if (phoneEl) phoneEl.value = '';
    if (msgEl)   msgEl.value  = '';
    if (resEl)   resEl.style.display = 'none';
    showModal('modal-test-wa');
}

function gowaCloseTestModal(e) {
    if (e && e.target.id !== 'modal-test-wa') return;
    _testDeviceId = null;
    closeModal('modal-test-wa');
}

async function gowaSendTestMessage() {
    const phoneEl = document.getElementById('test-phone');
    const msgEl   = document.getElementById('test-message');
    const resEl   = document.getElementById('test-result');
    const btn     = document.getElementById('btn-send-test');
    const phone   = (phoneEl?.value || '').trim();
    const message = (msgEl?.value   || '').trim();
    if (!phone || !message) {
        showToastMsg('Nomor dan pesan wajib diisi', 'error');
        return;
    }
    if (!_testDeviceId) return;
    if (btn) { btn.disabled = true; btn.textContent = 'Mengirim...'; }
    if (resEl) resEl.style.display = 'none';
    try {
        const params = new URLSearchParams({ action: 'test_send', device_id: _testDeviceId, phone, message });
        const resp = await fetch(API + '?' + params.toString(), { credentials: 'same-origin' });
        const json = await resp.json();
        if (resEl) {
            resEl.style.display = '';
            if (json.status === 'success') {
                resEl.innerHTML = '<div style="color:var(--success);font-size:0.875rem;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:8px;padding:0.75rem 1rem;">'
                    + '<strong>&#10003; Pesan berhasil dikirim!</strong></div>';
            } else {
                const errMsg = gowaEscapeHtml(json.error || 'Gagal mengirim pesan');
                resEl.innerHTML = '<div style="color:var(--danger);font-size:0.875rem;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.75rem 1rem;">'
                    + '<strong>&#10007; ' + errMsg + '</strong></div>';
            }
        }
    } catch (err) {
        if (resEl) {
            resEl.style.display = '';
            resEl.innerHTML = '<div style="color:var(--danger);font-size:0.875rem;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.75rem 1rem;">'
                + '<strong>&#10007; ' + gowaEscapeHtml(err.message) + '</strong></div>';
        }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Kirim'; }
    }
}

// ─────────────────────────────────────────────────────
// TOAST NOTIFICATION
// ─────────────────────────────────────────────────────
function showToastMsg(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.innerHTML = '<span>' + gowaEscapeHtml(message) + '</span>';
    container.appendChild(toast);
    toast.getBoundingClientRect();
    toast.classList.add('toast-show');
    setTimeout(() => {
        toast.classList.remove('toast-show');
        toast.classList.add('toast-hide');
        setTimeout(() => toast.remove(), 400);
    }, 3500);
}

// ─────────────────────────────────────────────────────
// DOM HELPERS
// ─────────────────────────────────────────────────────
function showModal(id)  { const el = document.getElementById(id); if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; } }
function closeModal(id) { const el = document.getElementById(id); if (el) { el.style.display = 'none';  document.body.style.overflow = ''; } }
function showEl(id)     { const el = document.getElementById(id); if (el) el.style.display = ''; }
function hideEl(id)     { const el = document.getElementById(id); if (el) el.style.display = 'none'; }
function setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = v; }
function setDeviceLoading(on) { const el = document.getElementById('device-loading'); if (el) el.style.display = on ? '' : 'none'; }
function showDeviceError(msg) { const w = document.getElementById('device-error'), m = document.getElementById('device-error-msg'); if (w) w.style.display = ''; if (m) m.textContent = msg; }
function hideDeviceError()    { const el = document.getElementById('device-error'); if (el) el.style.display = 'none'; }
function showCreateError(msg) { showEl('create-error'); setText('create-error-msg', msg); }

// ─────────────────────────────────────────────────────
// SANITIZERS (XSS prevention)
// ─────────────────────────────────────────────────────
function gowaEscapeHtml(str) {
    if (str === undefined || str === null) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#x27;');
}
function gowaEscapeAttr(str) { return gowaEscapeHtml(str).replace(/\//g, '&#x2F;'); }

// ─────────────────────────────────────────────────────
// INIT
// ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const labelInput = document.getElementById('create-device-label');
    if (labelInput) labelInput.addEventListener('keydown', e => { if (e.key === 'Enter') gowaCreateDevice(); });
    const searchInput = document.getElementById('device-search-input');
    const ownerSelect = document.getElementById('device-filter-owner');
    const statusSelect = document.getElementById('device-filter-status');
    if (searchInput && _pageMode === 'admin') {
        searchInput.addEventListener('input', e => {
            _deviceSearchTerm = String(e.target.value || '');
            applyDeviceSearch();
        });
    }
    if (ownerSelect && _pageMode === 'admin') {
        ownerSelect.addEventListener('change', e => {
            _deviceOwnerFilter = String(e.target.value || 'all');
            applyDeviceSearch();
        });
    }
    if (statusSelect && _pageMode === 'admin') {
        statusSelect.addEventListener('change', e => {
            _deviceStatusFilter = String(e.target.value || 'all');
            applyDeviceSearch();
        });
    }
    window.addEventListener('beforeunload', gowaStopPolling);
    gowaLoadDevices();
});
