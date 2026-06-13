<?php
// Description: Admin login page for the Kape Inato cafe management system.

require_once __DIR__ . '/helpers.php';

session_start();

// Redirect if already logged in
// Description: Checks if admin is already authenticated.
// Function: Redirects authenticated users to admin dashboard to prevent unnecessary login.
// Technical: Verifies session variable 'admin_logged_in'; uses header() for redirection.
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: admin.php");
    exit();
}

// Database connection
// Description: Includes the database configuration file.
// Function: Establishes database connection for user credential verification.
// Technical: Requires db.php to be in same directory; $conn becomes available globally.
include 'db.php';

// Error message variable
// Description: Stores error messages for display to user.
// Function: Holds validation or authentication failure messages.
// Technical: Initialized as empty string; displayed in HTML alert if set.
$error = '';

// Handle login form submission
// Description: Processes POST requests from the login form.
// Function: Validates input, checks credentials against database, sets session on success.
// Technical: Checks REQUEST_METHOD to ensure POST; processes username/password from form data.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Input validation
    // Description: Basic validation for required login fields.
    // Function: Ensures both username and password are provided.
    // Technical: Uses trim() to remove whitespace; empty() checks for non-empty strings.
    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        // Database query for user
        // Description: Retrieves user credentials from database using prepared statement.
        // Function: Fetches user record by username for authentication.
        // Technical: Prepared statement prevents SQL injection; bind_param for parameter binding.
        $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        // User exists check
        // Description: Verifies if the username exists in the database.
        // Function: Processes authentication if user found, handles invalid username gracefully.
        // Technical: num_rows check; fetch_assoc() retrieves user data as associative array.
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // Password verification — Fix #5: MD5 fallback removed
            // Only bcrypt via password_verify() is accepted.
            // MD5 is cryptographically broken and was removed for security.
            $valid = password_verify($password, $user['password']);

            // Successful authentication
            // Description: Sets up user session and redirects to admin dashboard.
            // Function: Establishes authenticated session state and navigates to protected area.
            // Technical: Sets multiple session variables; header() redirection; exit() prevents further execution.
            if ($valid) {
                // Fix LOW-4: Regenerate session ID on login to prevent session fixation attacks
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_id'] = $user['id'];
                header("Location: admin.php");
                exit();
            } else {
                $error = "Invalid credentials. Please try again.";
            }
        } else {
            // Timing attack prevention
            // Description: Performs dummy password verification to prevent timing attacks.
            // Function: Ensures consistent response time regardless of username validity.
            // Technical: password_verify() with dummy hash; same error message as invalid password.
            password_verify($password, '$2y$10$dummy_hash_to_prevent_timing_attacks_xxxxxxxxxx');
            $error = "Invalid credentials. Please try again.";
        }
        $stmt->close();
    }
}
?>
<!-- HTML Output Section -->
<!-- Description: Login page HTML structure with form and styling.
Function: Displays login interface, handles form submission, shows error messages.
Technical: HTML5 with CSS classes, form validation, PHP integration for dynamic content. -->
<!DOCTYPE html>
<html lang="en">
<!-- Description: HTML document head with metadata and styles.
Function: Sets page title, charset, viewport, favicon, and links to stylesheet.
Technical: Meta tags for mobile responsiveness and character encoding. -->

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Kape Inato</title>
    <link rel="icon" type="image/png" href="coffee.png">
    <link rel="stylesheet" href="style.css">
</head>
<!-- Description: HTML body with login page layout.
Function: Contains the login form wrapper and background styling.
Technical: Uses CSS class 'login-page' for specific styling. -->

<body class="login-page">

    <!-- Description: Login form container with branding and form elements.
    Function: Centers and styles the login interface.
    Technical: Flexbox or CSS Grid layout for centering; contains logo, title, form, and links. -->
    <div class="form-wrapper">
        <div class="login-logo">Kape Inato</div>
        <h2 class="form-title">Admin Login</h2>
        <p class="form-subtitle">Enter your credentials to access the dashboard.</p>

        <!-- Description: Error message display.
        Function: Shows authentication errors to the user.
        Technical: PHP conditional rendering; htmlspecialchars() prevents XSS; CSS alert styling. -->
        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Description: Login form with username and password fields.
        Function: Collects user credentials and submits for authentication.
        Technical: POST method for security; autocomplete="off" prevents browser autofill; required attributes for validation. -->
        <form method="POST" action="" autocomplete="off">
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <!-- Description: Username input field.
                Function: Accepts admin username input.
                Technical: Text input with placeholder; required validation; PHP preserves input on error. -->
                <input type="text" id="username" name="username"
                    placeholder="admin" required
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <!-- Description: Password input field.
                Function: Accepts admin password input (masked).
                Technical: Password input type for security; placeholder with dots; required validation. -->
                <input type="password" id="password" name="password"
                    placeholder="••••••••" required>
            </div>
            <!-- Description: Form submission button.
            Function: Triggers form submission for authentication.
            Technical: Submit button with full width styling; positioned with margin. -->
            <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">
                Login to Dashboard
            </button>
        </form>

        <!-- Description: Navigation link back to main site.
        Function: Allows users to return to the public website.
        Technical: Centered text link; styled with theme colors; no text decoration. -->
        <p style="text-align:center; color:var(--text-muted); font-size:0.8rem; margin-top:20px;">
            <a href="index.php" style="color:var(--amber); text-decoration:none;">&larr; Back to site</a>
        </p>
    </div>

</body>

</html>