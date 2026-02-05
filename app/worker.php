<?php
require_once __DIR__ . '/../config/database.php';

// Căutăm video-urile care așteaptă procesarea
$stmt = $pdo->prepare("SELECT * FROM videos WHERE status = 'pending'");
$stmt->execute();
$pendingVideos = $stmt->fetchAll();

foreach ($pendingVideos as $video) {
    echo "Procesez video: " . $video['title'] . "...\n";
    sleep(5); // Simulăm timpul de procesare AI (5 secunde)
    
    // Actualizăm statusul în baza de date
    $update = $pdo->prepare("UPDATE videos SET status = 'done' WHERE id = ?");
    $update->execute([$video['id']]);
    echo "Gata!\n";
}
