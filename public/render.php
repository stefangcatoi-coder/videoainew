<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(1800); // 30 minutes for long videos

// /var/www/video-ai/public/render.php

session_start();

// Security Middleware
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';

$user_id = $_SESSION['user_id'];
$video_id = $_GET['id'] ?? 0;

// 1. Fetch video data and verify
$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND user_id = ?");
$stmt->execute([$video_id, $user_id]);
$video = $stmt->fetch();

if (!$video) {
    header("Location: dashboard.php");
    exit;
}

// Dacă video-ul este deja procesat, redirecționăm direct
if ($video['status'] === 'done') {
    header("Location: dashboard.php?success=Video-ul este gata!");
    exit;
}

if ($video['status'] !== 'ready_for_render') {
    header("Location: dashboard.php");
    exit;
}

$video_type = $video['video_type'] ?? 'short';
$num_images = ($video_type === 'short') ? 3 : 10;
$width = ($video_type === 'short') ? 1080 : 1920;
$height = ($video_type === 'short') ? 1920 : 1080;

?>
<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="UTF-8">
    <title>Producție Video - Video AI</title>
    <meta http-equiv="refresh" content="300;url=dashboard.php">
    <style>
        body { background-color: #121212; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; flex-direction: column; }
        .loader { border: 5px solid #333; border-top: 5px solid #03dac6; border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 20px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        h2 { background: linear-gradient(45deg, #bb86fc, #03dac6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body>
    <div class="loader"></div>
    <h2>Generăm Video-ul Final (<?php echo strtoupper($video_type); ?>)...</h2>
    <p>Acest proces poate dura câteva minute. Te rugăm să nu închizi pagina.</p>

    <?php
    if (ob_get_level()) ob_end_flush();
    flush();

    // 2. Paths
    function getRealFfPath($path) {
        if (empty($path)) return "";
        if (strpos($path, "http") === 0) return $path;
        return __DIR__ . "/" . $path;
    }

    $audio = getRealFfPath($video['voiceover_path']);
    $fontPath = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf";

    $images = [];
    for ($i = 1; $i <= $num_images; $i++) {
        $img = getRealFfPath($video['image'.$i]);
        if ($img) $images[] = $img;
    }

    if (empty($images) || empty($audio) || !file_exists($audio)) {
        echo "<p style='color: red;'>Eroare: Fișiere media lipsă.</p>";
        exit;
    }

    // 3. Timing Calculation
    $ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
    $audio_duration = (float)shell_exec($ffprobe_cmd);
    if (!$audio_duration || $audio_duration <= 0) $audio_duration = 20.0;

    $img_duration = $audio_duration / count($images);

    // 4. Word-Level Subtitles with Whisper
    $tempDir = __DIR__ . "/uploads/temp_" . $video_id . "_" . time() . "/";
    if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);

    $audioBasename = pathinfo($audio, PATHINFO_FILENAME);
    $jsonOutput = $tempDir . $audioBasename . ".json";
    $assFile = $tempDir . "subtitles.ass";

    $whisper_bin = "whisper";
    $whisper_lang_map = [
        'ro' => 'Romanian', 'en' => 'English', 'it' => 'Italian',
        'es' => 'Spanish', 'fr' => 'French', 'de' => 'German'
    ];
    $w_lang = $whisper_lang_map[$video['language']] ?? 'Romanian';

    $whisper_cmd = "$whisper_bin " . escapeshellarg($audio) . " --model base --language " . escapeshellarg($w_lang) . " --word_timestamps True --output_format json --output_dir " . escapeshellarg($tempDir) . " 2>&1";
    exec($whisper_cmd, $w_out, $w_ret);

    function formatAssTime($seconds) {
        $h = (int)floor($seconds / 3600);
        $m = (int)floor($seconds / 60) % 60;
        $s = (int)floor($seconds) % 60;
        $cs = (int)round(($seconds - floor($seconds)) * 100);
        if ($cs >= 100) { $cs = 0; $s++; }
        return sprintf("%d:%02d:%02d.%02d", $h, $m, $s, $cs);
    }

    // Generate Random Color for Highlighting
    function getRandomAssColor() {
        $r = str_pad(dechex(rand(100, 255)), 2, '0', STR_PAD_LEFT);
        $g = str_pad(dechex(rand(100, 255)), 2, '0', STR_PAD_LEFT);
        $b = str_pad(dechex(rand(100, 255)), 2, '0', STR_PAD_LEFT);
        return "&H00" . strtoupper($b . $g . $r) . "&";
    }
    $highlightColor = getRandomAssColor();

    if ($w_ret === 0 && file_exists($jsonOutput)) {
        $data = json_decode(file_get_contents($jsonOutput), true);

        $assHeader = "[Script Info]\nScriptType: v4.00+\nPlayResX: $width\nPlayResY: $height\n\n";
        $assHeader .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";

        $fontSize = ($video_type === 'short') ? 72 : 48;
        $assHeader .= "Style: Default,Sans,$fontSize,&H00FFFFFF,$highlightColor,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,2,5,10,10,10,1\n\n";
        $assHeader .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $events = "";
        foreach ($data['segments'] as $segment) {
            if (!isset($segment['words'])) continue;
            $words = $segment['words'];
            foreach ($words as $idx => $wordData) {
                $start = formatAssTime($wordData['start']);
                $end = formatAssTime($wordData['end']);
                $lineText = "";
                foreach ($words as $i => $w) {
                    $cleanWord = trim($w['word']);
                    if ($i === $idx) {
                        $lineText .= "{\\1c$highlightColor}" . $cleanWord . "{\\1c&HFFFFFF&} ";
                    } else {
                        $lineText .= $cleanWord . " ";
                    }
                }
                $events .= "Dialogue: 0,$start,$end,Default,,0,0,0,," . trim($lineText) . "\n";
            }
        }
        file_put_contents($assFile, $assHeader . $events);
        $useAss = true;
    } else {
        $useAss = false;
    }

    // 5. Build Filter Complex
    $zoompan_d = round($img_duration * 25);
    $preScale = ($video_type === 'short') ? "scale=2160:3840:force_original_aspect_ratio=increase,crop=2160:3840" : "scale=3840:2160:force_original_aspect_ratio=increase,crop=3840:2160";
    $zoomLogic = "zoompan=z='min(zoom+0.001,1.3)':d=$zoompan_d:s={$width}x{$height}:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':fps=25";

    $filter = "";
    $concatParts = "";
    foreach ($images as $idx => $img) {
        $filter .= "[$idx:v]$preScale,setsar=1,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v$idx]; ";
        $concatParts .= "[v$idx]";
    }
    $filter .= $concatParts . "concat=n=" . count($images) . ":v=1:a=0[vbase]";

    if ($useAss) {
        $escapedAssPath = str_replace(['\\', ':', "'"], ['\\\\', '\\:', "'\\''"], $assFile);
        $filter .= "; [vbase]subtitles='" . $escapedAssPath . "'[vfinal]";
        $lastLabel = "vfinal";
    } else {
        $lastLabel = "vbase";
    }

    $output_filename = "video_" . $video_id . "_" . time() . ".mp4";
    $output_path = __DIR__ . "/uploads/videos/" . $output_filename;
    $relative_video_path = "uploads/videos/" . $output_filename;

    if (!is_dir(__DIR__ . "/uploads/videos/")) mkdir(__DIR__ . "/uploads/videos/", 0775, true);

    $inputs = "";
    foreach ($images as $img) {
        $inputs .= "-loop 1 -t $img_duration -i " . escapeshellarg($img) . " ";
    }

    $ffmpeg_cmd = "ffmpeg -y $inputs -i " . escapeshellarg($audio) . " " .
        "-filter_complex " . escapeshellarg($filter) . " " .
        "-map \"[$lastLabel]\" -map " . count($images) . ":a -c:v libx264 -pix_fmt yuv420p -preset faster -crf 23 -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path);

    $full_output = shell_exec("$ffmpeg_cmd 2>&1");
    file_put_contents(__DIR__ . '/../storage/debug_render.log', "CMD: $ffmpeg_cmd\n\nOUTPUT:\n" . $full_output);

    if (!file_exists($output_path) || filesize($output_path) < 1000) {
        echo "<p style='color: red;'>Eroare FFmpeg. Verifică storage/debug_render.log.</p>";
        exit;
    }

    // 6. Cleanup
    foreach ($images as $f) { if (strpos($f, 'http') !== 0 && file_exists($f)) @unlink($f); }
    if (file_exists($jsonOutput)) @unlink($jsonOutput);
    if (file_exists($assFile)) @unlink($assFile);

    // 7. Update Database
    $stmt = $pdo->prepare("UPDATE videos SET status = 'done', video_path = ? WHERE id = ?");
    $stmt->execute([$relative_video_path, $video_id]);

    // Redirecționare robustă
    echo "<div style='margin-top: 20px; text-align: center;'>";
    echo "<p>Video finalizat! Se redirecționează...</p>";
    echo "<a href='dashboard.php?success=Video-ul finalizat cu succes!' style='color: #03dac6; text-decoration: none; font-weight: bold;'>Click aici dacă nu ești redirecționat automat</a>";
    echo "</div>";
    echo "<script>window.location.href = 'dashboard.php?success=Video-ul finalizat cu succes!';</script>";
    ?>
</body>
</html>
