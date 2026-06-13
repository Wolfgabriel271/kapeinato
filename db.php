<?php
// ============================================================
//  db.php — Database Connection
//  Credentials are loaded from config.php outside the web root.
//  Nothing sensitive is written in this file.
// ============================================================

require_once __DIR__ . '/helpers.php';

// ---- Load config from above htdocs ----
// Fix #9: Use __DIR__ so the path resolves correctly from any call site.
$_config_path = __DIR__ . '/../config.php';

if (file_exists($_config_path)) {
    // Normal path: config.php is one level above htdocs
    require_once $_config_path;
} else {
    // ---- Fallback: InfinityFree .htaccess environment variables ----
    // If you cannot place config.php above htdocs, add these lines
    // to your /htdocs/.htaccess file instead:
    //
    //   SetEnv DB_HOST      sql211.infinityfree.com
    //   SetEnv DB_USER      if0_42007209
    //   SetEnv DB_PASS      your_actual_password
    //   SetEnv DB_NAME      if0_42007209_kapeinato_db
    //   SetEnv SMTP_USER    yourgmail@gmail.com
    //   SetEnv SMTP_PASS    xxxx xxxx xxxx xxxx
    //
    // Then this fallback block will pick them up automatically.
    define('DB_HOST',      getenv('DB_HOST')   ?: '');
    define('DB_USER',      getenv('DB_USER')   ?: '');
    define('DB_PASS',      getenv('DB_PASS')   ?: '');
    define('DB_NAME',      getenv('DB_NAME')   ?: '');
    define('SMTP_HOST',    'smtp.gmail.com');
    define('SMTP_PORT',    587);
    define('SMTP_USER',    getenv('SMTP_USER') ?: '');
    define('SMTP_PASS',    getenv('SMTP_PASS') ?: '');
    define('SMTP_FROM_NAME', 'Kape Inato');
}

// Fix #9: Bail early with a clear log message if config constants are empty.
// This prevents a confusing "Access denied for user ''" MySQL error.
if (!DB_HOST || !DB_USER || !DB_NAME) {
    error_log('[Kape Inato] db.php: DB constants empty — config.php missing or unreadable at ' . $_config_path);
    $is_ajax = (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    );
    if ($is_ajax) {
        http_response_code(503);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Service temporarily unavailable. Please try again.']));
    }
    http_response_code(503);
    die("
    <div style='font-family:sans-serif;text-align:center;padding:80px 24px;
                background:#080a0e;color:#F5F0E8;min-height:100vh;'>
        <p style='font-size:2rem;margin-bottom:16px;'>☕</p>
        <h2 style='color:#E8A040;font-size:1.4rem;margin-bottom:12px;'>We'll be right back</h2>
        <p style='color:#5A5248;font-size:0.9rem;'>Having a small technical hiccup. Please try again in a moment.</p>
        <a href='index.php' style='display:inline-block;margin-top:28px;padding:10px 24px;
           background:#E8A040;color:#080a0e;border-radius:40px;text-decoration:none;
           font-weight:600;font-size:0.85rem;'>&larr; Back to Home</a>
    </div>");
}

// ---- Establish Connection ----
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// ---- Connection Error Handling ----
// Never expose raw database errors to the browser (Fix #9).
// Errors are written to the server error log instead.
if ($conn->connect_error) {
    error_log('[Kape Inato] DB connection failed: ' . $conn->connect_error);

    // If this is an AJAX/JSON request, return a clean JSON error
    $is_ajax = (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    );

    if ($is_ajax) {
        http_response_code(503);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Service temporarily unavailable. Please try again.']));
    }

    // For regular page loads, show a friendly message — no stack traces
    http_response_code(503);
    die("
    <div style='font-family:sans-serif;text-align:center;padding:80px 24px;
                background:#080a0e;color:#F5F0E8;min-height:100vh;'>
        <p style='font-size:2rem;margin-bottom:16px;'>☕</p>
        <h2 style='color:#E8A040;font-size:1.4rem;margin-bottom:12px;'>
            We'll be right back
        </h2>
        <p style='color:#5A5248;font-size:0.9rem;'>
            Having a small technical hiccup. Please try again in a moment.
        </p>
        <a href='index.php' style='display:inline-block;margin-top:28px;
           padding:10px 24px;background:#E8A040;color:#080a0e;border-radius:40px;
           text-decoration:none;font-weight:600;font-size:0.85rem;'>
            &larr; Back to Home
        </a>
    </div>
    ");
}

// ---- Set Character Encoding ----
$conn->set_charset('utf8mb4');
