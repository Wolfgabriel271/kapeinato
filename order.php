<?php
// Description: Online ordering page for Kape Inato cafe with order processing and receipt generation.
// Function: Handles customer order placement, validates items and stock, sends confirmation emails, and displays printable receipts.
// Technical: Uses PHPMailer for email notifications, QRCode.js for receipt verification, and MySQLi for database operations with prepared statements.

// Error reporting — Fix #9 (also set in helpers.php via db.php)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Session initialization
// Description: Starts PHP session to maintain user state across page requests.
// Function: Enables session-based functionality for order processing and user tracking.
// Technical: Calls session_start() to initialize or resume a session, allowing access to $_SESSION superglobal.
session_start();

// Database connection inclusion
// Description: Includes the database configuration file to establish MySQL connection.
// Function: Provides access to the database connection object for order and menu queries.
// Technical: Requires db.php file which contains MySQLi connection setup with charset configuration.
include 'db.php';

// PHPMailer namespace imports
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Fix #9 / CRIT-5: Use __DIR__ so the path resolves correctly regardless of
// InfinityFree's working directory. Wrapped in file_exists() so a missing
// PHPMailer installation degrades gracefully instead of crashing the page.
$_mailer_available = file_exists(__DIR__ . '/src/PHPMailer.php');
if ($_mailer_available) {
    require __DIR__ . '/src/Exception.php';
    require __DIR__ . '/src/PHPMailer.php';
    require __DIR__ . '/src/SMTP.php';
} else {
    error_log('[Kape Inato] PHPMailer not found at ' . __DIR__ . '/src/ — email disabled.');
}

// Variable initialization
// Description: Initializes variables for storing success messages, errors, and order results.
// Function: Provides containers for feedback messages and order data throughout the script execution.
// Technical: Sets empty strings for $success and $error, null for $order_result to be populated during order processing.
$success      = '';
$error        = '';
$order_result = null;

// ---- Handle Payment Proof Upload (Customer submits screenshot) ----
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['upload_proof'])) {
    header('Content-Type: application/json');
    $proof_order_id = intval($_POST['order_id'] ?? 0);
    $proof_method   = in_array($_POST['payment_method'] ?? '', ['GCash', 'Maya']) ? $_POST['payment_method'] : 'GCash';

    if ($proof_order_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid order ID.']);
        exit();
    }

    // Validate uploaded image
    if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Please select an image file.']);
        exit();
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['proof_image']['tmp_name']);

    if (!in_array($mime, $allowed_types)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Please upload a JPG or PNG screenshot.']);
        exit();
    }
    if ($_FILES['proof_image']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['status' => 'error', 'message' => 'File too large. Max 5MB.']);
        exit();
    }

    // Save proof image
    $proof_dir = 'uploads/payment_proofs/';
    if (!is_dir($proof_dir)) mkdir($proof_dir, 0755, true);
    $ext        = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
    $proof_file = 'proof_' . $proof_order_id . '_' . time() . '.' . strtolower($ext);
    move_uploaded_file($_FILES['proof_image']['tmp_name'], $proof_dir . $proof_file);

    // Update order with proof
    $upd = $conn->prepare("UPDATE online_orders SET payment_status='proof_submitted', payment_proof=?, payment_method=? WHERE id=?");
    $upd->bind_param("ssi", $proof_file, $proof_method, $proof_order_id);
    $upd->execute();
    $upd->close();

    $log_msg = date("Y-m-d H:i:s") . " | PAYMENT PROOF | Order #$proof_order_id | Method: $proof_method | File: $proof_file\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);

    echo json_encode(['status' => 'success', 'message' => 'Payment proof submitted! Admin will confirm shortly.']);
    exit();
}


