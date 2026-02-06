<?php
// /var/www/video-ai/public/index.php

session_start();

// Dacă utilizatorul este deja logat, îl trimitem la dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
} else {
    // Altfel, îl trimitem la pagina de login
    header("Location: login.php");
    exit;
}
