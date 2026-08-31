<?php
/**
 * Luminal CMS - Admin Login System
 * @file /admin/login.php
 * @version 2026.02.03.1200.EST
 * @description Google-style login with RBAC support and first-run setup wizard
 */

require_once __DIR__ . '/includes/session_boot.php';   // long-lived admin session params BEFORE session_start
session_start();

// ── Debug logging (admin/data/login-debug.log) — OPT-IN, and never identifying ──
//
// This used to run unconditionally on every login attempt, on every site, and
// recorded the account identifier, the PASSWORD LENGTH and the first 15 chars
// of the bcrypt hash (algorithm + cost + salt head). One site had
// accumulated 158 KB of that — a permanent who-logs-in-here record sitting in a
// client's webroot tree on a site heading for handoff. admin/data/ is
// 403-blocked from the web, so it was never publicly readable, but it should
// not have been written in the first place and must not travel with the site.
//
// Now: OFF unless someone deliberately drops the marker file, and even then the
// identifying material is gone for good — the callers below log outcomes
// ("user found", "verify failed"), never who or what.
//
//   turn on : touch admin/data/.login-debug-enabled
//   turn off: rm    admin/data/.login-debug-enabled
//
// The log self-rotates at 256 KB so it can never grow without bound again.
function login_debug_enabled(): bool {
    static $on = null;
    if ($on !== null) return $on;
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/..');
    return $on = is_file($siteRoot . '/admin/data/.login-debug-enabled');
}

function login_debug(string $msg): void {
    if (!login_debug_enabled()) return;
    $siteRoot = defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/..');
    $logFile = $siteRoot . '/admin/data/login-debug.log';
    if (@filesize($logFile) > 262144) {
        @rename($logFile, $logFile . '.1');   // single generation, no unbounded growth
    }
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
    @chmod($logFile, 0600);
}
login_debug("=== LOGIN PAGE LOADED === METHOD={$_SERVER['REQUEST_METHOD']} URI={$_SERVER['REQUEST_URI']}");

// Include auth functions
require_once __DIR__ . '/auth.php';
login_debug("auth.php loaded. SITE_ROOT=" . (defined('SITE_ROOT') ? SITE_ROOT : 'NOT DEFINED'));

// Check users.json status
$usersFile = (defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/..')) . '/admin/data/users.json';
login_debug("users.json path: $usersFile exists=" . (file_exists($usersFile) ? 'YES' : 'NO') . " readable=" . (is_readable($usersFile) ? 'YES' : 'NO') . " writable=" . (is_writable($usersFile) ? 'YES' : 'NO'));
if (file_exists($usersFile)) {
    $perms = substr(sprintf('%o', fileperms($usersFile)), -4);
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($usersFile))['name'] ?? fileowner($usersFile)) : fileowner($usersFile);
    login_debug("users.json perms=$perms owner=$owner size=" . filesize($usersFile));
}
$dataDir = dirname($usersFile);
login_debug("admin/data/ dir writable=" . (is_writable($dataDir) ? 'YES' : 'NO'));

// Check if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    login_debug("Session exists: role={$_SESSION['user_role']}");
    $user = findUserById($_SESSION['user_id']);
    if ($user && $user['status'] === 'active') {
        login_debug("Valid session, redirecting to dashboard");
        header('Location: dashboard.php');
        exit;
    }
    login_debug("Session user not found or inactive");
} else {
    login_debug("No active session");
}

$artistName = htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'Site Admin', ENT_QUOTES, 'UTF-8');
$error_message = '';
$success_message = '';
$showSetup = !hasAnyUsers();
$lockoutMinutes = 0;
login_debug("hasAnyUsers()=" . ($showSetup ? 'NO (showSetup=true)' : 'YES (showSetup=false)'));

