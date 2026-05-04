/* Proxy Routes Management — modal UI */
(function () {
    'use strict';

    var apiUrl = window.VPN_API || 'api/vpn-controller.php';

    /* ------------------------------------------------------------------ */
    /* DOM helpers                                                          */
    /* ------------------------------------------------------------------ */

    function byId(id) { return document.getElementById(id); }
    function val(id) { var el = byId(id); return el ? String(el.value || '').trim() : ''; }
    function setVal(id, v) { var el = byId(id); if (el) el.value = v || ''; }
    function setDisabled(id, d) { var el = byId(id); if (el) el.disabled = !!d; }

    function escapeHtml(v) {
        return String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ------------------------------------------------------------------ */
    /* Alert helpers                                                        */
    /* ------------------------------------------------------------------ */

    function setAlert(type, msg) {
        var box = byId('proxy-alert');
        if (!box) return;
        box.style.display = 'block';
        box.className = 'admin-alert ' + (type === 'error' ? 'error' : 'success');
        box.textContent = msg || '';
        clearTimeout(box._t);
        if (type !== 'error') box._t = setTimeout(function () { box.style.display = 'none'; }, 5000);
    }

    function setModalAlert(modalId, type, msg) {
        var box = byId(modalId + '-alert');
        if (!box) return;
        box.style.display = 'block';
        box.className = 'admin-alert ' + (type === 'error' ? 'error' : 'success');
        box.textContent = msg || '';
    }

    function clearModalAlert(modalId) {
        var box = byId(modalId + '-alert');
        if (box) { box.style.display = 'none'; box.textContent = ''; }
    }

    /* ------------------------------------------------------------------ */
    /* Modal helpers                                                        */
    /* ------------------------------------------------------------------ */

    window.vpnOpenModal = window.vpnOpenModal || function (id) {
        var el = byId(id); if (el) el.classList.add('open');
    };
    window.vpnCloseModal = window.vpnCloseModal || function (id) {
        var el = byId(id); if (el) el.classList.remove('open');
    };
    window.proxyBackdropClose = function (e, id) {
        if (e.target === byId(id)) window.vpnCloseModal(id);
    };

    /* ------------------------------------------------------------------ */
    /* API request                                                          */
    /* ------------------------------------------------------------------ */

    async function request(action, data) {
        var res = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: action }, data || {}))
        });
        var json = null;
        try { json = await res.json(); } catch (e) { throw new Error('Respons server tidak valid JSON.'); }
        if (!res.ok) {
            var msg = (json && (json.error || json.message)) ? (json.error || json.message) : ('HTTP ' + res.status);
            throw new Error(msg);
        }
        return json;
    }

    /* ------------------------------------------------------------------ */
    /* State                                                                */
    /* ------------------------------------------------------------------ */

    var cachedRoutes = [];
    var cachedPfs = [];
    var pendingDeleteName = null;
    var proxySearch = '';
    var proxyOwnerFilter = 'all';
    var subdomainCheckTimer = null;
    var createProgressTimer = null;
    var sslStatusMap = {};
    var sslDetailMap = {};

    /* ------------------------------------------------------------------ */
    /* Load & render                                                        */
    /* ------------------------------------------------------------------ */

    window.proxyLoadRoutes = async function () {
        var tbody = byId('proxy-tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-secondary);">Memuat data…</td></tr>';

        try {
            var res = await request('list_proxy_routes');
            cachedRoutes = (res.data && res.data.items) ? res.data.items : [];
            refreshSslStatusMap();
            rebuildOwnerFilter();
            proxyRenderTable();
            startSslStatusChecks();
        } catch (e) {
            if (tbody) tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--danger);">Gagal memuat data: ' + escapeHtml(e.message) + '</td></tr>';
        }
    };

    function rebuildOwnerFilter() {
        var sel = byId('proxy-owner-filter');
        if (!sel) return;
        var current = sel.value;
        var owners = {};
        cachedRoutes.forEach(function (r) {
            if (r.owner_user_id) owners[r.owner_user_id] = r.owner_name || r.owner_email || ('User #' + r.owner_user_id);
        });
        var html = '<option value="all">Semua Owner</option>';
        Object.keys(owners).forEach(function (id) {
            html += '<option value="' + escapeHtml(id) + '">' + escapeHtml(owners[id]) + '</option>';
        });
        sel.innerHTML = html;
        if (current) sel.value = current;
    }

    function refreshSslStatusMap() {
        var nextMap = {};
        cachedRoutes.forEach(function (r) {
            var key = String(r.name || '');
            if (!r.ssl_enabled) {
                nextMap[key] = 'off';
                return;
            }
            nextMap[key] = sslStatusMap[key] || 'pending';
        });
        sslStatusMap = nextMap;
    }

    async function startSslStatusChecks() {
        var checks = cachedRoutes.map(function (r) {
            return checkRouteSslStatus(r);
        });
        await Promise.all(checks);
    }

    async function checkRouteSslStatus(route) {
        if (!route || !route.ssl_enabled) {
            return;
        }
        var name = String(route.name || '');
        sslStatusMap[name] = 'pending';
        proxyRenderTable();
        var domain = String(route.domain || '').trim();
        if (!name || !domain) {
            sslStatusMap[name] = 'error';
            proxyRenderTable();
            return;
        }

        // Use no-cors probe to quickly detect reachability of HTTPS endpoint.
        try {
            await fetch('https://' + domain + '/?ssl_probe=1', {
                method: 'GET',
                mode: 'no-cors',
                cache: 'no-store'
            });
            sslStatusMap[name] = 'ready';
            delete sslDetailMap[name];
        } catch (e) {
            sslStatusMap[name] = 'error';
            sslDetailMap[name] = e && e.message ? String(e.message) : 'Gagal terhubung ke HTTPS endpoint.';
        }
        proxyRenderTable();
    }

    function getSslStatus(route) {
        if (!route || !route.ssl_enabled) {
            return { cls: 'off', text: 'OFF', detail: '' };
        }
        var key = String(route.name || '');
        var status = sslStatusMap[key] || 'pending';
        if (status === 'ready') {
            return { cls: 'ready', text: 'READY', detail: '' };
        }
        if (status === 'error') {
            return { cls: 'error', text: 'ERROR', detail: sslDetailMap[key] || 'Gagal terhubung ke HTTPS endpoint.' };
        }
        return { cls: 'pending', text: 'PENDING', detail: 'Menunggu verifikasi SSL...' };
    }

    function proxyRenderTable() {
        var tbody = byId('proxy-tbody');
        var countEl = byId('proxy-count');
        if (!tbody) return;

        var search = proxySearch.toLowerCase();
        var filtered = cachedRoutes.filter(function (r) {
            if (proxyOwnerFilter !== 'all' && String(r.owner_user_id) !== proxyOwnerFilter) return false;
            if (search) {
                return (r.name || '').toLowerCase().includes(search)
                    || (r.subdomain || '').toLowerCase().includes(search)
                    || (r.domain || '').toLowerCase().includes(search)
                    || (r.port_forward_name || '').toLowerCase().includes(search);
            }
            return true;
        });

        if (countEl) countEl.textContent = 'Menampilkan ' + filtered.length + ' dari ' + cachedRoutes.length + ' route';

        if (!filtered.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-secondary);">Tidak ada data.</td></tr>';
            return;
        }

        var html = '';
        filtered.forEach(function (r) {
            var ssl = getSslStatus(r);
            var sslTitle = ssl.detail ? ' title="' + escapeHtml(ssl.detail) + '" style="cursor:help;"' : '';
            var sslBadge = '<span class="badge ssl-status ' + ssl.cls + '"' + sslTitle + '>' + ssl.text + '</span>';
            var domainLink = r.domain
                ? '<a href="https://' + escapeHtml(r.domain) + '" target="_blank" rel="noopener" style="color:var(--primary);font-size:.78rem;">' + escapeHtml(r.domain) + '</a>'
                : '—';
            var ownerText = r.owner_name ? escapeHtml(r.owner_name) : (r.owner_email ? escapeHtml(r.owner_email) : '—');
            var created = r.created_at ? r.created_at.substring(0, 16).replace('T', ' ') : '—';

            html += '<tr>'
                + '<td><code style="font-size:.8rem;">' + escapeHtml(r.name) + '</code></td>'
                + '<td><div style="font-weight:600;font-size:.85rem;">' + escapeHtml(r.subdomain) + '</div><div style="font-size:.75rem;">' + domainLink + '</div></td>'
                + '<td><code style="font-size:.75rem;">' + escapeHtml(r.port_forward_name) + '</code></td>'
                + '<td style="font-size:.8rem;">' + escapeHtml(r.upstream_ip) + ':' + escapeHtml(String(r.upstream_port)) + '</td>'
                + '<td>' + sslBadge + '</td>'
                + '<td style="font-size:.8rem;">' + ownerText + '</td>'
                + '<td style="font-size:.78rem;white-space:nowrap;">' + escapeHtml(created) + '</td>'
                + '<td><div class="table-actions">'
                + '<button class="btn-action info" onclick="proxyOpenDomain(\'' + escapeHtml(r.domain || '').replace(/'/g, "\\'") + '\')">Open</button>'
                + '<button class="btn-action success" onclick="proxyCopyDomain(\'' + escapeHtml(r.domain || '').replace(/'/g, "\\'") + '\')">Copy</button>'
                + '<button class="btn-action danger" onclick="proxyOpenDeleteModal(\'' + escapeHtml(r.name).replace(/'/g, "\\'") + '\')">Hapus</button>'
                + '</div></td>'
                + '</tr>';
        });

        tbody.innerHTML = html;
    }

    window.proxyApplyFilters = function () {
        proxySearch = val('proxy-search');
        proxyOwnerFilter = val('proxy-owner-filter') || 'all';
        proxyRenderTable();
    };

    /* ------------------------------------------------------------------ */
    /* Load port forwardings for dropdown                                   */
    /* ------------------------------------------------------------------ */

    async function loadPortForwardings() {
        try {
            var res = await request('list_port_forwardings');
            cachedPfs = (res.data && res.data.items) ? res.data.items : [];
        } catch (e) {
            cachedPfs = [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Create route                                                         */
    /* ------------------------------------------------------------------ */

    window.proxyOpenCreateModal = async function () {
        clearModalAlert('modal-proxy-create');
        setVal('proxy-create-name', '');
        setVal('proxy-create-subdomain', '');
        proxyClearCheck();
        proxyStopCreateProgress();

        // Load port forwardings into select
        await loadPortForwardings();
        var sel = byId('proxy-create-pf');
        if (sel) {
            var html = '<option value="">— pilih port forwarding —</option>';
            cachedPfs.forEach(function (pf) {
                html += '<option value="' + escapeHtml(pf.name) + '">'
                    + escapeHtml(pf.name) + ' (' + escapeHtml(pf.destination_ip) + ':' + escapeHtml(String(pf.destination_port)) + ')'
                    + '</option>';
            });
            sel.innerHTML = html;
        }

        setDisabled('proxy-create-btn', false);
        window.vpnOpenModal('modal-proxy-create');
    };

    window.proxyCreateRoute = async function () {
        var name = val('proxy-create-name');
        var subdomain = val('proxy-create-subdomain');
        var pf = val('proxy-create-pf');

        if (!name || !subdomain || !pf) {
            setModalAlert('modal-proxy-create', 'error', 'Semua field wajib diisi.');
            return;
        }

        setDisabled('proxy-create-btn', true);
        clearModalAlert('modal-proxy-create');
        proxyStartCreateProgress();

        try {
            var result = await request('create_proxy_route', { name: name, subdomain: subdomain, port_forward_name: pf });
            var domain = result && result.data ? (result.data.domain || (subdomain + '')) : (subdomain + '');
            proxySetCreateProgress('Sinkronisasi akhir, verifikasi route...');
            await proxyPollProvisioning(name, subdomain);
            window.vpnCloseModal('modal-proxy-create');
            setAlert('success', 'Proxy route "' + name + '" berhasil dibuat. Domain: ' + domain);
            await proxyLoadRoutes();
        } catch (e) {
            setModalAlert('modal-proxy-create', 'error', e.message);
        } finally {
            proxyStopCreateProgress();
            setDisabled('proxy-create-btn', false);
        }
    };

    /* ------------------------------------------------------------------ */
    /* Subdomain availability check                                         */
    /* ------------------------------------------------------------------ */

    window.proxyClearCheck = function () {
        if (subdomainCheckTimer) {
            clearTimeout(subdomainCheckTimer);
            subdomainCheckTimer = null;
        }
        var el = byId('proxy-check-result');
        if (el) { el.style.display = 'none'; el.textContent = ''; el.className = 'check-result'; }
    };

    window.proxyOnSubdomainInput = function () {
        proxyClearCheck();
        var subdomain = val('proxy-create-subdomain');
        if (!subdomain || subdomain.length < 2) {
            return;
        }
        subdomainCheckTimer = setTimeout(function () {
            proxyCheckSubdomain();
        }, 450);
    };

    window.proxyCheckSubdomain = async function () {
        var subdomain = val('proxy-create-subdomain');
        var el = byId('proxy-check-result');
        if (!el) return;

        if (!subdomain) {
            el.style.display = 'block';
            el.className = 'check-result taken';
            el.textContent = 'Masukkan subdomain terlebih dahulu.';
            return;
        }

        el.style.display = 'block';
        el.className = 'check-result checking';
        el.textContent = 'Mengecek ketersediaan…';

        try {
            var res = await request('check_subdomain', { subdomain: subdomain });
            var data = res.data || {};
            if (data.available) {
                el.className = 'check-result avail';
                el.textContent = '✓ ' + (data.domain || subdomain) + ' tersedia.';
            } else {
                el.className = 'check-result taken';
                el.textContent = '✗ ' + (data.domain || subdomain) + ' sudah digunakan.' + (data.reason ? ' (' + data.reason + ')' : '');
            }
        } catch (e) {
            el.className = 'check-result taken';
            el.textContent = 'Gagal cek: ' + e.message;
        }
    };

    function proxyStartCreateProgress() {
        var steps = [
            'Validasi data input...',
            'Mengecek ketersediaan subdomain...',
            'Menyiapkan konfigurasi DNS dan Apache...',
            'Memproses sertifikat SSL Let\'s Encrypt...',
            'Menyimpan route ke database...'
        ];
        var index = 0;
        proxySetCreateProgress(steps[index]);
        createProgressTimer = setInterval(function () {
            index = (index + 1) % steps.length;
            proxySetCreateProgress(steps[index]);
        }, 1400);
    }

    function proxyStopCreateProgress() {
        if (createProgressTimer) {
            clearInterval(createProgressTimer);
            createProgressTimer = null;
        }
        var el = byId('proxy-create-progress');
        if (el) {
            el.className = 'create-progress';
            el.textContent = '';
        }
    }

    function proxySetCreateProgress(text) {
        var el = byId('proxy-create-progress');
        if (!el) return;
        el.className = 'create-progress active';
        el.innerHTML = '<span class="dot">...</span> ' + escapeHtml(text || 'Memproses...');
    }

    async function proxyPollProvisioning(name, subdomain) {
        var attempts = 4;
        for (var i = 0; i < attempts; i++) {
            try {
                var listRes = await request('list_proxy_routes');
                var items = (listRes.data && listRes.data.items) ? listRes.data.items : [];
                var found = items.some(function (r) { return String(r.name || '') === String(name); });
                if (found) {
                    proxySetCreateProgress('Route terdaftar. Verifikasi DNS...');
                    await request('check_subdomain', { subdomain: subdomain });
                    return;
                }
            } catch (e) {
                // Keep polling quietly; final create response already succeeded.
            }
            proxySetCreateProgress('Menunggu sinkronisasi route (' + (i + 1) + '/' + attempts + ')...');
            await new Promise(function (resolve) { setTimeout(resolve, 900); });
        }
    }

    window.proxyOpenDomain = function (domain) {
        if (!domain) {
            setAlert('error', 'Domain tidak tersedia.');
            return;
        }
        window.open('https://' + domain, '_blank', 'noopener');
    };

    window.proxyCopyDomain = async function (domain) {
        if (!domain) {
            setAlert('error', 'Domain tidak tersedia.');
            return;
        }
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(domain);
            } else {
                var tmp = document.createElement('input');
                tmp.value = domain;
                document.body.appendChild(tmp);
                tmp.select();
                document.execCommand('copy');
                document.body.removeChild(tmp);
            }
            setAlert('success', 'Domain disalin: ' + domain);
        } catch (e) {
            setAlert('error', 'Gagal menyalin domain.');
        }
    };

    /* ------------------------------------------------------------------ */
    /* Delete route                                                         */
    /* ------------------------------------------------------------------ */

    window.proxyOpenDeleteModal = function (name) {
        pendingDeleteName = name;
        clearModalAlert('modal-proxy-delete');
        var txt = byId('proxy-delete-confirm-text');
        if (txt) txt.textContent = 'Apakah Anda yakin ingin menghapus proxy route "' + name + '"? SSL certificate dan DNS record akan ikut dihapus.';
        setDisabled('proxy-delete-btn', false);
        window.vpnOpenModal('modal-proxy-delete');
    };

    window.proxyConfirmDelete = async function () {
        if (!pendingDeleteName) return;
        var name = pendingDeleteName;
        setDisabled('proxy-delete-btn', true);
        clearModalAlert('modal-proxy-delete');

        try {
            await request('delete_proxy_route', { name: name });
            window.vpnCloseModal('modal-proxy-delete');
            setAlert('success', 'Proxy route "' + name + '" berhasil dihapus.');
            pendingDeleteName = null;
            await proxyLoadRoutes();
        } catch (e) {
            setModalAlert('modal-proxy-delete', 'error', e.message);
        } finally {
            setDisabled('proxy-delete-btn', false);
        }
    };

    /* ------------------------------------------------------------------ */
    /* Init                                                                 */
    /* ------------------------------------------------------------------ */

    document.addEventListener('DOMContentLoaded', function () {
        proxyLoadRoutes();
    });

}());
