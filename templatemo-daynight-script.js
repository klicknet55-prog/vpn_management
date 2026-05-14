/* ========================================
   DayNight Admin - JavaScript
   ======================================== */
   
/*

TemplateMo 608 DayNight Admin

https://templatemo.com/tm-608-daynight-admin

*/

// ===== Theme Toggle =====
function initTheme() {
    const savedTheme = localStorage.getItem('daynight-theme');
    if (savedTheme === 'carbon') {
        document.documentElement.classList.add('carbon');
        document.body.classList.add('carbon');
        updateThemeButtons('carbon');
    } else {
        updateThemeButtons('snow');
    }
}

function setTheme(theme) {
    if (theme === 'carbon') {
        document.documentElement.classList.add('carbon');
        document.body.classList.add('carbon');
        localStorage.setItem('daynight-theme', 'carbon');
    } else {
        document.documentElement.classList.remove('carbon');
        document.body.classList.remove('carbon');
        localStorage.setItem('daynight-theme', 'snow');
    }
    updateThemeButtons(theme);
}

function updateThemeButtons(theme) {
    const snowBtns = document.querySelectorAll('.theme-btn-snow');
    const carbonBtns = document.querySelectorAll('.theme-btn-carbon');
    
    snowBtns.forEach(btn => {
        btn.classList.toggle('active', theme === 'snow');
    });
    carbonBtns.forEach(btn => {
        btn.classList.toggle('active', theme === 'carbon');
    });
}

// ===== Time-based Greeting =====
function getGreeting() {
    const hour = new Date().getHours();
    if (hour >= 3 && hour < 10) return 'Selamat Pagi';
    if (hour >= 10 && hour < 15) return 'Selamat Siang';
    if (hour >= 15 && hour < 18) return 'Selamat Sore';
    return 'Selamat Malam';
}

function setGreeting() {
    const greetingEl = document.getElementById('greeting');
    const sessionName = (window.DAYNIGHT_SESSION && window.DAYNIGHT_SESSION.fullName)
        || localStorage.getItem('daynight-user-name')
        || 'User';
    if (greetingEl) {
        greetingEl.textContent = getGreeting() + ', ' + sessionName;
    }
}

// ===== Session and Auth Demo =====
function handleLoginSubmit(event) {
    if (event) {
        event.preventDefault();
    }

    const emailEl = document.getElementById('login-email');
    const passwordEl = document.getElementById('login-password');
    const errorEl = document.getElementById('login-error');
    const btnEl = document.getElementById('login-btn');
    const btnTextEl = document.getElementById('login-btn-text');
    const btnSpinnerEl = document.getElementById('login-btn-spinner');

    if (!emailEl || !passwordEl) {
        return;
    }

    const email = emailEl.value.trim();
    const password = passwordEl.value;

    if (!email || !password) {
        if (errorEl) {
            errorEl.textContent = 'Email dan password harus diisi';
            errorEl.style.display = 'block';
        }
        return;
    }

    // Disable button and show spinner
    if (btnEl) {
        btnEl.disabled = true;
        if (btnTextEl) btnTextEl.style.display = 'none';
        if (btnSpinnerEl) btnSpinnerEl.style.display = 'block';
    }

    // Hide error
    if (errorEl) {
        errorEl.style.display = 'none';
    }

    // Call auth API
    fetch('api/auth.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            email: email,
            password: password
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            // Store session data
            const user = data.user;
            localStorage.setItem('daynight-user-email', user.email);
            localStorage.setItem('daynight-user-name', user.fullName);
            localStorage.setItem('daynight-user-role', (user.roles.includes('admin') || user.roles.includes('super_admin')) ? 'admin' : 'user');
            localStorage.setItem('daynight-user-id', user.id);
            localStorage.setItem('daynight-user-roles', JSON.stringify(user.roles));

            // Redirect to dashboard
            setTimeout(() => {
                    const isAdmin = user.roles.includes('admin') || user.roles.includes('super_admin');
                    window.location.href = isAdmin ? 'admin/index.php' : 'user/index.php';
            }, 300);
        } else {
            // Show error
            if (errorEl) {
                errorEl.textContent = data.error || 'Login gagal';
                errorEl.style.display = 'block';
            }
            
            // Re-enable button
            if (btnEl) {
                btnEl.disabled = false;
                if (btnTextEl) btnTextEl.style.display = 'inline';
                if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
            }
        }
    })
    .catch(error => {
        console.error('Login error:', error);
        if (errorEl) {
            errorEl.textContent = 'Koneksi server gagal. Coba lagi nanti.';
            errorEl.style.display = 'block';
        }
        
        // Re-enable button
        if (btnEl) {
            btnEl.disabled = false;
            if (btnTextEl) btnTextEl.style.display = 'inline';
            if (btnSpinnerEl) btnSpinnerEl.style.display = 'none';
        }
    });
}

