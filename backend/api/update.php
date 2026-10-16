<?php
require_once __DIR__ . '/connect.php';

// Require authentication
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    respond_json(['error' => 'Invalid request method'], 405);
}

// Support form-data (POST) or JSON (PUT)
$input = $_SERVER['REQUEST_METHOD'] === 'PUT' ? json_decode(file_get_contents('php://input'), true) : $_POST;

$id = isset($input['id']) ? intval($input['id']) : 0;
if ($id <= 0) respond_json(['error' => 'Invalid note id'], 400);

// Check if user can modify this note (ownership or admin)
can_modify_note($conn, $id);

$title = isset($input['title']) ? trim($input['title']) : null;
$description = array_key_exists('description', $input) ? trim((string)$input['description']) : null;
$subject = isset($input['subject']) ? trim($input['subject']) : null;

// Fetch current row
$stmt = $conn->prepare("SELECT * FROM notes WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$note = $stmt->get_result()->fetch_assoc();
if (!$note) respond_json(['error' => 'Not found'], 404);

$newFilename = $note['filename'];
$replacementPath = null;
$oldPath = null;

// If file provided via multipart form-data, replace it
if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadsDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0775, true); }

    $file = $_FILES['file'];
    $validatedFile = validate_note_upload($file);
    $storedName = $validatedFile['originalName'] . '_' . bin2hex(random_bytes(8)) . '.' . $validatedFile['extension'];
    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        respond_json(['error' => 'Failed to save file'], 500);
    }
    $replacementPath = $destPath;
    $oldPath = $uploadsDir . DIRECTORY_SEPARATOR . $note['filename'];
    $newFilename = $storedName;
}

// Build dynamic update
$fields = [];
$types = '';
$values = [];

if ($title !== null) { $fields[] = 'title = ?'; $types .= 's'; $values[] = $title; }
if ($description !== null) { $fields[] = 'description = ?'; $types .= 's'; $values[] = $description; }
if ($subject !== null) { $fields[] = 'subject = ?'; $types .= 's'; $values[] = $subject; }
if ($newFilename !== $note['filename']) { $fields[] = 'filename = ?'; $types .= 's'; $values[] = $newFilename; }

if (empty($fields)) {
    respond_json(['message' => 'No changes'], 200);
}

$sql = 'UPDATE notes SET ' . implode(', ', $fields) . ' WHERE id = ?';
$types .= 'i';
$values[] = $id;

$stmt = $conn->prepare($sql);
if (!$stmt) {
    if ($replacementPath !== null && is_file($replacementPath) && !unlink($replacementPath)) {
        error_log('Failed to remove unrecorded replacement upload: ' . $replacementPath);
    }
    error_log('Note update preparation failed: ' . $conn->error);
    respond_json(['error' => 'Update failed. Please try again later.'], 500);
}
if (!$stmt->bind_param($types, ...$values) || !$stmt->execute()) {
    if ($replacementPath !== null && is_file($replacementPath) && !unlink($replacementPath)) {
        error_log('Failed to remove unrecorded replacement upload: ' . $replacementPath);
    }
    error_log('Note update failed: ' . $stmt->error);
    respond_json(['error' => 'Update failed. Please try again later.'], 500);
}

if ($oldPath !== null && is_file($oldPath) && !unlink($oldPath)) {
    error_log('Failed to remove replaced note file: ' . $oldPath);
}

respond_json(['message' => 'Updated successfully']);

?>



