<?php
// Description: Online ordering page for Kape Inato cafe with order processing and receipt generation.
// Function: Handles customer order placement, validates items and stock, sends confirmation emails, and displays printable receipts.
// Technical: Uses PHPMailer for email notifications, QRCode.js for receipt verification, and MySQLi for database operations with prepared statements.

// Error reporting configuration
// Description: Enables comprehensive error reporting for debugging during development.
// Function: Shows all PHP errors and warnings to help identify issues during order processing.
// Technical: Sets error_reporting to E_ALL and display_errors to 1, which is useful for development but should be disabled in production.
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
// Description: Imports PHPMailer classes for email functionality.
// Function: Enables sending HTML email confirmations to customers after successful orders.
// Technical: Uses use statements to import PHPMailer, Exception, and SMTP classes from the PHPMailer library.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// PHPMailer file includes
// Description: Includes PHPMailer library files for email sending capabilities.
// Function: Loads the necessary PHPMailer classes to handle SMTP email transmission.
// Technical: Requires Exception.php, PHPMailer.php, and SMTP.php files from the src directory.
require 'src/Exception.php';
require 'src/PHPMailer.php';
require 'src/SMTP.php';

// Variable initialization
// Description: Initializes variables for storing success messages, errors, and order results.
// Function: Provides containers for feedback messages and order data throughout the script execution.
// Technical: Sets empty strings for $success and $error, null for $order_result to be populated during order processing.
$success      = '';
$error        = '';
$order_result = null; 

