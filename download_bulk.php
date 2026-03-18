<?php

/**
 * JS Rendering Checker — Telechargement CSV recap d'un job bulk
 */

error_reporting(0);

$jobId = $_GET['jobId'] ?? '';

// Validation format (hex only)
if (!preg_match('/^[a-f0-9]{24}$/', $jobId)) {
    http_response_code(400);
    echo 'Parametre invalide';
    exit;
}

$fichier = __DIR__ . '/data/jobs/' . $jobId . '/summary.csv';

if (!file_exists($fichier)) {
    http_response_code(404);
    echo 'Fichier non trouve';
    exit;
}

// Anti path traversal
$realPath = realpath($fichier);
$jobsDir = realpath(__DIR__ . '/data/jobs/');
if ($realPath === false || !str_starts_with($realPath, $jobsDir)) {
    http_response_code(403);
    echo 'Acces refuse';
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="js-rendering-checker-bulk-' . $jobId . '.csv"');
header('Content-Length: ' . filesize($fichier));
readfile($fichier);
