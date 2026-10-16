<?php
require_once __DIR__ . '/connect.php';

// Require authentication
require_auth();

// Ensure uploads directory exists
$uploadsDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Invalid request method'], 405);
}

$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';

if ($title === '' || $subject === '') {
    respond_json(['error' => 'Title and subject are required'], 400);
}

if (!isset($_FILES['file'])) {
    respond_json(['error' => 'Valid file is required'], 400);
}

$file = $_FILES['file'];
$validatedFile = validate_note_upload($file);
$storedName = $validatedFile['originalName'] . '_' . bin2hex(random_bytes(8)) . '.' . $validatedFile['extension'];
$destPath = $uploadsDir . DIRECTORY_SEPARATOR . $storedName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    respond_json(['error' => 'Failed to save file'], 500);
}

try {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO notes (title, description, subject, filename, user_id) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt || !$stmt->bind_param('ssssi', $title, $description, $subject, $storedName, $user_id) || !$stmt->execute()) {
        throw new RuntimeException($conn->error ?: 'Note insert failed.');
    }
    $id = $stmt->insert_id;
    respond_json(['message' => 'Uploaded successfully', 'id' => $id, 'filename' => $storedName]);
} catch (Throwable $e) {
    if (is_file($destPath) && !unlink($destPath)) {
        error_log('Failed to remove unrecorded upload: ' . $destPath);
    }
    error_log('Upload database error: ' . $e->getMessage());
    respond_json(['error' => 'Upload failed. Please try again later.'], 500);
}

?>