function setAuthMode(mode) {
    const loginPanel = document.getElementById('login-panel-auth');
    const registerPanel = document.getElementById('register-panel-auth');
    const loginBtn = document.getElementById('auth-mode-login');
    const registerBtn = document.getElementById('auth-mode-register');

    if (!loginPanel || !registerPanel || !loginBtn || !registerBtn) {
        return;
    }

    const isRegister = mode === 'register';
    loginPanel.style.display = isRegister ? 'none' : 'block';
    registerPanel.style.display = isRegister ? 'block' : 'none';

    loginBtn.classList.toggle('btn-primary', !isRegister);
    loginBtn.classList.toggle('btn-secondary', isRegister);
    registerBtn.classList.toggle('btn-primary', isRegister);
    registerBtn.classList.toggle('btn-secondary', !isRegister);
}

function setRegisterButtonLoading(buttonId, isLoading, loadingText) {
    const btn = document.getElementById(buttonId);
    if (!btn) return;
    if (!btn.dataset.defaultText) {
        btn.dataset.defaultText = btn.textContent || '';
    }
    btn.disabled = isLoading;
    btn.textContent = isLoading ? loadingText : btn.dataset.defaultText;
}

function showRegisterMessage(message, type) {
    const messageEl = document.getElementById('register-message');
    if (!messageEl) return;

    const isSuccess = type === 'success';
    messageEl.style.display = 'block';
    messageEl.textContent = message;
    messageEl.style.background = isSuccess ? 'rgba(34,197,94,0.1)' : 'rgba(239,68,68,0.08)';
    messageEl.style.border = isSuccess ? '1px solid rgba(34,197,94,0.25)' : '1px solid rgba(239,68,68,0.2)';
    messageEl.style.color = isSuccess ? '#15803d' : 'var(--danger)';
}

function handleRegisterRequestOtp(event) {
    if (event) event.preventDefault();

    const fullName = (document.getElementById('reg-full-name')?.value || '').trim();
    const email = (document.getElementById('reg-email')?.value || '').trim();
    const phone = (document.getElementById('reg-phone')?.value || '').trim();
    const password = document.getElementById('reg-password')?.value || '';

    if (!fullName || !email || !phone || !password) {
        showRegisterMessage('Semua field registrasi wajib diisi.', 'error');
        return;
    }

    setRegisterButtonLoading('reg-request-btn', true, 'Mengirim OTP...');
    showRegisterMessage('', 'error');
    const messageEl = document.getElementById('register-message');
    if (messageEl) messageEl.style.display = 'none';

    fetch('api/register.php?action=request_otp', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            full_name: fullName,
            email: email,
            phone_number: phone,
            password: password,
        }),
    })
    .then(response => response.json())
    .then(data => {
        if (!data.ok) {
            throw new Error(data.error || 'Gagal mengirim OTP registrasi');
        }

        const userId = data?.data?.user_id || '';
        const userIdEl = document.getElementById('reg-user-id');
        if (userIdEl) {
            userIdEl.value = String(userId);
        }

        const otpForm = document.getElementById('verify-otp-form');
        if (otpForm) {
            otpForm.style.display = 'block';
        }

        showRegisterMessage('OTP berhasil dikirim. Silakan cek WhatsApp Anda lalu masukkan kodenya.', 'success');
    })
    .catch(error => {
        showRegisterMessage(error.message || 'Gagal mengirim OTP registrasi', 'error');
    })
    .finally(() => {
        setRegisterButtonLoading('reg-request-btn', false, 'Mengirim OTP...');
    });
}

