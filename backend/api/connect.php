<?php
// DB connection and common headers

// Define helper functions first
function respond_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function sanitize_filename($name) {
    $name = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $name);
    return trim($name, '_');
}

function validate_note_upload($file) {
    $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = $uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'File exceeds the 10 MB limit'
            : 'Valid file is required';
        respond_json(['error' => $message], 400);
    }

    $maxBytes = 10 * 1024 * 1024;
    if (!isset($file['size']) || $file['size'] < 1 || $file['size'] > $maxBytes) {
        respond_json(['error' => 'File must be between 1 byte and 10 MB'], 400);
    }

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'zip', 'rar'];
    if (!in_array($extension, $allowedExtensions, true)) {
        respond_json(['error' => 'Unsupported file type'], 400);
    }

    $originalName = sanitize_filename(pathinfo($file['name'], PATHINFO_FILENAME));
    return [
        'originalName' => $originalName !== '' ? $originalName : 'note',
        'extension' => $extension,
    ];
}

// Helper function to check if user is authenticated
function require_auth() {
    if (!isset($_SESSION['user_id'])) {
        respond_json(['error' => 'Authentication required'], 401);
    }
}

// Helper function to check if user is admin
function is_admin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
}

// Helper function to check admin login
function check_admin_login($conn, $email, $password) {
    $stmt = $conn->prepare("SELECT id, name, email, password FROM admins WHERE email = ?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    
    if ($admin && password_verify($password, $admin['password'])) {
        // Update last login
        $updateStmt = $conn->prepare("UPDATE admins SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->bind_param('i', $admin['id']);
        $updateStmt->execute();
        
        return $admin;
    }
    return false;
}

// Helper function to check if user owns the note or is admin
function can_modify_note($conn, $note_id) {
    require_auth();
    $user_id = $_SESSION['user_id'];
    
    if (is_admin()) {
        return true; // Admin can modify any note
    }
    
    $stmt = $conn->prepare("SELECT user_id FROM notes WHERE id = ?");
    $stmt->bind_param('i', $note_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result) {
        respond_json(['error' => 'Note not found'], 404);
    }
    
    if ($result['user_id'] != $user_id) {
        respond_json(['error' => 'You do not have permission to modify this note'], 403);
    }
    
    return true;
}

header('Content-Type: application/json; charset=utf-8');

$isProduction = getenv('APP_ENV') === 'production';
if ($isProduction) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
$configuredOrigins = getenv('EDUVAULT_ALLOWED_ORIGINS');
$allowedOrigins = $configuredOrigins !== false && trim($configuredOrigins) !== ''
    ? array_map('trim', explode(',', $configuredOrigins))
    : ($isProduction ? [] : [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:3001',
    ]);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// Start session for auth endpoints
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isProduction || $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'CLI') === 'OPTIONS') {
    respond_json(['status' => 'ok'], 200);
}

$DB_HOST = getenv('EDUVAULT_DB_HOST') ?: 'localhost';
$DB_PORT = (int) (getenv('EDUVAULT_DB_PORT') ?: 3306);
$DB_USER = getenv('EDUVAULT_DB_USER') ?: 'root';
$DB_PASS = getenv('EDUVAULT_DB_PASSWORD') ?: '';
$DB_NAME = getenv('EDUVAULT_DB_NAME') ?: 'eduvault';

if ($isProduction && ($DB_USER === 'root' || $DB_PASS === '')) {
    error_log('EduVault production database configuration must use a non-root account and a password.');
    respond_json(['error' => 'Server configuration error'], 500);
}
if (!preg_match('/^[A-Za-z0-9_]+$/D', $DB_NAME) || $DB_PORT < 1 || $DB_PORT > 65535) {
    error_log('EduVault database name or port configuration is invalid.');
    respond_json(['error' => 'Server configuration error'], 500);
}

// Report errors but don't throw exceptions - we'll handle them manually
mysqli_report(MYSQLI_REPORT_OFF);

