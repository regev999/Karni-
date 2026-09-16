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

/* ---------------------------------------------------------- brute force -- */

const LOGIN_FREE_TRIES = 3;     // typing it wrong twice is a person, not an attack
const LOGIN_MAX_WAIT   = 900;   // and the wait never grows past a quarter of an hour
const LOGIN_FORGET     = 3600;  // an address that stopped trying is forgotten

/**
 * The wait is per address and doubles with every wrong guess past the first
 * few, which costs a person nothing and turns a script from thousands of
 * guesses an hour into a few dozen. It is deliberately not a global counter:
 * one attacker could otherwise lock the owner out of their own panel.
 */
function login_key(): string
{
    return sha1((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

/** Seconds this address still has to wait, 0 when it may try. */
function login_locked_for(): int
{
    $row = (read_json(LOGINS_FILE, []) ?: [])[login_key()] ?? null;
    return is_array($row) ? max(0, (int) ($row['until'] ?? 0) - time()) : 0;
}

function login_failed(): void
{
    json_update(LOGINS_FILE, static function (array $rows): array {
        foreach ($rows as $k => $r) {
            if (time() - (int) ($r['seen'] ?? 0) > LOGIN_FORGET) {
                unset($rows[$k]);
            }
        }
        $key = login_key();
        $fails = (int) ($rows[$key]['fails'] ?? 0) + 1;
        $wait = $fails <= LOGIN_FREE_TRIES
            ? 0
            : (int) min(2 ** ($fails - LOGIN_FREE_TRIES), LOGIN_MAX_WAIT);
        $rows[$key] = ['fails' => $fails, 'until' => time() + $wait, 'seen' => time()];
        return $rows;
    });
}

function login_succeeded(): void
{
    json_update(LOGINS_FILE, static function (array $rows): array {
        unset($rows[login_key()]);
        return $rows;
    });
}

/** "בעוד 45 שניות" / "בעוד 3 דקות" — whichever reads better. */
function wait_text(int $seconds): string
{
    return $seconds >= 60
        ? 'בעוד ' . (int) ceil($seconds / 60) . ' דקות'
        : 'בעוד ' . max(1, $seconds) . ' שניות';
}

function admin_login(string $password): bool
{
    session_start_once();
    // Checked here rather than in the page, so no future caller can forget it.
    if (login_locked_for() > 0) {
        return false;
    }
    if (!admin_configured() || !password_verify($password, settings()['admin_hash'])) {
        login_failed();
        return false;
    }
    login_succeeded();
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