// ── Mothership Provisioning Detection ──
$provisionFile = (defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/..')) . '/admin/data/.provision_reset';
$showProvision = false;
$provisionToken = '';
$provisionSource = '';
$provisionHasUsers = hasAnyUsers();

if (is_file($provisionFile)) {
    $prov = json_decode(file_get_contents($provisionFile), true);
    if ($prov && !empty($prov['expires_at']) && strtotime($prov['expires_at']) > time()) {
        $showProvision = true;
        $provisionToken = $prov['token'] ?? '';
        $provisionSource = $prov['source'] ?? 'unknown';
    } else {
        @unlink($provisionFile);
    }
}

// Handle provisioned password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['provision_reset'])) {
    $submittedToken = $_POST['provision_token'] ?? '';

    // Re-read provision file to validate token
    if (!is_file($provisionFile)) {
        $error_message = 'Provision token has expired or been used.';
    } else {
        $prov = json_decode(file_get_contents($provisionFile), true);
        if (!$prov || empty($prov['token']) || !hash_equals($prov['token'], $submittedToken)) {
            $error_message = 'Invalid provision token.';
        } elseif (strtotime($prov['expires_at'] ?? '0') <= time()) {
            @unlink($provisionFile);
            $error_message = 'Provision token has expired.';
        } else {
            $email = trim($_POST['email'] ?? '');
            $displayName = trim($_POST['display_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($password !== $confirmPassword) {
                $error_message = 'Passwords do not match.';
                $showProvision = true;
            } else {
                $passwordErrors = validatePassword($password);
                if (!empty($passwordErrors)) {
                    $error_message = implode(' ', $passwordErrors);
                    $showProvision = true;
                } else {
                    $data = loadUsersData();
                    $targetUser = null;

                    if (!empty($data['users'])) {
                        // Find first superadmin to reset
                        foreach ($data['users'] as &$u) {
                            if (($u['role'] ?? '') === 'superadmin' && ($u['status'] ?? '') === 'active') {
                                $targetUser = &$u;
                                break;
                            }
                        }
                        unset($u);

                        if ($targetUser) {
                            // Reset existing superadmin password
                            $targetUser['password_hash'] = hashPassword($password);
                            $targetUser['password_reset_token'] = null;
                            $targetUser['password_reset_expires'] = null;
                            $targetUser['updated_at'] = date('c');
                            saveUsersData($data);

                            // Auto-login
                            session_regenerate_id(true);
                            $_SESSION['user_id'] = $targetUser['id'];
                            $_SESSION['user_role'] = $targetUser['role'];
                            $_SESSION['user_email'] = $targetUser['email'];
                            $_SESSION['user_display_name'] = $targetUser['display_name'];
                            $_SESSION['login_time'] = time();

                            @unlink($provisionFile);
                            header('Location: dashboard.php');
                            exit;
                        } else {
                            $error_message = 'No active superadmin found to reset.';
                            $showProvision = true;
                        }
                    } else {
                        // No users — create new superadmin
                        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $error_message = 'Please enter a valid email address.';
                            $showProvision = true;
                        } elseif (empty($displayName)) {
                            $error_message = 'Please enter your display name.';
                            $showProvision = true;
                        } else {
                            $result = createUser([
                                'email' => $email,
                                'username' => explode('@', $email)[0],
                                'display_name' => $displayName,
                                'password' => $password,
                                'role' => 'superadmin',
                            ]);

                            if ($result['success']) {
                                session_regenerate_id(true);
                                $_SESSION['user_id'] = $result['user']['id'];
                                $_SESSION['user_role'] = $result['user']['role'];
                                $_SESSION['user_email'] = $result['user']['email'];
                                $_SESSION['user_display_name'] = $result['user']['display_name'];
                                $_SESSION['login_time'] = time();

                                @unlink($provisionFile);
                                header('Location: dashboard.php');
                                exit;
                            } else {
                                $error_message = $result['error'];
                                $showProvision = true;
                            }
                        }
                    }
                }
            }
        }
    }
}