// Description: Handles form submission when customers place orders through the online form.
// Function: Validates order data, checks item availability and stock, processes payment calculation, and stores order in database.
// Technical: Checks for POST method and place_order parameter, then validates customer information and selected items.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['place_order'])) {
    // Extract customer information from form data
    // Description: Retrieves customer details from the submitted form fields.
    // Function: Gets name, email, phone, pickup time, and special instructions for order processing.
    // Technical: Uses $_POST superglobal to access form data, applies trim() to remove whitespace from text inputs.
    $customer_name        = trim($_POST['customer_name'] ?? '');
    $email                = trim($_POST['email'] ?? '');
    $phone                = trim($_POST['phone'] ?? '');
    $pickup_time          = $_POST['pickup_time'] ?? null;
    $special_instructions = trim($_POST['special_instructions'] ?? '');
    $items                = $_POST['items'] ?? [];
    $quantities           = $_POST['quantities'] ?? [];

    // Initial validation checks
    // Description: Performs basic validation on required customer information.
    // Function: Ensures name and email are provided, and email format is valid before processing items.
    // Technical: Checks for empty strings and uses filter_var with FILTER_VALIDATE_EMAIL for email validation.
    if (empty($customer_name) || empty($email) || empty($items)) {
        $error = "Please fill in your name, email, and select at least one item.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Initialize arrays for valid items and total calculation
        // Description: Sets up arrays to store validated items and calculates order total.
        // Function: Prepares data structures for processing selected menu items and computing final price.
        // Technical: Initializes empty array for valid_items and zero value for total_amount.
        $valid_items  = [];
        $total_amount = 0;

        // Process each selected item
        // Description: Iterates through selected menu items to validate availability and stock.
        // Function: Checks each item's existence, availability, and sufficient stock before adding to order.
        // Technical: Uses foreach loop on items array, performs database queries with prepared statements for security.
        foreach ($items as $item_id) {
            $qty = intval($quantities[$item_id] ?? 1);
            if ($qty > 0) {
                // Query item details from database
                // Description: Retrieves menu item information including price and stock status.
                // Function: Fetches current item data to validate availability and calculate pricing.
                // Technical: Uses prepared statement with bind_param for SQL injection prevention.
                $item_stmt = $conn->prepare("SELECT id, name, price, stock_quantity, is_available FROM menu_items WHERE id = ?");
                $item_stmt->bind_param("i", $item_id);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result()->fetch_assoc();
                $item_stmt->close();

                // Validate item availability and stock
                // Description: Checks if the item exists, is available, and has sufficient stock.
                // Function: Ensures only valid, in-stock items are added to the order.
                // Technical: Conditional checks on item_result existence and boolean flags.
                if ($item_result && $item_result['is_available'] && $item_result['stock_quantity'] >= $qty) {
                    // Calculate total and store valid item
                    // Description: Computes item subtotal and stores validated item data.
                    // Function: Adds item price to total and prepares item data for database insertion.
                    // Technical: Multiplies price by quantity, creates associative array with item details.
                    $total_amount += ($item_result['price'] * $qty);
                    $valid_items[] = [
                        'id'        => $item_result['id'],
                        'name'      => $item_result['name'],
                        'price'     => $item_result['price'],
                        'qty'       => $qty,
                        'new_stock' => $item_result['stock_quantity'] - $qty
                    ];
                }
            }
        }

        // Check if any valid items were found
        // Description: Validates that at least one item passed all checks.
        // Function: Ensures the order contains valid items before proceeding with database insertion.
        // Technical: Checks if valid_items array is not empty, sets error message if empty.
        if (!empty($valid_items)) {
            // Insert main order record
            // Description: Creates the primary order record in the online_orders table.
            // Function: Stores customer information, total amount, and order metadata.
            // Technical: Uses prepared statement with multiple bind_param calls for data insertion.
            $stmt = $conn->prepare("INSERT INTO online_orders (customer_name, email, phone, total_amount, pickup_time, special_instructions, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->bind_param("sssdss", $customer_name, $email, $phone, $total_amount, $pickup_time, $special_instructions);

            // Execute order insertion and process items
            // Description: Executes the order insertion and handles item details if successful.
            // Function: Creates order record and then processes individual order items.
            // Technical: Checks execute() return value, then proceeds with item insertion logic.
            if ($stmt->execute()) {
                $order_id = $stmt->insert_id;

                // Prepare statements for order items and stock updates
                // Description: Sets up prepared statements for inserting order items and updating stock.
                // Function: Prepares database operations for order details and inventory management.
                // Technical: Creates prepared statements for online_order_items and menu_items updates.
                $item_insert_stmt  = $conn->prepare("INSERT INTO online_order_items (online_order_id, menu_item_id, quantity, price_at_time) VALUES (?, ?, ?, ?)");
                $stock_update_stmt = $conn->prepare("UPDATE menu_items SET stock_quantity = ? WHERE id = ?");

                // Process each valid item
                // Description: Inserts order item details and updates inventory stock levels.
                // Function: Records individual items in order and reduces available stock quantities.
                // Technical: Loops through valid_items array, executes prepared statements for each item.
                foreach ($valid_items as $v_item) {
                    $item_insert_stmt->bind_param("iiid", $order_id, $v_item['id'], $v_item['qty'], $v_item['price']);
                    $item_insert_stmt->execute();
                    $stock_update_stmt->bind_param("ii", $v_item['new_stock'], $v_item['id']);
                    $stock_update_stmt->execute();
                }
                $item_insert_stmt->close();
                $stock_update_stmt->close();

                // Generate QR code data for receipt
                // Description: Creates encrypted QR code data containing order verification information.
                // Function: Prepares data string for QR code generation used in receipt verification.
                // Technical: Concatenates order details into formatted string with PHP date formatting.
                $qr_data = "ORDER#$order_id | $customer_name | Total: PHP " . number_format($total_amount, 2) . " | Pickup: " . ($pickup_time ? date('M d Y g:iA', strtotime($pickup_time)) : 'ASAP');

                // Log order to action logs file
                // Description: Records order activity in the action logs for administrative tracking.
                // Function: Maintains audit trail of all online orders placed through the system.
                // Technical: Uses file_put_contents with FILE_APPEND and LOCK_EX for thread-safe logging.
                $log_msg = date("Y-m-d H:i:s") . " | ONLINE ORDER | $customer_name | Order #$order_id | Total: ₱$total_amount\n";
                file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);

                // Generate HTML table for email items list
                // Description: Creates formatted HTML table of ordered items for email template.
                // Function: Builds email content showing itemized order details with pricing.
                // Technical: Uses heredoc-style string concatenation to build HTML table rows.
                $items_list_html = "";
                foreach ($valid_items as $v_item) {
                    $item_total       = $v_item['price'] * $v_item['qty'];
                    $items_list_html .= "
                    <tr>
                        <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);color:#aaa;font-size:13px;'>"
                        . htmlspecialchars($v_item['name']) . "
                        </td>
                        <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);color:#666;font-size:13px;text-align:center;'>x" . $v_item['qty'] . "</td>
                        <td style='padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);color:#E8A040;font-size:13px;text-align:right;font-weight:bold;'>&#8369;" . number_format($item_total, 2) . "</td>
                    </tr>";
                }

                // ── Fix #4: Email Confirmation ──────────────────────────
                // Uses SMTP_* constants from config.php — no hardcoded credentials
                // Fix #9: only attempt if PHPMailer was successfully loaded
                if ($_mailer_available) {
                    try {
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host       = SMTP_HOST;
                        $mail->SMTPAuth   = true;
                        $mail->Username   = SMTP_USER;
                        $mail->Password   = SMTP_PASS;
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = SMTP_PORT;
                        $mail->CharSet    = 'UTF-8';

                        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
                        $mail->addAddress($email, $customer_name);
                        $mail->isHTML(true);
                        $mail->Subject = "Order Confirmed #$order_id — Kape Inato ☕";

                        // Build pickup time display
                        $pickup_display = !empty($pickup_time)
                            ? date('F j, Y \a\t g:i A', strtotime($pickup_time))
                            : 'As soon as ready';

                        // Build order date
                        $order_date = date('F j, Y \a\t g:i A');

                        // Build reference number
                        $ref_number = 'KI-' . $order_id . '-' . date('Ymd');

                        $mail->Body = "
<!DOCTYPE html>
<html>
<body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f4f4;padding:30px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#0d0b08;border-radius:16px;overflow:hidden;max-width:600px;'>

  <!-- Header -->
  <tr>
    <td style='background:linear-gradient(135deg,#1a1208,#2a1e08);padding:36px 30px;text-align:center;border-bottom:2px solid #E8A040;'>
      <h1 style='color:#E8A040;margin:0;font-size:30px;letter-spacing:3px;font-family:Georgia,serif;'>KAPE INATO</h1>
      <p style='color:#888;margin:8px 0 0;font-size:12px;letter-spacing:1px;'>PANDA TEA · J.A. CLARINS ST · DAO, TAGBILARAN, BOHOL</p>
    </td>
  </tr>

  <!-- Order confirmed banner -->
  <tr>
    <td style='background:#1a1208;padding:20px 30px;text-align:center;border-bottom:1px solid rgba(232,160,64,0.2);'>
      <p style='margin:0;font-size:22px;color:#E8A040;font-weight:bold;'>✅ Order Confirmed!</p>
      <p style='margin:6px 0 0;color:#aaa;font-size:13px;'>Hi <strong style='color:#fff;'>$customer_name</strong>, we received your order.</p>
    </td>
  </tr>

  <!-- Order meta -->
  <tr>
    <td style='padding:24px 30px 0;'>
      <table width='100%' cellpadding='0' cellspacing='0'>
        <tr>
          <td style='padding:6px 0;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px;'>Order Number</td>
          <td style='padding:6px 0;color:#E8A040;font-size:14px;font-weight:bold;text-align:right;'>#$order_id</td>
        </tr>
        <tr>
          <td style='padding:6px 0;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px;'>Reference</td>
          <td style='padding:6px 0;color:#fff;font-size:13px;text-align:right;'>$ref_number</td>
        </tr>
        <tr>
          <td style='padding:6px 0;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px;'>Order Date</td>
          <td style='padding:6px 0;color:#fff;font-size:13px;text-align:right;'>$order_date</td>
        </tr>
        <tr>
          <td style='padding:6px 0;color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px;'>Pickup Time</td>
          <td style='padding:6px 0;color:#fff;font-size:13px;text-align:right;'>$pickup_display</td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Divider -->
  <tr><td style='padding:20px 30px 0;'><hr style='border:none;border-top:1px solid rgba(232,160,64,0.15);'></td></tr>

  <!-- Items -->
  <tr>
    <td style='padding:20px 30px;'>
      <p style='color:#888;font-size:11px;text-transform:uppercase;letter-spacing:2px;margin:0 0 14px;'>Items Ordered</p>
      <table width='100%' cellpadding='0' cellspacing='0'>
        $items_list_html
        <tr>
          <td colspan='3' style='padding-top:14px;border-top:1px solid rgba(232,160,64,0.2);'></td>
        </tr>
        <tr>
          <td colspan='2' style='color:#fff;font-size:15px;font-weight:bold;padding-top:4px;'>TOTAL AMOUNT</td>
          <td style='color:#E8A040;font-size:20px;font-weight:bold;text-align:right;padding-top:4px;'>&#8369;" . number_format($total_amount, 2) . "</td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- Payment instructions -->
  <tr>
    <td style='padding:0 30px 24px;'>
      <div style='background:#1a1208;border:1px solid rgba(232,160,64,0.3);border-radius:10px;padding:18px;text-align:center;'>
        <p style='color:#E8A040;margin:0 0 6px;font-size:13px;font-weight:bold;'>💳 Payment Instructions</p>
        <p style='color:#aaa;margin:0;font-size:12px;line-height:1.7;'>
          Scan our <strong style='color:#fff;'>GCash or Maya QR code</strong> to pay.<br>
          Use <strong style='color:#E8A040;'>Order #$order_id</strong> as your reference.<br>
          Upload your payment screenshot on the order page.
        </p>
      </div>
    </td>
  </tr>

  <!-- Contact -->
  <tr>
    <td style='padding:0 30px 30px;text-align:center;'>
      <p style='color:#666;font-size:12px;margin:0;'>Questions? Contact us at</p>
      <p style='color:#E8A040;font-size:13px;font-weight:bold;margin:4px 0 0;'>📞 0961 302 4006</p>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style='background:#080604;padding:20px 30px;text-align:center;border-top:1px solid rgba(232,160,64,0.1);'>
      <p style='color:#444;font-size:11px;margin:0;'>© 2024 Kape Inato — Panda Tea, J.A. Clarins Street, Dao, Tagbilaran, Bohol</p>
      <p style='color:#333;font-size:10px;margin:4px 0 0;'>This is an automated confirmation. Please do not reply to this email.</p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>";

                        // Plain text fallback
                        $mail->AltBody = "Order Confirmed! #$order_id\n\nHi $customer_name,\nThank you for your order at Kape Inato.\n\nTotal: PHP " . number_format($total_amount, 2) . "\nPickup: $pickup_display\nReference: $ref_number\n\nPay via GCash or Maya and upload your screenshot.\nContact: 0961 302 4006";

                        $mail->send();
                        error_log("[Kape Inato] Confirmation email sent to $email for Order #$order_id");
                    } catch (Exception $e) {
                        // Email failure must NOT stop the order from being saved
                        error_log("[Kape Inato] Email error for Order #$order_id: " . $mail->ErrorInfo);
                    }
                } // end if ($_mailer_available)

                // Create order result array for receipt display
                // Description: Prepares order data array for receipt modal rendering.
                // Function: Stores all order information needed to display the receipt popup.
                // Technical: Creates associative array with order details, items, and metadata.
                $order_result = [
                    'id'           => $order_id,
                    'name'         => $customer_name,
                    'email'        => $email,
                    'phone'        => $phone,
                    'pickup_time'  => $pickup_time,
                    'total'        => $total_amount,
                    'items'        => $valid_items,
                    'qr_data'      => $qr_data,
                    'special'      => $special_instructions,
                    'booked_on'    => date('M d, Y \a\t g:i A'),
                ];
            }
            $stmt->close();
        } else {
            // Set error for no valid items
            // Description: Handles case where no selected items pass validation.
            // Function: Provides user feedback when order cannot be processed due to stock issues.
            // Technical: Sets error message when valid_items array remains empty after processing.
            $error = "No valid items found. Items may be out of stock.";
        }
    }
}

