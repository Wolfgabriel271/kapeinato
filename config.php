<?php
// ============================================================
//  config.php
//  WHERE TO PUT THIS FILE:
//
//  InfinityFree folder structure:
//    /htdocs/          <- your public web root (DO NOT put this here)
//    /config.php       <- place it HERE, one level ABOVE htdocs
//
//  So if your site files are at:
//    /htdocs/db.php
//    /htdocs/admin.php  ... etc
//
//  This file goes at:
//    /config.php
//
//  db.php will load it with:
//    require_once __DIR__ . '/../config.php';
// ============================================================

// ---- Database Credentials ----
define('DB_HOST', 'sql211.infinityfree.com');
define('DB_USER', 'if0_42007209');
define('DB_PASS', 'wtfrjXL60z');        // <- paste your real password
define('DB_NAME', 'if0_42007209_kapeinato_db');

// ---- SMTP / Email Credentials ----
// Steps to get a Gmail App Password:
//   1. Go to https://myaccount.google.com/apppasswords
//   2. Sign in, select "Mail" as the app
//   3. Copy the 16-character password it generates
//   4. Paste it below — keep spaces, they are part of the key
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER', 'jazdigamon12@gmail.com'); // <- your Gmail address
define('SMTP_PASS',      'bhox lhtf zgeo iiit');   // <- new App Password
define('SMTP_FROM_NAME', 'Kape Inato');