function handleRegisterVerifyOtp(event) {
    if (event) event.preventDefault();

    const userId = (document.getElementById('reg-user-id')?.value || '').trim();
    const email = (document.getElementById('reg-email')?.value || '').trim();
    const otp = (document.getElementById('reg-otp')?.value || '').trim();

    if (!otp || (!userId && !email)) {
        showRegisterMessage('Data verifikasi OTP belum lengkap.', 'error');
        return;
    }

    const payload = { otp_code: otp };
    if (userId) {
        payload.user_id = Number(userId);
    } else {
        payload.email = email;
    }

    setRegisterButtonLoading('reg-verify-btn', true, 'Memverifikasi...');

    fetch('api/register.php?action=verify_otp', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    })
    .then(response => response.json())
    .then(data => {
        if (!data.ok) {
            throw new Error(data.error || 'Verifikasi OTP gagal');
        }

        showRegisterMessage('Registrasi berhasil. Akun Anda sudah aktif, silakan login.', 'success');

        const loginEmailEl = document.getElementById('login-email');
        if (loginEmailEl) {
            loginEmailEl.value = email;
        }
        const loginPasswordEl = document.getElementById('login-password');
        if (loginPasswordEl) {
            loginPasswordEl.focus();
        }
    })
    .catch(error => {
        showRegisterMessage(error.message || 'Verifikasi OTP gagal', 'error');
    })
    .finally(() => {
        setRegisterButtonLoading('reg-verify-btn', false, 'Memverifikasi...');
    });
}

function initSessionUI() {
    const serverSession = window.DAYNIGHT_SESSION || null;
    const userName = (serverSession && serverSession.fullName) || localStorage.getItem('daynight-user-name') || 'Guest';
    const userRole = (serverSession && serverSession.role) || localStorage.getItem('daynight-user-role') || 'user';
    const userEmail = (serverSession && serverSession.email) || localStorage.getItem('daynight-user-email') || 'unknown';

       // Update greeting with time of day
       const hour = new Date().getHours();
       let greeting = 'Hello';
       if (hour >= 3 && hour < 10) greeting = 'Selamat Pagi';
       else if (hour < 13) greeting = 'Selamat Siang';
       else if (hour < 17) greeting = 'Selamat Sore';
       else greeting = 'Selamat Malam';
   
       const greetingEl = document.getElementById('greeting');
       if (greetingEl) {
           greetingEl.textContent = `${greeting}, ${userName}`;
       }

    document.querySelectorAll('[data-current-user]').forEach(el => {
        el.textContent = userName;
    });

    document.querySelectorAll('[data-current-role]').forEach(el => {
        el.textContent = userRole === 'admin' ? 'ADMIN' : 'USER';
    });
   // Use email as workspace identifier
   document.querySelectorAll('[data-current-workspace]').forEach(el => {
       el.textContent = userEmail;
    });

    const userAvatar = document.querySelector('.user-avatar');
    if (userAvatar) {
        userAvatar.textContent = userName.charAt(0).toUpperCase();
    }

    const restrictedEls = document.querySelectorAll('[data-admin-only]');
    restrictedEls.forEach(el => {
        el.style.display = userRole === 'admin' ? '' : 'none';
    });
}

function applyWebBrandingLogo() {
    const logoMeta = document.querySelector('meta[name="web-logo-url"]');
    const logoUrl = logoMeta ? (logoMeta.getAttribute('content') || '').trim() : '';
    if (!logoUrl) {
        return;
    }

    const logoIcons = document.querySelectorAll('.logo-icon');
    logoIcons.forEach(iconEl => {
        iconEl.innerHTML = '';
        iconEl.classList.add('has-image');
        const img = document.createElement('img');
        img.src = logoUrl;
        img.alt = 'Company Logo';
        img.className = 'logo-image';
        iconEl.appendChild(img);
    });
}