// ── Fix #8: Customer Order Lookup ────────────────────────────
$lookup_result       = null;
$lookup_error        = '';
$lookup_items        = [];
$lookup_legacy_items = '';
if (isset($_GET['lookup_order'])) {
    $lk_id    = intval($_GET['order_lookup_id'] ?? 0);
    $lk_email = trim($_GET['order_lookup_email'] ?? '');

    if ($lk_id > 0 && filter_var($lk_email, FILTER_VALIDATE_EMAIL)) {
        $lk_stmt = $conn->prepare(
            "SELECT o.*,
                    COALESCE(GROUP_CONCAT(CONCAT(m.name,' x',oi.quantity) SEPARATOR ', '), NULL) AS item_list
             FROM online_orders o
             LEFT JOIN online_order_items oi ON o.id = oi.online_order_id
             LEFT JOIN menu_items m ON oi.menu_item_id = m.id
             WHERE o.id = ? AND LOWER(o.email) = LOWER(?)
             GROUP BY o.id"
        );
        if ($lk_stmt) {
            $lk_stmt->bind_param('is', $lk_id, $lk_email);
            $lk_stmt->execute();
            $lk_res = $lk_stmt->get_result();
            if ($lk_res->num_rows === 1) {
                $lookup_result = $lk_res->fetch_assoc();

                $li_stmt = $conn->prepare(
                    "SELECT m.name, oi.quantity, oi.price_at_time
                     FROM online_order_items oi
                     JOIN menu_items m ON oi.menu_item_id = m.id
                     WHERE oi.online_order_id = ?
                     ORDER BY m.name"
                );
                if ($li_stmt) {
                    $li_stmt->bind_param('i', $lk_id);
                    $li_stmt->execute();
                    $li_res = $li_stmt->get_result();
                    while ($li = $li_res->fetch_assoc()) {
                        $lookup_items[] = $li;
                    }
                    $li_stmt->close();
                }

                // Legacy fallback for databases not yet migrated (Fix #10)
                if (empty($lookup_items)) {
                    if (!empty($lookup_result['item_list'])) {
                        $lookup_legacy_items = $lookup_result['item_list'];
                    } elseif (!empty($lookup_result['items'])) {
                        $lookup_legacy_items = $lookup_result['items'];
                    }
                }
            } else {
                $lookup_error = 'No order found. Please check your Order ID and email address.';
            }
            $lk_stmt->close();
        } else {
            error_log('[Kape Inato] order lookup prepare() failed: ' . $conn->error);
            $lookup_error = 'Unable to look up orders right now. Please try again shortly.';
        }
    } else {
        $lookup_error = 'Please enter a valid Order ID and email address.';
    }
}

