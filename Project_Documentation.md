# Kape Inato Web System — Project Documentation

**Course:** Web Systems and Technologies / Capstone Project
**Institution:** University of Bohol
**Team Members:** Jazen Gabriel M. Digamon, Kenneth Asas, Joffre Troy P. Arcelo
**Live URL:** kapeinato.infinityfree.me

---

## 1. Project Overview

The Kape Inato Web System is a full-stack café management and online ordering platform developed for Kape Inato, a local café situated at Panda Tea, J.A. Clarins Street, Dao, Tagbilaran, Bohol. The system was conceived to address the operational inefficiencies of a manually managed café — specifically the lack of a digital ordering channel, absence of real-time order visibility for the admin, and the inability for customers to track their orders after placement. By digitizing these processes, the system aims to improve the overall customer experience while giving the café owner complete visibility and control over daily operations from a centralized web-based dashboard.

The platform is divided into two primary interfaces: a customer-facing public portal and a secure administrative dashboard. The public portal allows customers to browse the café's full menu organized by category, add items to a cart, place orders with a specified pickup time, pay via GCash or Maya QR codes, receive an automatic email confirmation with a detailed receipt, and look up their order status at any time using their Order ID and email address. The administrative dashboard, accessible only to authenticated users, provides tools for menu item management, online order monitoring, payment proof review and confirmation, inventory tracking, sales analytics, customer feedback viewing, and action log review — all without requiring any third-party point-of-sale software or subscription service.

The system was built using a lean and accessible technology stack consisting of PHP for server-side logic, MySQLi for database operations, and vanilla JavaScript and CSS for the frontend. It is hosted on InfinityFree and uses PHPMailer integrated with Gmail SMTP for transactional email delivery. Throughout the development cycle, ten professor-recommended improvements were systematically implemented to bring the system up to production-level standards, covering areas such as credential security, payment integration, mobile responsiveness, authentication hardening, real-time notifications, default image handling, customer order lookup, error handling, and database normalization.

---

## 2. Objectives

- Provide customers with a seamless online ordering experience accessible from any device
- Enable the café admin to monitor and manage orders in real time without manual page refreshes
- Implement secure payment collection via GCash and Maya QR with proof submission and admin confirmation
- Ensure the system meets basic web security standards including bcrypt authentication, SQL injection prevention, and production-safe error handling
- Normalize the database structure to support reliable analytics and inventory tracking

---

## 3. System Architecture

The system follows a traditional MVC-inspired structure without a formal framework, using plain PHP files for routing and logic, MySQLi for data access, and HTML/CSS/JS for presentation.

### 3.1 Tech Stack

| Component | Technology |
|---|---|
| Server-side Language | PHP 8.x |
| Database | MySQL / MariaDB (InnoDB) |
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Email Service | PHPMailer 6.x + Gmail SMTP |
| QR Generation | QRCode.js |
| QR Scanning | Html5QrcodeScanner |
| Hosting | InfinityFree |
| Database Management | phpMyAdmin |

### 3.2 Database Tables

| Table | Purpose |
|---|---|
| `users` | Admin credentials |
| `menu_items` | Café menu with pricing, stock, category, and image |
| `online_orders` | Customer order records with payment tracking |
| `online_order_items` | Normalized line items per order |
| `feedback` | Customer feedback submissions |

---

## 4. Features

### 4.1 Customer-Facing Features

**Menu Portal (`menu.php`)**
Displays all available menu items organized by category — Pizza, Pasta, Drinks, and Appetizers. Items can be filtered by category. Each card shows the item image, name, price, and an Add to Cart button. Items with no custom image automatically display a category-appropriate default image.

**Online Ordering (`order.php`)**
Customers fill in their name, email, phone number, pickup time, and special instructions. They select items from the menu, review their cart, and submit the order. Upon successful submission, a receipt modal appears with the order details and GCash/Maya QR codes for payment. Customers can switch between GCash and Maya tabs and upload a screenshot of their payment proof directly from the receipt modal.

**Email Confirmation**
After placing an order, customers automatically receive a professionally formatted HTML email receipt containing their order number, reference code, order date, pickup time, itemized list with quantities and prices, total amount, payment instructions, and the café's contact number. The email is delivered via PHPMailer using Gmail SMTP.

**Order Lookup**
Customers can retrieve their order status at any time by entering their Order ID and registered email address in the lookup section of `order.php`. The system returns the full order details including current status, payment status, and items ordered.

