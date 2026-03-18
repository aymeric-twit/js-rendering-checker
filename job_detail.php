<?php

/**
 * JS Rendering Checker — Detail d'une URL dans un job bulk
 */

error_reporting(0);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$jobId = $_GET['jobId'] ?? '';
$urlHash = $_GET['urlHash'] ?? '';

// Validation format (hex only)
if (!preg_match('/^[a-f0-9]{24}$/', $jobId) || !preg_match('/^[a-f0-9]{12}$/', $urlHash)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parametres invalides']);
    exit;
}

$fichier = __DIR__ . '/data/jobs/' . $jobId . '/' . $urlHash . '.json';

if (!file_exists($fichier)) {
    http_response_code(404);
    echo json_encode(['error' => 'Analyse non trouvee']);
    exit;
}

// Verifier que le fichier est bien dans le dossier attendu (anti path traversal)
$realPath = realpath($fichier);
$jobsDir = realpath(__DIR__ . '/data/jobs/');
if ($realPath === false || !str_starts_with($realPath, $jobsDir)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acces refuse']);
    exit;
}

readfile($fichier);