// Query available menu items for order form
// Description: Retrieves menu items that are available and in stock for ordering.
// Function: Fetches menu data to populate the order form with selectable items.
// Technical: SELECT query with WHERE conditions for availability and stock, ordered by category and name.
$menu_items = $conn->query("SELECT * FROM menu_items WHERE is_available = 1 AND stock_quantity > 0 ORDER BY category, name");
?>
<!-- HTML Document Structure -->
<!-- Description: HTML5 document for the online ordering page with form and receipt modal.
Function: Provides user interface for placing orders and displaying confirmation receipts.
Technical: Includes meta tags, external stylesheets, and JavaScript libraries for functionality. -->
<!DOCTYPE html>
<html lang="en">
<!-- HTML Head Section -->
<!-- Description: Document head containing metadata, styles, and script includes.
Function: Sets page title, character encoding, responsive design, and loads required assets.
Technical: Links to external CSS, includes QRCode.js library, and defines print styles. -->

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order — Kape Inato</title>
    <link rel="icon" type="image/png" href="coffee.png">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        .order-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .order-lookup-section {
            padding-top: calc(var(--nav-height) + 24px);
        }

        .order-form {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 16px;
            padding: 30px;
            border: 1px solid var(--border-subtle);
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: var(--amber);
            margin-bottom: 15px;
            font-family: 'Playfair Display', serif;
        }

        .menu-select-grid {
            display: grid;
            gap: 12px;
        }

        .menu-select-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            border: 1px solid var(--border-subtle);
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .menu-select-item:hover {
            border-color: var(--amber);
        }

        .menu-select-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--amber);
            flex-shrink: 0;
        }

        .menu-select-thumb {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--border-subtle);
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.3);
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .item-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .item-price {
            color: var(--amber);
            font-weight: 600;
            white-space: nowrap;
        }

        .qty-input {
            width: 70px !important;
            flex-shrink: 0;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid var(--border-subtle);
            background: rgba(0, 0, 0, 0.3);
            color: var(--text-primary);
            text-align: center;
        }

        .btn-place-order {
            width: 100%;
            padding: 16px;
            font-size: 1.1rem;
            background: linear-gradient(135deg, #065f46, #047857);
            margin-top: 20px;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .btn-place-order:hover {
            background: linear-gradient(135deg, #047857, #064e3b);
            transform: translateY(-1px);
        }

        .pickup-time-input {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-subtle);
            background: rgba(0, 0, 0, 0.3);
            color: var(--text-primary);
            width: 100%;
        }

        /* ── RECEIPT MODAL SCREEN STYLES ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            backdrop-filter: blur(6px);
        }

        .modal-overlay.show {
            display: flex;
        }

        .receipt-modal {
            background: #0d0b08;
            border: 1px solid #E8A040;
            border-radius: 16px;
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        .receipt-header {
            background: linear-gradient(135deg, #1a1208, #2a1e08);
            padding: 24px;
            text-align: center;
            border-bottom: 2px solid #E8A040;
            border-radius: 16px 16px 0 0;
        }

        .receipt-logo {
            color: #E8A040;
            font-size: 22px;
            font-weight: bold;
            letter-spacing: 3px;
            margin-bottom: 4px;
        }

        .receipt-address {
            color: #888;
            font-size: 11px;
        }

        .receipt-body {
            padding: 24px;
        }

        .receipt-badge {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid #22c55e;
            color: #22c55e;
            padding: 10px 16px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
            font-weight: bold;
            font-size: 14px;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #1a1a1a;
            font-size: 13px;
        }

        .receipt-row:last-child {
            border-bottom: none;
        }

        .receipt-label {
            color: #888;
        }

        .receipt-value {
            color: #fff;
            font-weight: 500;
            text-align: right;
        }

        .receipt-items-table {
            width: 100%;
            margin: 16px 0;
            border-collapse: collapse;
        }

        .receipt-items-table th {
            color: #888;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 8px 0;
            border-bottom: 1px solid #333;
            text-align: left;
        }

        .receipt-items-table th:last-child {
            text-align: right;
        }

        .receipt-items-table td {
            padding: 10px 0;
            border-bottom: 1px solid #1a1a1a;
            color: #ccc;
            font-size: 13px;
            vertical-align: top;
        }

        .receipt-items-table td:last-child {
            text-align: right;
            color: #E8A040;
            font-weight: bold;
        }

        .receipt-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            margin-top: 8px;
            border-top: 2px solid #E8A040;
        }

        .receipt-total-label {
            color: #fff;
            font-weight: bold;
            font-size: 15px;
        }

        .receipt-total-value {
            color: #E8A040;
            font-weight: bold;
            font-size: 22px;
        }

        .receipt-qr-section {
            background: #111;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }

        .receipt-qr-title {
            color: #888;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        #payment-qr-code {
            display: inline-block;
            background: #fff;
            padding: 10px;
            border-radius: 8px;
        }

        .receipt-qr-subtitle {
            color: #666;
            font-size: 11px;
            margin-top: 10px;
        }

        .receipt-actions {
            display: flex;
            gap: 10px;
            padding: 0 24px 24px;
        }

        .btn-print {
            flex: 1;
            padding: 12px;
            background: #E8A040;
            color: #000;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-print:hover {
            background: #d4922e;
        }

        .btn-close-receipt {
            flex: 1;
            padding: 12px;
            background: transparent;
            color: #888;
            border: 1px solid #333;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-close-receipt:hover {
            border-color: #555;
            color: #fff;
        }

        /* ── THE ULTIMATE 1-PAGE PRINT FIX ── */
        @media print {
            @page {
                size: 8.5in 13in;
                /* PERFECT FIT FOR PHILIPPINE LONG BOND PAPER */
                margin: 0.5in;
            }

            /* 1. COMPLETELY DELETE BACKGROUND ELEMENTS FROM PRINTER MEMORY */
            nav,
            .order-container,
            footer {
                display: none !important;
            }

            /* 2. RESET BODY SO IT DOES NOT FORCE BLANK PAGES */
            html,
            body {
                height: auto !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #000000 !important;
                overflow: visible !important;
            }

            /* 3. RELEASE MODAL FROM BEING A FIXED POPUP */
            .modal-overlay,
            #receiptModal {
                position: relative !important;
                display: block !important;
                background: transparent !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
                height: auto !important;
                backdrop-filter: none !important;
            }

            /* 4. PERFECT PAPER STYLING & CUT-OFF PREVENTION */
            .receipt-modal {
                border: 2px solid #000000 !important;
                border-radius: 12px !important;
                /* Forces rounded corners on paper */
                background: #ffffff !important;
                color: #000000 !important;
                box-shadow: none !important;
                width: 100% !important;
                max-width: 100% !important;
                overflow: visible !important;
                margin: 0 auto !important;
                padding-bottom: 24px !important;
                /* THIS PREVENTS THE BORDER FROM CUTTING THE TEXT */
            }

            .receipt-header {
                background: #f0f0f0 !important;
                border-bottom: 2px solid #000000 !important;
                -webkit-print-color-adjust: exact;
            }

            .receipt-logo,
            .receipt-total-value,
            .receipt-value {
                color: #000000 !important;
            }

            .receipt-label {
                color: #333333 !important;
            }

            .receipt-items-table th {
                color: #333333 !important;
                border-bottom: 1px solid #000000 !important;
            }

            .receipt-items-table td {
                color: #000000 !important;
                border-bottom: 1px dashed #cccccc !important;
                padding: 5px 0 !important;
            }

            #payment-qr-code {
                border: 1px solid #dddddd !important;
                padding: 5px !important;
            }

            .btn-print,
            .btn-close-receipt {
                display: none !important;
            }
        }
    </style>
</head>
<!-- HTML Body Section -->
<!-- Description: Main content area containing navigation, order form, and receipt modal.
Function: Displays the complete ordering interface with navigation and order processing.
Technical: Includes PHP conditional rendering for receipt modal display. -->

<body>

    <!-- Navigation Bar -->
    <!-- Description: Site navigation with logo, live clock, and menu links.
Function: Provides site-wide navigation and displays current time/date.
Technical: Uses flexbox layout with JavaScript-powered live clock update. -->
    <nav>
        <div class="nav-logo">Kape Inato</div>
        <div style="display:flex; align-items:center; gap:20px;">
            <!-- Live Clock Display -->
            <!-- Description: Real-time clock showing current time and date.
        Function: Provides users with current time information for pickup scheduling.
        Technical: Updates every second using JavaScript setInterval and Date API. -->
            <div id="liveClock" style="font-family:'Courier New',monospace; font-size:0.9rem; color:var(--amber); background:rgba(0,0,0,0.3); padding:6px 12px; border-radius:8px; border:1px solid var(--border-subtle); text-align:center;">🕐 --:--:--</div>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="menu.php">Menu</a></li>
                <li><a href="order.php" style="color:var(--amber);">Order Online 🌐</a></li>
                <li><a href="login.php" class="nav-btn-admin">Admin</a></li>
            </ul>
        </div>
    </nav>
    <!-- Live Clock JavaScript -->
    <!-- Description: Updates the live clock display with current time and date.
