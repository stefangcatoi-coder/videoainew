<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/google_tts.php';

$user_id = $_SESSION['user_id'];
$video_id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$video = $stmt->fetch();

if (!$video || $video['status'] !== 'draft') {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$video_type = $video['video_type'] ?? 'short';
$num_images = ($video_type === 'short') ? 3 : 5;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['produce'])) {
    $new_title = $_POST['title'] ?? $video['title'];
    $new_script = $_POST['script'] ?? $video['script'];
    $new_description = $_POST['description'] ?? $video['description'];
    $new_tags = $_POST['tags'] ?? $video['tags'];

    try {
        $pdo->beginTransaction();

        $stmt_user = $pdo->prepare("SELECT monthly_limit, videos_used FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_data = $stmt_user->fetch();

        if ($user_data['videos_used'] >= $user_data['monthly_limit']) {
            throw new Exception("Limită atinsă.");
        }

        $stmt_update = $pdo->prepare("UPDATE videos SET title = ?, script = ?, description = ?, tags = ? WHERE id = ?");
        $stmt_update->execute([$new_title, $new_script, $new_description, $new_tags, $video_id]);

        // GOOGLE TTS with Chunking
        $clean_script = strip_tags($new_script);
        $clean_script = str_replace(['*', '#', '`'], '', $clean_script);

        $chunks = [];
        if (strlen($clean_script) > 4000) {
            $words = explode(' ', $clean_script);
            $current = '';
            foreach ($words as $w) {
                if (strlen($current . $w) < 4000) { $current .= $w . ' '; }
                else { $chunks[] = trim($current); $current = $w . ' '; }
            }
            if ($current) $chunks[] = trim($current);
        } else {
            $chunks[] = $clean_script;
        }

        $voice = getGoogleVoice($video['language'] ?? 'ro');
        $audio_data = '';

        foreach ($chunks as $chunk) {
            $payload = [
                "input" => ["text" => $chunk],
                "voice" => ["languageCode" => $voice['languageCode'], "name" => $voice['name']],
                "audioConfig" => ["audioEncoding" => "MP3"]
            ];

            $ch = curl_init(GOOGLE_TTS_URL . "?key=" . GOOGLE_TTS_API_KEY);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $resData = json_decode($response, true);
            curl_close($ch);

            if (!empty($resData['audioContent'])) {
                $audio_data .= base64_decode($resData['audioContent']);
            } else {
                file_put_contents(__DIR__ . '/../storage/logs/tts_error.log', "TTS Error for video $video_id: " . $response . "\n", FILE_APPEND);
                throw new Exception("Google TTS failed.");
            }
        }

        $filename = "voiceover_" . $video_id . "_" . time() . ".mp3";
        if (!is_dir(__DIR__ . "/uploads/audio/")) mkdir(__DIR__ . "/uploads/audio/", 0775, true);
        file_put_contents(__DIR__ . "/uploads/audio/" . $filename, $audio_data);
        $relative_audio_path = "uploads/audio/" . $filename;

        $stmt_final = $pdo->prepare("UPDATE videos SET status = 'ready_for_render', voiceover_path = ? WHERE id = ?");
        $stmt_final->execute([$relative_audio_path, $video_id]);
        $pdo->prepare("UPDATE users SET videos_used = videos_used + 1 WHERE id = ?")->execute([$user_id]);
        $pdo->commit();

        header("Location: render.php?id=" . $video_id);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Studio Creație - <?php echo strtoupper($video_type); ?> (<?php echo strtoupper($video['language']); ?>)</title>
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: sans-serif; display: flex; margin: 0; }
        .main-content { margin-left: 250px; padding: 2rem; width: 100%; }
        .studio-card { background-color: #1e1e1e; padding: 2rem; border-radius: 16px; max-width: 900px; margin: 0 auto; }
        .form-group { margin-bottom: 1.5rem; }
        label { display: block; margin-bottom: 0.5rem; color: #bb86fc; font-weight: bold; font-size: 0.8rem; }
        input, textarea { width: 100%; padding: 0.8rem; border-radius: 8px; border: 1px solid #333; background: #2c2c2c; color: #fff; box-sizing: border-box; }
        .images-grid { display: grid; grid-template-columns: repeat(<?php echo $num_images; ?>, 1fr); gap: 1rem; margin-top: 1rem; }
        .image-card { aspect-ratio: <?php echo ($video_type === 'short') ? '9/16' : '16/9'; ?>; background: #222; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; border: 1px solid #333; }
        .image-card img { width: 100%; height: 100%; object-fit: cover; }
        .image-actions { display: flex; gap: 0.3rem; margin-top: 0.5rem; }
        .btn-small { flex:1; padding: 0.4rem; border: none; border-radius: 4px; font-size: 0.7rem; font-weight: bold; cursor: pointer; }
        .btn-produce { width: 100%; padding: 1rem; border: none; border-radius: 12px; background: #4caf50; color: #121212; font-weight: bold; cursor: pointer; margin-top: 2rem; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: #1e1e1e; padding: 2rem; border-radius: 16px; width: 80%; max-width: 800px; }
        .stock-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; max-height: 50vh; overflow-y: auto; }
        .stock-item img { width: 100%; height: 150px; object-fit: cover; cursor: pointer; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../views/header.php'; ?>
    <div class="main-content">
        <h1>Studio Creație Video - <?php echo strtoupper($video_type); ?> (<?php echo strtoupper($video['language']); ?>)</h1>
        <form method="POST">
            <div class="studio-card">
                <div class="form-group"><label>TITLU VIDEO</label><input type="text" name="title" value="<?php echo htmlspecialchars($video['title']); ?>"></div>
                <div class="form-group"><label>SCRIPT (VOCE AI)</label><textarea name="script" rows="6"><?php echo htmlspecialchars($video['script']); ?></textarea></div>
                <div class="form-group"><label>DESCRIERE SEO</label><textarea name="description" rows="4"><?php echo htmlspecialchars($video['description']); ?></textarea></div>
                <div class="form-group"><label>ETICHETE</label><input type="text" name="tags" value="<?php echo htmlspecialchars($video['tags']); ?>"></div>
                <label>IMAGINI SELECTATE (STOCK)</label>
                <div class="images-grid">
                    <?php for($i=1; $i<=$num_images; $i++): ?>
                    <div class="image-container">
                        <div class="image-card" id="card-<?php echo $i; ?>"><img src="<?php echo htmlspecialchars($video['image'.$i]); ?>"></div>
                        <div class="image-actions">
                            <button type="button" class="btn-small" style="background:#bb86fc" onclick="openStockModal(<?php echo $i; ?>)">🔄 Schimbă</button>
                            <button type="button" class="btn-small" style="background:#03dac6" onclick="triggerUpload(<?php echo $i; ?>)">📤 Upload</button>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                <input type="file" id="fileInput" style="display:none" onchange="handleFileUpload(event)">
                <button type="submit" name="produce" class="btn-produce">GENEREAZĂ VIDEO FINAL</button>
            </div>
        </form>
    </div>

    <div id="stockModal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between"><h2>Selectează Imagine</h2><span onclick="closeModal()" style="cursor:pointer; font-size:2rem">&times;</span></div>
            <div style="display:flex; gap:1rem; margin-bottom:1rem">
                <input type="text" id="stockSearch" placeholder="Caută...">
                <button type="button" onclick="searchStock()">Caută</button>
            </div>
            <div id="stockGrid" class="stock-grid"></div>
        </div>
    </div>

    <script>
    let currentSlot = 1;
    const videoId = <?php echo (int)$video_id; ?>;
    const orientation = "<?php echo ($video_type === 'short') ? 'portrait' : 'landscape'; ?>";

    function openStockModal(slot) {
        currentSlot = slot;
        document.getElementById('stockModal').style.display = 'flex';
        searchStock();
    }
    function closeModal() { document.getElementById('stockModal').style.display = 'none'; }
    async function searchStock() {
        const query = document.getElementById('stockSearch').value;
        const res = await fetch(`fetch_stock_images.php?query=${encodeURIComponent(query)}&orientation=${orientation}`);
        const data = await res.json();
        const grid = document.getElementById('stockGrid');
        grid.innerHTML = '';
        data.images.forEach(img => {
            const el = document.createElement('img'); el.src = img.thumb;
            el.onclick = () => selectStockImage(img.url);
            grid.appendChild(el);
        });
    }
    async function selectStockImage(url) {
        closeModal();
        const formData = new FormData(); formData.append('video_id', videoId); formData.append('index', currentSlot); formData.append('url', url);
        const res = await fetch('save_selected_image.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) document.querySelector(`#card-${currentSlot} img`).src = data.path;
    }
    function triggerUpload(slot) { currentSlot = slot; document.getElementById('fileInput').click(); }
    async function handleFileUpload(event) {
        const file = event.target.files[0]; if (!file) return;
        const formData = new FormData(); formData.append('video_id', videoId); formData.append('index', currentSlot); formData.append('image', file);
        const res = await fetch('upload_custom_image.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) document.querySelector(`#card-${currentSlot} img`).src = data.path;
    }
    </script>
</body>
</html>
