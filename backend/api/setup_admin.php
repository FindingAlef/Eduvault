<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$adminEmail = getenv('EDUVAULT_ADMIN_EMAIL') ?: '';
$adminPassword = getenv('EDUVAULT_ADMIN_PASSWORD') ?: '';
$adminName = getenv('EDUVAULT_ADMIN_NAME') ?: '';

if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || $adminName === '' || strlen($adminPassword) < 12) {
    fwrite(STDERR, "Set EDUVAULT_ADMIN_EMAIL, EDUVAULT_ADMIN_NAME, and a 12+ character EDUVAULT_ADMIN_PASSWORD.\n");
    exit(1);
}

require_once __DIR__ . '/connect.php';

try {
    $stmt = $conn->prepare('INSERT INTO admins (name, email, password) VALUES (?, ?, ?)');
    $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
    $stmt->bind_param('sss', $adminName, $adminEmail, $hashedPassword);
    if (!$stmt->execute()) {
        throw new RuntimeException('Administrator insert failed.');
    }
    fwrite(STDOUT, "Administrator account created.\n");
} catch (Throwable $e) {
    error_log('Admin setup failed: ' . $e->getMessage());
    fwrite(STDERR, "Could not create the administrator account. Check the database configuration and that the email is unused.\n");
    exit(1);
}
?>
