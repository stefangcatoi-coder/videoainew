<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(600);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../config/images_api.php';

$user_id = $_SESSION['user_id'];
$error = '';

function getAutoImage($keyword, $index, $orientation = 'portrait') {
    $foundUrl = '';
    $localPath = '';
    $unsplashKey = trim(UNSPLASH_ACCESS_KEY);
    $url = "https://api.unsplash.com/search/photos?query=" . urlencode($keyword) . "&orientation=$orientation&per_page=1";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID $unsplashKey"]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200) {
        $data = json_decode($res, true);
        if (!empty($data['results'][0]['urls']['regular'])) $foundUrl = $data['results'][0]['urls']['regular'];
    }
    if (!$foundUrl) {
        $pexelsKey = trim(PEXELS_API_KEY);
        $url = "https://api.pexels.com/v1/search?query=" . urlencode($keyword) . "&orientation=$orientation&per_page=1";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $pexelsKey"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $data = json_decode($res, true);
            if (!empty($data['photos'][0]['src']['large2x'])) $foundUrl = $data['photos'][0]['src']['large2x'];
        }
        curl_close($ch);
    }
    if ($foundUrl) {
        $imgData = @file_get_contents($foundUrl);
        if ($imgData) {
            $filename = "stock_" . time() . "_" . $index . ".jpg";
            $relative_path = "uploads/images/" . $filename;
            if (!is_dir(__DIR__ . "/uploads/images/")) mkdir(__DIR__ . "/uploads/images/", 0777, true);
            file_put_contents(__DIR__ . "/" . $relative_path, $imgData);
            $localPath = $relative_path;
        }
    }
    return $localPath ?: "https://via.placeholder.com/1080x1920.png/222222/FFFFFF?text=Imagine+Indisponibila";
}

$stmt = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$can_generate = ($user['videos_used'] < $user['monthly_limit']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_generate) {
    $idea = trim($_POST['idea'] ?? '');
    $video_type = $_POST['video_type'] ?? 'short';
    $language = $_POST['language'] ?? 'ro';
    if (!empty($idea)) {
        try {
            $lang_names = ['ro'=>'română', 'en'=>'engleză', 'it'=>'italiană', 'es'=>'spaniolă', 'fr'=>'franceză', 'de'=>'germană'];
            $target_lang = $lang_names[$language] ?? 'română';
            $num_images = ($video_type === 'short') ? 3 : 5;
            $orientation = ($video_type === 'short') ? 'portrait' : 'landscape';
            $prompt = "Generează un plan video profesional pentru ideea: \"$idea\". Limba: $target_lang. Tip video: " . ($video_type === 'short' ? "Short (60 sec)" : "Long (3-5 min)"). ". Răspunsul trebuie să fie JSON pur: {\"title\": \"Titlu\", \"script\": \"Textul scriptului\", \"description\": \"Descriere SEO\", \"tags\": \"tag1, tag2\", \"keywords\": [\"keyword1\", ...]}";
            $url = GEMINI_API_URL . "?key=" . trim(GEMINI_API_KEY);
            $payload = ["contents" => [["parts" => [["text" => $prompt]]]]];
            $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload)); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch); $result = json_decode($response, true); curl_close($ch);
            $aiText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (preg_match('/\{.*\}/s', $aiText, $matches)) $aiText = $matches[0];
            $aiData = json_decode($aiText, true);
            if (!$aiData) throw new Exception("AI format error.");
            $keywords = $aiData['keywords'] ?? array_fill(0, $num_images, $idea);
            $img_paths = [];
            for($i=0; $i<$num_images; $i++) $img_paths[$i] = getAutoImage($keywords[$i] ?? $idea, $i+1, $orientation);
            $pdo->beginTransaction();
            $sql = "INSERT INTO videos (user_id, title, status, script, description, tags, video_type, language";
            $placeholders = "?, ?, 'draft', ?, ?, ?, ?, ?";
            $params = [$user_id, $aiData['title'], $aiData['script'], $aiData['description'], $aiData['tags'], $video_type, $language];
            for($i=1; $i<=$num_images; $i++) { $sql .= ", image$i, prompt$i"; $placeholders .= ", ?, ?"; $params[] = $img_paths[$i-1]; $params[] = $keywords[$i-1]; }
            $sql .= ") VALUES ($placeholders)";
            $stmt = $pdo->prepare($sql); $stmt->execute($params); $video_id = $pdo->lastInsertId(); $pdo->commit();
            header("Location: edit_draft.php?id=" . $video_id); exit;
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = $e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generează Video - Video AI</title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; display: flex; }
        .main-content { margin-left: 250px; padding: 2rem; width: 100%; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 600px; }
        h1 { color: #ffffff; margin-bottom: 2rem; }
        .card { background-color: #1e1e1e; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5); }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; color: #bb86fc; }
        input, select { width: 100%; padding: 0.75rem; border-radius: 4px; border: 1px solid #333; background-color: #2c2c2c; color: #fff; box-sizing: border-box; font-size: 1rem; }
        input:focus, select:focus { outline: none; border-color: #bb86fc; }
        .btn-generate { width: 100%; padding: 1rem; border: none; border-radius: 4px; background-color: #03dac6; color: #121212; font-weight: bold; font-size: 1.1rem; cursor: pointer; transition: background-color 0.3s; }
        .btn-generate:hover { background-color: #01b0a1; }
        .error { color: #cf6679; background-color: rgba(207, 102, 121, 0.1); padding: 1rem; border-radius: 4px; margin-bottom: 1.5rem; text-align: center; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div class="main-content">
        <div class="container">
            <h1>Generează Video Nou</h1>
            <?php if (!$can_generate): ?>
                <div class="error">Limită atinsă! Ai folosit <?php echo $user['videos_used']; ?> din <?php echo $user['monthly_limit']; ?> video-uri.</div>
            <?php else: ?>
                <div class="card">
                    <?php if ($error): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="idea">Ideea Video-ului</label>
                            <input type="text" name="idea" id="idea" placeholder="Ex: Cum să gătești paste" maxlength="500" required>
                        </div>
                        <div class="form-group">
                            <label>Format Video</label>
                            <select name="video_type">
                                <option value="short">Short (Portrait 9:16)</option>
                                <option value="long">Long (Landscape 16:9)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Limba</label>
                            <select name="language">
                                <option value="ro">Română</option>
                                <option value="en">English</option>
                                <option value="it">Italiano</option>
                                <option value="es">Español</option>
                                <option value="fr">Français</option>
                                <option value="de">Deutsch</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-generate">Generează Plan și Imagini AI</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
