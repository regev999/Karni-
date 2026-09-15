<?php
declare(strict_types=1);

function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function admin_configured(): bool
{
    return (settings()['admin_hash'] ?? '') !== '';
}

function admin_login(string $password): bool
{
    session_start_once();
    if (!admin_configured() || !password_verify($password, settings()['admin_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    return true;
}

function admin_logged_in(): bool
{
    session_start_once();
    return !empty($_SESSION['admin']);
}

/** Send anyone who is not signed in back to the login screen. */
function admin_require(): void
{
    if (!admin_logged_in()) {
        header('Location: index.php');
        exit;
    }
}

function csrf_token(): string
{
    session_start_once();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    session_start_once();
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Bad CSRF token');
    }
}
