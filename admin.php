<?php
// Description: This is the main admin dashboard file for the Kape Inato cafe management system.
// Function: Handles all admin operations including menu management, order processing, inventory, analytics, and system settings.
// Technical: Uses PHP sessions for authentication, MySQLi for database interactions, and includes HTML/CSS/JS for the frontend interface.
// Fix #9 — disable display_errors in production (bootstrap also set in helpers.php)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();

include 'db.php';
// helpers.php loaded via db.php (Fix #7 image helpers, Fix #9 error bootstrap)

// Auth guard
// Description: Security check to ensure only authenticated admins can access this page.
// Function: Verifies admin login status and redirects unauthorized users to login page.
// Technical: Checks session variable 'admin_logged_in'; uses header() for redirection and exit() to stop execution.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$success = '';
// Description: Variable to store success messages for user feedback.
// Function: Holds strings that indicate successful operations (e.g., item added).
// Technical: Initialized as empty string; displayed in HTML alerts.

$error = '';
// Description: Variable to store error messages for user feedback.
// Function: Holds strings that indicate failed operations or validation errors.
// Technical: Initialized as empty string; displayed in HTML alerts.

$active_tab = $_GET['tab'] ?? 'dashboard';
// Description: Determines which admin panel tab/section to display.
// Function: Uses URL parameter 'tab' to switch between dashboard, menu, orders, etc.
// Technical: Null coalescing operator defaults to 'dashboard'; sanitized via whitelist in HTML.

// ---- Handle Logout ----
// Description: Processes admin logout requests.
// Function: Destroys the session and redirects to login page.
// Technical: Uses session_destroy() to clear all session data; header() for redirection.
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

// ---- Handle Add Menu Item ----
// Description: Processes form submission for adding new menu items.
// Function: Validates input data, handles image uploads, inserts item into database, logs the action.
// Technical: Uses POST method check, prepared statements for SQL injection prevention, file validation with MIME types.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_item'])) {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = $_POST['category'] ?? 'Pizza';
    $price       = floatval($_POST['price'] ?? 0);
    $is_special  = isset($_POST['is_special']) ? 1 : 0;
    $stock_quantity = intval($_POST['stock_quantity'] ?? 100);
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    // Description: Basic validation for required fields.
    // Function: Checks if name is not empty and price is positive.
    // Technical: Uses empty() for string check, numeric comparison for price.
    if (empty($name) || $price <= 0) {
        $error = "Item name and a valid price are required.";
    } else {
        $image_path = get_category_default_filename($category);

        // File Upload Handling
        // Description: Handles optional image upload for menu items.
        // Function: Validates file type and size, generates unique filename, moves file to uploads directory.
        // Technical: Uses finfo for MIME type detection, pathinfo for extension, uniqid for unique names.
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['image']['tmp_name']);

            if (!in_array($mime, $allowed_types)) {
                $error = "Invalid file type. Only JPEG, PNG, WebP, or GIF allowed.";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $error = "Image must be under 5MB.";
            } else {
                $target_dir = "uploads/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                $ext        = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $image_path = uniqid('item_', true) . '.' . strtolower($ext);
                move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $image_path);
            }
        }

        // Description: Executes database insertion if no errors occurred.
        // Function: Inserts new menu item record, logs the action, sets success message.
        // Technical: Uses prepared statement with bind_param, file_put_contents for logging with lock.
        if (empty($error)) {
            $stmt = $conn->prepare("INSERT INTO menu_items (name, description, category, price, is_special, image_path, stock_quantity, is_available) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssdissi", $name, $description, $category, $price, $is_special, $image_path, $stock_quantity, $is_available);

            if ($stmt->execute()) {
                // Append to action log
                $log_msg = date("Y-m-d H:i:s") . " | ADD | " . $_SESSION['admin_username'] . " | Added: $name (₱$price)\n";
                file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);
                $success = "✅ \"$name\" added successfully!";
                $active_tab = 'menu';
            } else {
                $error = db_error_message($conn, 'add menu item');
            }
            $stmt->close();
        }
    }
}

// ---- Handle Delete Menu Item ----
// Description: Processes deletion of menu items via GET parameter.
// Function: Retrieves and deletes associated image file, removes database record, logs action, redirects with success.
// Technical: Uses prepared statements, checks for default image to avoid deleting shared placeholder, file_exists() for safety.
if (isset($_GET['delete_item'])) {
    $del_id = intval($_GET['delete_item']);
    // Get image path to delete file
    $del_stmt = $conn->prepare("SELECT image_path FROM menu_items WHERE id = ?");
    $del_stmt->bind_param("i", $del_id);
    $del_stmt->execute();
    $del_result = $del_stmt->get_result()->fetch_assoc();
    $del_stmt->close();

    if ($del_result && !is_default_menu_image($del_result['image_path'])) {
        $file_to_delete = "uploads/" . $del_result['image_path'];
        if (file_exists($file_to_delete)) unlink($file_to_delete);
    }

    $del = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
    $del->bind_param("i", $del_id);
    $del->execute();
    $del->close();

    $log_msg = date("Y-m-d H:i:s") . " | DELETE | " . $_SESSION['admin_username'] . " | Item ID: $del_id deleted\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);

    header("Location: admin.php?tab=menu&success=deleted");
    exit();
}

// ---- Handle Edit/Update Menu Item ----
// Description: Processes form submission for updating existing menu items.
// Function: Validates input, handles image replacement, updates database record, logs action.
// Technical: Similar to add handler but includes image deletion for replacements, UPDATE query instead of INSERT.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['edit_item'])) {
    $edit_id     = intval($_POST['edit_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category    = $_POST['category'] ?? 'Pizza';
    $price       = floatval($_POST['price'] ?? 0);
    $is_special  = isset($_POST['is_special']) ? 1 : 0;
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    // Description: Validation for edit operation including item ID check.
    // Function: Ensures all required fields are present and valid.
    // Technical: Adds $edit_id > 0 check compared to add validation.
    if (empty($name) || $price <= 0 || $edit_id <= 0) {
        $error = "Item name, valid price, and item ID are required.";
    } else {
        // Get current image path
        $img_stmt = $conn->prepare("SELECT image_path FROM menu_items WHERE id = ?");
        $img_stmt->bind_param("i", $edit_id);
        $img_stmt->execute();
        $img_result = $img_stmt->get_result()->fetch_assoc();
        $img_stmt->close();
        $image_path = $img_result['image_path'] ?? get_category_default_filename($category);

        // Handle new image upload
        // Description: Processes new image upload, deleting old image if replaced.
        // Function: Validates new image, removes old file, uploads new one.
        // Technical: Includes deletion of previous image to prevent orphaned files.
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['image']['tmp_name']);

            if (!in_array($mime, $allowed_types)) {
                $error = "Invalid file type. Only JPEG, PNG, WebP, or GIF allowed.";
            } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                $error = "Image must be under 5MB.";
            } else {
                // Delete old image if exists
                if (!is_default_menu_image($image_path) && file_exists("uploads/" . $image_path)) {
                    unlink("uploads/" . $image_path);
                }
                $target_dir = "uploads/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                $ext        = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $image_path = uniqid('item_', true) . '.' . strtolower($ext);
                move_uploaded_file($_FILES['image']['tmp_name'], $target_dir . $image_path);
            }
        }

        // Description: Executes database update if no errors.
        // Function: Updates menu item record, logs the edit action.
        // Technical: Uses UPDATE prepared statement, logs with "EDIT" type.
        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE menu_items SET name=?, description=?, category=?, price=?, is_special=?, image_path=?, stock_quantity=?, is_available=? WHERE id=?");
            $stmt->bind_param("sssdisiii", $name, $description, $category, $price, $is_special, $image_path, $stock_quantity, $is_available, $edit_id);

            if ($stmt->execute()) {
                $log_msg = date("Y-m-d H:i:s") . " | EDIT | " . $_SESSION['admin_username'] . " | Updated: $name (₱$price)\n";
                file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);
                $success = "✅ \"$name\" updated successfully!";
                $active_tab = 'menu';
            } else {
                $error = db_error_message($conn, 'add menu item');
            }
            $stmt->close();
        }
    }
}

