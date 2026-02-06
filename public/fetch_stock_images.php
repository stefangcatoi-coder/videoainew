<?php
session_start();
require_once __DIR__ . '/../config/images_api.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$query = $_GET['query'] ?? 'nature';
$orientation = $_GET['orientation'] ?? 'portrait';

function fetchUnsplash($q, $count, $orient) {
    $key = trim(UNSPLASH_ACCESS_KEY);
    $url = "https://api.unsplash.com/search/photos?query=" . urlencode($q) . "&orientation=$orient&per_page=" . $count;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID $key"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $data = json_decode($res, true);
    curl_close($ch);
    $results = [];
    foreach ($data['results'] ?? [] as $item) {
        $results[] = ['url' => $item['urls']['regular'], 'thumb' => $item['urls']['small'], 'source' => 'Unsplash'];
    }
    return $results;
}

function fetchPexels($q, $count, $orient) {
    $key = trim(PEXELS_API_KEY);
    $url = "https://api.pexels.com/v1/search?query=" . urlencode($q) . "&orientation=$orient&per_page=" . $count;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $key"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $data = json_decode($res, true);
    curl_close($ch);
    $results = [];
    foreach ($data['photos'] ?? [] as $item) {
        $results[] = ['url' => $item['src']['large2x'], 'thumb' => $item['src']['small'], 'source' => 'Pexels'];
    }
    return $results;
}

$images = array_merge(fetchUnsplash($query, 5, $orientation), fetchPexels($query, 5, $orientation));
echo json_encode(['success' => true, 'images' => $images]);
