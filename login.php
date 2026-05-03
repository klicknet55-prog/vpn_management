<?php
session_start();
if (!empty($_SESSION['logged_in'])) {
    $sessionRoles = $_SESSION['roles'] ?? [];
    $isAdmin = in_array('admin', $sessionRoles, true) || in_array('super_admin', $sessionRoles, true);
    header('Location: ' . ($isAdmin ? 'admin/index.php' : 'user/index.php'));
    exit();
}

require_once __DIR__ . '/system/seo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php seoRenderHeadTags('Login - VPN & WhatsApp API Manager'); ?>
    <script>if(localStorage.getItem("daynight-theme")==="carbon"){document.documentElement.classList.add("carbon");}</script>
    <link rel="stylesheet" href="templatemo-daynight-style.css?v=<?php echo (int) (file_exists(__DIR__ . '/templatemo-daynight-style.css') ? filemtime(__DIR__ . '/templatemo-daynight-style.css') : time()); ?>">
    <!--

TemplateMo 608 DayNight Admin

https://templatemo.com/tm-608-daynight-admin

-->
</head>
<body>
    <?php seoRenderBodyOpenTags(); ?>
    <!-- Theme Toggle (Fixed Position) -->
    <div class="login-theme-toggle">
        <div class="theme-toggle">
            <button class="theme-btn theme-btn-snow active" onclick="setTheme('snow')" title="Snow Edition">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/>
                    <line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/>
                    <line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
            </button>
            <button class="theme-btn theme-btn-carbon" onclick="setTheme('carbon')" title="Carbon Edition">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Login Page -->
    <div class="login-page">
        <div class="login-container">
            <div class="login-card">
                <div class="login-header">
                    <div class="login-logo">
                        <div class="logo-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </div>
                        <span><?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <h1 class="login-title">VPN & WhatsApp API Manager</h1>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-bottom:1rem;">
                    <button type="button" id="auth-mode-login" class="btn btn-primary" onclick="setAuthMode('login')">Login</button>
                    <button type="button" id="auth-mode-register" class="btn btn-secondary" onclick="setAuthMode('register')">Register</button>
                </div>

                <div id="login-panel-auth">
                    <form class="login-form" id="login-form" onsubmit="handleLoginSubmit(event)">
                        <div class="form-group">
                            <label class="form-label">Email / Username</label>
                            <input type="text" id="login-email" class="form-input" placeholder="admin" required autofocus>
                        </div>

                        <div class="form-group">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <label class="form-label" style="margin-bottom: 0;">Password</label>
                                <a href="#" style="font-size: 0.8125rem; color: var(--accent);">Lupa password?</a>
                            </div>
                            <input type="password" id="login-password" class="form-input" placeholder="Masukkan password" required>
                        </div>

                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem;">
                            <input type="checkbox" id="remember" style="width: 16px; height: 16px; accent-color: var(--accent);">
                            <label for="remember" style="font-size: 0.875rem; color: var(--text-secondary); cursor: pointer;">Pertahankan sesi login</label>
                        </div>

                        <button type="submit" id="login-btn" class="btn btn-primary" style="position: relative;">
                            <span id="login-btn-text">Masuk ke Dashboard</span>
                            <div id="login-btn-spinner" class="spinner" style="display: none; width: 16px; height: 16px; position: absolute; left: 12px;"></div>
                        </button>

                        <div id="login-error" style="display: none; margin-top: 1rem; padding: 0.75rem; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); border-radius: 8px; color: var(--danger); font-size: 0.875rem;"></div>
                    </form>

                    <p class="login-footer">Belum punya akun? <a href="#" onclick="setAuthMode('register');return false;">Register di sini</a></p>
                </div>

                <div id="register-panel-auth" class="card" style="padding: 1rem; border-radius: 12px; margin-bottom: 1rem; display:none;">
                    <div id="register-panel" style="display:block; margin-top:0;">
                        <form id="register-form" class="login-form" onsubmit="handleRegisterRequestOtp(event)">
                            <div class="form-group">
                                <label class="form-label" for="reg-full-name">Nama Lengkap</label>
                                <input type="text" id="reg-full-name" class="form-input" placeholder="Nama lengkap" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="reg-email">Email / Username</label>
                                <input type="text" id="reg-email" class="form-input" placeholder="username" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="reg-phone">No. HP</label>
                                <input type="text" id="reg-phone" class="form-input" placeholder="No. WA aktif" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="reg-password">Password</label>
                                <input type="password" id="reg-password" class="form-input" placeholder="Minimal 6 karakter" minlength="6" required>
                            </div>
                            <button type="submit" class="btn btn-primary" id="reg-request-btn">Kirim OTP Registrasi</button>
                        </form>

                        <form id="verify-otp-form" class="login-form" style="display:none; margin-top:0.9rem;" onsubmit="handleRegisterVerifyOtp(event)">
                            <input type="hidden" id="reg-user-id">
                            <div class="form-group">
                                <label class="form-label" for="reg-otp">Kode OTP</label>
                                <input type="text" id="reg-otp" class="form-input" placeholder="6 digit OTP" maxlength="6" required>
                            </div>
                            <button type="submit" class="btn btn-primary" id="reg-verify-btn">Verifikasi OTP & Aktifkan Akun</button>
                        </form>

                        <div id="register-message" style="display:none; margin-top:0.9rem; padding:0.75rem; border-radius:8px; font-size:0.875rem;"></div>
                    </div>

                    <p class="login-footer" style="margin-top:1rem;">Sudah punya akun? <a href="#" onclick="setAuthMode('login');return false;">Kembali ke Login</a></p>
                </div>
            </div>

            <p style="text-align: center; margin-top: 1.5rem; font-size: 0.8125rem; color: var(--text-secondary);">
                &copy; 2026 <?php echo htmlspecialchars(webCompanyName(), ENT_QUOTES, 'UTF-8'); ?> Admin. Designed by <a href="https://www.templatemo.com" target="_blank" rel="nofollow" style="color: var(--accent);">TemplateMo</a>
            </p>
        </div>
    </div>

    <script src="templatemo-daynight-script.js?v=<?php echo (int) (file_exists(__DIR__ . '/templatemo-daynight-script.js') ? filemtime(__DIR__ . '/templatemo-daynight-script.js') : time()); ?>"></script>
</body>
</html>

