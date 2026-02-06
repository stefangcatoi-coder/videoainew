<?php
set_time_limit(0);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// --- PREGĂTIRE DATE (Sincron) ---

function getRealFfPath($path) {
    if (empty($path)) return "";
    if (strpos($path, "http") === 0) return $path;
    return __DIR__ . "/" . $path;
}

$img1 = getRealFfPath($video['image1']);
$img2 = getRealFfPath($video['image2']);
$img3 = getRealFfPath($video['image3']);
$audio = getRealFfPath($video['voiceover_path']);

if (!file_exists($audio)) {
    header("Location: dashboard.php?error=Fișier audio lipsă.");
    exit;
}

// 3. Timing Calculation
$ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
$audio_duration = (float)shell_exec($ffprobe_cmd);
if (!$audio_duration || $audio_duration <= 0) $audio_duration = 20.0;

$img_duration = $audio_duration / 3;

// 4. Word-Level Subtitles with Whisper (Sincron)
$tempDir = __DIR__ . "/uploads/temp_" . $video_id . "_" . time() . "/";
if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);

$audioBasename = pathinfo($audio, PATHINFO_FILENAME);
$jsonOutput = $tempDir . $audioBasename . ".json";
$assFile = $tempDir . "subtitles.ass";

$whisper_bin = "whisper";
$whisper_cmd = "$whisper_bin " . escapeshellarg($audio) . " --model base --language Romanian --word_timestamps True --output_format json --output_dir " . escapeshellarg($tempDir) . " 2>&1";
exec($whisper_cmd, $w_out, $w_ret);

function formatAssTime($seconds) {
    $h = (int)floor($seconds / 3600);
    $m = (int)floor($seconds / 60) % 60;
    $s = (int)floor($seconds) % 60;
    $cs = (int)round(($seconds - floor($seconds)) * 100);
    if ($cs >= 100) { $cs = 0; $s++; }
    return sprintf("%d:%02d:%02d.%02d", $h, $m, $s, $cs);
}

$useAss = false;
if ($w_ret === 0 && file_exists($jsonOutput)) {
    $data = json_decode(file_get_contents($jsonOutput), true);
    if ($data && isset($data['segments'])) {
        $assHeader = "[Script Info]\nScriptType: v4.00+\nPlayResX: 1080\nPlayResY: 1920\n\n";
        $assHeader .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $assHeader .= "Style: Default,Sans,72,&H00FFFFFF,&H0000FFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,2,5,10,10,10,1\n\n";
        $assHeader .= "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";

        $events = "";
        foreach ($data['segments'] as $segment) {
            if (!isset($segment['words'])) continue;
            foreach ($segment['words'] as $idx => $wordData) {
                $start = formatAssTime($wordData['start']);
                $end = formatAssTime($wordData['end']);
                $lineText = "";
                foreach ($segment['words'] as $i => $w) {
                    $cleanWord = trim($w['word']);
                    if ($i === $idx) { $lineText .= "{\\1c&H00FFFF&}" . $cleanWord . "{\\1c&HFFFFFF&} "; }
                    else { $lineText .= $cleanWord . " "; }
                }
                $events .= "Dialogue: 0,$start,$end,Default,,0,0,0,," . trim($lineText) . "\n";
            }
        }
        file_put_contents($assFile, $assHeader . $events);
        $useAss = true;
    }
}

// 5. Build Filter Complex
$zoompan_d = (int)round($img_duration * 25);
$preScale = "scale=2160:3840:force_original_aspect_ratio=increase,crop=2160:3840,setsar=1";
$zoomLogic = "zoompan=z='min(zoom+0.0015,1.5)':d=$zoompan_d:s=1080x1920:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':fps=25";

$filter = "[0:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v1]; ";
$filter .= "[1:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v2]; ";
$filter .= "[2:v]$preScale,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v3]; ";
$filter .= "[v1][v2][v3]concat=n=3:v=1:a=0[vbase]";

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

$ffmpeg_cmd = "ffmpeg -y " .
    "-loop 1 -t $img_duration -i " . escapeshellarg($img1) . " " .
    "-loop 1 -t $img_duration -i " . escapeshellarg($img2) . " " .
    "-loop 1 -t $img_duration -i " . escapeshellarg($img3) . " " .
    "-i " . escapeshellarg($audio) . " " .
    "-filter_complex " . escapeshellarg($filter) . " " .
    "-map \"[$lastLabel]\" -map 3:a -c:v libx264 -pix_fmt yuv420p -preset faster -crf 23 -c:a aac -b:a 192k -shortest " . escapeshellarg($output_path);

// --- LANSARE BACKGROUND ---

$db_path = realpath(__DIR__ . '/../storage/app.db');
$update_cmd = "sqlite3 " . escapeshellarg($db_path) . " \"UPDATE videos SET status='done', video_path=" . escapeshellarg($relative_video_path) . " WHERE id=$video_id;\"";

// Conform cerinței: > /dev/null 2>&1 & la final și fără 2>&1 în shell_exec
$full_bg_cmd = "($ffmpeg_cmd && $update_cmd) > /dev/null 2>&1 &";

shell_exec($full_bg_cmd);

// Update status în processing
$stmt = $pdo->prepare("UPDATE videos SET status = 'processing' WHERE id = ?");
$stmt->execute([$video_id]);

session_write_close();
header("Location: dashboard.php");
exit;
