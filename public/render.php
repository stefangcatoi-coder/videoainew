<?php
set_time_limit(0);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// /var/www/video-ai/public/render.php

if (php_sapi_name() !== 'cli') {
    session_start();

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    require_once __DIR__ . '/../config/database.php';

    $user_id = $_SESSION['user_id'];
    $video_id = (int)($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ? AND user_id = ?");
    $stmt->execute([$video_id, $user_id]);
    $video = $stmt->fetch();

    if (!$video || $video['status'] === 'done') {
        header("Location: dashboard.php");
        exit;
    }

    // Marcăm procesul ca fiind în lucru
    $stmt = $pdo->prepare("UPDATE videos SET status = 'processing' WHERE id = ?");
    $stmt->execute([$video_id]);

    // Lansăm întregul proces (Whisper + FFmpeg) în fundal folosind CLI
    $php_bin = PHP_BINARY;
    $script = __FILE__;
    $log = dirname(__DIR__) . "/storage/logs/render_$video_id.log";
    if (!is_dir(dirname($log))) mkdir(dirname($log), 0775, true);

    // Comanda de lansare în fundal conform cerințelor de format (fără 2>&1 în shell_exec, cu > /dev/null 2>&1 & la final)
    $cmd = "$php_bin $script $video_id > /dev/null 2>&1 &";
    shell_exec($cmd);

    header("Location: dashboard.php?msg=Procesarea a început în fundal.");
    exit;
}

// --- LOGICA CLI (BACKGROUND) ---
if ($argc < 2) exit;
$video_id = (int)$argv[1];

require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$video_id]);
$video = $stmt->fetch();
if (!$video) exit;

$video_type = $video['video_type'] ?? 'short';
$num_images = ($video_type === 'short') ? 3 : 5;
$width = ($video_type === 'short') ? 1080 : 1920;
$height = ($video_type === 'short') ? 1920 : 1080;

function getRealFfPath($path) {
    if (empty($path)) return "";
    return (strpos($path, "http") === 0) ? $path : __DIR__ . "/" . $path;
}

$audio = getRealFfPath($video['voiceover_path']);
$images = [];
for ($i = 1; $i <= $num_images; $i++) {
    $img = getRealFfPath($video['image'.$i]);
    if ($img && (strpos($img, 'http') === 0 || file_exists($img))) $images[] = $img;
}

if (empty($images) || !file_exists($audio)) {
    $pdo->prepare("UPDATE videos SET status = 'failed' WHERE id = ?")->execute([$video_id]);
    exit;
}

$ffprobe_cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($audio);
$audio_duration = (float)shell_exec($ffprobe_cmd) ?: 20.0;
$img_duration = $audio_duration / count($images);

$tempDir = __DIR__ . "/uploads/temp_" . $video_id . "_" . time() . "/";
if (!is_dir($tempDir)) mkdir($tempDir, 0775, true);
$jsonOutput = $tempDir . pathinfo($audio, PATHINFO_FILENAME) . ".json";
$assFile = $tempDir . "subtitles.ass";