Function: Provides real-time timekeeping for user reference during ordering.
Technical: Uses setInterval for periodic updates and toLocaleTimeString for formatting. -->
    <script>
        function updateClock() {
            const now = new Date();
            document.getElementById('liveClock').innerHTML = '🕐 ' + now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }) + '<br><small>' + now.toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric'
            }) + '</small>';
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>

    <!-- ===== Fix #8: Customer Order Lookup ===== -->
    <div class="order-container order-lookup-section" id="order-lookup">
        <div class="order-lookup-panel">
            <h3 class="order-lookup-title">🔍 Check Existing Order Status</h3>
            <p class="order-lookup-desc">Already placed an order? Enter your Order ID and email to check its status.</p>
            <form method="GET" action="order.php#order-lookup" class="order-lookup-form">
                <input type="hidden" name="lookup_order" value="1">
                <div class="order-lookup-field">
                    <label class="order-lookup-label">Order ID</label>
                    <input type="number" name="order_lookup_id" placeholder="e.g. 42" min="1" required
                        value="<?= htmlspecialchars($_GET['order_lookup_id'] ?? '') ?>"
                        class="order-lookup-input">
                </div>
                <div class="order-lookup-field order-lookup-field-wide">
                    <label class="order-lookup-label">Email Address</label>
                    <input type="email" name="order_lookup_email" placeholder="juan@example.com" required
                        value="<?= htmlspecialchars($_GET['order_lookup_email'] ?? '') ?>"
                        class="order-lookup-input">
                </div>
                <button type="submit" class="order-lookup-btn">Check Status</button>
            </form>

            <?php if ($lookup_error): ?>
                <div class="order-lookup-alert order-lookup-alert-error">
                    ⚠️ <?= htmlspecialchars($lookup_error) ?>
                </div>
            <?php endif; ?>

            <?php if ($lookup_result): ?>
                <?php
                $ls = $lookup_result['status'] ?? 'pending';
                $lp = $lookup_result['payment_status'] ?? 'unpaid';
                $status_colors = ['pending' => '#f59e0b', 'confirmed' => '#3b82f6', 'preparing' => '#a855f7', 'ready' => '#22c55e', 'completed' => '#22c55e', 'cancelled' => '#ef4444'];
                $status_icons  = ['pending' => '⏳', 'confirmed' => '✓', 'preparing' => '🍳', 'ready' => '✅', 'completed' => '🎉', 'cancelled' => '✕'];
                $pay_colors    = ['unpaid' => '#ef4444', 'proof_submitted' => '#f59e0b', 'confirmed' => '#22c55e'];
                $pay_labels    = ['unpaid' => '⛔ Unpaid', 'proof_submitted' => '📸 Proof Submitted — Awaiting Admin Confirmation', 'confirmed' => '✅ Payment Confirmed'];
                $sc = $status_colors[$ls] ?? '#aaa';
                $si = $status_icons[$ls]  ?? '•';
                $pc = $pay_colors[$lp]    ?? '#ef4444';
                $pl = $pay_labels[$lp]    ?? '⛔ Unpaid';
                ?>
                <div class="order-lookup-result">
                    <div class="order-lookup-result-header">
                        <div>
                            <span class="order-lookup-order-id">Order #<?= (int) $lookup_result['id'] ?></span>
                            <span class="order-lookup-order-date">
                                Placed <?= date('M d, Y \a\t g:i A', strtotime($lookup_result['created_at'])) ?>
                            </span>
                        </div>
                        <span class="order-lookup-status-badge" style="color:<?= $sc ?>; border-color:<?= $sc ?>;">
                            <?= $si ?> <?= ucfirst($ls) ?>
                        </span>
                    </div>
                    <div class="order-lookup-meta">
                        <div><span class="order-lookup-meta-label">Customer:</span> <strong><?= htmlspecialchars($lookup_result['customer_name']) ?></strong></div>
                        <div><span class="order-lookup-meta-label">Pickup:</span> <strong><?= $lookup_result['pickup_time'] ? date('M d, g:i A', strtotime($lookup_result['pickup_time'])) : 'ASAP' ?></strong></div>
                        <div><span class="order-lookup-meta-label">Total:</span> <strong class="order-lookup-total">₱<?= number_format($lookup_result['total_amount'], 2) ?></strong></div>
                        <div><span class="order-lookup-meta-label">Payment:</span> <strong style="color:<?= $pc ?>;"><?= $pl ?></strong></div>
                    </div>
                    <?php if (!empty($lookup_items)): ?>
                        <div class="order-lookup-items">
                            <p class="order-lookup-items-title">Items Ordered</p>
                            <?php foreach ($lookup_items as $li): ?>
                                <div class="order-lookup-item-row">
                                    <span><?= htmlspecialchars($li['name']) ?> <span class="order-lookup-qty">x<?= (int) $li['quantity'] ?></span></span>
                                    <span class="order-lookup-item-price">₱<?= number_format($li['price_at_time'] * $li['quantity'], 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($lookup_legacy_items): ?>
                        <div class="order-lookup-items">
                            <p class="order-lookup-items-title">Items Ordered</p>
                            <p class="order-lookup-legacy-items"><?= htmlspecialchars($lookup_legacy_items) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($ls === 'ready'): ?>
                        <div class="order-lookup-banner order-lookup-banner-ready">
                            🎉 Your order is ready for pickup! Head to Kape Inato now.
                        </div>
                    <?php elseif ($ls === 'cancelled'): ?>
                        <div class="order-lookup-banner order-lookup-banner-cancelled">
                            This order has been cancelled. Contact us at 0961 302 4006 for assistance.
                        </div>
                    <?php elseif ($lp === 'unpaid' && $ls !== 'cancelled'): ?>
                        <div class="order-lookup-banner order-lookup-banner-pay">
                            Payment pending — scroll down to place a new order or contact us if you need help with payment.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($order_result): ?>
        <!-- Receipt Modal Overlay -->
        <!-- Description: Modal overlay displaying order confirmation receipt with QR code.
Function: Shows detailed order summary and provides print functionality for customers.
Technical: Uses CSS grid layout and QRCode.js library for dynamic QR generation. -->
        <div class="modal-overlay show" id="receiptModal">
            <div class="receipt-modal">
                <!-- Receipt Header -->
                <!-- Description: Header section with cafe branding and contact information.
        Function: Displays official cafe branding and location details on receipt.
        Technical: Uses logo image and styled text with gradient background. -->
                <div class="receipt-header">
                    <div style="margin-bottom: 5px;"><img src="coffee.png" width="50" alt="Logo"></div>
                    <div class="receipt-logo">KAPE INATO</div>
                    <div class="receipt-address">Panda Tea · J.A. Clarins St · Dao, Tagbilaran, Bohol</div>
                </div>

                <!-- Receipt Body -->
                <!-- Description: Main receipt content with order details and item breakdown.
        Function: Displays all order information including items, totals, and special instructions.
        Technical: Uses PHP loops to render dynamic item list and conditional special instructions. -->
                <div class="receipt-body">
                    <div class="receipt-badge">✓ OFFICIAL BOOKING RECEIPT</div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px;">
                        <div class="receipt-row"><span class="receipt-label">ID:</span> <span class="receipt-value">#<?= $order_result['id'] ?></span></div>
                        <div class="receipt-row"><span class="receipt-label">Date:</span> <span class="receipt-value"><?= date('m/d/Y') ?></span></div>
                        <div class="receipt-row"><span class="receipt-label">Customer:</span> <span class="receipt-value"><?= htmlspecialchars($order_result['name']) ?></span></div>
                        <div class="receipt-row"><span class="receipt-label">Pickup:</span> <span class="receipt-value"><?= $order_result['pickup_time'] ? date('g:i A', strtotime($order_result['pickup_time'])) : 'ASAP' ?></span></div>
                    </div>

                    <!-- Items Table -->
                    <!-- Description: Table displaying ordered items with quantities and subtotals.
            Function: Shows detailed breakdown of each item ordered with pricing.
            Technical: Uses PHP foreach loop to iterate through order items array. -->
                    <table class="receipt-items-table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Qty</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $item_count = 0;
                            foreach ($order_result['items'] as $item):
                                $item_count += $item['qty'];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['name']) ?></td>
                                    <td><?= $item['qty'] ?></td>
                                    <td>₱<?= number_format($item['price'] * $item['qty'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="text-align: right; margin-top: 10px; font-size: 0.85rem; color: #555;">
                        Total Items: <?= $item_count ?> | Payment: QR / GCash / PayMaya
                    </div>

                    <!-- Total Row -->
                    <!-- Description: Displays the total order amount prominently.
            Function: Shows final order total with emphasized styling.
            Technical: Uses number_format for proper currency display. -->
                    <div class="receipt-total-row">
                        <span class="receipt-total-label">TOTAL</span>
                        <span class="receipt-total-value">₱<?= number_format($order_result['total'], 2) ?></span>
                    </div>

                    <!-- ====== PAYMENT QR SYSTEM ====== -->
                    <div class="receipt-qr-section" style="padding:16px;">

                        <!-- Payment Method Toggle -->
                        <div style="display:flex; gap:8px; margin-bottom:14px;">
                            <button onclick="switchQR('gcash')" id="tab-gcash"
                                style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid var(--amber);
                               background:var(--amber); color:#000; font-size:0.8rem; font-weight:700; cursor:pointer; transition:all .2s;">
                                💙 GCash
                            </button>
                            <button onclick="switchQR('maya')" id="tab-maya"
                                style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid rgba(255,255,255,0.15);
                               background:transparent; color:#aaa; font-size:0.8rem; font-weight:700; cursor:pointer; transition:all .2s;">
                                💚 Maya
                            </button>
                        </div>

                        <!-- ── GCash QR Panel ── -->
                        <div id="pay-gcash-panel">
                            <div style="font-size:11px; letter-spacing:1px; color:#aaa; margin-bottom:8px; text-transform:uppercase;">
                                💙 Scan to Pay via GCash
                            </div>
                            <div style="display:inline-block; background:#fff; padding:10px; border-radius:12px; margin:10px 0; box-shadow:0 4px 20px rgba(0,0,0,0.4);">
                                <img src="Gcash.jpg" alt="GCash Payment QR" style="width:200px; height:200px; display:block; border-radius:4px;">
                            </div>
                            <div style="margin-top:10px; color:#fff; font-size:0.95rem;">
                                💳 <strong style="color:var(--amber);">₱<?= number_format($order_result['total'], 2) ?></strong>
                                &nbsp;|&nbsp; Order <strong>#<?= $order_result['id'] ?></strong>
                            </div>
                            <div style="color:#666; font-size:10px; margin-top:6px; line-height:1.6;">
                                GCash — Kape Inato &nbsp;|&nbsp; Ref: KI-<?= $order_result['id'] ?>-<?= date('Ymd') ?>
                            </div>
                        </div>

                        <!-- ── Maya QR Panel ── -->
                        <div id="pay-maya-panel" style="display:none;">
                            <div style="font-size:11px; letter-spacing:1px; color:#aaa; margin-bottom:8px; text-transform:uppercase;">
                                💚 Scan to Pay via Maya
                            </div>
                            <div style="display:inline-block; background:#fff; padding:10px; border-radius:12px; margin:10px 0; box-shadow:0 4px 20px rgba(0,0,0,0.4);">
                                <img src="Maya.jpg" alt="Maya Payment QR" style="width:200px; height:200px; display:block; border-radius:4px;">
                            </div>
                            <div style="margin-top:10px; color:#fff; font-size:0.95rem;">
                                💳 <strong style="color:var(--amber);">₱<?= number_format($order_result['total'], 2) ?></strong>
                                &nbsp;|&nbsp; Order <strong>#<?= $order_result['id'] ?></strong>
                            </div>
                            <div style="color:#666; font-size:10px; margin-top:6px; line-height:1.6;">
                                Maya — Kape Inato &nbsp;|&nbsp; Ref: KI-<?= $order_result['id'] ?>-<?= date('Ymd') ?>
                            </div>
                        </div>

                        <!-- ── Proof Upload Section ── -->
                        <div id="proof-upload-section" style="margin-top:16px; border-top:1px solid rgba(255,255,255,0.1); padding-top:14px;">
                            <div style="font-size:11px; letter-spacing:1px; color:#aaa; margin-bottom:8px; text-transform:uppercase;">
                                📸 Upload Payment Screenshot
                            </div>
                            <div id="proof-upload-form">
                                <input type="file" id="proof-file-input" accept="image/*"
                                    style="width:100%; padding:8px; background:rgba(255,255,255,0.05);
                                      border:1px solid rgba(255,255,255,0.15); border-radius:8px;
                                      color:#ccc; font-size:0.82rem; margin-bottom:10px; cursor:pointer;">
                                <button onclick="submitProof()"
                                    style="width:100%; padding:10px; border-radius:8px;
                                   background:linear-gradient(135deg,#1d4ed8,#2563eb);
                                   border:none; color:#fff; font-size:0.88rem; font-weight:700; cursor:pointer;">
                                    📤 Submit Proof of Payment
                                </button>
                            </div>
                            <div id="proof-status" style="display:none; margin-top:10px;"></div>
                        </div>

                        <!-- ── OLD SCAN MODE REMOVED (replaced by proof upload) ── -->
                        <div id="pay-scan-panel" style="display:none;">
                            <!-- old panels removed -->
                        </div>

                    </div>
                    <!-- ====== END PAYMENT QR SYSTEM ====== -->

                    <?php if ($order_result['special']): ?>
                        <!-- Special Instructions Section -->
                        <!-- Description: Displays customer special instructions if provided.
            Function: Shows additional order notes or special requests.
            Technical: Conditional rendering with htmlspecialchars for security. -->
                        <div style="margin-top:20px; border-top:2px dashed #ccc; padding-top:20px; padding-bottom:10px; page-break-inside:avoid;">
                            <div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#555; margin-bottom:8px; font-weight:bold;">
                                Special Instructions / Notes:
                            </div>
                            <div style="font-size:14px; color:#000; font-style:italic; line-height:1.6; word-wrap:break-word; white-space:pre-wrap;">"<?= htmlspecialchars($order_result['special']) ?>"</div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Receipt Actions -->
                <!-- Description: Action buttons for printing receipt and closing modal.
        Function: Provides print functionality and modal dismissal options.
        Technical: Uses onclick handlers for print and close functions. -->
                <div class="receipt-actions">
                    <button class="btn-print" onclick="printReceipt()">🖨️ Print Receipt</button>
                    <button class="btn-close-receipt" onclick="closeReceipt()">✕ Close</button>
                </div>
            </div>
        </div>

        <!-- ====== PAYMENT QR SYSTEM SCRIPTS ====== -->
        <!-- Requires: QRCode.js (already loaded), Html5QrcodeScanner (loaded via CDN below) -->
        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
        <script>
            // ─── State ───────────────────────────────────────────────────────────────────
            let paymentScanner = null;
            let paymentScanHandled = false;
            let paymentScanActive = false;

            // Real InstaPay QR is embedded as a static image — no JS generation needed.

            // ─── QR Tab Switcher (GCash / Maya) ──────────────────────────────────────────
            function switchQR(method) {
                const isGcash = method === 'gcash';

                document.getElementById('pay-gcash-panel').style.display = isGcash ? 'block' : 'none';
                document.getElementById('pay-maya-panel').style.display = isGcash ? 'none' : 'block';

                const tabG = document.getElementById('tab-gcash');
                const tabM = document.getElementById('tab-maya');

                if (isGcash) {
                    tabG.style.background = 'var(--amber)';
                    tabG.style.color = '#000';
                    tabG.style.border = '1px solid var(--amber)';
                    tabM.style.background = 'transparent';
                    tabM.style.color = '#aaa';
                    tabM.style.border = '1px solid rgba(255,255,255,0.15)';
                } else {
                    tabM.style.background = 'var(--amber)';
                    tabM.style.color = '#000';
                    tabM.style.border = '1px solid var(--amber)';
                    tabG.style.background = 'transparent';
                    tabG.style.color = '#aaa';
                    tabG.style.border = '1px solid rgba(255,255,255,0.15)';
                }
            }

            // ─── Proof Upload ─────────────────────────────────────────────────────────────
            function submitProof() {
                const fileInput = document.getElementById('proof-file-input');
                const statusEl = document.getElementById('proof-status');

                if (!fileInput.files || !fileInput.files[0]) {
                    alert('Please select a screenshot first.');
                    return;
                }

                const method = document.getElementById('pay-maya-panel').style.display === 'none' ? 'GCash' : 'Maya';

                const formData = new FormData();
                formData.append('upload_proof', '1');
                formData.append('order_id', '<?= $order_result['id'] ?>');
                formData.append('payment_method', method);
                formData.append('proof_image', fileInput.files[0]);

                statusEl.innerHTML = `<div style="background:rgba(245,158,11,0.1);padding:12px;border-radius:8px;border:1px solid #f59e0b;color:#f59e0b;font-size:0.85rem;">⏳ Uploading proof...</div>`;
                statusEl.style.display = 'block';

                fetch('order.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'success') {
                            statusEl.innerHTML = `<div style="background:rgba(34,197,94,0.1);padding:12px;border-radius:8px;border:1px solid #22c55e;color:#22c55e;font-size:0.85rem;">✅ ${data.message}</div>`;
                            document.getElementById('proof-upload-form').style.display = 'none';
                        } else {
                            statusEl.innerHTML = `<div style="background:rgba(220,38,38,0.1);padding:12px;border-radius:8px;border:1px solid #ef4444;color:#ef4444;font-size:0.85rem;">⚠️ ${data.message}</div>`;
                        }
                    })
                    .catch(() => {
                        statusEl.innerHTML = `<div style="background:rgba(220,38,38,0.1);padding:12px;border-radius:8px;border:1px solid #ef4444;color:#ef4444;font-size:0.85rem;">⚠️ Upload failed. Please try again.</div>`;
                    });
            }

            // ─── Payment Scanner ──────────────────────────────────────────────────────────
            // Opens the device camera inside the receipt modal to scan the customer's
            // GCash / PayMaya QR code for payment verification.
            function startPaymentScanner() {
                if (paymentScanActive) return;

                document.getElementById('start-pay-scan-btn').style.display = 'none';
                document.getElementById('pay-scan-result').style.display = 'none';
                document.getElementById('pay-confirm-box').style.display = 'none';
                document.getElementById('pay-confirmed').style.display = 'none';

                paymentScanHandled = false;
                paymentScanActive = true;

                paymentScanner = new Html5QrcodeScanner(
                    'payment-reader', {
                        fps: 30,
                        disableFlip: true,
                        aspectRatio: 1.333334,
                        rememberLastUsedCamera: true,
                        formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE]
                    },
                    false
                );
                paymentScanner.render(onPaymentScanSuccess, () => {});
            }

            // ─── Scan Success Callback ────────────────────────────────────────────────────
            // Fires when a QR code is successfully decoded from the camera feed.
            // Stops the scanner, displays the decoded data, and shows the confirm button.
            function onPaymentScanSuccess(decodedText) {
                if (paymentScanHandled) return;
                paymentScanHandled = true;
                paymentScanActive = false;

                if (paymentScanner) {
                    paymentScanner.clear().catch(() => {});
                }

                const resultEl = document.getElementById('pay-scan-result');
                resultEl.innerHTML = `
        <div style="background:rgba(245,158,11,0.08); padding:12px; border-radius:8px;
                    border:1px solid #f59e0b; color:#f59e0b; font-size:0.82rem; word-break:break-all;">
            <strong>📷 QR Scanned:</strong><br>
            <span style="color:#ccc;">${decodedText.substring(0, 120)}${decodedText.length > 120 ? '…' : ''}</span>
        </div>`;
                resultEl.style.display = 'block';

                // Show the manual confirm button so staff can verify and press confirm
                document.getElementById('pay-confirm-box').style.display = 'block';
            }

            // ─── Receipt Utility Functions ────────────────────────────────────────────────
            function printReceipt() {
                window.print();
            }

            function closeReceipt() {
                document.getElementById('receiptModal').classList.remove('show');
                window.location.href = 'order.php';
            }
        </script>
    <?php endif; ?>

    <!-- Order Container -->
    <!-- Description: Main container for the online ordering interface.
