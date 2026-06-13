<?php
// ============================================================
// notifications.php — Fix #6: Real-Time Order Notifications
// ============================================================

require_once __DIR__ . '/helpers.php';

session_start();

// Must be logged in as admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');
require_once 'db.php';

// Get the last checked timestamp from request (sent by JS)
$last_check = $_GET['last_check'] ?? null;

// Sanitize — must be a valid datetime or null
if ($last_check && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $last_check)) {
    $last_check = null;
}

// Default to 30 seconds ago if no timestamp provided
if (!$last_check) {
    $last_check = date('Y-m-d H:i:s', strtotime('-30 seconds'));
}

$notifications = [];

// ── Check for new orders ──────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, customer_name, total_amount, created_at
    FROM online_orders
    WHERE created_at > ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->bind_param("s", $last_check);
$stmt->execute();
$new_orders = $stmt->get_result();

while ($row = $new_orders->fetch_assoc()) {
    $notifications[] = [
        'type'    => 'new_order',
        'title'   => '🔔 New Order Received!',
        'message' => 'Order #' . $row['id'] . ' from ' . $row['customer_name'] . ' — ₱' . number_format($row['total_amount'], 2),
        'order_id'=> $row['id'],
        'time'    => $row['created_at']
    ];
}
$stmt->close();

// ── Check for new payment proofs ─────────────────────────────
$stmt2 = $conn->prepare("
    SELECT id, customer_name, total_amount, payment_method, updated_at
    FROM online_orders
    WHERE payment_status = 'proof_submitted'
    AND updated_at > ?
    ORDER BY updated_at DESC
    LIMIT 5
");
$stmt2->bind_param("s", $last_check);
$stmt2->execute();
$new_proofs = $stmt2->get_result();

while ($row = $new_proofs->fetch_assoc()) {
    $notifications[] = [
        'type'    => 'payment_proof',
        'title'   => '💳 Payment Proof Submitted!',
        'message' => 'Order #' . $row['id'] . ' — ' . $row['customer_name'] . ' sent ' . ($row['payment_method'] ?? 'GCash') . ' proof',
        'order_id'=> $row['id'],
        'time'    => $row['updated_at']
    ];
}
$stmt2->close();

// ── Current pending count for badge update ───────────────────
$pending = $conn->query("SELECT COUNT(*) as c FROM online_orders WHERE status='pending'")->fetch_assoc()['c'] ?? 0;

// Fix #6 / MED-5: Suggest a longer poll interval when no pending work exists,
// to reduce load on InfinityFree shared hosting.
$poll_interval = ($pending > 0 || count($notifications) > 0) ? 20 : 45;

echo json_encode([
    'notifications'   => $notifications,
    'pending_count'   => (int)$pending,
    'server_time'     => date('Y-m-d H:i:s'),
    'poll_interval_s' => $poll_interval,
]);
