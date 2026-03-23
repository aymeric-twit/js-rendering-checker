<?php

/**
 * JS Rendering Checker — Endpoint SSE
 *
 * Orchestre les 3 phases : fetch brut, fetch rendu, comparaison.
 * Repond en Server-Sent Events.
 */

error_reporting(0);
ignore_user_abort(false);
set_time_limit(120);

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/functions.php';

// --- Headers SSE ---
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

while (ob_get_level()) {
    ob_end_clean();
}

// --- Shutdown handler : capturer les erreurs fatales et notifier le client ---
register_shutdown_function(function (): void {
    $erreur = error_get_last();
    if ($erreur !== null && in_array($erreur['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $messageFr = 'Erreur serveur : ' . $erreur['message'];
        $messageEn = 'Server error: ' . $erreur['message'];
        $json = json_encode(['message' => $messageFr, 'message_fr' => $messageFr, 'message_en' => $messageEn, 'phase' => 'fatal']);
        $json = str_replace(["\n", "\r"], '', $json);
        echo "event: error\ndata: {$json}\n\n";
        flush();
    }
});


/**
 * Envoie un evenement SSE.
 */
function sseEvent(string $event, array $data): void
{
    static $allowedEvents = ['phase', 'progress', 'done', 'error', 'info'];
    if (!in_array($event, $allowedEvents, true)) {
        return;
    }

    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $json = json_encode(['message' => 'Erreur encodage JSON : ' . $e->getMessage(), 'message_fr' => 'Erreur encodage JSON : ' . $e->getMessage(), 'message_en' => 'JSON encoding error: ' . $e->getMessage()]);
    }

    $json = str_replace(["\n", "\r"], '', $json);
    echo "event: {$event}\n";
    echo "data: {$json}\n\n";
    flush();
}


// --- Validation CSRF (plateforme) ---
if (defined('PLATFORM_EMBEDDED')) {
    $tokenRecu = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($tokenRecu) || !hash_equals($_SESSION['_csrf_token'] ?? '', $tokenRecu)) {
        sseEvent('error', ['message' => 'Token CSRF invalide.', 'message_fr' => 'Token CSRF invalide.', 'message_en' => 'Invalid CSRF token.', 'phase' => 'init']);
        exit;
    }
}

// --- Quota : vérifier sans déduire (déduction après succès des deux fetch) ---
if (class_exists('\\Platform\\Module\\Quota')) {
    if (!\Platform\Module\Quota::creditsDisponibles('js-rendering-checker')) {
        sseEvent('error', ['message' => 'Quota mensuel epuise.', 'message_fr' => 'Quota mensuel epuise.', 'message_en' => 'Monthly quota exhausted.', 'phase' => 'init', 'code' => 429]);
        exit;
    }
}

// --- Parametres ---
$url = trim($_POST['url'] ?? '');
$uaType = $_POST['ua_type'] ?? 'smartphone';
$timeout = (int) ($_POST['timeout'] ?? 15);
$avecScreenshots = !empty($_POST['screenshots']);

// Validation
if ($url === '') {
    sseEvent('error', ['message' => 'URL requise.', 'message_fr' => 'URL requise.', 'message_en' => 'URL required.', 'phase' => 'init']);
    exit;
}

if (!valider_url($url)) {
    sseEvent('error', ['message' => 'URL invalide ou non autorisee (localhost, IPs privees interdites).', 'message_fr' => 'URL invalide ou non autorisee (localhost, IPs privees interdites).', 'message_en' => 'Invalid or forbidden URL (localhost, private IPs not allowed).', 'phase' => 'init']);
    exit;
}

$timeout = max(3, min(30, $timeout));
$ua = ($uaType === 'desktop') ? CHROME_DESKTOP_UA : CHROME_MOBILE_UA;


// =========================================================================
// Phase 1 : Fetch HTML brut
// =========================================================================
sseEvent('progress', ['phase' => 'raw', 'pct' => 10, 'message_fr' => 'Recuperation du HTML brut...', 'message_en' => 'Fetching raw HTML...']);

if (connection_aborted()) {
    exit;
}

$brutResultat = fetch_brut($url, $ua);

if ($brutResultat['status'] !== 'ok') {
    $erreurBrut = $brutResultat['error'] ?? 'Erreur fetch brut';
    sseEvent('error', ['message' => $erreurBrut, 'message_fr' => $erreurBrut, 'message_en' => $brutResultat['error'] ?? 'Raw fetch error', 'phase' => 'raw']);
    exit;
}

sseEvent('phase', [
    'phase'    => 'raw',
    'httpCode' => $brutResultat['httpCode'],
    'taille'   => $brutResultat['taille'],
    'urlFinale' => $brutResultat['urlFinale'],
]);

