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
    echo json_encode(['error' => 'Parametres invalides', 'error_fr' => 'Parametres invalides', 'error_en' => 'Invalid parameters']);
    exit;
}

$jobDir = __DIR__ . '/data/jobs/' . $jobId;
$fichier = $jobDir . '/' . $urlHash . '.json';

// Verifier que le job appartient a l'utilisateur courant
$metaFile = $jobDir . '/meta.json';
if (file_exists($metaFile)) {
    $meta = json_decode(file_get_contents($metaFile), true);
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Auth')) {
        $userId = \Auth::id();
        if ($userId !== null && isset($meta['user_id']) && (int) $meta['user_id'] !== $userId) {
            http_response_code(403);
            echo json_encode(['erreur' => 'Accès non autorisé.', 'erreur_fr' => 'Accès non autorisé.', 'erreur_en' => 'Unauthorized access.']);
            exit;
        }
    }
}

if (!file_exists($fichier)) {
    http_response_code(404);
    echo json_encode(['error' => 'Analyse non trouvee', 'error_fr' => 'Analyse non trouvee', 'error_en' => 'Analysis not found']);
    exit;
}

// Verifier que le fichier est bien dans le dossier attendu (anti path traversal)
$realPath = realpath($fichier);
$jobsDir = realpath(__DIR__ . '/data/jobs/');
if ($realPath === false || !str_starts_with($realPath, $jobsDir)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acces refuse', 'error_fr' => 'Acces refuse', 'error_en' => 'Access denied']);
    exit;
}

readfile($fichier);
