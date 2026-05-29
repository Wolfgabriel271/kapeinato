<?php
// Description: Database configuration file for the Kape Inato cafe management system.
// Function: Establishes a secure connection to the MySQL database and handles connection errors.
// Technical: Uses MySQLi extension for database interactions, includes charset setting for UTF-8 support.

// Database connection parameters
// Description: Defines the database server details for connection.
// Function: Specifies host, username, password, and database name.
// Technical: Uses localhost for local development; credentials should be secured in production.
$host = "sql211.infinityfree.com";
$user = "if0_42007209";
$pass = "wtfrjXL60z";
$dbname = "if0_42007209_kapeinato_db";

// Establish database connection
// Description: Creates a new MySQLi connection object using the defined parameters.
// Function: Initializes the database link for executing queries throughout the application.
// Technical: MySQLi constructor takes host, user, password, database; returns connection object or false on failure.
$conn = new mysqli($host, $user, $pass, $dbname);

// Connection error handling
// Description: Checks if the database connection was successful.
// Function: Terminates script execution with JSON error message if connection fails.
// Technical: Uses connect_error property; json_encode for API-friendly error response; die() stops execution.
if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

// Set character set
// Description: Configures the database connection to use UTF-8 encoding.
// Function: Ensures proper handling of international characters and emojis in data.
// Technical: set_charset() method sets the character set; utf8mb4 supports full Unicode including emojis.
$conn->set_charset("utf8mb4");
?>