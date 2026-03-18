<?php

// Chargement du .env en mode standalone
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lignes = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lignes as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || str_starts_with($ligne, '#')) {
            continue;
        }
        if (str_contains($ligne, '=')) {
            [$cle, $valeur] = explode('=', $ligne, 2);
            $cle = trim($cle);
            $valeur = trim($valeur);
            if ($cle !== '' && getenv($cle) === false) {
                putenv("{$cle}={$valeur}");
            }
        }
    }
}

// Propagation depuis $_ENV (mode plateforme)
if (!empty($_ENV['BROWSERLESS_API_KEY']) && getenv('BROWSERLESS_API_KEY') === false) {
    putenv("BROWSERLESS_API_KEY={$_ENV['BROWSERLESS_API_KEY']}");
}