// ---- Handle Inventory Update ----
// Description: Processes quick stock and availability updates from inventory table.
// Function: Updates stock quantity and availability status for a specific item, logs the change.
// Technical: Uses GET parameters for quick updates, prepared statement for security.
if (isset($_GET['update_stock']) && isset($_GET['item_id'])) {
    $item_id = intval($_GET['item_id']);
    $new_stock = intval($_GET['stock'] ?? 0);
    $available = isset($_GET['available']) ? 1 : 0;

    $upd = $conn->prepare("UPDATE menu_items SET stock_quantity = ?, is_available = ? WHERE id = ?");
    $upd->bind_param("iii", $new_stock, $available, $item_id);
    $upd->execute();
    $upd->close();

    $log_msg = date("Y-m-d H:i:s") . " | STOCK | " . $_SESSION['admin_username'] . " | Updated stock for Item ID: $item_id to $new_stock\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);

    header("Location: admin.php?tab=inventory&success=stock_updated");
    exit();
}

// ---- Handle Full Inventory Reset ----
// Description: Resets all menu items' stock to 100.
// Function: Bulk update operation for resetting inventory levels, logs the action.
// Technical: Uses direct query since it's a bulk operation, no prepared statement needed.
if (isset($_GET['reset_inventory']) && $_GET['reset_inventory'] === 'confirm') {
    $conn->query("UPDATE menu_items SET stock_quantity = 100");

    $log_msg = date("Y-m-d H:i:s") . " | STOCK | " . $_SESSION['admin_username'] . " | Reset ALL inventory stock to 100\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);

    header("Location: admin.php?tab=inventory&success=inventory_reset");
    exit();
}

// ---- Handle Factory Reset (Danger Zone) ----
// Description: Performs a complete system reset, wiping all order and feedback data.
// Function: Truncates multiple tables, clears logs, logs the reset action.
// Technical: Disables foreign key checks during truncate to avoid constraint violations, re-enables after.
if (isset($_GET['factory_reset']) && $_GET['factory_reset'] === 'confirm') {
    // 1. Turn off foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // 2. Truncate tables to wipe data and reset IDs to #1
    $conn->query("TRUNCATE TABLE online_orders");
    $conn->query("TRUNCATE TABLE online_order_items");
    $conn->query("TRUNCATE TABLE feedback");

    // 3. Turn on foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    // 4. Wipe logs
    file_put_contents("action_logs.txt", "");

    // 5. Add a single log entry saying the reset happened
    $log_msg = date("Y-m-d H:i:s") . " | SYSTEM | " . $_SESSION['admin_username'] . " | Performed a full Factory Reset.\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);

    header("Location: admin.php?tab=dashboard&success=reset");
    exit();
}

// ---- Handle Payment Confirmation by Admin ----
if (isset($_GET['confirm_payment']) && isset($_SESSION['admin_logged_in'])) {
    $cp_id = intval($_GET['confirm_payment']);
    // Fix CRIT-2: never chain on prepare() — it returns false on failure
    $cp_stmt = $conn->prepare("UPDATE online_orders SET payment_status='confirmed', payment_confirmed_at=NOW() WHERE id=?");
    if ($cp_stmt) {
        $cp_stmt->bind_param("i", $cp_id);
        $cp_stmt->execute();
        $cp_stmt->close();
    } else {
        error_log("[Kape Inato] confirm_payment prepare() failed: " . $conn->error);
    }
    $log_msg = date("Y-m-d H:i:s") . " | PAYMENT CONFIRMED | Order #$cp_id | Admin: " . ($_SESSION['admin_username'] ?? 'admin') . "\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);
    header("Location: admin.php?tab=online_orders");
    exit();
}


// Description: Updates status of online orders placed through the website form.
// Function: Changes order status, logs email notification simulation.
// Technical: Extended status workflow compared to QR orders, includes email logging.
if (isset($_GET['online_order_id']) && isset($_GET['status'])) {
    $oo_id = intval($_GET['online_order_id']);
    $oo_status = $_GET['status'];
    $allowed = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
    if (in_array($oo_status, $allowed)) {
        $upd = $conn->prepare("UPDATE online_orders SET status = ? WHERE id = ?");
        $upd->bind_param("si", $oo_status, $oo_id);
        $upd->execute();
        $upd->close();

        // Fix CRIT-3: use prepared statement — not raw string interpolation
        $oi_stmt = $conn->prepare("SELECT email, customer_name FROM online_orders WHERE id = ?");
        if ($oi_stmt) {
            $oi_stmt->bind_param("i", $oo_id);
            $oi_stmt->execute();
            $order_info = $oi_stmt->get_result()->fetch_assoc();
            $oi_stmt->close();
        } else {
            $order_info = ['email' => 'customer', 'customer_name' => ''];
        }
        $log_msg = date("Y-m-d H:i:s") . " | EMAIL | Order #$oo_id status updated to '$oo_status' - notification sent to " . ($order_info['email'] ?? 'customer') . "\n";
        file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);
    }
    header("Location: admin.php?tab=online_orders");
    exit();
}

// ---- Fetch Stats ----
// Description: Retrieves basic statistics for dashboard display.
// Function: Counts total items, orders, pending orders, and special items.
// Technical: Simple COUNT queries with null coalescing for safety.
$total_items  = $conn->query("SELECT COUNT(*) as c FROM menu_items")->fetch_assoc()['c'] ?? 0;
$special_items  = $conn->query("SELECT COUNT(*) as c FROM menu_items WHERE is_special=1")->fetch_assoc()['c'] ?? 0;

// ---- Sales Analytics ----
// Total revenue from all orders
$total_revenue = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM online_orders WHERE status != 'cancelled'")->fetch_assoc()['revenue'] ?? 0;

// Today's sales
$today_sales = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as sales FROM online_orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'")->fetch_assoc()['sales'] ?? 0;

// Weekly sales data for chart
$weekly_sales = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $day_online = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as sales FROM online_orders WHERE DATE(created_at) = '$date' AND status != 'cancelled'")->fetch_assoc()['sales'] ?? 0;
    $weekly_sales[] = ['date' => date('D', strtotime($date)), 'amount' => $day_online];
}

