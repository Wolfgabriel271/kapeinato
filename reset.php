<?php
// ============================================================
// reset.php — Administrative data wipe (auth-guarded)
// ============================================================

require_once __DIR__ . '/helpers.php';

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    die("<h2 style='font-family:sans-serif;text-align:center;margin-top:80px;color:#ef4444;'>
        403 — Forbidden<br>
        <a href='login.php' style='font-size:1rem;color:#E8A040;text-decoration:none;'>← Admin Login</a>
    </h2>");
}

/*
 * This file performs a full reset of temporary operational data used by the application.
 * It is intended for administrative cleanup, so it starts by loading the shared database connection from `db.php`.
 */
require 'db.php';

/*
 * Foreign key checks are disabled for a short time so the truncate operations can run without relational constraint errors.
 * This is technically necessary because child and parent tables may reference each other, and MySQL would otherwise block destructive operations that break those dependencies mid-process.
 */
$conn->query("SET FOREIGN_KEY_CHECKS = 0");

/*
 * These TRUNCATE statements remove all rows from the transactional and analytics-related tables in a fast, table-level operation.
 * Unlike DELETE without a WHERE clause, TRUNCATE also resets the auto-increment counters, which is why future order records start again from ID 1 after the reset.
 */
$conn->query("TRUNCATE TABLE online_orders");
$conn->query("TRUNCATE TABLE online_order_items");
$conn->query("TRUNCATE TABLE feedback");

/*
 * Foreign key enforcement is immediately turned back on after the reset queries finish.
 * Re-enabling this database safety feature ensures that future inserts, updates, and deletes continue to respect referential integrity rules across related tables.
 */
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

/*
 * The action log text file is cleared so that old administrative or test activity does not remain after the system reset.
 * Technically, `file_put_contents` with an empty string overwrites the file contents in place, leaving the file itself present but with zero stored log data.
 */
file_put_contents("action_logs.txt", "");

/*
 * The remaining output renders a simple success message directly in HTML so the administrator gets immediate visual confirmation in the browser.
 * Each echoed string builds part of the response markup, including status text and a navigation link back to the admin dashboard, without requiring a separate template file.
 */
echo "<div style='font-family: Arial; text-align: center; margin-top: 50px;'>";
echo "<h1 style='color: #22c55e;'>✅ System Reset Successful!</h1>";
echo "<p>All test orders, analytics, feedback, and logs have been wiped.</p>";
echo "<p>Your Order IDs will start at <b>#1</b> again.</p>";
echo "<p><i>(Don't worry, your Menu Items were kept safe!)</i></p>";
echo "<br><a href='admin.php' style='padding: 10px 20px; background: #E8A040; color: #000; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go back to Admin Dashboard</a>";
echo "</div>";

/*
 * The closing PHP tag marks the end of this script after the reset and confirmation output have completed.
 * In mixed PHP/HTML files this tag separates server-side execution from any following content, although here it simply cleanly terminates the file.
 */
?>