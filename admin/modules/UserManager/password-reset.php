<?php
/**
 * Luminal CMS - Password Reset (Self-Service)
 * @file /admin/user-mgr/password-reset.php
 *
 * Two modes:
 *   1. Request form (default) — enter email to receive reset link
 *   2. Reset form (?token=X) — set new password
 *
 * Falls back to "contact admin" if Mailgun is not configured.
 */

session_start();
if (!defined('SITE_ROOT')) {
    define('SITE_ROOT', realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3));
}
require_once SITE_ROOT . '/admin/auth.php';

$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Site Admin', ENT_QUOTES, 'UTF-8');

// If user is logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: /admin/dashboard.php');
    exit;
}

$token = trim($_GET['token'] ?? '');
$mode = ($token !== '') ? 'reset' : 'request';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - <?php echo $artistName; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Roboto', 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            padding: 20px;
        }

        .container {
            background: #ffffff;
            border-radius: 8px;
            padding: 48px 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1), 0 10px 40px rgba(0,0,0,0.2);
            color: #202124;
        }

        .header { text-align: center; margin-bottom: 32px; }
        .header h1 { font-size: 24px; font-weight: 400; color: #202124; margin-bottom: 16px; }
        .header p { color: #5f6368; font-size: 14px; line-height: 1.6; }

        .form-group { margin-bottom: 24px; position: relative; }
        .form-group label { display: block; margin-bottom: 8px; color: #5f6368; font-size: 14px; font-weight: 500; }
        .input-wrapper { position: relative; }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"] {
            width: 100%;
            padding: 13px 16px;
            border: 1px solid #dadce0;
            border-radius: 4px;
            font-size: 16px;
            color: #202124;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-group input:focus { border-color: #1a73e8; box-shadow: 0 0 0 2px rgba(26,115,232,0.2); }

        .password-toggle {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; padding: 4px; color: #5f6368; font-size: 18px;
        }

        .submit-btn {
            width: 100%; padding: 12px 24px; background: #1a73e8; border: none; border-radius: 4px;
            color: #ffffff; font-size: 15px; font-weight: 500; cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }
        .submit-btn:hover { background: #1557b0; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        .submit-btn:disabled { background: #dadce0; cursor: not-allowed; }

        .message { padding: 12px 16px; border-radius: 4px; margin-bottom: 24px; font-size: 14px; }
        .message.error { background: #fce8e6; color: #c5221f; border: 1px solid #f5c6cb; }
        .message.success { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
        .message.info { background: #e8f0fe; color: #1967d2; border: 1px solid #d2e3fc; }

        .back-link {
            display: block; text-align: center; margin-top: 24px;
            color: #1a73e8; text-decoration: none; font-size: 14px; font-weight: 500;
        }
        .back-link:hover { text-decoration: underline; }

        .fallback-box {
            background: #f8f9fa; border-radius: 4px; padding: 20px; text-align: center;
        }
        .fallback-box p { color: #5f6368; font-size: 14px; margin-bottom: 12px; line-height: 1.6; }

        .password-requirements {
            margin-top: 8px; padding: 12px; background: #f8f9fa; border-radius: 4px;
            font-size: 13px; color: #5f6368;
        }
        .password-requirements ul { margin: 8px 0 0 20px; }
        .password-requirements li { margin-bottom: 4px; }
        .password-requirements li.valid { color: #137333; }
        .password-requirements li.invalid { color: #c5221f; }

        .hidden { display: none; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #fff; border-top-color: transparent; border-radius: 50%; animation: spin 0.6s linear infinite; vertical-align: middle; margin-right: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media screen and (max-width: 480px) {
            .container { padding: 32px 24px; }
        }
    </style>
</head>
<body>
    <div class="container">

        <?php if ($mode === 'request'): ?>
        <!-- ============ REQUEST MODE ============ -->
        <div id="request-view">
            <div class="header">
                <h1>Reset Your Password</h1>
                <p>Enter your email address and we'll send you a link to reset your password.</p>
            </div>

            <div id="request-message" class="hidden"></div>

            <form id="request-form">
                <div class="form-group">
                    <label for="reset-email">Email address</label>
                    <input type="email" id="reset-email" placeholder="Enter your email" required autofocus>
                </div>
                <button type="submit" class="submit-btn" id="request-btn">Send Reset Link</button>
            </form>

            <!-- Fallback shown when Mailgun not configured -->
            <div id="fallback-view" class="hidden">
                <div class="fallback-box">
                    <p>Email-based password reset is not available on this site.</p>
                    <p>Please contact your site administrator to request a temporary password.</p>
                </div>
            </div>
        </div>

        <!-- Success view (after email sent) -->
        <div id="sent-view" class="hidden">
            <div class="header">
                <h1>Reset Link Sent</h1>
                <p>If an account with that email exists, we've sent a password reset link to its recovery channel &mdash; check your email inbox (and spam folder), or the site administrator's Telegram.</p>
            </div>
            <div class="message info">
                The link will expire in 1 hour. If it doesn't arrive within a few minutes, try again or contact your site administrator.
            </div>
        </div>

        <?php else: ?>
        <!-- ============ RESET MODE ============ -->
        <div id="reset-view">
            <div class="header">
                <h1>Set New Password</h1>
                <p>Choose a strong password for your account.</p>
            </div>

            <!-- Loading state while validating token -->
            <div id="token-loading" class="message info">Validating reset link...</div>

            <!-- Invalid token message -->
            <div id="token-invalid" class="hidden"></div>

            <!-- Password form (shown after token validated) -->
            <div id="reset-form-wrap" class="hidden">
                <div id="reset-message" class="hidden"></div>

                <form id="reset-form">
                    <div class="form-group">
                        <label for="new-password">New password</label>
                        <div class="input-wrapper">
                            <input type="password" id="new-password" placeholder="Enter new password" required>
                            <button type="button" class="password-toggle" onclick="togglePwd('new-password', this)">&#128065;</button>
                        </div>
                        <div class="password-requirements">
                            Password must contain:
                            <ul>
                                <li id="req-length">At least 8 characters</li>
                                <li id="req-upper">One uppercase letter</li>
                                <li id="req-lower">One lowercase letter</li>
                                <li id="req-number">One number</li>
                                <li id="req-special">One special character</li>
                            </ul>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm-password">Confirm password</label>
                        <div class="input-wrapper">
                            <input type="password" id="confirm-password" placeholder="Confirm new password" required>
                            <button type="button" class="password-toggle" onclick="togglePwd('confirm-password', this)">&#128065;</button>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="reset-btn">Reset Password</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <a href="/admin/login.php" class="back-link">&larr; Back to Login</a>
    </div>

    <script>
    (function() {
        const API = '/admin/modules/UserManager/password-reset_api.php';
        const MODE = <?php echo json_encode($mode); ?>;
        const TOKEN = <?php echo json_encode($token); ?>;

        function showMsg(elId, text, type) {
            const el = document.getElementById(elId);
            el.className = 'message ' + type;
            el.textContent = text;
            el.classList.remove('hidden');
        }

        function togglePwd(id, btn) {
            const input = document.getElementById(id);
            if (input.type === 'password') { input.type = 'text'; btn.innerHTML = '&#128064;'; }
            else { input.type = 'password'; btn.innerHTML = '&#128065;'; }
        }
        window.togglePwd = togglePwd;

        /* ---- REQUEST MODE ---- */
        if (MODE === 'request') {
            const form = document.getElementById('request-form');
            const btn = document.getElementById('request-btn');

            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const email = document.getElementById('reset-email').value.trim();
                if (!email) return;

                btn.disabled = true;
                btn.innerHTML = '<span class="spinner"></span>Sending...';

                try {
                    const resp = await fetch(API + '?action=request', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ email: email })
                    });
                    const data = await resp.json();

                    if (data.no_email) {
                        // Mailgun not configured — show fallback
                        form.classList.add('hidden');
                        document.getElementById('fallback-view').classList.remove('hidden');
                        return;
                    }

                    if (data.ok) {
                        document.getElementById('request-view').classList.add('hidden');
                        document.getElementById('sent-view').classList.remove('hidden');
                    } else {
                        showMsg('request-message', data.error || 'An error occurred.', 'error');
                    }
                } catch (err) {
                    showMsg('request-message', 'Network error. Please try again.', 'error');
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Send Reset Link';
                }
            });
        }

        /* ---- RESET MODE ---- */
        if (MODE === 'reset') {
            // Validate token on load
            (async function() {
                try {
                    const resp = await fetch(API + '?action=validate&token=' + encodeURIComponent(TOKEN));
                    const data = await resp.json();

                    document.getElementById('token-loading').classList.add('hidden');

                    if (data.ok && data.valid) {
                        document.getElementById('reset-form-wrap').classList.remove('hidden');
                    } else {
                        const el = document.getElementById('token-invalid');
                        el.className = 'message error';
                        el.textContent = data.reason || 'This reset link is invalid or has expired.';
                        el.classList.remove('hidden');
                    }
                } catch (err) {
                    document.getElementById('token-loading').classList.add('hidden');
                    const el = document.getElementById('token-invalid');
                    el.className = 'message error';
                    el.textContent = 'Could not validate reset link. Please try again.';
                    el.classList.remove('hidden');
                }
            })();

            // Password strength indicators
            const pwdInput = document.getElementById('new-password');
            if (pwdInput) {
                pwdInput.addEventListener('input', function() {
                    const p = this.value;
                    document.getElementById('req-length').className = p.length >= 8 ? 'valid' : 'invalid';
                    document.getElementById('req-upper').className = /[A-Z]/.test(p) ? 'valid' : 'invalid';
                    document.getElementById('req-lower').className = /[a-z]/.test(p) ? 'valid' : 'invalid';
                    document.getElementById('req-number').className = /[0-9]/.test(p) ? 'valid' : 'invalid';
                    document.getElementById('req-special').className = /[^A-Za-z0-9]/.test(p) ? 'valid' : 'invalid';
                });
            }

            // Submit new password
            const form = document.getElementById('reset-form');
            if (form) {
                form.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const password = document.getElementById('new-password').value;
                    const confirm = document.getElementById('confirm-password').value;
                    const btn = document.getElementById('reset-btn');

                    if (password !== confirm) {
                        showMsg('reset-message', 'Passwords do not match.', 'error');
                        return;
                    }

                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner"></span>Resetting...';

                    try {
                        const resp = await fetch(API + '?action=reset', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ token: TOKEN, password: password, confirm: confirm })
                        });
                        const data = await resp.json();

                        if (data.ok) {
                            window.location.href = '/admin/login.php?msg=password_reset';
                        } else {
                            showMsg('reset-message', data.error || 'Reset failed.', 'error');
                        }
                    } catch (err) {
                        showMsg('reset-message', 'Network error. Please try again.', 'error');
                    } finally {
                        btn.disabled = false;
                        btn.textContent = 'Reset Password';
                    }
                });
            }
        }
    })();
    </script>
</body>
</html>