function selectProduct(productType) {
    const cards = document.querySelectorAll('[data-product-card]');
    const panels = document.querySelectorAll('[data-product-panel]');

    cards.forEach(card => {
        card.classList.toggle('active', card.getAttribute('data-product-card') === productType);
    });

    panels.forEach(panel => {
        panel.style.display = panel.getAttribute('data-product-panel') === productType ? 'block' : 'none';
    });
}

function initProductSelector() {
    const firstCard = document.querySelector('[data-product-card]');
    if (firstCard) {
        const defaultProduct = firstCard.getAttribute('data-product-card');
        selectProduct(defaultProduct);
    }
}

// ===== Date Range Picker =====
function setDateRange(range, btn) {
    const btns = document.querySelectorAll('.date-btn');
    btns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Update charts based on range
    updateCharts(range);
}

function updateCharts(range) {
    // Animate chart bars based on selected range
    const bars = document.querySelectorAll('.bar');
    bars.forEach(bar => {
        const currentHeight = parseInt(bar.style.height);
        let multiplier = 1;
        
        if (range === '7d') multiplier = 0.7;
        if (range === '30d') multiplier = 1;
        if (range === '90d') multiplier = 1.2;
        if (range === '12m') multiplier = 1.4;
        
        // Random variation
        const variation = 0.8 + Math.random() * 0.4;
        bar.style.height = (currentHeight * multiplier * variation) + 'px';
    });
}

// ===== Inbox =====
function selectMessage(el, index) {
    // Remove active from all
    document.querySelectorAll('.message-item').forEach(item => {
        item.classList.remove('active');
    });
    
    // Add active to selected
    el.classList.add('active');
    el.classList.remove('unread');
    
    // Update message view
    updateMessageView(index);
}

function updateMessageView(index) {
    const messages = [
        {
            subject: 'Project Update: Q1 Dashboard Redesign',
            sender: 'Sarah Chen',
            email: 'sarah.chen@company.com',
            date: 'Jan 2, 2026 at 9:45 AM',
            body: `<p>Hi Alex,</p>
                   <p>I wanted to give you a quick update on the Q1 dashboard redesign project. We've completed the wireframes and initial mockups, and the team is ready to move into the development phase.</p>
                   <p>Key highlights from our progress:</p>
                   <p>• User research completed with 15 participants<br>
                   • 3 design concepts presented to stakeholders<br>
                   • Final direction approved by leadership<br>
                   • Development sprint starting next Monday</p>
                   <p>Could we schedule a quick sync tomorrow to go over the technical requirements? Let me know what time works best for you.</p>
                   <p>Best regards,<br>Sarah</p>`
        },
        {
            subject: 'Weekly Analytics Report',
            sender: 'Analytics Bot',
            email: 'analytics@company.com',
            date: 'Jan 1, 2026 at 8:00 AM',
            body: `<p>Hello Alex,</p>
                   <p>Here's your weekly analytics summary for December 25-31, 2025:</p>
                   <p><strong>Traffic Overview:</strong><br>
                   Total visitors: 45,230 (+12% vs last week)<br>
                   Page views: 128,450 (+8%)<br>
                   Avg. session duration: 4m 32s</p>
                   <p><strong>Top Performing Pages:</strong><br>
                   1. /dashboard - 15,230 views<br>
                   2. /analytics - 8,450 views<br>
                   3. /projects - 6,780 views</p>
                   <p>View the full report in your Analytics dashboard.</p>`
        },
        {
            subject: 'New Team Member Introduction',
            sender: 'HR Team',
            email: 'hr@company.com',
            date: 'Dec 31, 2025 at 2:30 PM',
            body: `<p>Dear Team,</p>
                   <p>We're excited to announce that Michael Torres will be joining our engineering team starting January 6th as a Senior Frontend Developer.</p>
                   <p>Michael comes to us with 8 years of experience in web development and has previously worked at several notable tech companies. He'll be working closely with the product team on our new features.</p>
                   <p>Please join us in welcoming Michael to the team!</p>
                   <p>Best,<br>HR Team</p>`
        }
    ];
    
    const msg = messages[index] || messages[0];
    
    document.querySelector('.message-view-subject').textContent = msg.subject;
    document.querySelector('.message-view-sender-name').textContent = msg.sender;
    document.querySelector('.message-view-sender-email').textContent = msg.email;
    document.querySelector('.message-view-date').textContent = msg.date;
    document.querySelector('.message-view-body').innerHTML = msg.body;
}