// Retry connection logic
$maxRetries = 3;
$retryDelay = 1; // seconds
$conn = null;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    try {
        // Try connecting directly to the database first
        $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
        
        if ($conn->connect_error) {
            // If database doesn't exist, connect without it first
            if ($conn->connect_errno == 1049) { // Unknown database
                if ($isProduction) {
                    throw new Exception('Production database does not exist.');
                }
                // Do not call close() on an already failed/closed connection
                $conn = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, '', $DB_PORT);
                if ($conn->connect_error) {
                    throw new Exception('Connection failed: ' . $conn->connect_error);
                }
                
                // Create database
                $createDbSql = "CREATE DATABASE IF NOT EXISTS `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
                if (!$conn->query($createDbSql)) {
                    throw new Exception('Failed to create database: ' . $conn->error);
                }
                $conn->select_db($DB_NAME);
            } else {
                throw new Exception('Connection failed: ' . $conn->connect_error);
            }
        }
        
        // Connection successful, set charset
        $conn->set_charset('utf8mb4');

        if ($isProduction) {
            $requiredColumns = [
                'users' => ['id', 'name', 'email', 'password', 'role', 'institution', 'bio', 'created_at'],
                'admins' => ['id', 'name', 'email', 'password', 'institution', 'bio', 'created_at', 'last_login'],
                'notes' => ['id', 'title', 'description', 'subject', 'filename', 'user_id', 'downloads', 'likes', 'uploaded_at'],
                'saved_notes' => ['user_id', 'note_id', 'saved_at'],
                'admin_saved_notes' => ['admin_id', 'note_id', 'saved_at'],
                'liked_notes' => ['user_id', 'note_id', 'liked_at'],
                'admin_liked_notes' => ['admin_id', 'note_id', 'liked_at'],
            ];

            $tableResult = $conn->query('SHOW TABLES');
            $existingTables = [];
            while ($tableResult && ($row = $tableResult->fetch_row())) {
                $existingTables[] = $row[0];
            }
            foreach ($requiredColumns as $table => $columns) {
                if (!in_array($table, $existingTables, true)) {
                    error_log("EduVault production schema is missing table: {$table}");
                    respond_json(['error' => 'Database schema is incomplete. Apply the database SQL before deployment.'], 500);
                }

                $columnResult = $conn->query("SHOW COLUMNS FROM `{$table}`");
                $existingColumns = [];
                while ($columnResult && ($column = $columnResult->fetch_assoc())) {
                    $existingColumns[] = $column['Field'];
                }
                foreach ($columns as $column) {
                    if (!in_array($column, $existingColumns, true)) {
                        error_log("EduVault production schema is missing column: {$table}.{$column}");
                        respond_json(['error' => 'Database schema is incomplete. Apply the database SQL before deployment.'], 500);
                    }
                }
            }
        }
        
        // Check if users table exists, create if it doesn't
        $usersTableCheck = $conn->query("SHOW TABLES LIKE 'users'");
        if (!$usersTableCheck || $usersTableCheck->num_rows === 0) {
            $createUsersTableSql = "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role ENUM('user', 'admin') DEFAULT 'user' NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_users_email (email),
                INDEX idx_users_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($createUsersTableSql)) {
                throw new Exception('Failed to create users table: ' . $conn->error);
            }
        } else {
            // Check if role column exists, add if not (for existing databases)
            $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
            if (!$columnCheck || $columnCheck->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') DEFAULT 'user' NOT NULL AFTER password");
                $conn->query("ALTER TABLE users ADD INDEX idx_users_role (role)");
            }
        }

        // Check if admins table exists, create if it doesn't
        $adminsTableCheck = $conn->query("SHOW TABLES LIKE 'admins'");
        if (!$adminsTableCheck || $adminsTableCheck->num_rows === 0) {
            $createAdminsTableSql = "CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL,
                INDEX idx_admins_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($createAdminsTableSql)) {
                throw new Exception('Failed to create admins table: ' . $conn->error);
            }
        } else {
            // Add optional profile fields to admins table if missing
            $adminInstitutionCheck = $conn->query("SHOW COLUMNS FROM admins LIKE 'institution'");
            if (!$adminInstitutionCheck || $adminInstitutionCheck->num_rows === 0) {
                $conn->query("ALTER TABLE admins ADD COLUMN institution VARCHAR(255) NULL AFTER email");
            }
            $adminBioCheck = $conn->query("SHOW COLUMNS FROM admins LIKE 'bio'");
            if (!$adminBioCheck || $adminBioCheck->num_rows === 0) {
                $conn->query("ALTER TABLE admins ADD COLUMN bio TEXT NULL AFTER institution");
            }
        }

        // Check if notes table exists, create if it doesn't
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notes'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            $createTableSql = "CREATE TABLE IF NOT EXISTS notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                subject VARCHAR(100) NOT NULL,
                filename VARCHAR(255) NOT NULL,
                user_id INT NOT NULL,
                downloads INT DEFAULT 0,
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notes_subject (subject),
                INDEX idx_notes_title (title),
                INDEX idx_notes_user_id (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            if (!$conn->query($createTableSql)) {
                throw new Exception('Failed to create table: ' . $conn->error);
            }
        } else {
            // Check if user_id column exists, add if not (for existing databases)
            $columnCheck = $conn->query("SHOW COLUMNS FROM notes LIKE 'user_id'");
            if (!$columnCheck || $columnCheck->num_rows === 0) {
                // First, check if there are existing notes - if so, we need to handle them
                $noteCount = $conn->query("SELECT COUNT(*) as count FROM notes")->fetch_assoc()['count'];
                if ($noteCount > 0) {
                    // Set user_id to 0 (system/admin) for existing notes
                    $conn->query("ALTER TABLE notes ADD COLUMN user_id INT DEFAULT 0 NOT NULL AFTER filename");
                } else {
                    $conn->query("ALTER TABLE notes ADD COLUMN user_id INT NOT NULL AFTER filename");
                }
                $conn->query("ALTER TABLE notes ADD INDEX idx_notes_user_id (user_id)");
                // Try to add foreign key (may fail if existing data conflicts)
                @$conn->query("ALTER TABLE notes ADD CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
            }
            // Add likes column to notes table if it doesn't exist
            $likesCheck = $conn->query("SHOW COLUMNS FROM notes LIKE 'likes'");
            if (!$likesCheck || $likesCheck->num_rows === 0) {
                $conn->query("ALTER TABLE notes ADD COLUMN likes INT DEFAULT 0 AFTER downloads");
            }
        }

        // Add profile fields to users table if they don't exist
        $institutionCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'institution'");
        if (!$institutionCheck || $institutionCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN institution VARCHAR(255) NULL AFTER email");
        }
        $bioCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'bio'");
        if (!$bioCheck || $bioCheck->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER institution");
        }

        // Create saved_notes table if it doesn't exist
        $savedNotesCheck = $conn->query("SHOW TABLES LIKE 'saved_notes'");
        if (!$savedNotesCheck || $savedNotesCheck->num_rows === 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS saved_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                note_id INT NOT NULL,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_saved (user_id, note_id),
                INDEX idx_saved_user (user_id),
                INDEX idx_saved_note (note_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Create admin_saved_notes table if it doesn't exist
        $adminSavedNotesCheck = $conn->query("SHOW TABLES LIKE 'admin_saved_notes'");
        if (!$adminSavedNotesCheck || $adminSavedNotesCheck->num_rows === 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS admin_saved_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                note_id INT NOT NULL,
                saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_admin_saved (admin_id, note_id),
                INDEX idx_admin_saved_admin (admin_id),
                INDEX idx_admin_saved_note (note_id),
                FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
                FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Create liked_notes table if it doesn't exist
        $likedNotesCheck = $conn->query("SHOW TABLES LIKE 'liked_notes'");
        if (!$likedNotesCheck || $likedNotesCheck->num_rows === 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS liked_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                note_id INT NOT NULL,
                liked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_liked (user_id, note_id),
                INDEX idx_liked_user (user_id),
                INDEX idx_liked_note (note_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        // Create admin_liked_notes table if it doesn't exist
        $adminLikedNotesCheck = $conn->query("SHOW TABLES LIKE 'admin_liked_notes'");
        if (!$adminLikedNotesCheck || $adminLikedNotesCheck->num_rows === 0) {
            $conn->query("CREATE TABLE IF NOT EXISTS admin_liked_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                note_id INT NOT NULL,
                liked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_admin_liked (admin_id, note_id),
                INDEX idx_admin_liked_admin (admin_id),
                INDEX idx_admin_liked_note (note_id),
                FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
                FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        
        // Success - break out of retry loop
        break;
        
    } catch (Exception $e) {
        if ($attempt < $maxRetries) {
            // Wait before retrying
            sleep($retryDelay);
            continue;
        } else {
            error_log('EduVault database connection failed: ' . $e->getMessage());
            respond_json(['error' => 'Database connection failed'], 500);
        }
    }
}

// Final check - ensure we have a valid connection
if (!$conn || $conn->connect_error) {
    error_log('EduVault database connection failed after ' . $maxRetries . ' attempts.');
    respond_json(['error' => 'Database connection failed'], 500);
}

?>