// Top selling items (based on online sales volume)
$top_items = $conn->query("
    SELECT m.name, m.price, m.is_special, m.stock_quantity, m.is_available,
           COALESCE((SELECT SUM(quantity) FROM online_order_items WHERE menu_item_id = m.id), 0) as order_count
    FROM menu_items m
    ORDER BY order_count DESC, m.is_special DESC 
    LIMIT 5
");

// ---- Fetch Menu Items ----
// Description: Retrieves all menu items for display in menu management tab.
// Function: Gets complete menu item data ordered by special status and creation date.
// Technical: Simple SELECT with ORDER BY for display prioritization.
$menu_result = $conn->query("SELECT * FROM menu_items ORDER BY is_special DESC, created_at DESC");

// ---- Fetch Online Orders ----
// Description: Retrieves online orders grouped by customer for cleaner admin display.
// Function: Fetches all orders with items, then groups them in PHP by customer identity.
// Technical: Groups by customer_name+phone key; each customer entry holds all their orders.
$online_orders_result = $conn->query("
    SELECT o.*, 
           COALESCE(GROUP_CONCAT(CONCAT(m.name, ' (x', oi.quantity, ')') SEPARATOR ', '), 'No items') as items
    FROM online_orders o 
    LEFT JOIN online_order_items oi ON o.id = oi.online_order_id 
    LEFT JOIN menu_items m ON oi.menu_item_id = m.id 
    GROUP BY o.id 
    ORDER BY o.created_at DESC 
    LIMIT 100
");
$pending_online_orders = $conn->query("SELECT COUNT(*) as c FROM online_orders WHERE status='pending'")->fetch_assoc()['c'] ?? 0;

// Group orders by customer (name + phone as unique key)
$customers_orders = [];
if ($online_orders_result && $online_orders_result->num_rows > 0) {
    while ($ord = $online_orders_result->fetch_assoc()) {
        $ckey = trim($ord['customer_name']) . '||' . trim($ord['phone'] ?? '');
        if (!isset($customers_orders[$ckey])) {
            $customers_orders[$ckey] = [
                'customer_name' => $ord['customer_name'],
                'email'         => $ord['email'],
                'phone'         => $ord['phone'] ?? 'N/A',
                'orders'        => [],
                'total_spent'   => 0,
                'pending_count' => 0,
            ];
        }
        $customers_orders[$ckey]['orders'][] = $ord;
        $customers_orders[$ckey]['total_spent'] += floatval($ord['total_amount']);
        if ($ord['status'] === 'pending') $customers_orders[$ckey]['pending_count']++;
    }
}

// ---- Fetch Feedback ----
// Description: Retrieves customer feedback with associated menu item names.
// Function: Joins feedback with menu items, calculates average rating.
// Technical: JOIN instead of LEFT JOIN since feedback must have valid menu_item_id.
$feedback_result = $conn->query("
    SELECT f.*, m.name as item_name 
    FROM feedback f 
    JOIN menu_items m ON f.menu_item_id = m.id 
    ORDER BY f.created_at DESC LIMIT 20
");
$avg_rating = $conn->query("SELECT COALESCE(AVG(rating), 0) as avg FROM feedback")->fetch_assoc()['avg'] ?? 0;

// ---- Read Logs ----
// Description: Loads recent action logs for display in logs tab.
// Function: Reads log file, reverses order (newest first), limits to 30 entries.
// Technical: file() reads lines into array, array_reverse for chronological order, array_slice for limit.
$logs = file_exists("action_logs.txt") ? array_filter(array_reverse(file("action_logs.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))) : [];
$logs = array_slice($logs, 0, 30);
?>
<!-- HTML Output Section -->
<!-- Description: Renders the admin dashboard interface using HTML, CSS, and JavaScript.
Function: Displays different tabs based on active_tab, handles user interactions, shows data from PHP variables.
Technical: Uses inline PHP for dynamic content, Chart.js for analytics, CSS for styling. -->
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Kape Inato</title>
    <link rel="icon" type="image/png" href="coffee.png">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chart-container {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
        }

        .analytics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .analytics-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
        }

        .search-box {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-subtle);
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.3);
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 20px;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--amber);
        }

        .inventory-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .in-stock {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
        }

        .low-stock {
            background: rgba(234, 179, 8, 0.2);
            color: #eab308;
        }

        .out-of-stock {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .edit-form-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .edit-form-container {
            background: var(--bg-void);
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border-subtle);
        }

        .rating-stars {
            color: #fbbf24;
            font-size: 1.2rem;
        }

        .chat-widget {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 350px;
            height: 450px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            display: none;
            flex-direction: column;
            z-index: 1000;
            overflow: hidden;
        }

        .chat-header {
            background: linear-gradient(135deg, #065f46, #047857);
            color: white;
            padding: 16px;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        .chat-input {
            border-top: 1px solid #e5e7eb;
            padding: 12px;
            display: flex;
            gap: 8px;
        }

        .chat-input input {
            flex: 1;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 20px;
        }

        .chat-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #065f46, #047857);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 24px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            z-index: 999;
        }
    </style>
</head>

<body>

    <nav>
        <div class="nav-logo">Kape Inato</div>
        <div style="display:flex; align-items:center; gap:20px;">
            <div id="liveClock" style="font-family:'Courier New',monospace; font-size:1rem; color:var(--amber); background:rgba(0,0,0,0.3); padding:8px 16px; border-radius:8px; border:1px solid var(--border-subtle);">
                🕐 Loading...
            </div>
            <ul>
                <li><a href="menu.php" target="_blank">View Site</a></li>
                <li><a href="admin.php?logout=1" class="nav-btn-admin">Logout</a></li>
            </ul>
        </div>
    </nav>

    <script>
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const dateString = now.toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric'
            });
            document.getElementById('liveClock').innerHTML = '🕐 ' + timeString + '<br><small>' + dateString + '</small>';
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>

    <!-- Mobile sidebar overlay (tap to close) -->
    <div class="admin-sidebar-overlay" id="sidebarOverlay" onclick="toggleAdminSidebar()"></div>

    <div class="admin-layout">

        <!-- Mobile sidebar toggle bar -->
        <div class="admin-sidebar-toggle" onclick="toggleAdminSidebar()">
            ☰ &nbsp; Menu
        </div>

        <aside class="admin-sidebar" id="adminSidebar">
            <div class="admin-nav-section">
                <p class="admin-nav-label">Main</p>
                <a href="admin.php?tab=dashboard" class="admin-nav-link <?= $active_tab === 'dashboard' ? 'active' : '' ?>">
                    <span>📊</span> Dashboard
                </a>
                <a href="admin.php?tab=menu" class="admin-nav-link <?= $active_tab === 'menu' ? 'active' : '' ?>">
                    <span>🍕</span> Menu Items
                </a>
                <a href="admin.php?tab=add" class="admin-nav-link <?= $active_tab === 'add' ? 'active' : '' ?>">
                    <span>➕</span> Add New Item
                </a>
                <a href="admin.php?tab=online_orders" class="admin-nav-link <?= $active_tab === 'online_orders' ? 'active' : '' ?>">
                    <span>🌐</span> Online Orders
                    <?php if ($pending_online_orders > 0): ?>
                        <span style="margin-left:auto; background:#3b82f6; color:white; border-radius:12px; padding:2px 8px; font-size:0.72rem; font-weight:700;">
                            <?= $pending_online_orders ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="admin.php?tab=inventory" class="admin-nav-link <?= $active_tab === 'inventory' ? 'active' : '' ?>">
                    <span>📦</span> Inventory
                </a>
                <a href="admin.php?tab=feedback" class="admin-nav-link <?= $active_tab === 'feedback' ? 'active' : '' ?>">
                    <span>⭐</span> Feedback
                </a>
            </div>
            <div class="admin-nav-section">
                <p class="admin-nav-label">Analytics</p>
                <a href="admin.php?tab=analytics" class="admin-nav-link <?= $active_tab === 'analytics' ? 'active' : '' ?>">
                    <span>📈</span> Sales Analytics
                </a>
                <a href="admin.php?tab=logs" class="admin-nav-link <?= $active_tab === 'logs' ? 'active' : '' ?>">
                    <span>📄</span> Action Logs
                </a>

            </div>
            <div style="margin-top: auto; padding: 16px 8px; border-top: 1px solid var(--border-subtle);">
                <p style="font-size:0.78rem; color: var(--text-muted);">Logged in as</p>
                <p style="font-size:0.88rem; color: var(--amber); font-weight:500; margin-top:2px;">
                    <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>
                </p>
            </div>
        </aside>

        <main class="admin-main">

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['success']) && $_GET['success'] === 'deleted'): ?>
                <div class="alert alert-success">✅ Item deleted successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET['success']) && $_GET['success'] === 'reset'): ?>
                <div class="alert alert-success">✅ System Reset Successful! All test orders and logs have been wiped.</div>
            <?php endif; ?>
            <?php if (isset($_GET['success']) && $_GET['success'] === 'inventory_reset'): ?>
                <div class="alert alert-success">✅ All inventory stocks have been successfully reset to 100.</div>
            <?php endif; ?>

            <?php if ($active_tab === 'dashboard'): ?>
                <!-- Dashboard Tab Display -->
                <!-- Description: Shows overview statistics and quick actions for cafe management.
        Function: Displays key metrics, stats cards, and action buttons.
        Technical: Uses PHP variables for dynamic stats, HTML for layout. -->
                <div class="admin-header">
                    <h1>Dashboard</h1>
                    <p>Welcome back, <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>! Here's your cafe at a glance.</p>
                </div>

                <div class="stats-row">
                    <div class="stat-card">
                        <p class="stat-card-label">Menu Items</p>
                        <p class="stat-card-value"><?= $total_items ?></p>
                        <p class="stat-card-sub">Total listed items</p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-card-label">Trending Items</p>
                        <p class="stat-card-value"><?= $special_items ?></p>
                        <p class="stat-card-sub">Marked as special</p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-card-label">Online Orders</p>
                        <p class="stat-card-value"><?= $conn->query("SELECT COUNT(*) as c FROM online_orders")->fetch_assoc()['c'] ?? 0 ?></p>
                        <p class="stat-card-sub">All time</p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-card-label">Pending Online</p>
                        <p class="stat-card-value" style="color:var(--amber)"><?= $pending_online_orders ?></p>
                        <p class="stat-card-sub">Awaiting action</p>
                    </div>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title">Quick Actions</h2>
                    </div>
                    <div style="display:flex; gap:16px; flex-wrap:wrap;">
                        <a href="admin.php?tab=add" class="btn btn-primary">➕ Add Menu Item</a>
                        <a href="admin.php?tab=online_orders" class="btn btn-ghost">🌐 View Online Orders</a>
                        <a href="menu.php" target="_blank" class="btn btn-ghost">🌐 View Public Menu</a>
                    </div>
                </div>

                <div class="admin-panel" style="margin-top: 20px; border: 1px solid rgba(239, 68, 68, 0.3); background: rgba(239, 68, 68, 0.05);">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title" style="color: #ef4444;">⚠️ Danger Zone</h2>
                    </div>
                    <p style="color: var(--text-secondary); margin-bottom: 15px; font-size: 0.9rem; line-height: 1.5;">
                        This will permanently delete all online orders, sales analytics, feedback, and action logs. Your menu items and inventory will <strong>NOT</strong> be affected. Order IDs will reset to #1.
                    </p>
                    <a href="admin.php?factory_reset=confirm" class="btn btn-danger" onclick="return confirm('🚨 WARNING! Are you absolutely sure you want to wipe all test data? This cannot be undone!')">
                        🗑️ Factory Reset System
                    </a>
                </div>

            <?php elseif ($active_tab === 'menu'): ?>
                <div class="admin-header">
                    <h1>Menu Items</h1>
                    <p>Manage all items on your public menu. Use the search box to filter items instantly.</p>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title">All Items (<?= $total_items ?>)</h2>
                        <a href="admin.php?tab=add" class="btn btn-primary btn-sm">+ Add New</a>
                    </div>
                    <input type="text" id="menuSearch" class="search-box" placeholder="🔍 Search menu items by name, category, or price..." onkeyup="filterMenuItems()">
                    <?php if ($menu_result && $menu_result->num_rows > 0):
                        // Reset pointer to beginning for display
                        $menu_result->data_seek(0);
                    ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table" id="menuTable">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th>Added</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $menu_result->fetch_assoc()):
                                        $stock_class = $row['stock_quantity'] > 20 ? 'in-stock' : ($row['stock_quantity'] > 0 ? 'low-stock' : 'out-of-stock');
                                        $stock_text = $row['stock_quantity'] > 20 ? 'In Stock' : ($row['stock_quantity'] > 0 ? 'Low Stock' : 'Out of Stock');
                                    ?>
                                        <tr data-search="<?= strtolower(htmlspecialchars($row['name'] . ' ' . $row['category'] . ' ' . $row['price'])) ?>">
                                            <td><strong><?= htmlspecialchars($row['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($row['category']) ?></td>
                                            <td><span class="price">₱<?= number_format($row['price'], 2) ?></span></td>
                                            <td>
                                                <span class="inventory-status <?= $stock_class ?>">
                                                    <?= $row['stock_quantity'] ?> (<?= $stock_text ?>)
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($row['is_special']): ?>
                                                    <span class="status-badge status-special">🔥 Trending</span>
                                                <?php else: ?>
                                                    <span class="status-badge" style="background:rgba(255,255,255,0.05); color:var(--text-muted);">Regular</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="admin.php?tab=edit&edit_id=<?= $row['id'] ?>" class="btn btn-success btn-sm">Edit</a>
                                                    <a href="admin.php?delete_item=<?= $row['id'] ?>"
                                                        class="btn btn-danger btn-sm"
                                                        onclick="return confirm('Delete \'<?= htmlspecialchars($row['name']) ?>\'? This cannot be undone.')">
                                                        Delete
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <script>
                            function filterMenuItems() {
                                const searchTerm = document.getElementById('menuSearch').value.toLowerCase();
                                const rows = document.querySelectorAll('#menuTable tbody tr');
                                rows.forEach(row => {
                                    const searchData = row.getAttribute('data-search');
                                    row.style.display = searchData.includes(searchTerm) ? '' : 'none';
                                });
                            }
                        </script>
                    <?php else: ?>
                        <div class="empty-state"><span>🍽️</span>No menu items yet. <a href="admin.php?tab=add" style="color:var(--amber);">Add your first item</a>.</div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'add'): ?>
                <div class="admin-header">
                    <h1>Add New Item</h1>
                    <p>Add a new food or drink item to the public menu.</p>
                </div>

                <div class="admin-panel">
                    <h2 class="admin-panel-title" style="margin-bottom:28px;">Item Details</h2>
                    <form method="POST" action="admin.php?tab=add" enctype="multipart/form-data" class="add-item-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Item Name *</label>
                                <input type="text" name="name" placeholder="e.g., Homemade Margherita Pizza" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category">
                                    <option value="Pizza">🍕 Pizza</option>
                                    <option value="Pasta">🍝 Pasta</option>
                                    <option value="Drinks">☕ Drinks</option>
                                    <option value="Appetizers">🥗 Appetizers</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" placeholder="Brief description of the item (ingredients, flavour profile, etc.)"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Price (₱) *</label>
                                <input type="number" name="price" placeholder="0.00" step="0.01" min="0.01" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Stock Quantity *</label>
                                <input type="number" name="stock_quantity" placeholder="100" min="0" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="display:flex; align-items:flex-end; padding-bottom:2px;">
                                <label class="form-checkbox">
                                    <input type="checkbox" name="is_special">
                                    <span>Mark as 🔥 Trending / Special</span>
                                </label>
                            </div>
                            <div class="form-group" style="display:flex; align-items:flex-end; padding-bottom:2px;">
                                <label class="form-checkbox">
                                    <input type="checkbox" name="is_available" checked>
                                    <span>Available for ordering</span>
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Menu Image (optional)</label>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                            <p style="color:var(--text-muted); font-size:0.78rem; margin-top:6px;">Max 5MB. JPG, PNG, WebP, GIF. A default placeholder will be used if none is uploaded.</p>
                        </div>
                        <div style="display:flex; gap:16px; margin-top:8px;">
                            <button type="submit" name="add_item" class="btn btn-primary">Add to Menu</button>
                            <a href="admin.php?tab=menu" class="btn btn-ghost">Cancel</a>
                        </div>
                    </form>
                </div>

                <?php elseif ($active_tab === 'edit' && isset($_GET['edit_id'])):
                $edit_id = intval($_GET['edit_id']);
                $edit_stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ?");
                $edit_stmt->bind_param("i", $edit_id);
                $edit_stmt->execute();
                $edit_item = $edit_stmt->get_result()->fetch_assoc();
                $edit_stmt->close();
                if ($edit_item):
                ?>
                    <div class="admin-header">
                        <h1>Edit Menu Item</h1>
                        <p>Update details for "<?= htmlspecialchars($edit_item['name']) ?>"</p>
                    </div>

                    <div class="admin-panel">
                        <h2 class="admin-panel-title" style="margin-bottom:28px;">Edit Item Details</h2>
                        <form method="POST" action="admin.php?tab=menu" enctype="multipart/form-data" class="add-item-form">
                            <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Item Name *</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($edit_item['name']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Category *</label>
                                    <select name="category">
                                        <option value="Pizza" <?= $edit_item['category'] == 'Pizza' ? 'selected' : '' ?>>🍕 Pizza</option>
                                        <option value="Pasta" <?= $edit_item['category'] == 'Pasta' ? 'selected' : '' ?>>🍝 Pasta</option>
                                        <option value="Drinks" <?= $edit_item['category'] == 'Drinks' ? 'selected' : '' ?>>☕ Drinks</option>
                                        <option value="Appetizers" <?= $edit_item['category'] == 'Appetizers' ? 'selected' : '' ?>>🥗 Appetizers</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea name="description"><?= htmlspecialchars($edit_item['description']) ?></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Price (₱) *</label>
                                    <input type="number" name="price" value="<?= $edit_item['price'] ?>" step="0.01" min="0.01" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Stock Quantity *</label>
                                    <input type="number" name="stock_quantity" value="<?= $edit_item['stock_quantity'] ?>" min="0" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group" style="display:flex; align-items:flex-end; padding-bottom:2px;">
                                    <label class="form-checkbox">
                                        <input type="checkbox" name="is_special" <?= $edit_item['is_special'] ? 'checked' : '' ?>>
                                        <span>Mark as 🔥 Trending / Special</span>
                                    </label>
                                </div>
                                <div class="form-group" style="display:flex; align-items:flex-end; padding-bottom:2px;">
                                    <label class="form-checkbox">
                                        <input type="checkbox" name="is_available" <?= $edit_item['is_available'] ? 'checked' : '' ?>>
                                        <span>Available for ordering</span>
                                    </label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Current Image</label>
                                <?php
                                $display_img = resolveMenuItemImage(
                                    $edit_item['image_path'],
                                    $edit_item['category']
                                );
                                ?>
                                <img src="<?= htmlspecialchars($display_img) ?>" alt="Current"
                                     style="max-width:200px; border-radius:8px; margin-bottom:10px; border:1px solid var(--border-subtle);">
                                <label class="form-label" style="margin-top:15px;">Upload New Image (optional)</label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif">
                                <p style="color:var(--text-muted); font-size:0.78rem; margin-top:6px;">Leave empty to keep current image. Max 5MB.</p>
                            </div>
                            <div style="display:flex; gap:16px; margin-top:8px;">
                                <button type="submit" name="edit_item" class="btn btn-primary">💾 Save Changes</button>
                                <a href="admin.php?tab=menu" class="btn btn-ghost">Cancel</a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="admin-header">
                        <h1>Item Not Found</h1>
                        <p>The item you're trying to edit doesn't exist.</p>
                    </div>
                    <div class="admin-panel">
                        <a href="admin.php?tab=menu" class="btn btn-primary">← Back to Menu</a>
                    </div>
                <?php endif; ?>

            <?php elseif ($active_tab === 'online_orders'): ?>
                <div class="admin-header">
                    <h1>Online Orders</h1>
                    <p>Orders grouped by customer — click a customer card to expand their orders.</p>
                </div>

                <!-- Online Orders: Customer-grouped cards -->
                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title">Customers with Orders</h2>
                        <span style="color:#3b82f6; font-size:0.85rem;"><?= $pending_online_orders ?> pending</span>
                    </div>

                    <?php if (!empty($customers_orders)): ?>

                        <!-- Per-customer cards -->
                        <?php $cust_index = 0;
                        foreach ($customers_orders as $ckey => $cdata): $cust_index++; ?>
                            <div class="customer-order-card" style="
                background: rgba(255,255,255,0.03);
                border: 1px solid var(--border-subtle);
                border-radius: 14px;
                margin-bottom: 16px;
                overflow: hidden;
            ">
                                <!-- Customer Header Row (clickable toggle) -->
                                <div class="customer-card-header" onclick="toggleCustomerOrders('cust-<?= $cust_index ?>')" style="
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 18px 24px;
                    cursor: pointer;
                    background: rgba(255,255,255,0.04);
                    transition: background 0.2s;
                " onmouseover="this.style.background='rgba(255,255,255,0.07)'" onmouseout="this.style.background='rgba(255,255,255,0.04)'">

                                    <!-- Left: Avatar + Customer Info -->
                                    <div style="display:flex; align-items:center; gap:16px;">
                                        <div style="
                            width:46px; height:46px;
                            background: linear-gradient(135deg, var(--amber), #b45309);
                            border-radius:50%;
                            display:flex; align-items:center; justify-content:center;
                            font-weight:700; font-size:1.1rem; color:#000; flex-shrink:0;
                        ">
                                            <?= strtoupper(substr($cdata['customer_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:700; font-size:1.05rem; color:var(--text-primary);">
                                                <?= htmlspecialchars($cdata['customer_name']) ?>
                                                <?php if ($cdata['pending_count'] > 0): ?>
                                                    <span style="
                                        background:#ef4444; color:white;
                                        font-size:0.7rem; font-weight:700;
                                        padding:2px 8px; border-radius:20px;
                                        margin-left:8px; vertical-align:middle;
                                    "><?= $cdata['pending_count'] ?> pending</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:0.82rem; color:var(--text-muted); margin-top:3px;">
                                                📞 <?= htmlspecialchars($cdata['phone']) ?>
                                                &nbsp;|&nbsp;
                                                ✉ <?= htmlspecialchars($cdata['email']) ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right: Stats + Chevron -->
                                    <div style="display:flex; align-items:center; gap:24px; flex-shrink:0;">
                                        <div style="text-align:center;">
                                            <div style="font-size:1.3rem; font-weight:700; color:var(--amber);">
                                                <?= count($cdata['orders']) ?>
                                            </div>
                                            <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em;">
                                                Order<?= count($cdata['orders']) > 1 ? 's' : '' ?>
                                            </div>
                                        </div>
                                        <div style="text-align:center;">
                                            <div style="font-size:1.1rem; font-weight:700; color:#22c55e;">
                                                ₱<?= number_format($cdata['total_spent'], 2) ?>
                                            </div>
                                            <div style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em;">Total</div>
                                        </div>
                                        <div id="chevron-cust-<?= $cust_index ?>" style="font-size:1.2rem; color:var(--text-muted); transition:transform .25s; transform:rotate(0deg);">▾</div>
                                    </div>
                                </div>

                                <!-- Collapsible Orders Table -->
                                <div id="cust-<?= $cust_index ?>" style="display:none;">
                                    <div style="overflow-x:auto; padding:0 0 4px 0;">
                                        <table class="data-table" style="margin:0; border-radius:0; border-top:1px solid var(--border-subtle);">
                                            <thead>
                                                <tr>
                                                    <th style="padding-left:24px;">#</th>
                                                    <th>Items Ordered</th>
                                                    <th>Total</th>
                                                    <th>Pickup</th>
                                                    <th>Date</th>
                                                    <th>Status</th>
                                                    <th>Payment</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $online_status_flow = [
                                                    'pending'   => ['confirmed' => '✓ Confirm'],
                                                    'confirmed' => ['preparing' => '🍳 Prepare'],
                                                    'preparing' => ['ready'     => '✅ Ready'],
                                                    'ready'     => ['completed' => '🎉 Complete'],
                                                    'completed' => [],
                                                    'cancelled' => []
                                                ];
                                                foreach ($cdata['orders'] as $ord):
                                                    $status_css = str_replace(
                                                        ['confirmed', 'completed', 'cancelled'],
                                                        ['ready', 'served', 'danger'],
                                                        $ord['status'] ?? 'pending'
                                                    );
                                                ?>
                                                    <tr style="border-bottom:1px solid var(--border-subtle);">
                                                        <td style="padding-left:24px;"><strong style="color:var(--amber);">#<?= $ord['id'] ?></strong></td>
                                                        <td style="max-width:240px; word-wrap:break-word; font-size:0.85rem; color:var(--text-secondary);">
                                                            <?= htmlspecialchars($ord['items']) ?>
                                                        </td>
                                                        <td><span class="price">₱<?= number_format($ord['total_amount'], 2) ?></span></td>
                                                        <td style="font-size:0.85rem;">
                                                            <?= $ord['pickup_time'] ? date('M d, H:i', strtotime($ord['pickup_time'])) : '<span style="color:var(--amber);">ASAP</span>' ?>
                                                        </td>
                                                        <td style="font-size:0.82rem; color:var(--text-muted);">
                                                            <?= date('M d, H:i', strtotime($ord['created_at'])) ?>
                                                        </td>
                                                        <td>
                                                            <span class="status-badge status-<?= $status_css ?>">
                                                                <?= ucfirst($ord['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td style="font-size:0.82rem; min-width:120px;">
                                                            <?php
                                                            $pay_status = $ord['payment_status'] ?? 'unpaid';
                                                            $pay_colors = ['unpaid' => '#ef4444', 'proof_submitted' => '#f59e0b', 'confirmed' => '#22c55e'];
                                                            $pay_labels = ['unpaid' => '⛔ Unpaid', 'proof_submitted' => '📸 Proof Sent', 'confirmed' => '✅ Paid'];
                                                            $pay_col = $pay_colors[$pay_status] ?? '#ef4444';
                                                            $pay_lbl = $pay_labels[$pay_status] ?? '⛔ Unpaid';
                                                            ?>
                                                            <span style="color:<?= $pay_col ?>; font-weight:700;"><?= $pay_lbl ?></span>
                                                            <?php if ($pay_status === 'proof_submitted' && !empty($ord['payment_proof'])): ?>
                                                                <br>
                                                                <a href="uploads/payment_proofs/<?= htmlspecialchars($ord['payment_proof']) ?>"
                                                                    target="_blank"
                                                                    style="font-size:0.78rem; color:var(--amber); text-decoration:none;">
                                                                    📎 View Proof
                                                                </a>
                                                                <br>
                                                                <a href="admin.php?tab=online_orders&confirm_payment=<?= $ord['id'] ?>"
                                                                    class="btn btn-success btn-sm"
                                                                    style="margin-top:4px; font-size:0.75rem; padding:3px 8px;"
                                                                    onclick="return confirm('Confirm payment for Order #<?= $ord['id'] ?>?')">
                                                                    ✅ Confirm Pay
                                                                </a>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="action-btns">
                                                                <?php foreach ($online_status_flow[$ord['status']] ?? [] as $st => $label): ?>
                                                                    <a href="admin.php?tab=online_orders&online_order_id=<?= $ord['id'] ?>&status=<?= $st ?>"
                                                                        class="btn btn-success btn-sm"><?= $label ?></a>
                                                                <?php endforeach; ?>
                                                                <?php if ($ord['status'] !== 'cancelled' && $ord['status'] !== 'completed'): ?>
                                                                    <a href="admin.php?tab=online_orders&online_order_id=<?= $ord['id'] ?>&status=cancelled"
                                                                        class="btn btn-danger btn-sm"
                                                                        onclick="return confirm('Cancel order #<?= $ord['id'] ?>?')">✕</a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <div class="empty-state"><span>🌐</span>No online orders yet. Customers can place orders via the online order form.</div>
                    <?php endif; ?>
                </div>

                <script>
                    // Toggle per-customer order list expand/collapse
                    function toggleCustomerOrders(id) {
                        const panel = document.getElementById(id);
                        const chevrId = 'chevron-' + id;
                        const chevron = document.getElementById(chevrId);
                        if (panel.style.display === 'none' || panel.style.display === '') {
                            panel.style.display = 'block';
                            chevron.style.transform = 'rotate(180deg)';
                        } else {
                            panel.style.display = 'none';
                            chevron.style.transform = 'rotate(0deg)';
                        }
                    }
                    // Auto-expand customers with pending orders on page load
                    document.addEventListener('DOMContentLoaded', function() {
                        <?php $ai = 0;
                        foreach ($customers_orders as $ck => $cd): $ai++;
                            if ($cd['pending_count'] > 0): ?>
                                toggleCustomerOrders('cust-<?= $ai ?>');
                        <?php endif;
                        endforeach; ?>
                    });
                </script>

            <?php elseif ($active_tab === 'inventory'): ?>
                <div class="admin-header">
                    <h1>Inventory Management</h1>
                    <p>Track stock levels and manage item availability. Low stock items are highlighted.</p>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title">Stock Overview</h2>
                        <div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
                            <div>
                                <span class="inventory-status in-stock" style="margin-right:10px;">✓ In Stock (&gt;20)</span>
                                <span class="inventory-status low-stock" style="margin-right:10px;">⚠ Low Stock (1-20)</span>
                                <span class="inventory-status out-of-stock">✗ Out of Stock (0)</span>
                            </div>
                            <a href="admin.php?tab=inventory&reset_inventory=confirm" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to reset ALL menu items to 100 stock?')">
                                🔄 Reset All to 100
                            </a>
                        </div>
                    </div>
                    <?php
                    // Reset menu result for inventory display
                    $inv_result = $conn->query("SELECT * FROM menu_items ORDER BY stock_quantity ASC, name ASC");
                    if ($inv_result && $inv_result->num_rows > 0):
                    ?>
                        <div style="overflow-x:auto;">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Current Stock</th>
                                        <th>Status</th>
                                        <th>Available</th>
                                        <th>Quick Update</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($item = $inv_result->fetch_assoc()):
                                        $stock_class = $item['stock_quantity'] > 20 ? 'in-stock' : ($item['stock_quantity'] > 0 ? 'low-stock' : 'out-of-stock');
                                        $stock_text = $item['stock_quantity'] > 20 ? 'In Stock' : ($item['stock_quantity'] > 0 ? 'Low Stock' : 'Out of Stock');
                                    ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                                            <td><?= htmlspecialchars($item['category']) ?></td>
                                            <td><span class="price">₱<?= number_format($item['price'], 2) ?></span></td>
                                            <td>
                                                <form method="GET" action="admin.php" style="display:flex; gap:8px; align-items:center;">
                                                    <input type="hidden" name="tab" value="inventory">
                                                    <input type="hidden" name="update_stock" value="1">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    <input type="number" name="stock" value="<?= $item['stock_quantity'] ?>" min="0" style="width:80px; padding:6px; border-radius:6px; border:1px solid var(--border-subtle); background:rgba(0,0,0,0.3); color:var(--text-primary);">
                                            </td>
                                            <td><span class="inventory-status <?= $stock_class ?>"><?= $stock_text ?></span></td>
                                            <td>
                                                <label class="form-checkbox" style="margin:0;">
                                                    <input type="checkbox" name="available" <?= $item['is_available'] ? 'checked' : '' ?>>
                                                    <span>Available</span>
                                                </label>
                                            </td>
                                            <td>
                                                <button type="submit" class="btn btn-success btn-sm">Update</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><span>📦</span>No items to manage.</div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'analytics'): ?>
                <div class="admin-header">
                    <h1>Sales Analytics</h1>
                    <p>Track revenue, view sales trends, and identify top-performing items.</p>
                </div>

                <div class="stats-row">
                    <div class="stat-card">
                        <p class="stat-card-label">Total Revenue</p>
                        <p class="stat-card-value" style="color:#22c55e;">₱<?= number_format($total_revenue, 2) ?></p>
                        <p class="stat-card-sub">All time sales</p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-card-label">Today's Sales</p>
                        <p class="stat-card-value" style="color:#3b82f6;">₱<?= number_format($today_sales, 2) ?></p>
                        <p class="stat-card-sub"><?= date('M d, Y') ?></p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-card-label">Avg. Rating</p>
                        <p class="stat-card-value" style="color:#fbbf24;"><?= number_format($avg_rating, 1) ?>★</p>
                        <p class="stat-card-sub">Customer feedback</p>
                    </div>
                    <div class="stat-card">
                        <p class="stat-card-label">Total Orders</p>
                        <p class="stat-card-value"><?= $conn->query("SELECT COUNT(*) as c FROM online_orders WHERE status != 'cancelled'")->fetch_assoc()['c'] ?? 0 ?></p>
                        <p class="stat-card-sub">Online orders</p>
                    </div>
                </div>

                <div class="analytics-grid">
                    <div class="analytics-card">
                        <h3 style="margin-bottom:20px;">📈 Weekly Sales Trend</h3>
                        <canvas id="salesChart" height="200"></canvas>
                    </div>
                    <div class="analytics-card">
                        <h3 style="margin-bottom:20px;">🏆 Top Performing Items</h3>
                        <?php if ($top_items && $top_items->num_rows > 0): ?>
                            <table class="data-table" style="font-size:0.9rem;">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Orders</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($top = $top_items->fetch_assoc()):
                                        $revenue = $top['price'] * $top['order_count'];
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($top['name']) ?></td>
                                            <td><?= $top['order_count'] ?></td>
                                            <td>₱<?= number_format($revenue, 2) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color:var(--text-muted);">No sales data yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                    const ctx = document.getElementById('salesChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: <?= json_encode(array_column($weekly_sales, 'date')) ?>,
                            datasets: [{
                                label: 'Daily Sales (₱)',
                                data: <?= json_encode(array_column($weekly_sales, 'amount')) ?>,
                                borderColor: '#f59e0b',
                                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    grid: {
                                        color: 'rgba(255,255,255,0.1)'
                                    },
                                    ticks: {
                                        color: '#a1a1aa'
                                    }
                                },
                                x: {
                                    grid: {
                                        display: false
                                    },
                                    ticks: {
                                        color: '#a1a1aa'
                                    }
                                }
                            }
                        }
                    });
                </script>

            <?php elseif ($active_tab === 'feedback'): ?>
                <div class="admin-header">
                    <h1>Customer Feedback</h1>
                    <p>View ratings and reviews from customers. Average rating: <strong style="color:#fbbf24;"><?= number_format($avg_rating, 1) ?>★</strong></p>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title">Recent Reviews</h2>
                        <span style="color:var(--text-muted); font-size:0.85rem;">Latest 20 entries</span>
                    </div>
                    <?php if ($feedback_result && $feedback_result->num_rows > 0): ?>
                        <div style="display:grid; gap:16px;">
                            <?php while ($fb = $feedback_result->fetch_assoc()): ?>
                                <div style="background:rgba(255,255,255,0.03); border-radius:12px; padding:20px; border-left:4px solid #fbbf24;">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                                        <div>
                                            <strong style="color:var(--amber); font-size:1.1rem;"><?= htmlspecialchars($fb['item_name']) ?></strong>
                                            <p style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">By <?= htmlspecialchars($fb['customer_name'] ?? 'Anonymous') ?> • <?= date('M d, Y', strtotime($fb['created_at'])) ?></p>
                                        </div>
                                        <div class="rating-stars">
                                            <?= str_repeat('★', $fb['rating']) . str_repeat('☆', 5 - $fb['rating']) ?>
                                            <span style="color:#fbbf24; font-weight:600;"><?= $fb['rating'] ?>/5</span>
                                        </div>
                                    </div>
                                    <?php if ($fb['comment']): ?>
                                        <p style="color:var(--text-secondary); font-style:italic;">"<?= htmlspecialchars($fb['comment']) ?>"</p>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><span>⭐</span>No feedback yet. Customers can leave ratings on menu items.</div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab === 'logs'): ?>
                <!-- Logs Tab Display -->
                <!-- Description: Shows recent admin action logs for auditing purposes.
        Function: Displays last 30 log entries in reverse chronological order.
        Technical: Loops through $logs array, uses htmlspecialchars for safe output. -->
                <div class="admin-header">
                    <h1>Action Logs</h1>
                    <p>A record of all admin actions performed on this system.</p>
                </div>

                <div class="admin-panel">
                    <div class="admin-panel-header">
                        <h2 class="admin-panel-title">Recent Activity</h2>
                        <span style="color:var(--text-muted); font-size:0.82rem;">Last 30 entries</span>
                    </div>
                    <?php if (!empty($logs)): ?>
                        <div style="font-family:monospace; font-size:0.82rem; line-height:2;">
                            <?php foreach ($logs as $log): ?>
                                <p style="padding:8px 0; border-bottom:1px solid var(--border-subtle); color:var(--text-secondary);">
                                    <?= htmlspecialchars($log) ?>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state"><span>📄</span>No logs yet. Actions will be recorded here.</div>
                    <?php endif; ?>
                </div>

            <?php endif; ?>

        </main>
    </div>

    <script>
        // ── Admin Sidebar Mobile Toggle ──────────────────────────────
        function toggleAdminSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        }

        // Close sidebar when any nav link is tapped on mobile
        document.querySelectorAll('.admin-nav-link').forEach(link => {
            link.addEventListener('click', () => {
                const sidebar = document.getElementById('adminSidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('open');
                }
            });
        });

        // ── Wrap all data-tables for horizontal scroll ───────────────
        document.querySelectorAll('.data-table').forEach(table => {
            if (!table.closest('.table-scroll-wrapper')) {
                const wrapper = document.createElement('div');
                wrapper.className = 'table-scroll-wrapper';
                table.parentNode.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            }
        });
    </script>

<!-- ====== Fix #6: Real-Time Notifications ====== -->
<div id="toast-container" style="
    position:fixed; bottom:24px; right:24px;
    z-index:9999; display:flex; flex-direction:column;
    gap:10px; max-width:340px; pointer-events:none;">
</div>

<style>
.toast-item {
    background:#1a1208; border:1px solid rgba(232,160,64,0.4);
    border-radius:12px; padding:14px 16px; color:#F5F0E8;
    font-size:0.85rem; box-shadow:0 8px 32px rgba(0,0,0,0.5);
    pointer-events:all; animation:toastIn 0.4s ease forwards;
    position:relative; overflow:hidden;
}
.toast-item::before {
    content:''; position:absolute; left:0; top:0; bottom:0;
    width:3px; background:var(--amber); border-radius:12px 0 0 12px;
}
.toast-item.toast-payment { border-color:rgba(34,197,94,0.4); }
.toast-item.toast-payment::before { background:#22c55e; }
.toast-title { font-weight:700; color:var(--amber); margin-bottom:4px; font-size:0.88rem; }
.toast-payment .toast-title { color:#22c55e; }
.toast-msg { color:#aaa; line-height:1.4; }
.toast-close {
    position:absolute; top:8px; right:10px;
    background:none; border:none; color:#666;
    cursor:pointer; font-size:14px; pointer-events:all;
}
.toast-close:hover { color:#fff; }
.toast-link { display:inline-block; margin-top:8px; font-size:0.78rem; color:var(--amber); text-decoration:none; font-weight:600; }
@keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
@keyframes toastOut { from{opacity:1;transform:translateX(0);max-height:200px;margin-bottom:10px} to{opacity:0;transform:translateX(20px);max-height:0;padding:0;margin:0} }
@keyframes badgePulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.4)} }
.badge-pulse { animation:badgePulse 0.6s ease 3; }
</style>

<script>
// Use null first — server will default to 30s ago on first call
// This avoids browser/server timezone mismatch entirely
let lastCheckTime = null;
let isFirstPoll   = true;
let pollTimer     = null;
const shownToastKeys = new Set(
    JSON.parse(sessionStorage.getItem('kape_shown_toasts') || '[]')
);

function saveShownToasts() {
    sessionStorage.setItem('kape_shown_toasts', JSON.stringify([...shownToastKeys].slice(-50)));
}

function pollNotifications() {
    const url = lastCheckTime
        ? 'notifications.php?last_check=' + encodeURIComponent(lastCheckTime)
        : 'notifications.php';

    fetch(url)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (data.error) return;

            // Update pending badges
            if (typeof data.pending_count !== 'undefined') {
                document.querySelectorAll('.pending-badge').forEach(el => {
                    const old = parseInt(el.textContent) || 0;
                    el.textContent = data.pending_count;
                    el.style.display = data.pending_count > 0 ? 'inline-flex' : 'none';
                    if (data.pending_count > old) {
                        el.classList.remove('badge-pulse');
                        void el.offsetWidth;
                        el.classList.add('badge-pulse');
                    }
                });
            }

            // Show toasts — skip first poll to avoid flooding with old orders
            if (!isFirstPoll && data.notifications && data.notifications.length > 0) {
                data.notifications.forEach(n => {
                    const key = (n.type || 'alert') + '-' + (n.order_id || n.time || n.title);
                    if (shownToastKeys.has(key)) return;
                    shownToastKeys.add(key);
                    saveShownToasts();
                    showToast(n);
                });
            }
            isFirstPoll = false;

            // Always update lastCheckTime from server so timezone is irrelevant
            if (data.server_time) lastCheckTime = data.server_time;

            // Fix #6/MED-5: respect server-suggested interval to reduce DB load
            const nextMs = ((data.poll_interval_s ?? 20)) * 1000;
            clearTimeout(pollTimer);
            pollTimer = setTimeout(pollNotifications, nextMs);
        })
        .catch(() => {
            // On network error, retry in 30s
            clearTimeout(pollTimer);
            pollTimer = setTimeout(pollNotifications, 30000);
        });
}

function showToast(n) {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const isPayment = n.type === 'payment_proof';
    const toast = document.createElement('div');
    toast.className = 'toast-item' + (isPayment ? ' toast-payment' : '');

    const titleEl = document.createElement('div');
    titleEl.className = 'toast-title';
    titleEl.textContent = n.title || 'Notification';

    const msgEl = document.createElement('div');
    msgEl.className = 'toast-msg';
    msgEl.textContent = n.message || '';

    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.textContent = '✕';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.onclick = function() { dismissToast(toast); };

    const linkEl = document.createElement('a');
    linkEl.href = 'admin.php?tab=online_orders';
    linkEl.className = 'toast-link';
    linkEl.textContent = 'View Orders →';

    toast.appendChild(closeBtn);
    toast.appendChild(titleEl);
    toast.appendChild(msgEl);
    toast.appendChild(linkEl);
    container.appendChild(toast);

    setTimeout(() => { dismissToast(toast); }, 8000);
}

function dismissToast(toast) {
    if (!toast || !toast.parentNode) return;
    toast.style.animation = 'toastOut 0.35s ease forwards';
    setTimeout(() => toast.remove(), 350);
}

// Add pending-badge class to all badge elements
document.querySelectorAll('.nav-badge, .badge').forEach(el => el.classList.add('pending-badge'));

// Poll immediately on load — adaptive re-scheduling happens inside pollNotifications()
pollNotifications();
</script>

</body>

</html>