// ===== Kanban =====
function initKanban() {
    const cards = document.querySelectorAll('.kanban-card');
    const columns = document.querySelectorAll('.kanban-cards');
    
    cards.forEach(card => {
        card.setAttribute('draggable', true);
        
        card.addEventListener('dragstart', (e) => {
            card.classList.add('dragging');
        });
        
        card.addEventListener('dragend', (e) => {
            card.classList.remove('dragging');
        });
    });
    
    columns.forEach(column => {
        column.addEventListener('dragover', (e) => {
            e.preventDefault();
            const dragging = document.querySelector('.dragging');
            column.appendChild(dragging);
        });
    });
}

// ===== Settings Toggles =====
function initToggles() {
    const toggles = document.querySelectorAll('.toggle input');
    toggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            console.log(`${this.id} is now ${this.checked ? 'enabled' : 'disabled'}`);
        });
    });
}

// ===== Mobile Menu =====
function toggleMobileMenu() {
    const menu = document.querySelector('.mobile-menu');
    const overlay = document.querySelector('.mobile-menu-overlay');
    
    if (menu && overlay) {
        menu.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.style.overflow = menu.classList.contains('active') ? 'hidden' : '';
    }
}

function closeMobileMenu() {
    const menu = document.querySelector('.mobile-menu');
    const overlay = document.querySelector('.mobile-menu-overlay');
    
    if (menu && overlay) {
        menu.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// ===== Account Dropdown & Modals =====
function toggleAccountDropdown() {
    const wrap = document.getElementById('account-menu-wrap');
    if (!wrap) return;
    wrap.classList.toggle('open');
}

function openAccountModal(type) {
    // Close dropdown
    const wrap = document.getElementById('account-menu-wrap');
    if (wrap) wrap.classList.remove('open');

    if (type === 'edit-profile') {
        const overlay = document.getElementById('modal-edit-profile');
        if (!overlay) return;
        // Pre-fill with current session data
        const session = window.DAYNIGHT_SESSION || {};
        const nameInput = overlay.querySelector('#ep-full-name');
        const phoneInput = overlay.querySelector('#ep-phone');
        const emailInput = overlay.querySelector('#ep-email');
        if (nameInput) nameInput.value = session.fullName || '';
        if (phoneInput) phoneInput.value = session.phoneNumber || '';
        if (emailInput) emailInput.value = session.email || '';
        // Clear messages
        const msg = overlay.querySelector('.modal-msg');
        if (msg) { msg.className = 'modal-msg'; msg.textContent = ''; }
        overlay.classList.add('open');

    } else if (type === 'change-password') {
        const overlay = document.getElementById('modal-change-password');
        if (!overlay) return;
        // Clear fields
        overlay.querySelectorAll('input[type="password"]').forEach(i => { i.value = ''; });
        const msg = overlay.querySelector('.modal-msg');
        if (msg) { msg.className = 'modal-msg'; msg.textContent = ''; }
        overlay.classList.add('open');
    }
}

function closeAccountModal(modalId) {
    const overlay = document.getElementById(modalId);
    if (overlay) overlay.classList.remove('open');
}

function submitEditProfile() {
    const overlay = document.getElementById('modal-edit-profile');
    const msg = overlay.querySelector('.modal-msg');
    const btn = overlay.querySelector('#ep-submit-btn');
    const fullName = (overlay.querySelector('#ep-full-name').value || '').trim();
    const phone    = (overlay.querySelector('#ep-phone').value || '').trim();

    if (!fullName) {
        msg.className = 'modal-msg error';
        msg.textContent = 'Nama lengkap tidak boleh kosong.';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Menyimpan...';
    msg.className = 'modal-msg';
    msg.textContent = '';

    const body = new URLSearchParams({ action: 'update_profile', full_name: fullName, phone_number: phone });
    const apiBase = (window.ACCOUNT_API_BASE || '../api') + '/update-profile.php';

    fetch(apiBase, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(r => r.json())
        .then(data => {
            msg.className = 'modal-msg ' + (data.success ? 'success' : 'error');
            msg.textContent = data.message || (data.success ? 'Berhasil.' : 'Gagal.');
            if (data.success) {
                // Update session name live
                if (window.DAYNIGHT_SESSION) window.DAYNIGHT_SESSION.fullName = data.full_name || fullName;
                if (window.DAYNIGHT_SESSION) window.DAYNIGHT_SESSION.phoneNumber = phone;
                document.querySelectorAll('[data-current-user]').forEach(el => { el.textContent = data.full_name || fullName; });
                const av = document.querySelector('.user-avatar');
                if (av) av.textContent = (data.full_name || fullName).charAt(0).toUpperCase();
            }
        })
        .catch(() => {
            msg.className = 'modal-msg error';
            msg.textContent = 'Gagal terhubung ke server.';
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Simpan';
        });
}

function submitChangePassword() {
    const overlay = document.getElementById('modal-change-password');
    const msg = overlay.querySelector('.modal-msg');
    const btn = overlay.querySelector('#cp-submit-btn');
    const oldPwd  = overlay.querySelector('#cp-old-password').value;
    const newPwd  = overlay.querySelector('#cp-new-password').value;
    const confPwd = overlay.querySelector('#cp-confirm-password').value;

    if (!oldPwd || !newPwd || !confPwd) {
        msg.className = 'modal-msg error';
        msg.textContent = 'Semua field wajib diisi.';
        return;
    }
    if (newPwd.length < 6) {
        msg.className = 'modal-msg error';
        msg.textContent = 'Password baru minimal 6 karakter.';
        return;
    }
    if (newPwd !== confPwd) {
        msg.className = 'modal-msg error';
        msg.textContent = 'Konfirmasi password tidak cocok.';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Menyimpan...';
    msg.className = 'modal-msg';
    msg.textContent = '';

    const body = new URLSearchParams({ action: 'change_password', old_password: oldPwd, new_password: newPwd, confirm_password: confPwd });
    const apiBase = (window.ACCOUNT_API_BASE || '../api') + '/update-profile.php';

    fetch(apiBase, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
        .then(r => r.json())
        .then(data => {
            msg.className = 'modal-msg ' + (data.success ? 'success' : 'error');
            msg.textContent = data.message || (data.success ? 'Berhasil.' : 'Gagal.');
            if (data.success) {
                overlay.querySelectorAll('input[type="password"]').forEach(i => { i.value = ''; });
            }
        })
        .catch(() => {
            msg.className = 'modal-msg error';
            msg.textContent = 'Gagal terhubung ke server.';
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Simpan';
        });
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('account-menu-wrap');
    if (wrap && wrap.classList.contains('open') && !wrap.contains(e.target)) {
        wrap.classList.remove('open');
    }
});

// ===== Initialize =====
document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    applyWebBrandingLogo();
    initSessionUI();
    setGreeting();
    initProductSelector();
    
    if (document.querySelector('.kanban-board')) {
        initKanban();
    }
    
    if (document.querySelector('.toggle')) {
        initToggles();
    }
    
    // Close mobile menu on overlay click
    const overlay = document.querySelector('.mobile-menu-overlay');
    if (overlay) {
        overlay.addEventListener('click', closeMobileMenu);
    }

    if (document.getElementById('auth-mode-login') && document.getElementById('auth-mode-register')) {
        setAuthMode('login');
    }
});