// Handle setup form submission (first-run)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    login_debug("SETUP FORM SUBMITTED");
    $email = trim($_POST['email'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    login_debug("Setup: submitted (identifier and password length deliberately not logged)");

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
        login_debug("Setup FAIL: invalid email");
    } elseif (empty($displayName)) {
        $error_message = 'Please enter your display name.';
        login_debug("Setup FAIL: empty display name");
    } elseif ($password !== $confirmPassword) {
        $error_message = 'Passwords do not match.';
        login_debug("Setup FAIL: passwords don't match");
    } else {
        login_debug("Setup: calling createUser()...");
        $result = createUser([
            'email' => $email,
            'username' => explode('@', $email)[0],
            'display_name' => $displayName,
            'password' => $password,
            'role' => 'superadmin'
        ]);
        login_debug("createUser result: " . json_encode(['success' => $result['success'] ?? false, 'error' => $result['error'] ?? null]));

        if ($result['success']) {
            // Auto-login after setup
            $_SESSION['user_id'] = $result['user']['id'];
            $_SESSION['user_role'] = $result['user']['role'];
            $_SESSION['user_email'] = $result['user']['email'];
            $_SESSION['user_display_name'] = $result['user']['display_name'];
            $_SESSION['login_time'] = time();

            login_debug("Setup SUCCESS: user created, session set, redirecting to dashboard");
            // Verify the file was actually written
            if (file_exists($usersFile)) {
                login_debug("users.json exists after create, size=" . filesize($usersFile));
            } else {
                login_debug("WARNING: users.json DOES NOT EXIST after createUser!");
            }

            header('Location: dashboard.php');
            exit;
        } else {
            $error_message = $result['error'];
            login_debug("Setup FAIL: " . ($result['error'] ?? 'unknown error'));
        }
    }
    $showSetup = true;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    login_debug("LOGIN FORM SUBMITTED");
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    login_debug("Login: credentials submitted");

    if (empty($identifier) || empty($password)) {
        $error_message = 'Please enter your email and password.';
        login_debug("Login FAIL: empty identifier or password");
    } else {
        // Check for lockout
        if (!checkLoginAttempts($identifier)) {
            $lockoutMinutes = getLockoutRemainingMinutes($identifier);
            $error_message = "Account temporarily locked. Try again in {$lockoutMinutes} minute(s).";
            login_debug("Login FAIL: account locked for $lockoutMinutes min");
        } else {
            $user = findUserByIdentifier($identifier);
            login_debug("findUserByIdentifier: " . ($user ? "FOUND role={$user['role']} status={$user['status']}" : "NOT FOUND"));

            $pwdVerify = $user ? verifyPassword($password, $user['password_hash']) : false;
            login_debug("verifyPassword: " . ($pwdVerify ? 'PASS' : 'FAIL'));

            if ($user && $user['status'] === 'active' && $pwdVerify) {
                // Successful login
                recordLoginAttempt($identifier, true);
                updateLastLogin($user['id']);

                // Regenerate session ID for security
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_display_name'] = $user['display_name'];
                $_SESSION['login_time'] = time();

                login_debug("Login SUCCESS: role={$user['role']} → redirecting to dashboard");

                // Handle remember me (extend session cookie)
                if ($remember) {
                    $lifetime = 60 * 60 * 24 * 30; // 30 days
                    $params = session_get_cookie_params();
                    setcookie(session_name(), session_id(), [
                        'expires' => time() + $lifetime,
                        'path' => $params['path'],
                        'domain' => $params['domain'],
                        'secure' => $params['secure'],
                        'httponly' => $params['httponly'],
                        'samesite' => 'Lax'
                    ]);
                }

                header('Location: dashboard.php');
                exit;
            } else {
                recordLoginAttempt($identifier, false);

                if ($user && $user['status'] !== 'active') {
                    $error_message = 'This account has been deactivated.';
                    login_debug("Login FAIL: account deactivated");
                } else {
                    $error_message = 'Invalid email or password.';
                    login_debug("Login FAIL: bad credentials. user_found=" . ($user ? 'YES' : 'NO') . " status=" . ($user['status'] ?? 'n/a') . " pwd_verify=" . ($pwdVerify ? 'PASS' : 'FAIL'));
                }
            }
        }
    }
}

// Check for error messages from redirects
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'session_invalid':
            $error_message = 'Your session has expired. Please log in again.';
            break;
        case 'access_denied':
            $error_message = 'You do not have permission to access that page.';
            break;
    }
}

if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'password_reset':
            $success_message = 'Your password has been reset successfully. Please sign in with your new password.';
            break;
    }
}

if (isset($_GET['notice']) && $_GET['notice'] === 'emergency_access_granted') {
    $success_message = 'Emergency access granted! Your IP has been whitelisted. You may now log in.';
}