// ---- Handle Payment Confirmation (QR Scan by Staff) ----
// Description: Logs a payment confirmation event when staff confirms the customer's QR payment.
// Function: Records the paid event in the action log for auditing.
// Technical: Triggered by AJAX POST from the payment confirm button in the receipt modal.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['payment_confirm'])) {
    $confirmed_order_id = intval($_POST['order_id'] ?? 0);
    $confirmed_amount   = floatval($_POST['amount'] ?? 0);
    $log_msg = date("Y-m-d H:i:s") . " | PAYMENT | Order #$confirmed_order_id | QR Payment Confirmed | ₱$confirmed_amount\n";
    file_put_contents("action_logs.txt", $log_msg, FILE_APPEND | LOCK_EX);
    // Update online_orders payment_status if column exists (graceful fail)
    $conn->query("UPDATE online_orders SET status='confirmed' WHERE id=$confirmed_order_id");
    echo json_encode(['status' => 'ok']);
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
                    $items_list_html .= "<tr>
                        <td style='padding:10px 0;border-bottom:1px solid #333;color:#ccc;'>" . $v_item['qty'] . "x " . htmlspecialchars($v_item['name']) . "</td>
                        <td style='padding:10px 0;border-bottom:1px solid #333;color:#E8A040;text-align:right;font-weight:bold;'>₱" . number_format($item_total, 2) . "</td>
                    </tr>";
                }

                // Send confirmation email using PHPMailer
                // Description: Configures and sends HTML email confirmation to customer.
                // Function: Notifies customer of successful order with detailed receipt information.
                // Technical: Uses PHPMailer library with SMTP authentication and HTML email template.
                try {
                    // Initialize PHPMailer instance
                    // Description: Creates new PHPMailer object for email composition.
                    // Function: Sets up email client with SMTP configuration for Gmail sending.
                    // Technical: Instantiates PHPMailer with exception handling enabled.
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'jazdigamon12@gmail.com';
                    $mail->Password   = 'itfl kutw rryn xtvm';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    // Configure email sender and recipient
                    // Description: Sets sender address and adds customer as email recipient.
                    // Function: Defines email source and destination for order confirmation.
                    // Technical: Uses setFrom() for sender and addAddress() for recipient with customer details.
                    $mail->setFrom('jazdigamon12@gmail.com', 'Kape Inato');
                    $mail->addAddress($email, $customer_name);
                    $mail->isHTML(true);
                    $mail->Subject = "Order Confirmation #$order_id — Kape Inato";
                    // Create HTML email body with order details
                    // Description: Generates styled HTML email template containing order information.
                    // Function: Builds professional email layout with order summary and pickup instructions.
                    // Technical: Uses heredoc string syntax with embedded PHP variables for dynamic content.
                    $mail->Body    = "
                    <div style='background:#0d0b08;color:#fff;font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border-radius:12px;overflow:hidden;'>
                        <div style='background:linear-gradient(135deg,#1a1208,#2a1e08);padding:30px;text-align:center;border-bottom:2px solid #E8A040;'>
                            <h1 style='color:#E8A040;margin:0;font-size:28px;letter-spacing:2px;'>KAPE INATO</h1>
                            <p style='color:#aaa;margin:8px 0 0;font-size:13px;'>Panda Tea · J.A. Clarins St · Dao, Tagbilaran, Bohol</p>
                        </div>
                        <div style='padding:30px;'>
                            <h2 style='color:#E8A040;margin:0 0 6px;'>Order Confirmed!</h2>
                            <p style='color:#aaa;margin:0 0 24px;font-size:14px;'>Hi <b style='color:#fff;'>$customer_name</b>, your order has been received.</p>
                            <div style='background:#1a1a1a;border-radius:8px;padding:20px;margin-bottom:20px;'>
                                <p style='color:#888;font-size:12px;text-transform:uppercase;letter-spacing:1px;margin:0 0 12px;'>Order Details</p>
                                <table style='width:100%;border-collapse:collapse;'>
                                    $items_list_html
                                    <tr>
                                        <td style='padding:14px 0 0;color:#fff;font-weight:bold;font-size:16px;'>TOTAL</td>
                                        <td style='padding:14px 0 0;color:#E8A040;font-weight:bold;font-size:18px;text-align:right;'>₱" . number_format($total_amount, 2) . "</td>
                                    </tr>
                                </table>
                            </div>
                            <div style='background:#1a1208;border:1px solid #E8A040;border-radius:8px;padding:16px;text-align:center;margin-bottom:20px;'>
                                <p style='color:#E8A040;margin:0;font-size:13px;'>💳 Payment via QR (GCash / PayMaya) is available at pickup.</p>
                                <p style='color:#E8A040;margin:6px 0 0;font-size:13px;'>📞 <b>0961 302 4006</b></p>
                            </div>
                        </div>
                    </div>";
                    // Send the email
                    // Description: Executes email transmission to customer.
                    // Function: Delivers order confirmation email with all order details.
                    // Technical: Calls send() method which throws exception on failure.
                    $mail->send();
                } catch (Exception $e) {
                    // Log email sending errors
                    // Description: Records email transmission failures for debugging.
                    // Function: Maintains error logs for troubleshooting email delivery issues.
                    // Technical: Uses error_log() to write PHPMailer error messages to PHP error log.
                    error_log("Email error: " . $mail->ErrorInfo);
                }

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
        .order-container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .order-form { background: rgba(255,255,255,0.03); border-radius: 16px; padding: 30px; border: 1px solid var(--border-subtle); }
        .form-section { margin-bottom: 30px; }
        .form-section h3 { color: var(--amber); margin-bottom: 15px; font-family: 'Playfair Display', serif; }
        .menu-select-grid { display: grid; gap: 12px; }
        .menu-select-item { display: flex; align-items: center; gap: 15px; padding: 15px; background: rgba(0,0,0,0.2); border-radius: 10px; border: 1px solid var(--border-subtle); transition: all 0.2s ease; cursor: pointer; }
        .menu-select-item:hover { border-color: var(--amber); }
        .menu-select-item input[type="checkbox"] { width: 20px; height: 20px; accent-color: var(--amber); flex-shrink: 0; }
        .item-info { flex: 1; }
        .item-name { font-weight: 600; color: var(--text-primary); }
        .item-desc { font-size: 0.85rem; color: var(--text-muted); }
        .item-price { color: var(--amber); font-weight: 600; white-space: nowrap; }
        .qty-input { width: 70px; padding: 8px; border-radius: 6px; border: 1px solid var(--border-subtle); background: rgba(0,0,0,0.3); color: var(--text-primary); text-align: center; }
        .btn-place-order { width: 100%; padding: 16px; font-size: 1.1rem; background: linear-gradient(135deg, #065f46, #047857); margin-top: 20px; color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s; font-weight: bold; letter-spacing: 1px; }
        .btn-place-order:hover { background: linear-gradient(135deg, #047857, #064e3b); transform: translateY(-1px); }
        .pickup-time-input { padding: 12px; border-radius: 8px; border: 1px solid var(--border-subtle); background: rgba(0,0,0,0.3); color: var(--text-primary); width: 100%; }

        /* ── RECEIPT MODAL SCREEN STYLES ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 9999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(6px); }
        .modal-overlay.show { display: flex; }
        .receipt-modal { background: #0d0b08; border: 1px solid #E8A040; border-radius: 16px; max-width: 480px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative; }
        .receipt-header { background: linear-gradient(135deg, #1a1208, #2a1e08); padding: 24px; text-align: center; border-bottom: 2px solid #E8A040; border-radius: 16px 16px 0 0; }
        .receipt-logo { color: #E8A040; font-size: 22px; font-weight: bold; letter-spacing: 3px; margin-bottom: 4px; }
        .receipt-address { color: #888; font-size: 11px; }
        .receipt-body { padding: 24px; }
        .receipt-badge { background: rgba(34,197,94,0.1); border: 1px solid #22c55e; color: #22c55e; padding: 10px 16px; border-radius: 8px; text-align: center; margin-bottom: 20px; font-weight: bold; font-size: 14px; }
        .receipt-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #1a1a1a; font-size: 13px; }
        .receipt-row:last-child { border-bottom: none; }
        .receipt-label { color: #888; }
        .receipt-value { color: #fff; font-weight: 500; text-align: right; }
        .receipt-items-table { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .receipt-items-table th { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; padding: 8px 0; border-bottom: 1px solid #333; text-align: left; }
        .receipt-items-table th:last-child { text-align: right; }
        .receipt-items-table td { padding: 10px 0; border-bottom: 1px solid #1a1a1a; color: #ccc; font-size: 13px; vertical-align: top; }
        .receipt-items-table td:last-child { text-align: right; color: #E8A040; font-weight: bold; }
        .receipt-total-row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; margin-top: 8px; border-top: 2px solid #E8A040; }
        .receipt-total-label { color: #fff; font-weight: bold; font-size: 15px; }
        .receipt-total-value { color: #E8A040; font-weight: bold; font-size: 22px; }
        .receipt-qr-section { background: #111; border-radius: 10px; padding: 20px; text-align: center; margin: 20px 0; }
        .receipt-qr-title { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }
        #payment-qr-code { display: inline-block; background: #fff; padding: 10px; border-radius: 8px; }
        .receipt-qr-subtitle { color: #666; font-size: 11px; margin-top: 10px; }
        .receipt-actions { display: flex; gap: 10px; padding: 0 24px 24px; }
        .btn-print { flex: 1; padding: 12px; background: #E8A040; color: #000; border: none; border-radius: 8px; font-weight: bold; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .btn-print:hover { background: #d4922e; }
        .btn-close-receipt { flex: 1; padding: 12px; background: transparent; color: #888; border: 1px solid #333; border-radius: 8px; font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .btn-close-receipt:hover { border-color: #555; color: #fff; }

        /* ── THE ULTIMATE 1-PAGE PRINT FIX ── */
        @media print {
            @page {
                size: 8.5in 13in; /* PERFECT FIT FOR PHILIPPINE LONG BOND PAPER */
                margin: 0.5in;
            }

            /* 1. COMPLETELY DELETE BACKGROUND ELEMENTS FROM PRINTER MEMORY */
            nav, .order-container, footer {
                display: none !important;
            }

            /* 2. RESET BODY SO IT DOES NOT FORCE BLANK PAGES */
            html, body {
                height: auto !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                color: #000000 !important;
                overflow: visible !important;
            }

            /* 3. RELEASE MODAL FROM BEING A FIXED POPUP */
            .modal-overlay, #receiptModal { 
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
                border-radius: 12px !important; /* Forces rounded corners on paper */
                background: #ffffff !important;
                color: #000000 !important;
                box-shadow: none !important;
                width: 100% !important;
                max-width: 100% !important;
                overflow: visible !important;
                margin: 0 auto !important;
                padding-bottom: 24px !important; /* THIS PREVENTS THE BORDER FROM CUTTING THE TEXT */
            }

            .receipt-header {
                background: #f0f0f0 !important;
                border-bottom: 2px solid #000000 !important;
                -webkit-print-color-adjust: exact;
            }

            .receipt-logo, .receipt-total-value, .receipt-value { 
                color: #000000 !important; 
            }
            
            .receipt-label { color: #333333 !important; }

            .receipt-items-table th { color: #333333 !important; border-bottom: 1px solid #000000 !important; }
            .receipt-items-table td { color: #000000 !important; border-bottom: 1px dashed #cccccc !important; padding: 5px 0 !important; }

            #payment-qr-code {
                border: 1px solid #dddddd !important;
                padding: 5px !important;
            }

            .btn-print, .btn-close-receipt { display: none !important; }
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
    document.getElementById('liveClock').innerHTML = '🕐 ' + now.toLocaleTimeString('en-US', {hour12:true,hour:'2-digit',minute:'2-digit',second:'2-digit'}) + '<br><small>' + now.toLocaleDateString('en-US', {weekday:'short',month:'short',day:'numeric'}) + '</small>';
}
setInterval(updateClock, 1000); updateClock();
</script>

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
            <!-- Description: Dual-mode payment QR section for cashless transactions.
            Function: Lets staff generate a payment QR for customers to scan (GCash/PayMaya),
                      OR scan the customer's own QR to confirm payment.
            Technical: Mode-toggle tabs, QRCode.js for generation, Html5QrcodeScanner for scanning. -->
            <div class="receipt-qr-section" style="padding:16px;">

                <!-- Payment Mode Toggle Tabs -->
                <div style="display:flex; gap:8px; margin-bottom:14px;">
                    <button onclick="switchPayMode('generate')" id="tab-generate"
                        style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid var(--amber);
                               background:var(--amber); color:#000; font-size:0.8rem; font-weight:700; cursor:pointer; transition:all .2s;">
                        📲 Show Payment QR
                    </button>
                    <button onclick="switchPayMode('scan')" id="tab-scan"
                        style="flex:1; padding:9px 6px; border-radius:8px; border:1px solid rgba(255,255,255,0.15);
                               background:transparent; color:#aaa; font-size:0.8rem; font-weight:700; cursor:pointer; transition:all .2s;">
                        📷 Scan Customer QR
                    </button>
                </div>

                <!-- ── GENERATE MODE: Shows real InstaPay QR → Customer scans to pay ── -->
                <div id="pay-generate-panel">
                    <div class="receipt-qr-title" style="font-size:11px; letter-spacing:1px; color:#aaa; margin-bottom:8px;">
                        📲 CUSTOMER SCANS THIS TO PAY
                    </div>
                    <!-- Real InstaPay/GCash QR — embedded as base64, no file needed -->
                    <div id="payment-qr-code" style="display:inline-block; background:#fff; padding:10px; border-radius:12px; margin:10px 0; box-shadow:0 4px 20px rgba(0,0,0,0.4);">
                        <img src="data:image/png;base64,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"
                             alt="InstaPay Payment QR — Kape Inato"
                             style="width:220px; height:220px; display:block; border-radius:4px;">
                    </div>
                    <div class="receipt-qr-subtitle" style="margin-top:10px;">
                        💳 <strong style="color:var(--amber); font-size:1rem;">₱<?= number_format($order_result['total'], 2) ?></strong>
                        &nbsp;|&nbsp; Order <strong style="color:#fff;">#<?= $order_result['id'] ?></strong>
                    </div>
                    <div style="color:#666; font-size:10px; margin-top:6px; line-height:1.6;">
                        InstaPay / GCash — Kape Inato<br>
                        Ref: KAPEINATO-<?= $order_result['id'] ?>-<?= date('Ymd') ?>
                    </div>
                </div>

                <!-- ── SCAN MODE: Staff scans customer's GCash/PayMaya QR ── -->
                <div id="pay-scan-panel" style="display:none;">
                    <div class="receipt-qr-title">Point camera at customer's payment QR</div>
                    <!-- Camera container -->
                    <div id="payment-reader" style="margin:12px 0; border-radius:8px; overflow:hidden;"></div>
                    <!-- Start scanner button (shown before scan starts) -->
                    <button id="start-pay-scan-btn" onclick="startPaymentScanner()"
                        style="width:100%; padding:10px; border-radius:8px; background:rgba(245,158,11,0.15);
                               border:1px solid var(--amber); color:var(--amber); font-size:0.88rem;
                               font-weight:700; cursor:pointer; margin-top:6px;">
                        📷 Start Camera Scanner
                    </button>
                    <!-- Scan result display -->
                    <div id="pay-scan-result" style="display:none; margin-top:12px;"></div>
                    <!-- Confirm payment manually after scan -->
                    <div id="pay-confirm-box" style="display:none; margin-top:10px; text-align:center;">
                        <button onclick="confirmPayment()"
                            style="width:100%; padding:10px; border-radius:8px; background:linear-gradient(135deg,#065f46,#047857);
                                   border:none; color:#fff; font-size:0.9rem; font-weight:700; cursor:pointer;">
                            ✅ Confirm Payment — ₱<?= number_format($order_result['total'], 2) ?>
                        </button>
                    </div>
                    <!-- Payment confirmed state -->
                    <div id="pay-confirmed" style="display:none; margin-top:10px; background:rgba(34,197,94,0.1);
                         border:1px solid #22c55e; border-radius:8px; padding:12px; text-align:center; color:#22c55e; font-weight:700; font-size:0.9rem;">
                        ✅ Payment Confirmed! Order #<?= $order_result['id'] ?> is PAID.
                    </div>
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
let paymentScanner     = null;
let paymentScanHandled = false;
let paymentScanActive  = false;

// Real InstaPay QR is embedded as a static image — no JS generation needed.

// ─── Tab Switcher ─────────────────────────────────────────────────────────────
// Switches between "Show Payment QR" and "Scan Customer QR" modes.
function switchPayMode(mode) {
    const isGenerate = mode === 'generate';

    document.getElementById('pay-generate-panel').style.display = isGenerate ? 'block' : 'none';
    document.getElementById('pay-scan-panel').style.display     = isGenerate ? 'none'  : 'block';

    const tabGen  = document.getElementById('tab-generate');
    const tabScan = document.getElementById('tab-scan');

    // Active tab = amber filled, inactive = transparent ghost
    if (isGenerate) {
        tabGen.style.background  = 'var(--amber)';
        tabGen.style.color       = '#000';
        tabGen.style.border      = '1px solid var(--amber)';
        tabScan.style.background = 'transparent';
        tabScan.style.color      = '#aaa';
        tabScan.style.border     = '1px solid rgba(255,255,255,0.15)';

        // Stop scanner if active when switching away
        if (paymentScanner && paymentScanActive) {
            paymentScanner.clear().catch(() => {});
            paymentScanActive  = false;
            paymentScanHandled = false;
        }
    } else {
        tabScan.style.background = 'var(--amber)';
        tabScan.style.color      = '#000';
        tabScan.style.border     = '1px solid var(--amber)';
        tabGen.style.background  = 'transparent';
        tabGen.style.color       = '#aaa';
        tabGen.style.border      = '1px solid rgba(255,255,255,0.15)';
    }
}

// ─── Payment Scanner ──────────────────────────────────────────────────────────
// Opens the device camera inside the receipt modal to scan the customer's
// GCash / PayMaya QR code for payment verification.
function startPaymentScanner() {
    if (paymentScanActive) return;

    document.getElementById('start-pay-scan-btn').style.display  = 'none';
    document.getElementById('pay-scan-result').style.display     = 'none';
    document.getElementById('pay-confirm-box').style.display     = 'none';
    document.getElementById('pay-confirmed').style.display       = 'none';

    paymentScanHandled = false;
    paymentScanActive  = true;

    paymentScanner = new Html5QrcodeScanner(
        'payment-reader',
        {
            fps                : 30,
            disableFlip        : true,
            aspectRatio        : 1.333334,
            rememberLastUsedCamera: true,
            formatsToSupport   : [Html5QrcodeSupportedFormats.QR_CODE]
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
    paymentScanActive  = false;

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
function printReceipt() { window.print(); }

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
    <p>&copy; 2024 Kape Inato — Panda Tea, J.A. Clarins Street, Dao, Tagbilaran, Bohol.</p>
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