$whisper_lang_map = ['ro' => 'Romanian', 'en' => 'English', 'it' => 'Italian', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German'];
$w_lang = $whisper_lang_map[$video['language']] ?? 'Romanian';

exec("whisper " . escapeshellarg($audio) . " --model base --language " . escapeshellarg($w_lang) . " --word_timestamps True --output_format json --output_dir " . escapeshellarg($tempDir) . " 2>&1", $w_out, $w_ret);

function formatAssTime($s) {
    $h = (int)($s / 3600); $m = (int)($s / 60) % 60; $sec = (int)$s % 60; $cs = (int)round(($s - (int)$s) * 100);
    if ($cs >= 100) { $cs = 0; $sec++; }
    return sprintf("%d:%02d:%02d.%02d", $h, $m, $sec, $cs);
}

$useAss = false;
if ($w_ret === 0 && file_exists($jsonOutput)) {
    $data = json_decode(file_get_contents($jsonOutput), true);
    if ($data && isset($data['segments'])) {
        $color = "&H00" . strtoupper(str_pad(dechex(rand(100,255)),2,'0').str_pad(dechex(rand(100,255)),2,'0').str_pad(dechex(rand(100,255)),2,'0')) . "&";
        $fs = ($video_type === 'short') ? 72 : 48;
        $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: $width\nPlayResY: $height\n\n";
        $ass .= "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $ass .= "Style: Default,Sans,$fs,&H00FFFFFF,$color,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,2,2,5,10,10,10,1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($data['segments'] as $seg) {
            if (!isset($seg['words'])) continue;
            foreach ($seg['words'] as $idx => $word) {
                $txt = ""; foreach ($seg['words'] as $i => $w) { $clean = trim($w['word']); $txt .= ($i==$idx ? "{\\1c$color}$clean{\\1c&HFFFFFF&} " : "$clean "); }
                $ass .= "Dialogue: 0,".formatAssTime($word['start']).",".formatAssTime($word['end']).",Default,,0,0,0,,".trim($txt)."\n";
            }
        }
        file_put_contents($assFile, $ass); $useAss = true;
    }
}

$zoompan_d = (int)round($img_duration * 25);
$preScale = ($video_type === 'short') ? "scale=2160:3840:force_original_aspect_ratio=increase,crop=2160:3840" : "scale=3840:2160:force_original_aspect_ratio=increase,crop=3840:2160";
$zoomLogic = "zoompan=z='min(zoom+0.001,1.3)':d=$zoompan_d:s={$width}x{$height}:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':fps=25";
$filter = ""; $concat = "";
foreach ($images as $idx => $img) {
    $filter .= "[$idx:v]$preScale,setsar=1,$zoomLogic,trim=duration=$img_duration,setpts=PTS-STARTPTS[v$idx]; ";
    $concat .= "[v$idx]";
}
$filter .= $concat . "concat=n=".count($images).":v=1:a=0[vbase]";
if ($useAss) {
    $esc = str_replace(['\\', ':', "'"], ['\\\\', '\\:', "'\\''"], $assFile);
    $filter .= "; [vbase]subtitles='$esc'[vfinal]"; $last = "vfinal";
} else { $last = "vbase"; }

$out_name = "video_" . $video_id . "_" . time() . ".mp4";
$out_path = __DIR__ . "/uploads/videos/" . $out_name;
if (!is_dir(__DIR__ . "/uploads/videos/")) mkdir(__DIR__ . "/uploads/videos/", 0775, true);

$inputs = ""; foreach ($images as $img) { $inputs .= "-loop 1 -t $img_duration -i " . escapeshellarg($img) . " "; }
$ffmpeg_cmd = "ffmpeg -y $inputs -i " . escapeshellarg($audio) . " -filter_complex " . escapeshellarg($filter) . " -map \"[$last]\" -map ".count($images).":a -c:v libx264 -pix_fmt yuv420p -preset faster -crf 23 -c:a aac -b:a 192k -shortest " . escapeshellarg($out_path);

$full_output = shell_exec("$ffmpeg_cmd 2>&1");
file_put_contents(dirname(__DIR__) . "/storage/logs/render_$video_id.log", $full_output, FILE_APPEND);

if (file_exists($out_path) && filesize($out_path) > 1000) {
    $pdo->prepare("UPDATE videos SET status='done', video_path='uploads/videos/$out_name' WHERE id=?")->execute([$video_id]);
} else {
    $pdo->prepare("UPDATE videos SET status='failed' WHERE id=?")->execute([$video_id]);
}

// Cleanup
foreach ($images as $img) { if (strpos($img, 'http') !== 0 && file_exists($img)) @unlink($img); }
if (file_exists($jsonOutput)) @unlink($jsonOutput);
if (file_exists($assFile)) @unlink($assFile);
@rmdir($tempDir);