// Google Auth error messages from OAuth callback
if (isset($_GET['error'])) {
    $gaErrors = [
        'google_auth_not_configured' => 'Google sign-in is not configured on this site.',
        'google_denied'              => 'Google sign-in was cancelled or denied.',
        'google_state_mismatch'      => 'Security check failed. Please try again.',
        'google_no_code'             => 'No authorization code received from Google.',
        'google_token_exchange_failed' => 'Failed to verify with Google. Please try again.',
        'google_no_email'            => 'Could not retrieve your email from Google.',
        'google_domain_not_allowed'  => 'Your email domain is not authorized for this site.',
        'google_no_account'          => 'No account found for your email. Contact an admin.',
        'account_deactivated'        => 'Your account has been deactivated.',
    ];
    $errKey = $_GET['error'];
    if (isset($gaErrors[$errKey]) && empty($error_message)) {
        $error_message = $gaErrors[$errKey];
    }
}

// Check if Google Auth is available and enabled
$googleAuthEnabled = false;
$googleAuthConfigFile = (defined('SITE_ROOT') ? SITE_ROOT : realpath(__DIR__ . '/..')) . '/admin/data/GoogleAuth/config.json';
if (is_file($googleAuthConfigFile)) {
    $gaConfig = json_decode(file_get_contents($googleAuthConfigFile), true);
    if (!empty($gaConfig['enabled']) && !empty($gaConfig['client_id'])) {
        $googleAuthEnabled = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $artistName; ?> - Admin Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .login-container {
            background: #ffffff;
            border-radius: 8px;
            padding: 48px 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1), 0 10px 40px rgba(0, 0, 0, 0.2);
            color: #202124;
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .site-logo {
            width: 75px;
            height: 75px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #4285f4, #34a853, #fbbc05, #ea4335);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 500;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 400;
            color: #202124;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #5f6368;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #5f6368;
            font-size: 14px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

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

        .form-group input:focus {
            border-color: #1a73e8;
            box-shadow: 0 0 0 2px rgba(26, 115, 232, 0.2);
        }

        .form-group input::placeholder {
            color: #9aa0a6;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #5f6368;
            font-size: 18px;
        }

        .password-toggle:hover {
            color: #202124;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #1a73e8;
        }

        .remember-me span {
            color: #5f6368;
            font-size: 14px;
        }

        .forgot-link {
            color: #1a73e8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .login-button {
            width: 100%;
            padding: 12px 24px;
            background: #1a73e8;
            border: none;
            border-radius: 4px;
            color: #ffffff;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }

        .login-button:hover {
            background: #1557b0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        .login-button:disabled {
            background: #dadce0;
            cursor: not-allowed;
        }

        .message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .message.error {
            background: #fce8e6;
            color: #c5221f;
            border: 1px solid #f5c6cb;
        }

        .message.success {
            background: #e6f4ea;
            color: #137333;
            border: 1px solid #ceead6;
        }

        .back-link {
            text-align: center;
            margin-top: 24px;
        }

        .back-link a {
            color: #5f6368;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link a:hover {
            color: #1a73e8;
        }

        /* Setup wizard specific styles */
        .setup-step {
            text-align: center;
            margin-bottom: 24px;
            padding: 12px;
            background: #e8f0fe;
            border-radius: 4px;
            color: #1967d2;
            font-size: 14px;
        }

        .password-requirements {
            margin-top: 8px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 4px;
            font-size: 13px;
            color: #5f6368;
        }

        .password-requirements ul {
            margin: 8px 0 0 20px;
        }

        .password-requirements li {
            margin-bottom: 4px;
        }

        .password-requirements li.valid {
            color: #137333;
        }

        .password-requirements li.invalid {
            color: #c5221f;
        }

        /* Google Sign-In button */
        .google-divider {
            text-align: center;
            margin: 20px 0;
            position: relative;
        }
        .google-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            border-top: 1px solid #dadce0;
        }
        .google-divider span {
            background: #ffffff;
            padding: 0 12px;
            position: relative;
            color: #5f6368;
            font-size: 14px;
        }
        .google-signin-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            width: 100%;
            padding: 14px 24px;
            background: #ffffff;
            border: 2px solid #4285F4;
            border-radius: 4px;
            color: #3c4043;
            font-size: 15px;
            font-weight: 500;
            font-family: 'Roboto', sans-serif;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 4px rgba(66,133,244,0.15);
        }
        .google-signin-btn:hover {
            background: #f0f6ff;
            box-shadow: 0 2px 8px rgba(66,133,244,0.25);
            transform: translateY(-1px);
        }
        .google-signin-btn svg {
            flex-shrink: 0;
        }
        .google-signin-top {
            margin-bottom: 24px;
        }
        .email-divider {
            position: relative;
            text-align: center;
            margin: 0 0 24px;
        }
        .email-divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            border-top: 1px solid #dadce0;
        }
        .email-divider span {
            background: #ffffff;
            padding: 0 12px;
            position: relative;
            color: #80868b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        @media screen and (max-width: 480px) {
            .login-container {
                padding: 32px 24px;
            }

            .form-options {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="site-logo">
                <?php echo strtoupper(substr($artistName, 0, 1)); ?>
            </div>
            <?php if ($showProvision): ?>
                <h1>Password Reset</h1>
                <p>Provisioned by <?php echo htmlspecialchars($provisionSource); ?></p>
            <?php elseif ($showSetup): ?>
                <h1>Welcome!</h1>
                <p>Set up your admin account</p>
            <?php else: ?>
                <h1>Sign in</h1>
                <p><?php echo $artistName; ?> Admin Panel</p>
            <?php endif; ?>
        </div>

        <?php if ($error_message): ?>
            <div class="message error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="message success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($showProvision): ?>
            <!-- Mothership-provisioned password reset -->
            <div class="setup-step" style="background:#fef3c7;color:#92400e;border:1px solid #f59e0b">
                <?php if ($provisionHasUsers): ?>
                    A password reset has been provisioned for this site. Set a new superadmin password below.
                <?php else: ?>
                    This site has been provisioned for setup. Create your superadmin account below.
                <?php endif; ?>
            </div>

            <form method="POST" action="" id="provisionForm">
                <input type="hidden" name="provision_reset" value="1">
                <input type="hidden" name="provision_token" value="<?php echo htmlspecialchars($provisionToken); ?>">

                <?php if (!$provisionHasUsers): ?>
                    <div class="form-group">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                               placeholder="admin@example.com" required autofocus>
                    </div>

                    <div class="form-group">
                        <label for="display_name">Display name</label>
                        <input type="text" id="display_name" name="display_name"
                               value="<?php echo htmlspecialchars($_POST['display_name'] ?? ''); ?>"
                               placeholder="Your name" required>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="password"><?php echo $provisionHasUsers ? 'New password' : 'Password'; ?></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password"
                               placeholder="<?php echo $provisionHasUsers ? 'Enter new password' : 'Create a password'; ?>" required <?php echo $provisionHasUsers ? 'autofocus' : ''; ?>>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                            <span class="eye-icon">&#128065;</span>
                        </button>
                    </div>
                    <div class="password-requirements" id="passwordRequirements">
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
                    <label for="confirm_password">Confirm password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Confirm your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                            <span class="eye-icon">&#128065;</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-button" style="background:#d97706">Set Password & Sign In</button>
            </form>

        <?php elseif ($showSetup): ?>
            <!-- First-run setup form -->
            <div class="setup-step">
                No admin account found. Create your superadmin account to get started.
            </div>

            <form method="POST" action="" id="setupForm">
                <input type="hidden" name="setup" value="1">

                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           placeholder="admin@example.com" required autofocus>
                </div>

                <div class="form-group">
                    <label for="display_name">Display name</label>
                    <input type="text" id="display_name" name="display_name"
                           value="<?php echo htmlspecialchars($_POST['display_name'] ?? ''); ?>"
                           placeholder="Your name" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password"
                               placeholder="Create a password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('password', this)">
                            <span class="eye-icon">&#128065;</span>
                        </button>
                    </div>
                    <div class="password-requirements" id="passwordRequirements">
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
                    <label for="confirm_password">Confirm password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Confirm your password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)">
                            <span class="eye-icon">&#128065;</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="login-button" id="setupButton">Create Account</button>
            </form>

        <?php else: ?>
            <!-- Google Sign-In REMOVED from admin panel — admin is password-only -->
            <!-- Google Auth is available for member portal signups via /member/login -->

            <!-- Email/password login form -->
            <form method="POST" action="">
                <input type="hidden" name="login" value="1">

                <div class="form-group">
                    <label for="identifier">Email or username</label>
                    <input type="text" id="identifier" name="identifier"
                           value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>"
                           placeholder="Enter your email or username"
                           required <?php echo $lockoutMinutes ? 'disabled' : 'autofocus'; ?>>
                </div>

                <div class="form-group">
                    <label for="login_password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="login_password" name="password"
                               placeholder="Enter your password"
                               required <?php echo $lockoutMinutes ? 'disabled' : ''; ?>>
                        <button type="button" class="password-toggle" onclick="togglePassword('login_password', this)"
                                <?php echo $lockoutMinutes ? 'disabled' : ''; ?>>
                            <span class="eye-icon">&#128065;</span>
                        </button>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" <?php echo $lockoutMinutes ? 'disabled' : ''; ?>>
                        <span>Remember me</span>
                    </label>
                    <a href="modules/UserManager/password-reset.php" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="login-button" <?php echo $lockoutMinutes ? 'disabled' : ''; ?>>
                    <?php echo $lockoutMinutes ? "Locked ({$lockoutMinutes} min)" : 'Sign in'; ?>
                </button>
            </form>

            <?php /* Google sign-in moved to top of form */ ?>

        <?php endif; ?>

        <div class="back-link">
            <a href="/">&larr; Back to website</a>
            <span style="margin:0 8px;color:#dadce0;">|</span>
            <a href="/admin/emergency-access.php" style="color:#5f6368;text-decoration:none;font-size:14px;">Locked out?</a>
        </div>
    </div>

    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                button.innerHTML = '<span class="eye-icon">&#128064;</span>';
            } else {
                input.type = 'password';
                button.innerHTML = '<span class="eye-icon">&#128065;</span>';
            }
        }

        // Password strength validation for setup/provision form
        <?php if ($showSetup || $showProvision): ?>
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const pwd = this.value;

                // Check each requirement
                document.getElementById('req-length').className = pwd.length >= 8 ? 'valid' : 'invalid';
                document.getElementById('req-upper').className = /[A-Z]/.test(pwd) ? 'valid' : 'invalid';
                document.getElementById('req-lower').className = /[a-z]/.test(pwd) ? 'valid' : 'invalid';
                document.getElementById('req-number').className = /[0-9]/.test(pwd) ? 'valid' : 'invalid';
                document.getElementById('req-special').className = /[^A-Za-z0-9]/.test(pwd) ? 'valid' : 'invalid';
            });
        }

        const setupForm = document.getElementById('setupForm') || document.getElementById('provisionForm');
        if (setupForm) {
            setupForm.addEventListener('submit', function(e) {
                const pwd = passwordInput.value;
                const confirm = confirmInput.value;

                if (pwd !== confirm) {
                    e.preventDefault();
                    alert('Passwords do not match.');
                    return false;
                }

                const hasLength = pwd.length >= 8;
                const hasUpper = /[A-Z]/.test(pwd);
                const hasLower = /[a-z]/.test(pwd);
                const hasNumber = /[0-9]/.test(pwd);
                const hasSpecial = /[^A-Za-z0-9]/.test(pwd);

                if (!hasLength || !hasUpper || !hasLower || !hasNumber || !hasSpecial) {
                    e.preventDefault();
                    alert('Password does not meet all requirements.');
                    return false;
                }
            });
        }
        <?php endif; ?>

        // Auto-focus first empty input
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = document.querySelectorAll('input[type="text"], input[type="email"], input[type="password"]');
            for (const input of inputs) {
                if (!input.value && !input.disabled) {
                    input.focus();
                    break;
                }
            }
        });
    </script>
    <?php
    /* Password reveal — login page has no admin_footer, so include it directly. */
    $__pwr_js  = __DIR__ . '/shared/password-reveal/password-reveal.js';
    $__pwr_css = __DIR__ . '/shared/password-reveal/password-reveal.css';
    if (is_file($__pwr_js) && is_file($__pwr_css)) {
        $__pwrv = max((int)@filemtime($__pwr_js), (int)@filemtime($__pwr_css)) ?: time();
        echo '<link rel="stylesheet" href="/admin/shared/password-reveal/password-reveal.css?v=' . $__pwrv . '">' . "\n";
        echo '<script src="/admin/shared/password-reveal/password-reveal.js?v=' . $__pwrv . '"></script>' . "\n";
    }
    ?>
</body>
</html>