Function: Wraps the entire order form and provides responsive layout.
Technical: Uses CSS classes for styling and contains form validation. -->
    <div class="order-container">
        <!-- Order Header -->
        <!-- Description: Header section with title and description for the ordering page.
    Function: Introduces the online ordering feature to customers.
    Technical: Uses semantic HTML with styled text and section eyebrow. -->
        <div style="text-align:center; margin-bottom:30px;">
            <span class="section-eyebrow" style="color:gray;">Order from Anywhere</span>
            <h1 style="font-family:'Playfair Display',serif; font-size:2.5rem; margin:10px 0;">Online <span style="color:var(--amber);">Order</span></h1>
            <p style="color:var(--text-muted);">Place your order online and pick it up at our cafe. Fast, easy, convenient!</p>
        </div>

        <!-- Error Message Display -->
        <!-- Description: Shows validation errors or processing errors to the user.
    Function: Provides feedback when order submission fails or encounters issues.
    Technical: Conditional PHP rendering with htmlspecialchars for security. -->
        <?php if ($error): ?>
            <div style="background:rgba(220,38,38,0.1);border:1px solid #ef4444;color:#ef4444;padding:14px 20px;border-radius:10px;margin-bottom:20px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Order Form -->
        <!-- Description: Main form for collecting customer order information and item selections.
    Function: Handles order submission with validation and processes customer data.
    Technical: Uses POST method with multipart/form-data for file uploads and form validation. -->
        <form id="expressOrderForm" method="POST" action="order.php" class="order-form">
            <!-- Customer Information Section -->
            <!-- Description: Form section for collecting customer contact and pickup details.
        Function: Gathers required information for order processing and communication.
        Technical: Uses flexbox layout with required field validation and input types. -->
            <div class="form-section">
                <h3>👤 Your Information</h3>
                <div style="display:flex; gap:20px; margin-bottom:15px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px;">
                        <label class="form-label" style="display:block;margin-bottom:5px;color:var(--text-secondary);font-size:0.9rem;text-transform:uppercase;">Full Name *</label>
                        <input type="text" name="customer_name" required placeholder="Juan dela Cruz" style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--border-subtle);background:rgba(0,0,0,0.3);color:var(--text-primary);box-sizing:border-box;">
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label class="form-label" style="display:block;margin-bottom:5px;color:var(--text-secondary);font-size:0.9rem;text-transform:uppercase;">Email Address *</label>
                        <input type="email" name="email" required placeholder="juan@example.com" style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--border-subtle);background:rgba(0,0,0,0.3);color:var(--text-primary);box-sizing:border-box;">
                    </div>
                </div>
                <div style="display:flex; gap:20px; flex-wrap:wrap;">
                    <div style="flex:1; min-width:200px;">
                        <label class="form-label" style="display:block;margin-bottom:5px;color:var(--text-secondary);font-size:0.9rem;text-transform:uppercase;">Phone Number</label>
                        <input type="tel" name="phone" placeholder="0917 123 4567" style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--border-subtle);background:rgba(0,0,0,0.3);color:var(--text-primary);box-sizing:border-box;">
                    </div>
                    <div style="flex:1; min-width:200px;">
                        <label class="form-label" style="display:block;margin-bottom:5px;color:var(--text-secondary);font-size:0.9rem;text-transform:uppercase;">Preferred Pickup Time</label>
                        <input type="datetime-local" name="pickup_time" class="pickup-time-input" style="box-sizing:border-box;">
                        <small style="color:var(--text-muted);display:block;margin-top:5px;">Leave empty for ASAP</small>
                    </div>
                </div>
            </div>

            <!-- Menu Items Selection Section -->
            <!-- Description: Dynamic menu display with category grouping and quantity selection.
        Function: Allows customers to select items and specify quantities for their order.
        Technical: Uses PHP loops to render menu items with category headers and stock validation. -->
            <div class="form-section">
                <h3>🍽️ Select Your Items</h3>
                <?php
                $current_category = '';
                if ($menu_items && $menu_items->num_rows > 0):
                    while ($item = $menu_items->fetch_assoc()):
                        if ($current_category != $item['category']):
                            if ($current_category != '') echo '</div>';
                            $current_category = $item['category'];
                            echo '<h4 style="color:var(--amber);margin:20px 0 10px;text-transform:uppercase;font-size:0.9rem;letter-spacing:1px;">' . htmlspecialchars($current_category) . '</h4>';
                            echo '<div class="menu-select-grid">';
                        endif;
                ?>
                        <!-- Menu Item Checkbox -->
                        <!-- Description: Individual menu item with checkbox, quantity input, and pricing.
                Function: Enables selection of menu items with quantity specification.
                Technical: Uses checkbox input with associated quantity field and stock limits. -->
                        <label class="menu-select-item">
                            <input type="checkbox" name="items[]" value="<?= $item['id'] ?>">
                            <?php $item_img = resolveMenuItemImage($item['image_path'], $item['category']); ?>
                            <img src="<?= htmlspecialchars($item_img) ?>" alt="" class="menu-select-thumb" loading="lazy">
                            <div class="item-info">
                                <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="item-desc"><?= htmlspecialchars($item['description'] ?? '') ?> <span style="color:#f59e0b;">(Stock: <?= $item['stock_quantity'] ?>)</span></div>
                            </div>
                            <div class="item-price">₱<?= number_format($item['price'], 2) ?></div>
                            <input type="number" name="quantities[<?= $item['id'] ?>]" class="qty-input" value="1" min="1" max="<?= $item['stock_quantity'] ?>" onclick="event.stopPropagation();">
                        </label>
                    <?php
                    endwhile;
                    if ($current_category != '') echo '</div>';
                endif;
                if ($menu_items && $menu_items->num_rows === 0): ?>
                    <p style="color:var(--text-muted);">No items available at the moment.</p>
                <?php endif; ?>
            </div>

            <!-- Special Instructions Section -->
            <!-- Description: Textarea for customer special requests or dietary requirements.
        Function: Allows customers to provide additional order instructions.
        Technical: Uses textarea with vertical resize and placeholder text. -->
            <div class="form-section">
                <h3>📝 Special Instructions</h3>
                <textarea name="special_instructions" rows="3" placeholder="Any allergies, preferences, or special requests?" style="width:100%;padding:12px;border-radius:8px;border:1px solid var(--border-subtle);background:rgba(0,0,0,0.3);color:var(--text-primary);resize:vertical;box-sizing:border-box;"></textarea>
            </div>

            <!-- Order Information Panel -->
            <!-- Description: Information panel explaining what happens after order placement.
        Function: Sets customer expectations for the ordering process and receipt generation.
        Technical: Uses styled div with list items and highlighted text. -->
            <div style="background:rgba(245,158,11,0.05);border:1px solid var(--amber);border-radius:10px;padding:20px;margin-top:10px;">
                <p style="margin:0;color:var(--text-muted);font-weight:bold;">After placing your order, you will receive:</p>
                <ul style="margin:10px 0;padding-left:20px;color:var(--text-secondary);">
                    <li>A <b style="color:var(--amber);">receipt popup</b> with your <b style="color:var(--amber);">Payment QR Code</b></li>
                    <li>Scan our <b style="color:var(--amber);">Payment QR</b> to pay via GCash / PayMaya, or show your own QR to the cashier</li>
                    <li><b style="color:var(--amber);">Email confirmation</b> sent to your inbox</li>
                    <li>Pickup instructions at Kape Inato, Panda Tea, J.A. Clarins St.</li>
                </ul>
            </div>

            <input type="hidden" name="place_order" value="1">
            <button type="submit" class="btn-place-order">📋 Place Order & Get Receipt</button>
        </form>
    </div>

    <!-- Footer Section -->
    <!-- Description: Site footer with copyright and location information.
Function: Provides legal and contact information at the bottom of the page.
Technical: Uses footer element with logo and paragraph text. -->
    <footer>
        <div class="footer-logo">Kape Inato</div>
        <p>&copy; <?= date('Y') ?> Kape Inato — Panda Tea, J.A. Clarins Street, Dao, Tagbilaran, Bohol.</p>
    </footer>

    <!-- Form Validation JavaScript -->
    <!-- Description: Client-side validation to ensure at least one item is selected.
Function: Prevents form submission when no items are selected and shows alert.
Technical: Uses addEventListener for form submit event and querySelectorAll for checkbox checking. -->
    <script>
        document.getElementById('expressOrderForm').addEventListener('submit', function(e) {
            const checked = this.querySelectorAll('input[name="items[]"]:checked');
            if (checked.length === 0) {
                e.preventDefault();
                alert("⚠️ Please select at least one item before placing your order!");
            }
        });
    </script>
</body>

</html>