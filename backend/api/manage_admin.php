<?php
// Admin Management Script
// Requires an active administrator session.
// This allows you to:
// 1. Create new admin accounts
// 2. Promote existing users to admin (adds to admins table)
// 3. Change admin password
// 4. Delete admin accounts

require_once __DIR__ . '/connect.php';

if (($_SESSION['user_type'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/html; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

$action = $_POST['action'] ?? ($_GET['action'] ?? 'list');
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$name = $_POST['name'] ?? '';

if ($action !== 'list') {
    if ($method !== 'POST') {
        http_response_code(405);
        exit('POST required');
    }
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['admin_csrf_token'], $submittedToken)) {
        http_response_code(403);
        exit('Invalid request token');
    }
}

echo "<!DOCTYPE html><html><head><title>Admin Management</title>";
echo "<style>
    body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
    .success { background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
    .error { background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
    .info { background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background: #f8f9fa; }
    .form-group { margin: 15px 0; }
    label { display: block; margin-bottom: 5px; font-weight: bold; }
    input { width: 300px; padding: 8px; }
    button { padding: 10px 20px; margin: 5px; cursor: pointer; }
</style></head><body>";

try {
    switch ($action) {
        case 'create':
            // Create new admin account
            if (!$email || !$password || !$name) {
                echo "<div class='error'>All fields are required!</div>";
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                echo "<div class='error'>Enter a valid email and a password of at least 12 characters.</div>";
                break;
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (name, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $name, $email, $hashedPassword);
            
            if ($stmt->execute()) {
                echo "<div class='success'>✓ Admin account created successfully!</div>";
            } else {
                error_log('Admin creation failed: ' . $conn->error);
                echo "<div class='error'>Could not create the admin account. Verify that the email is unused.</div>";
            }
            break;
            
        case 'promote_user':
            // Promote existing user to admin (copies to admins table)
            if (!$email || !$password) {
                echo "<div class='error'>Email and password are required!</div>";
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo "<div class='error'>Enter a valid email address.</div>";
                break;
            }
            
            // Check if user exists
            $stmt = $conn->prepare("SELECT id, name, email, password FROM users WHERE email = ?");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if (!$user) {
                echo "<div class='error'>✗ User not found with email: " . htmlspecialchars($email) . "</div>";
                break;
            }
            
            // Verify password
            if (!password_verify($password, $user['password'])) {
                echo "<div class='error'>✗ Invalid password for user!</div>";
                break;
            }
            
            // Check if admin already exists
            $checkStmt = $conn->prepare("SELECT id FROM admins WHERE email = ?");
            $checkStmt->bind_param('s', $email);
            $checkStmt->execute();
            $adminExists = $checkStmt->get_result()->num_rows > 0;
            
            if ($adminExists) {
                echo "<div class='error'>✗ Admin account already exists for this email!</div>";
                break;
            }
            
            // Copy user to admins table
            $insertStmt = $conn->prepare("INSERT INTO admins (name, email, password) VALUES (?, ?, ?)");
            $insertStmt->bind_param('sss', $user['name'], $user['email'], $user['password']);
            
            if ($insertStmt->execute()) {
                // Also update role in users table
                $updateStmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE email = ?");
                $updateStmt->bind_param('s', $email);
                $updateStmt->execute();
                
                echo "<div class='success'>✓ User promoted to admin successfully!</div>";
                echo "<div class='info'>User can now login using Admin Login with email: " . htmlspecialchars($email) . "</div>";
            } else {
                error_log('Admin promotion failed: ' . $conn->error);
                echo "<div class='error'>Could not promote this account.</div>";
            }
            break;
            
        case 'change_password':
            // Change admin password
            if (!$email || !$password) {
                echo "<div class='error'>Email and new password are required!</div>";
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                echo "<div class='error'>Enter a valid email and a password of at least 12 characters.</div>";
                break;
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE email = ?");
            $stmt->bind_param('ss', $hashedPassword, $email);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo "<div class='success'>✓ Admin password updated successfully!</div>";
                } else {
                    echo "<div class='error'>✗ Admin not found with email: " . htmlspecialchars($email) . "</div>";
                }
            } else {
                error_log('Admin password update failed: ' . $conn->error);
                echo "<div class='error'>Could not update the admin password.</div>";
            }
            break;
            
        case 'delete':
            // Delete admin account
            if (!$email) {
                echo "<div class='error'>Email is required!</div>";
                break;
            }
            
            $stmt = $conn->prepare("DELETE FROM admins WHERE email = ?");
            $stmt->bind_param('s', $email);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    echo "<div class='success'>✓ Admin account deleted successfully!</div>";
                } else {
                    echo "<div class='error'>✗ Admin not found with email: " . htmlspecialchars($email) . "</div>";
                }
            } else {
                error_log('Admin deletion failed: ' . $conn->error);
                echo "<div class='error'>Could not delete the admin account.</div>";
            }
            break;
    }
    
    // Always show list of admins
    echo "<h2>Current Admin Accounts</h2>";
    $result = $conn->query("SELECT id, name, email, created_at, last_login FROM admins ORDER BY created_at DESC");
    
    if ($result && $result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Created</th><th>Last Login</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['id']) . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "<td>" . ($row['last_login'] ? htmlspecialchars($row['last_login']) : 'Never') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='info'>No admin accounts found.</div>";
    }
    
    // Show action forms
    echo "<hr>";
    echo "<h2>Admin Management Actions</h2>";
    
    // Create new admin
    echo "<div style='border: 1px solid #ddd; padding: 20px; margin: 10px 0;'>";
    echo "<h3>1. Create New Admin</h3>";
    echo "<form method='POST'>";
    echo "<input type='hidden' name='action' value='create'>";
    echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['admin_csrf_token']) . "'>";
    echo "<div class='form-group'>";
    echo "<label>Name:</label>";
    echo "<input type='text' name='name' required>";
    echo "</div>";
    echo "<div class='form-group'>";
    echo "<label>Email:</label>";
    echo "<input type='email' name='email' required>";
    echo "</div>";
    echo "<div class='form-group'>";
    echo "<label>Password:</label>";
    echo "<input type='password' name='password' minlength='12' required>";
    echo "</div>";
    echo "<button type='submit'>Create Admin</button>";
    echo "</form>";
    echo "</div>";
    
    // Promote user to admin
    echo "<div style='border: 1px solid #ddd; padding: 20px; margin: 10px 0;'>";
    echo "<h3>2. Promote Existing User to Admin</h3>";
    echo "<form method='POST'>";
    echo "<input type='hidden' name='action' value='promote_user'>";
    echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['admin_csrf_token']) . "'>";
    echo "<div class='form-group'>";
    echo "<label>User Email (must exist in users table):</label>";
    echo "<input type='email' name='email' required>";
    echo "</div>";
    echo "<div class='form-group'>";
    echo "<label>User's Current Password (for verification):</label>";
    echo "<input type='password' name='password' required>";
    echo "</div>";
    echo "<button type='submit'>Promote to Admin</button>";
    echo "</form>";
    echo "</div>";
    
    // Change password
    echo "<div style='border: 1px solid #ddd; padding: 20px; margin: 10px 0;'>";
    echo "<h3>3. Change Admin Password</h3>";
    echo "<form method='POST'>";
    echo "<input type='hidden' name='action' value='change_password'>";
    echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['admin_csrf_token']) . "'>";
    echo "<div class='form-group'>";
    echo "<label>Admin Email:</label>";
    echo "<input type='email' name='email' required>";
    echo "</div>";
    echo "<div class='form-group'>";
    echo "<label>New Password:</label>";
    echo "<input type='password' name='password' minlength='12' required>";
    echo "</div>";
    echo "<button type='submit'>Change Password</button>";
    echo "</form>";
    echo "</div>";
    
    // Delete admin
    echo "<div style='border: 1px solid #ddd; padding: 20px; margin: 10px 0;'>";
    echo "<h3>4. Delete Admin Account</h3>";
    echo "<form method='POST' onsubmit=\"return confirm('Are you sure you want to delete this admin account?');\">";
    echo "<input type='hidden' name='action' value='delete'>";
    echo "<input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['admin_csrf_token']) . "'>";
    echo "<div class='form-group'>";
    echo "<label>Admin Email:</label>";
    echo "<input type='email' name='email' required>";
    echo "</div>";
    echo "<button type='submit' style='background: #dc3545; color: white;'>Delete Admin</button>";
    echo "</form>";
    echo "</div>";
    
} catch (Exception $e) {
    error_log('Admin management error: ' . $e->getMessage());
    echo "<div class='error'>The requested action could not be completed.</div>";
}

echo "</body></html>";
?>
