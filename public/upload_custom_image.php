<?php
session_start();
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$video_id = $_POST['video_id'] ?? 0;
$index = $_POST['index'] ?? 0;
$file = $_FILES['image'] ?? null;

if (!$video_id || !$index || !$file) { echo json_encode(['success' => false, 'error' => 'Missing data']); exit; }

$filename = "custom_" . time() . "_" . $index . ".jpg";
$relative_path = "uploads/images/" . $filename;
if (!is_dir(__DIR__ . "/uploads/images/")) mkdir(__DIR__ . "/uploads/images/", 0777, true);

if (move_uploaded_file($file['tmp_name'], __DIR__ . "/" . $relative_path)) {
    $col = "image" . (int)$index;
    $stmt = $pdo->prepare("UPDATE videos SET $col = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$relative_path, $video_id, $_SESSION['user_id']]);
    echo json_encode(['success' => true, 'path' => $relative_path]);
} else {
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
}
