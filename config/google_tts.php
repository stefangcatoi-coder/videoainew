<?php
// /var/www/video-ai/config/google_tts.php

define('GOOGLE_TTS_API_KEY', 'YOUR_GOOGLE_API_KEY');
define('GOOGLE_TTS_URL', 'https://texttospeech.googleapis.com/v1/text:synthesize');

// Mapping languages to Google Voices
function getGoogleVoice($lang_code) {
    $voices = [
        'ro' => ['name' => 'ro-RO-Wavenet-A', 'languageCode' => 'ro-RO'],
        'en' => ['name' => 'en-US-Wavenet-D', 'languageCode' => 'en-US'],
        'it' => ['name' => 'it-IT-Wavenet-A', 'languageCode' => 'it-IT'],
        'es' => ['name' => 'es-ES-Wavenet-C', 'languageCode' => 'es-ES'],
        'fr' => ['name' => 'fr-FR-Wavenet-C', 'languageCode' => 'fr-FR'],
        'de' => ['name' => 'de-DE-Wavenet-B', 'languageCode' => 'de-DE']
    ];
    return $voices[$lang_code] ?? $voices['ro'];
}