sseEvent('progress', ['phase' => 'raw_done', 'pct' => 33, 'message_fr' => 'HTML brut recupere.', 'message_en' => 'Raw HTML fetched.']);


// =========================================================================
// Phase 2 : Fetch HTML rendu (Browserless)
// =========================================================================
$modeRawOnly = false;

sseEvent('progress', ['phase' => 'render', 'pct' => 40, 'message_fr' => 'Rendu JavaScript en cours...', 'message_en' => 'JavaScript rendering in progress...']);

if (connection_aborted()) {
    exit;
}

$renduResultat = fetch_rendu($url, $ua, $timeout);

if ($renduResultat['status'] === 'unavailable') {
    $modeRawOnly = true;
    sseEvent('info', [
        'message_fr' => 'Cle API Browserless non configuree — mode HTML brut uniquement.',
        'message_en' => 'Browserless API key not configured — raw HTML only mode.',
    ]);
    sseEvent('progress', ['phase' => 'render_skip', 'pct' => 66, 'message_fr' => 'Rendu JS ignore (pas de cle API).', 'message_en' => 'JS rendering skipped (no API key).']);
} elseif ($renduResultat['status'] === 'error') {
    $modeRawOnly = true;
    sseEvent('info', [
        'message_fr' => 'Erreur Browserless : ' . ($renduResultat['error'] ?? '') . ' — mode HTML brut uniquement.',
        'message_en' => 'Browserless error: ' . ($renduResultat['error'] ?? '') . ' — raw HTML only mode.',
    ]);
    sseEvent('progress', ['phase' => 'render_error', 'pct' => 66, 'message_fr' => 'Rendu JS echoue.', 'message_en' => 'JS rendering failed.']);
} else {
    sseEvent('phase', [
        'phase'      => 'render',
        'tempsRendu' => $renduResultat['tempsRendu'],
    ]);
    sseEvent('progress', ['phase' => 'render_done', 'pct' => 66, 'message_fr' => 'HTML rendu obtenu.', 'message_en' => 'Rendered HTML obtained.']);
}


// =========================================================================
// Phase 3 : Comparaison
// =========================================================================
sseEvent('progress', ['phase' => 'compare', 'pct' => 75, 'message_fr' => 'Comparaison en cours...', 'message_en' => 'Comparison in progress...']);

if (connection_aborted()) {
    exit;
}

$zonesBrut = extraire_zones_seo($brutResultat['html'], $url);

$zonesRendu = null;
$comparaison = null;
$score = null;
$recommandations = null;

if (!$modeRawOnly) {
    $zonesRendu = extraire_zones_seo($renduResultat['html'], $url);
    $comparaison = comparer_zones($zonesBrut, $zonesRendu);
    $score = calculer_score($comparaison);
    $recommandations = generer_recommandations($comparaison);
}

// Compteurs de statuts
$compteurs = ['identique' => 0, 'modifie' => 0, 'js_seul' => 0, 'supprime' => 0, 'absent' => 0];
if ($comparaison !== null) {
    foreach ($comparaison as $donnees) {
        $compteurs[$donnees['statut']] = ($compteurs[$donnees['statut']] ?? 0) + 1;
    }
}

// =========================================================================
// Phase 4 (optionnelle) : Screenshots
// =========================================================================
$screenshotBrut = null;
$screenshotRendu = null;

if ($avecScreenshots && !$modeRawOnly) {
    sseEvent('progress', ['phase' => 'screenshots', 'pct' => 85, 'message_fr' => 'Capture des screenshots...', 'message_en' => 'Capturing screenshots...']);

    if (connection_aborted()) {
        exit;
    }

    // Creer le dossier temporaire pour les screenshots
    $screenshotDir = __DIR__ . '/data/screenshots';
    if (!is_dir($screenshotDir)) {
        mkdir($screenshotDir, 0755, true);
    }
    $jobId = bin2hex(random_bytes(8));

    $screenshotPaths = [];

    $screenshotBrut = capturer_screenshot($url, false);
    if ($screenshotBrut['status'] === 'ok') {
        $screenshotData = $screenshotBrut['base64'];
        if (strlen($screenshotData) <= 5000000) {
            $fichier = $jobId . '_brut.png';
            file_put_contents($screenshotDir . '/' . $fichier, base64_decode($screenshotData));
            $screenshotPaths['brut'] = 'data/screenshots/' . $fichier;
        }
    }

    $screenshotRendu = capturer_screenshot($url, true);
    if ($screenshotRendu['status'] === 'ok') {
        $screenshotData = $screenshotRendu['base64'];
        if (strlen($screenshotData) <= 5000000) {
            $fichier = $jobId . '_rendu.png';
            file_put_contents($screenshotDir . '/' . $fichier, base64_decode($screenshotData));
            $screenshotPaths['rendu'] = 'data/screenshots/' . $fichier;
        }
    }

    // Envoyer les URLs des screenshots (quelques octets au lieu de Mo)
    sseEvent('phase', [
        'phase'       => 'screenshots',
        'screenshotBrut'  => $screenshotPaths['brut'] ?? null,
        'screenshotRendu' => $screenshotPaths['rendu'] ?? null,
    ]);

    sseEvent('progress', ['phase' => 'screenshots_done', 'pct' => 95, 'message_fr' => 'Screenshots captures.', 'message_en' => 'Screenshots captured.']);
}

