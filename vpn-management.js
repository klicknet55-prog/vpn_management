/* VPN Management — modal-based UI + sync */
(function () {
    'use strict';

    var apiUrl = window.VPN_API || 'api/vpn-controller.php';
    var pageMode = window.VPN_PAGE_MODE || 'user';

    // pending confirm action: { action, username }
    var pendingUserAction = null;
    // pending pf delete: name string
    var pendingPfName = null;
    var adminUserSearch = '';
    var adminPfSearch = '';
    var adminUserOwnerFilter = 'all';
    var adminUserStatusFilter = 'all';
    var adminPfOwnerFilter = 'all';
    var adminPfProtocolFilter = 'all';
    var cachedUsers = [];
    var cachedPfs = [];
    var pfDetailCache = [];

    /* ------------------------------------------------------------------ */
    /* DOM helpers                                                          */
    /* ------------------------------------------------------------------ */

    function byId(id) { return document.getElementById(id); }
    function val(id) { var el = byId(id); return el ? String(el.value || '').trim() : ''; }
    function setVal(id, v) { var el = byId(id); if (el) el.value = v || ''; }
    function setDisabled(id, disabled) { var el = byId(id); if (el) el.disabled = !!disabled; }

    function escapeHtml(v) {
        return String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeJs(v) {
        return String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    /* ------------------------------------------------------------------ */
    /* Alert helpers                                                        */
    /* ------------------------------------------------------------------ */

    function setAlert(type, message) {
        var box = byId('vpn-alert');
        if (!box) return;
        box.style.display = 'block';
        box.className = 'admin-alert ' + (type === 'error' ? 'error' : 'success');
        box.textContent = message || (type === 'error' ? 'Terjadi kesalahan.' : 'Berhasil.');
        clearTimeout(box._timer);
        if (type !== 'error') {
            box._timer = setTimeout(function () { box.style.display = 'none'; }, 5000);
        }
    }

    function setModalAlert(modalId, type, message) {
        var box = byId(modalId + '-alert');
        if (!box) return;
        box.style.display = 'block';
        box.className = 'admin-alert ' + (type === 'error' ? 'error' : 'success');
        box.textContent = message || '';
    }

    function clearModalAlert(modalId) {
        var box = byId(modalId + '-alert');
        if (box) { box.style.display = 'none'; box.textContent = ''; }
    }

    /* ------------------------------------------------------------------ */
    /* Modal helpers                                                        */
    /* ------------------------------------------------------------------ */

    window.vpnOpenModal = function (id) {
        var el = byId(id);
        if (el) el.classList.add('open');
    };
    window.vpnCloseModal = function (id) {
        var el = byId(id);
        if (el) el.classList.remove('open');
    };
    window.vpnBackdropClose = function (e, id) {
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
    /* Render tables                                                        */
    /* ------------------------------------------------------------------ */

    function statusBadge(status) {
        var cls = status === 'active' ? 'active' : (status === 'suspended' ? 'suspended' : 'expired');
        return '<span class="badge ' + cls + '">' + escapeHtml(status) + '</span>';
    }

    function textContains(haystack, needle) {
        return String(haystack || '').toLowerCase().indexOf(String(needle || '').toLowerCase()) !== -1;
    }

    function ownerPassesFilter(row, filterValue) {
        var f = String(filterValue || 'all').toLowerCase();
        if (f === 'all') return true;
        var ownerName = String((row && row.owner_name) || '').trim();
        var ownerEmail = String((row && row.owner_email) || '').trim().toLowerCase();
        var sessionEmail = String(((window.DAYNIGHT_SESSION || {}).email) || '').trim().toLowerCase();
        if (f === 'mine') {
            if (sessionEmail !== '' && ownerEmail !== '') return ownerEmail === sessionEmail;
            return ownerName === '' || ownerName.toLowerCase() === 'me' || ownerName.toLowerCase() === 'saya';
        }
        if (f === 'others') {
            if (sessionEmail !== '' && ownerEmail !== '') return ownerEmail !== sessionEmail;
            return !(ownerName === '' || ownerName.toLowerCase() === 'me' || ownerName.toLowerCase() === 'saya');
        }
        return true;
    }

    function renderSummaryStats(userItems, pfItems) {
        var users = Array.isArray(userItems) ? userItems : [];
        var pfs = Array.isArray(pfItems) ? pfItems : [];
        var active = 0;
        var suspended = 0;

        users.forEach(function (row) {
            var st = String((row && row.vpn_status) || 'active').toLowerCase();
            if (st === 'suspended') suspended++;
            else active++;
        });

        var setText = function (id, v) {
            var el = byId(id);
            if (el) el.textContent = String(v);
        };

        setText('vpn-stat-total-users', users.length);
        setText('vpn-stat-active-users', active);
        setText('vpn-stat-suspended-users', suspended);
        setText('vpn-stat-total-pf', pfs.length);
    }

    function renderUsers(items) {
        var tbody = byId('vpn-users-body');
        var countEl = byId('vpn-users-count');
        if (!tbody) return;
        var list = Array.isArray(items) ? items.slice() : [];
        if (pageMode === 'admin') {
            list = list.filter(function (row) {
                if (!ownerPassesFilter(row, adminUserOwnerFilter)) return false;
                var status = String((row && row.vpn_status) || 'active').toLowerCase();
                if (adminUserStatusFilter !== 'all' && status !== String(adminUserStatusFilter || '').toLowerCase()) return false;
                return true;
            });
        }
        if (pageMode === 'admin' && adminUserSearch.trim() !== '') {
            var q = adminUserSearch.trim().toLowerCase();
            list = list.filter(function (row) {
                var owner = (row.owner_name || '') + ' ' + (row.owner_email || '');
                return textContains(row.username, q)
                    || textContains(row.vpn_status, q)
                    || textContains(row.external_vpn_user_id, q)
                    || textContains(owner, q)
                    || textContains(row.created_at, q);
            });
        }
        if (countEl) countEl.textContent = list.length + ' user';
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="opacity:.6;padding:.75rem .6rem;">Belum ada user VPN yang cocok.</td></tr>';
            return;
        }
        var td = 'style="padding:.45rem .6rem;border-bottom:1px solid var(--border-color);vertical-align:middle;"';
        tbody.innerHTML = list.map(function (row) {
            var owner = pageMode === 'admin'
                ? escapeHtml((row.owner_name || '-') + ' (' + (row.owner_email || '-') + ')')
                : 'Me';
            var status = row.vpn_status || 'active';
            var u = escapeJs(row.username || '');
            var toggleAction = status === 'suspended'
                ? '<button class="btn-action success" onclick="vpnAskEnable(\'' + u + '\')">Enable</button> '
                : '<button class="btn-action warn" onclick="vpnAskDisable(\'' + u + '\')">Disable</button> ';
            var actions = '<button class="btn-action" onclick="vpnShowDetail(\'' + u + '\')">Detail</button> '
                + toggleAction
                + '<button class="btn-action" onclick="vpnAskDisconnect(\'' + u + '\')">Disconnect</button> '
                + '<button class="btn-action danger" onclick="vpnAskDelete(\'' + u + '\')">Hapus</button>';
            return '<tr>'
                + '<td ' + td + '><strong>' + escapeHtml(row.username || '-') + '</strong></td>'
                + '<td ' + td + '>' + statusBadge(status) + '</td>'
                + (pageMode === 'admin' ? '<td ' + td + '>' + owner + '</td>' : '')
                + '<td ' + td + '>' + escapeHtml(row.external_vpn_user_id || '-') + '</td>'
                + '<td ' + td + ' style="white-space:nowrap;">' + escapeHtml((row.created_at || '').substring(0, 16)) + '</td>'
                + '<td ' + td + ' style="white-space:nowrap;">' + actions + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPortForwardings(items) {
        var tbody = byId('vpn-pf-body');
        var countEl = byId('vpn-pf-count');
        if (!tbody) return;
        var list = Array.isArray(items) ? items.slice() : [];
        if (pageMode === 'admin') {
            list = list.filter(function (row) {
                if (!ownerPassesFilter(row, adminPfOwnerFilter)) return false;
                var proto = String((row && row.protocol) || '').toLowerCase();
                if (adminPfProtocolFilter !== 'all' && proto !== String(adminPfProtocolFilter || '').toLowerCase()) return false;
                return true;
            });
        }
        if (pageMode === 'admin' && adminPfSearch.trim() !== '') {
            var q = adminPfSearch.trim().toLowerCase();
            list = list.filter(function (row) {
                var owner = (row.owner_name || '') + ' ' + (row.owner_email || '');
                return textContains(row.name, q)
                    || textContains(row.protocol, q)
                    || textContains(row.listen_port, q)
                    || textContains(row.destination_ip, q)
                    || textContains(row.destination_port, q)
                    || textContains(owner, q)
                    || textContains(row.created_at, q);
            });
        }
        if (countEl) countEl.textContent = list.length + ' rule';
        if (list.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" style="opacity:.6;padding:.75rem .6rem;">Belum ada rule port forwarding yang cocok.</td></tr>';
            return;
        }
        pfDetailCache = list;
        var td = 'style="padding:.45rem .6rem;border-bottom:1px solid var(--border-color);vertical-align:middle;"';
        tbody.innerHTML = list.map(function (row, idx) {
            var owner = pageMode === 'admin'
                ? escapeHtml((row.owner_name || '-') + ' (' + (row.owner_email || '-') + ')')
                : '';
            var n = escapeJs(row.name || '');
            return '<tr>'
                + '<td ' + td + '><strong>' + escapeHtml(row.name || '-') + '</strong></td>'
                + '<td ' + td + '><span class="badge active" style="background:rgba(99,102,241,.12);color:#6366f1;">' + escapeHtml(row.protocol || '-') + '</span></td>'
                + '<td ' + td + '>' + escapeHtml(String(row.listen_port || '-')) + '</td>'
                + '<td ' + td + '>' + escapeHtml(row.destination_ip || '-') + ':' + escapeHtml(String(row.destination_port || '-')) + '</td>'
                + (pageMode === 'admin' ? '<td ' + td + '>' + owner + '</td>' : '')
                + '<td ' + td + ' style="white-space:nowrap;">' + escapeHtml((row.created_at || '').substring(0, 16)) + '</td>'
                + '<td ' + td + ' style="white-space:nowrap;display:flex;gap:.35rem;">'
                + '<button class="btn-action" onclick="vpnOpenPfDetail(' + idx + ')">Detail</button>'
                + '<button class="btn-action danger" onclick="vpnAskDeletePf(\'' + n + '\')">Hapus</button>'
                + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderFromCache() {
        renderSummaryStats(cachedUsers, cachedPfs);
        renderUsers(cachedUsers);
        renderPortForwardings(cachedPfs);
    }

    /* ------------------------------------------------------------------ */
    /* Load data (DB)                                                       */
    /* ------------------------------------------------------------------ */

    async function refreshLists() {
        try {
            var usersRes = await request('list_users');
            var pfRes = await request('list_port_forwardings');
            cachedUsers = ((usersRes || {}).data || {}).items || [];
            cachedPfs = ((pfRes || {}).data || {}).items || [];
            renderFromCache();
        } catch (err) {
            setAlert('error', err.message);
        }
    }

    window.vpnRefreshData = function () { refreshLists(); };

    /* ------------------------------------------------------------------ */
    /* Sync with VPN server                                                 */
    /* ------------------------------------------------------------------ */

    window.vpnSyncServer = async function () {
        var btn = byId('vpn-btn-sync');
        if (btn) { btn.disabled = true; btn.textContent = 'Syncing\u2026'; }
        try {
            var res = await request('sync_server');
            setAlert('success', res.message || 'Sync selesai.');
            await refreshLists();
        } catch (err) {
            setAlert('error', 'Sync gagal: ' + err.message);
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Sync Server'; }
        }
    };

    /* ------------------------------------------------------------------ */
    /* Create VPN User modal                                                */
    /* ------------------------------------------------------------------ */

    window.vpnDoCreateUser = async function () {
        clearModalAlert('modal-create-user');
        var username = val('vpn_create_username');
        var password = val('vpn_create_password');
        var winboxEnabledEl = byId('vpn_tpl_winbox_enabled');
        var apiEnabledEl = byId('vpn_tpl_api_enabled');
        var winboxEnabled = !!(winboxEnabledEl && winboxEnabledEl.checked);
        var apiEnabled = !!(apiEnabledEl && apiEnabledEl.checked);
        var winboxDestPort = Number(val('vpn_tpl_winbox_dest_port') || 0);
        var apiDestPort = Number(val('vpn_tpl_api_dest_port') || 0);
        if (!username || !password) {
            setModalAlert('modal-create-user', 'error', 'Username dan password wajib diisi.');
            return;
        }
        setDisabled('btn-do-create-user', true);
        try {
            var res = await request('create_user', {
                username: username,
                password: password,
                create_pf_winbox: winboxEnabled,
                create_pf_api: apiEnabled,
                winbox_dest_port: winboxDestPort,
                api_dest_port: apiDestPort
            });
            setVal('vpn_create_username', ''); setVal('vpn_create_password', '');
            setVal('vpn_tpl_winbox_dest_port', '');
            setVal('vpn_tpl_api_dest_port', '');
            window.vpnCloseModal('modal-create-user');
            setAlert('success', res.message || 'VPN user berhasil dibuat.');

            var data = (res && res.data) ? res.data : {};
            var scriptEl = byId('mikrotik-install-script');
            if (scriptEl && data.mikrotik_install_script) {
                scriptEl.value = String(data.mikrotik_install_script || '');
                var pfSummaryEl = byId('mikrotik-pf-summary');
                if (pfSummaryEl) {
                    var lines = [];
                    var pfList = Array.isArray(data.port_forwardings) ? data.port_forwardings : [];
                    if (pfList.length > 0) {
                        lines.push('Template port forwarding aktif:');
                        pfList.forEach(function (pf) {
                            lines.push('- ' + String(pf.name || '-') + ' | ' + String(pf.protocol || 'tcp') + ' ' + String(pf.listen_port || '-') + ' -> ' + String(pf.destination_ip || '-') + ':' + String(pf.destination_port || '-'));
                        });
                    } else {
                        lines.push('Template port forwarding tidak dibuat.');
                    }
                    var pfErrors = Array.isArray(data.port_forwarding_errors) ? data.port_forwarding_errors : [];
                    if (pfErrors.length > 0) {
                        lines.push('Warning: ' + pfErrors.join(' | '));
                    }
                    pfSummaryEl.textContent = lines.join('\n');
                    pfSummaryEl.style.whiteSpace = 'pre-wrap';
                }
                window.vpnOpenModal('modal-install-script');
            }

            await refreshLists();
        } catch (err) {
            setModalAlert('modal-create-user', 'error', err.message);
        } finally {
            setDisabled('btn-do-create-user', false);
        }
    };

    window.vpnCopyInstallScript = async function () {
        var scriptEl = byId('mikrotik-install-script');
        if (!scriptEl) return;
        var text = String(scriptEl.value || '');
        if (!text) return;

        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                scriptEl.focus();
                scriptEl.select();
                document.execCommand('copy');
                scriptEl.setSelectionRange(0, 0);
            }
            setAlert('success', 'Script Mikrotik berhasil di-copy.');
        } catch (err) {
            setAlert('error', 'Copy script gagal: ' + (err && err.message ? err.message : 'Unknown error'));
        }
    };

    /* ------------------------------------------------------------------ */
    /* Create Port Forwarding modal                                         */
    /* ------------------------------------------------------------------ */

    function pfLastOctet(vpnIp) {
        if (!vpnIp) return null;
        var parts = String(vpnIp).split('.');
        var oct = parseInt(parts[parts.length - 1], 10);
        return (oct > 0 && oct <= 254) ? oct : null;
    }

    window.vpnOpenCreatePf = function () {
        clearModalAlert('modal-create-pf');
        // Reset form
        setVal('vpn_pf_name', '');
        setVal('vpn_pf_listen_port', '');
        setVal('vpn_pf_destination_port', '89');
        setVal('vpn_pf_to_ip', '');
        setVal('vpn_pf_to_port', '');
        var scriptWrap = byId('vpn_pf_script_wrap');
        if (scriptWrap) scriptWrap.style.display = 'none';

        // Populate VPN user dropdown
        var sel = byId('vpn_pf_vpn_user');
        if (sel) {
            sel.innerHTML = '<option value="">-- pilih akun --</option>';
            var users = Array.isArray(cachedUsers) ? cachedUsers : [];
            users.forEach(function (u) {
                if (!u.username) return;
                var label = u.username + (u.vpn_ip ? ' (' + u.vpn_ip + ')' : '');
                var opt = document.createElement('option');
                opt.value = u.username;
                opt.dataset.ip = u.vpn_ip || '';
                opt.textContent = label;
                sel.appendChild(opt);
            });
        }
        window.vpnPfOnUserChange();
        window.vpnOpenModal('modal-create-pf');
    };

    window.vpnPfOnUserChange = function () {
        var sel = byId('vpn_pf_vpn_user');
        if (!sel) return;
        var opt = sel.options[sel.selectedIndex];
        var ip = (opt && opt.dataset.ip) ? opt.dataset.ip : '';
        var oct = pfLastOctet(ip);
        if (oct !== null) {
            var suggestedPort = 3000 + oct;
            setVal('vpn_pf_listen_port', String(suggestedPort));
        } else {
            setVal('vpn_pf_listen_port', '');
        }
        window.vpnPfPreviewScript();
    };

    window.vpnPfPreviewScript = function () {
        var toIp = (byId('vpn_pf_to_ip') ? byId('vpn_pf_to_ip').value.trim() : '');
        var wrap = byId('vpn_pf_script_wrap');
        var out = byId('vpn_pf_script_out');
        if (!wrap || !out) return;
        if (!toIp) { wrap.style.display = 'none'; return; }

        // dst-address = VPN user's IP; dst-port = Destination Port (service port on VPN client)
        var sel = byId('vpn_pf_vpn_user');
        var opt = sel ? sel.options[sel.selectedIndex] : null;
        var vpnIp = (opt && opt.dataset.ip) ? opt.dataset.ip.trim() : '?';
        var dstPort = val('vpn_pf_destination_port') || '?';
        var toPort = val('vpn_pf_to_port') || '?';
        var proto = val('vpn_pf_to_protocol') || val('vpn_pf_protocol') || 'tcp';
        var name = val('vpn_pf_name') || 'rule';

        var script = '/ip firewall nat\n'
            + 'add action=dst-nat chain=dstnat'
            + ' dst-address=' + vpnIp
            + ' dst-port=' + dstPort
            + ' protocol=' + proto
            + ' to-addresses=' + toIp
            + ' to-ports=' + toPort
            + ' comment="pf-' + name + '"';
        out.textContent = script;
        wrap.style.display = 'block';
    };

    window.vpnDoCreatePortForwarding = async function () {
        clearModalAlert('modal-create-pf');
        var name = val('vpn_pf_name');
        var protocol = val('vpn_pf_protocol') || 'tcp';
        var listenPort = Number(val('vpn_pf_listen_port') || 0);
        var destPort = Number(val('vpn_pf_destination_port') || 0);

        // Resolve destination IP from selected VPN user
        var sel = byId('vpn_pf_vpn_user');
        var opt = sel ? sel.options[sel.selectedIndex] : null;
        var destIp = (opt && opt.dataset.ip) ? opt.dataset.ip.trim() : '';

        if (!name) { setModalAlert('modal-create-pf', 'error', 'Rule Name wajib diisi.'); return; }
        if (!destIp) { setModalAlert('modal-create-pf', 'error', 'Pilih akun VPN terlebih dahulu.'); return; }
        if (!listenPort) { setModalAlert('modal-create-pf', 'error', 'Listen Port wajib diisi.'); return; }
        if (!destPort) { setModalAlert('modal-create-pf', 'error', 'Destination Port wajib diisi.'); return; }

        setDisabled('btn-do-create-pf', true);
        try {
            var res = await request('create_port_forwarding', {
                name: name, protocol: protocol,
                listen_port: listenPort, destination_ip: destIp, destination_port: destPort
            });
            window.vpnCloseModal('modal-create-pf');
            setAlert('success', res.message || 'Port forwarding berhasil dibuat.');
            await refreshLists();
        } catch (err) {
            setModalAlert('modal-create-pf', 'error', err.message);
        } finally {
            setDisabled('btn-do-create-pf', false);
        }
    };

    /* ------------------------------------------------------------------ */
    /* Detail user modal                                                    */
    /* ------------------------------------------------------------------ */

    window.vpnShowDetail = async function (username) {
        // reset fields
        var fields = ['detail-username','detail-password','detail-ip','detail-status','detail-owner','detail-created','detail-updated'];
        fields.forEach(function(id){ var el = byId(id); if(el) el.textContent = '\u2026'; });
        var pwWrap = byId('detail-password-wrap');
        var pwToggle = byId('detail-password-toggle');
        if (pwWrap) pwWrap.style.filter = 'blur(5px)';
        if (pwToggle) { pwToggle.textContent = 'Tampilkan'; pwToggle.dataset.shown = '0'; }
        clearModalAlert('modal-user-detail');
        window.vpnOpenModal('modal-user-detail');

        try {
            var res = await request('get_user_detail', { username: username });
            var d = res.data || {};
            var set = function(id, v) { var el = byId(id); if(el) el.textContent = v || '-'; };
            set('detail-username', d.username);
            set('detail-password', d.vpn_password || '(tidak tersimpan)');
            set('detail-ip', d.vpn_ip || '-');
            set('detail-status', d.vpn_status);
            set('detail-owner', d.owner_name ? (d.owner_name + (d.owner_email ? ' <' + d.owner_email + '>' : '')) : 'Me');
            set('detail-created', (d.created_at || '').substring(0, 19));
            set('detail-updated', (d.updated_at || '').substring(0, 19));
        } catch (err) {
            setModalAlert('modal-user-detail', 'error', err.message);
        }
    };

    /* ------------------------------------------------------------------ */
    /* User action confirm modals                                           */
    /* ------------------------------------------------------------------ */

    function openUserConfirm(action, username, title, text, btnColor) {
        pendingUserAction = { action: action, username: username };
        var titleEl = byId('modal-confirm-user-title');
        var textEl = byId('modal-confirm-user-text');
        var btn = byId('btn-do-confirm-user');
        if (titleEl) titleEl.textContent = title;
        if (textEl) textEl.textContent = text;
        if (btn) { btn.style.background = btnColor || ''; btn.style.borderColor = btnColor || ''; }
        clearModalAlert('modal-confirm-user');
        window.vpnOpenModal('modal-confirm-user');
    }

    window.vpnAskDisable = function (username) {
        openUserConfirm('disable_user', username, 'Nonaktifkan User',
            'Nonaktifkan user "' + username + '"? User tidak akan bisa login VPN.', 'var(--warning,#ca8a04)');
    };
    window.vpnAskEnable = function (username) {
        openUserConfirm('enable_user', username, 'Aktifkan User',
            'Aktifkan kembali user "' + username + '" agar bisa login VPN lagi?', 'var(--success,#22c55e)');
    };
    window.vpnAskDisconnect = function (username) {
        openUserConfirm('disconnect_user', username, 'Disconnect User',
            'Putuskan koneksi aktif user "' + username + '"?', '');
    };
    window.vpnAskDelete = function (username) {
        openUserConfirm('delete_user', username, 'Hapus User',
            'Hapus user "' + username + '" secara permanen? Aksi ini tidak dapat dibatalkan.', 'var(--danger,#ef4444)');
    };

    window.vpnDoConfirmUser = async function () {
        if (!pendingUserAction) return;
        clearModalAlert('modal-confirm-user');
        setDisabled('btn-do-confirm-user', true);
        var act = pendingUserAction;
        try {
            var res = await request(act.action, { username: act.username });
            window.vpnCloseModal('modal-confirm-user');
            setAlert('success', res.message || 'Aksi berhasil.');
            pendingUserAction = null;
            await refreshLists();
        } catch (err) {
            setModalAlert('modal-confirm-user', 'error', err.message);
        } finally {
            setDisabled('btn-do-confirm-user', false);
        }
    };

    /* ------------------------------------------------------------------ */
    /* Port Forwarding detail modal                                        */
    /* ------------------------------------------------------------------ */

    window.vpnOpenPfDetail = function (idx) {
        var row = pfDetailCache[idx];
        if (!row) return;

        // Build IP Remote: extract host from VPN server URL, append rule listen_port
        var serverUrl = (window.VPN_SERVER_URL || '').replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
        var host = serverUrl.replace(/:\d+$/, ''); // strip existing port
        var listenPort = row.listen_port ? String(row.listen_port) : '-';
        var ipRemote = host ? (host + ':' + listenPort) : listenPort;

        var nameEl = byId('pf-detail-name');
        var ownerEl = byId('pf-detail-owner');
        var ipEl = byId('pf-detail-ip-remote');
        var createdEl = byId('pf-detail-created');

        if (nameEl) nameEl.textContent = row.name || '-';
        if (ownerEl) {
            if (pageMode === 'admin') {
                ownerEl.textContent = (row.owner_name || '-') + ' (' + (row.owner_email || '-') + ')';
            } else {
                ownerEl.textContent = row.owner_name || row.owner_email || '-';
            }
        }
        if (ipEl) ipEl.textContent = ipRemote;
        if (createdEl) createdEl.textContent = (row.created_at || '-').substring(0, 16);

        window.vpnOpenModal('modal-pf-detail');
    };

    /* ------------------------------------------------------------------ */
    /* Port Forwarding delete confirm modal                                 */
    /* ------------------------------------------------------------------ */

    window.vpnAskDeletePf = function (name) {
        pendingPfName = name;
        var nameEl = byId('modal-confirm-pf-name');
        if (nameEl) nameEl.textContent = name;
        clearModalAlert('modal-confirm-pf');
        window.vpnOpenModal('modal-confirm-pf');
    };

    window.vpnDoDeletePortForwarding = async function () {
        if (!pendingPfName) return;
        clearModalAlert('modal-confirm-pf');
        setDisabled('btn-do-delete-pf', true);
        var name = pendingPfName;
        try {
            var res = await request('delete_port_forwarding', { name: name });
            window.vpnCloseModal('modal-confirm-pf');
            setAlert('success', res.message || 'Port forwarding dihapus.');
            pendingPfName = null;
            await refreshLists();
        } catch (err) {
            setModalAlert('modal-confirm-pf', 'error', err.message);
        } finally {
            setDisabled('btn-do-delete-pf', false);
        }
    };

    /* ------------------------------------------------------------------ */
    /* Init                                                                 */
    /* ------------------------------------------------------------------ */

    refreshLists();

    if (pageMode === 'admin') {
        var userSearchInput = byId('vpn-search-users');
        if (userSearchInput) {
            userSearchInput.addEventListener('input', function () {
                adminUserSearch = String(userSearchInput.value || '');
                renderFromCache();
            });
        }
        var userOwnerSelect = byId('vpn-filter-users-owner');
        if (userOwnerSelect) {
            userOwnerSelect.addEventListener('change', function () {
                adminUserOwnerFilter = String(userOwnerSelect.value || 'all');
                renderFromCache();
            });
        }
        var userStatusSelect = byId('vpn-filter-users-status');
        if (userStatusSelect) {
            userStatusSelect.addEventListener('change', function () {
                adminUserStatusFilter = String(userStatusSelect.value || 'all');
                renderFromCache();
            });
        }
        var pfSearchInput = byId('vpn-search-pf');
        if (pfSearchInput) {
            pfSearchInput.addEventListener('input', function () {
                adminPfSearch = String(pfSearchInput.value || '');
                renderFromCache();
            });
        }
        var pfOwnerSelect = byId('vpn-filter-pf-owner');
        if (pfOwnerSelect) {
            pfOwnerSelect.addEventListener('change', function () {
                adminPfOwnerFilter = String(pfOwnerSelect.value || 'all');
                renderFromCache();
            });
        }
        var pfProtocolSelect = byId('vpn-filter-pf-protocol');
        if (pfProtocolSelect) {
            pfProtocolSelect.addEventListener('change', function () {
                adminPfProtocolFilter = String(pfProtocolSelect.value || 'all');
                renderFromCache();
            });
        }
    }

})();
