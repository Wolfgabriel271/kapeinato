# ☕ Kape Inato Web System

A full-stack café management and online ordering web application built for **Kape Inato** — a local café located at Panda Tea, J.A. Clarins Street, Dao, Tagbilaran, Bohol. This system was developed as a final project for the University of Bohol, designed to digitize and streamline the café's order management, customer experience, and administrative operations.

The system provides customers with a public-facing menu portal and an online ordering page where they can browse available items, place orders, pay via GCash or Maya QR codes, and receive automatic email confirmations. On the administrative side, a secure dashboard allows the café owner to manage menu items, monitor incoming online orders in real time, track payment proof submissions, confirm payments, view sales analytics, manage inventory, and perform system resets — all from a single interface without requiring any third-party POS software.

Built entirely with PHP, MySQLi, vanilla JavaScript, and CSS, the system is hosted on InfinityFree and uses PHPMailer for SMTP email delivery via Gmail. Security measures include bcrypt password hashing, prepared statements throughout to prevent SQL injection, credential isolation via a protected config file, and production-safe error handling. Ten professor-recommended improvements were implemented across the project lifecycle covering payment integration, mobile responsiveness, email confirmation, authentication hardening, real-time notifications, default image fallbacks, customer order lookup, error handling, and full database normalization to InnoDB with transactional order processing.

---

## 🚀 Features

- 📋 **Public Menu Portal** — Browse items by category with search and filtering
- 🛒 **Online Ordering** — Cart-based ordering with pickup time selection
- 💳 **GCash & Maya QR Payment** — Customers scan QR and upload proof of payment
- 📧 **Email Confirmation** — Automatic HTML receipt sent via PHPMailer/Gmail SMTP
- 🔔 **Real-Time Notifications** — Admin badge updates automatically without page refresh
- 📱 **Mobile Responsive** — Hamburger menu, collapsible sidebar, scrollable tables
- 🔍 **Order Lookup** — Customers can check order status by Order ID + email
- 🖼️ **Category Default Images** — Automatic fallback images per menu category
- 🔐 **Secure Admin Login** — bcrypt only, session regeneration, no MD5 fallback
- 📊 **Sales Analytics** — Revenue and order trends visible in the dashboard
- 📦 **Inventory Management** — Stock tracking per menu item
- 🗂️ **Action Logs** — Admin activity logged to file
- 🔄 **Factory Reset** — Wipe test data with one click

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.x |
| Database | MySQL / MariaDB (InnoDB) |
| Frontend | Vanilla JS, CSS3 |
| Email | PHPMailer + Gmail SMTP |
| QR Codes | QRCode.js, Html5QrcodeScanner |
| Hosting | InfinityFree |
| DB Admin | phpMyAdmin |

---

## 👨‍💻 Team

| Name | Role |
|---|---|
| Jazen Gabriel M. Digamon | Lead Developer |
| Kenneth Asas | Developer |
| Joffre Troy P. Arcelo | Developer |

**University of Bohol — Final Project**

---

## 📁 Project Structure

```
htdocs/
├── index.php          # Landing page
├── menu.php           # Public menu portal
├── order.php          # Online ordering + order lookup
├── login.php          # Admin login
├── admin.php          # Admin dashboard
├── notifications.php  # Real-time polling endpoint
├── reset.php          # Factory reset (admin only)
├── db.php             # Database connection
├── config.php         # Credentials (protected by .htaccess)
├── helpers.php        # Shared utility functions
├── style.css          # Global styles
├── script.js          # QR generation + scanning logic
├── setup.sql          # Initial database schema
├── migration.sql      # Incremental migrations
├── .htaccess          # Security rules
└── uploads/           # Payment proofs + menu images
```

---

## ⚙️ Setup

1. Clone the repository into your `htdocs` folder
2. Create the database and run `setup.sql` followed by `migration.sql` in phpMyAdmin
3. Update `config.php` with your database credentials and Gmail App Password
4. Upload all files to your hosting provider
5. Visit `yoursite.com` to access the public site
6. Visit `yoursite.com/login.php` to access the admin dashboard

---

## 🔒 Security Notes

- `config.php` is blocked from direct browser access via `.htaccess`
- All database queries use prepared statements
- Passwords are hashed with bcrypt (`password_hash` / `password_verify`)
- `display_errors` is disabled in production
- Session ID is regenerated on login to prevent session fixation