sseEvent('progress', ['phase' => 'done', 'pct' => 100, 'message_fr' => 'Analyse terminee.', 'message_en' => 'Analysis complete.']);

// =========================================================================
// Envoi des resultats (sans le HTML brut complet pour eviter un payload enorme)
// =========================================================================

// Sauvegarder les details complets en fichier JSON pour la modale de diff
$detailDir = __DIR__ . '/data/details';
if (!is_dir($detailDir)) {
    mkdir($detailDir, 0755, true);
}
$detailId = $jobId ?? bin2hex(random_bytes(8));
$detailFichier = $detailId . '.json';

$detailData = [
    'zonesBrut'  => $zonesBrut,
    'zonesRendu' => $zonesRendu,
    'htmlBrut'   => mb_substr($brutResultat['html'] ?? '', 0, 500000),
    'htmlRendu'  => mb_substr($renduResultat['html'] ?? '', 0, 500000),
];
file_put_contents(
    $detailDir . '/' . $detailFichier,
    json_encode($detailData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
);

// Alleger les zones pour le payload SSE : remplacer les listes par des compteurs
function alleger_zones(array $zones): array
{
    $zones['texte_extrait'] = mb_substr($zones['texte_extrait'] ?? '', 0, 500);
    $zones['liens_internes'] = count($zones['liens_internes'] ?? []);
    $zones['liens_externes'] = count($zones['liens_externes'] ?? []);
    $zones['images'] = count($zones['images'] ?? []);
    $zones['donnees_structurees'] = array_map(function ($s) {
        return ['type' => $s['type'], 'json' => mb_substr($s['json'], 0, 2000)];
    }, $zones['donnees_structurees'] ?? []);
    return $zones;
}

$zonesBrutLight = alleger_zones($zonesBrut);
$zonesRenduLight = $zonesRendu !== null ? alleger_zones($zonesRendu) : null;

$payload = [
    'modeRawOnly'      => $modeRawOnly,
    'url'              => $url,
    'urlFinale'        => $brutResultat['urlFinale'],
    'httpCode'         => $brutResultat['httpCode'],
    'tailleBrut'       => $brutResultat['taille'],
    'tempsRendu'       => $renduResultat['tempsRendu'] ?? null,
    'detailUrl'        => 'data/details/' . $detailFichier,
    'zonesBrut'        => $zonesBrutLight,
    'zonesRendu'       => $zonesRenduLight,
    'comparaison'      => $comparaison,
    'score'            => $score,
    'recommandations'  => $recommandations,
    'compteurs'        => $compteurs,
    'hashBrut'         => hash('sha256', $brutResultat['html'] ?? ''),
    'hashRendu'        => !$modeRawOnly ? hash('sha256', $renduResultat['html'] ?? '') : null,
];

// Nettoyer l'UTF-8 invalide avant encodage JSON (pages mal encodees)
$jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

if ($jsonPayload === false) {
    // Fallback : retenter sans les donnees textuelles problematiques
    $payload['comparaison'] = $comparaison !== null
        ? array_map(function ($c) {
            $c['brut'] = is_string($c['brut']) ? mb_convert_encoding($c['brut'], 'UTF-8', 'UTF-8') : $c['brut'];
            $c['rendu'] = is_string($c['rendu']) ? mb_convert_encoding($c['rendu'], 'UTF-8', 'UTF-8') : $c['rendu'];
            return $c;
        }, $comparaison)
        : null;
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

if ($jsonPayload === false) {
    sseEvent('error', ['message' => 'Erreur JSON : ' . json_last_error_msg(), 'message_fr' => 'Erreur JSON : ' . json_last_error_msg(), 'message_en' => 'JSON error: ' . json_last_error_msg(), 'phase' => 'done']);
} else {
    // Envoyer directement pour eviter la double-encode de sseEvent
    $jsonPayload = str_replace(["\n", "\r"], '', $jsonPayload);
    echo "event: done\n";
    echo "data: {$jsonPayload}\n\n";
    flush();
}

// --- Quota : déduire le crédit APRES envoi des résultats au client ---
if (class_exists('\\Platform\\Module\\Quota') && !$modeRawOnly) {
    try {
        \Platform\Module\Quota::track('js-rendering-checker');
    } catch (\Throwable $e) {
        // Ne pas bloquer si le tracking échoue
    }
}