### 4.2 Administrative Features

**Secure Login (`login.php`)**
Admin authentication uses bcrypt password hashing via PHP's `password_verify()`. MD5 fallback has been removed. Session IDs are regenerated upon login to prevent session fixation attacks. Timing attack prevention is implemented via dummy hash comparison for invalid usernames.

**Dashboard (`admin.php`)**
The main admin interface displays summary statistics — total menu items, trending items, total online orders, and pending orders awaiting action. Quick action buttons provide direct access to common tasks. The sidebar provides navigation to all admin sections.

**Real-Time Order Notifications**
The admin dashboard polls `notifications.php` every 20 seconds in the background. When new orders arrive or payment proofs are submitted, the pending order badge on the sidebar updates automatically without requiring a page refresh. Toast notifications appear in the bottom-right corner of the screen.

**Menu Item Management**
Admins can add, edit, and delete menu items. Each item has a name, description, category, price, stock quantity, availability toggle, special/trending flag, and an optional image upload. Items without a custom image display a category-appropriate default.

**Online Orders Management**
All customer orders are displayed grouped by customer. Each order shows the items ordered, total, pickup time, date, current status, and payment status. Admins can confirm or reject orders and confirm payment after reviewing uploaded proof screenshots.

**Inventory Management**
Stock quantities are tracked per menu item. When an order is placed, stock is automatically deducted. Items with zero stock are hidden from the public ordering page.

**Sales Analytics**
Graphical revenue and order trend data is available in the dashboard for monitoring café performance over time.

**Action Logs**
All significant admin actions — order confirmations, payment confirmations, item changes — are recorded to `action_logs.txt` for audit purposes.

**Factory Reset**
A protected reset function allows the admin to wipe all test orders, feedback, and logs while preserving the menu item data.

---

## 5. Security Implementation

| Security Measure | Implementation |
|---|---|
| Password Hashing | bcrypt via `password_hash()` / `password_verify()` |
| SQL Injection Prevention | Prepared statements throughout all queries |
| Credential Protection | `config.php` blocked by `.htaccess`, not exposed in code |
| Error Handling | `display_errors` disabled; errors logged server-side only |
| Session Security | `session_regenerate_id(true)` on login |
| Timing Attack Prevention | Dummy hash comparison for invalid usernames |
| Directory Listing | Disabled via `.htaccess` |
| Direct File Access | `db.php` and `config.php` blocked via `.htaccess` |

---

## 6. Ten Professor-Recommended Fixes

| Fix | Description | Status |
|---|---|---|
| Fix #1 | Remove exposed DB and SMTP credentials from source code; isolate in `config.php` | ✅ Complete |
| Fix #2 | Integrate GCash and Maya QR payment with customer proof upload and admin confirmation | ✅ Complete |
| Fix #3 | Implement full mobile responsiveness — hamburger menu, collapsible sidebar, table scrolling | ✅ Complete |
| Fix #4 | Fix PHPMailer to use config constants; upgrade to professional HTML email receipt | ✅ Complete |
| Fix #5 | Remove MD5 password fallback; enforce bcrypt-only authentication | ✅ Complete |
| Fix #6 | Add real-time order notifications via JavaScript polling; auto-updating badge counter | ✅ Complete |
| Fix #7 | Map category-specific default images to replace broken image icons | ✅ Complete |
| Fix #8 | Build customer order lookup by Order ID and email on the ordering page | ✅ Complete |
| Fix #9 | Disable `display_errors` in production; route all errors to server log | ✅ Complete |
| Fix #10 | Normalize order items to `online_order_items` table; convert all tables to InnoDB; add transactional order processing | ✅ Complete |

---

## 7. Deployment

The system is deployed on **InfinityFree** free hosting at `kapeinato.infinityfree.me`. Files are managed via the InfinityFree File Manager. The database is hosted on `sql211.infinityfree.com` and managed through phpMyAdmin. Email delivery is handled by Gmail SMTP using an App Password configured in `config.php`.

---

## 8. Limitations

- The site runs on HTTP (not HTTPS) due to InfinityFree's SSL limitations on free subdomains. This does not affect functionality for a project demonstration environment.
- Toast notifications require the admin dashboard tab to remain open and unrefreshed for the polling interval to fire correctly.
- InfinityFree has occasional file-serving inconsistencies with large PNG files; QR images were converted to JPEG to resolve this.
- The free hosting tier imposes database connection limits that may affect performance under high concurrent load.
