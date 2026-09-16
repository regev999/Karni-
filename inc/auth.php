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

/* ----------------------------------------------------------------- reset -- */

const RESET_TTL   = 1800;   // half an hour is long enough to find the mail
const RESET_PAUSE = 600;    // and one request per ten minutes is enough to ask

/**
 * Start a reset: mint a one-time token and keep only its hash. Null when one
 * was asked for too recently, or when there is no address to send it to. The
 * caller turns the token into a link, so this file stays free of URL building.
 */
function reset_start(): ?string
{
    $s = settings();
    $stamp = DATA_DIR . '/.reset_stamp';
    if (is_file($stamp) && time() - (int) filemtime($stamp) < RESET_PAUSE) {
        return null;
    }
    if (!array_filter((array) ($s['lead_emails'] ?? []), static fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))) {
        return null;
    }
    @touch($stamp);

    $token = bin2hex(random_bytes(32));
    save_settings([
        'reset_hash'    => hash('sha256', $token),
        'reset_expires' => time() + RESET_TTL,
    ]);
    return $token;
}

/** Is this token the live one, and still in date? */
function reset_valid(string $token): bool
{
    $s = settings();
    $hash = (string) ($s['reset_hash'] ?? '');
    return $token !== '' && $hash !== ''
        && time() < (int) ($s['reset_expires'] ?? 0)
        && hash_equals($hash, hash('sha256', $token));
}

/** Set the new password and burn the token, whatever else happens. */
function reset_complete(string $password): void
{
    save_settings([
        'admin_hash'    => password_hash($password, PASSWORD_DEFAULT),
        'reset_hash'    => '',
        'reset_expires' => 0,
    ]);
    @unlink(DATA_DIR . '/.reset_stamp');
}
