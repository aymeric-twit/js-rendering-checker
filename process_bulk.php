<?php

/**
 * JS Rendering Checker — Endpoint SSE Bulk (multi-URL)
 */

error_reporting(0);
ignore_user_abort(false);
set_time_limit(600); // 10 min max pour 50 URLs

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/functions.php';

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('X-Content-Type-Options: nosniff');

while (ob_get_level()) {
    ob_end_clean();
}

function sseEvent(string $event, array $data): void
{
    static $allowedEvents = ['validation', 'url_start', 'url_done', 'url_error', 'progress', 'bulk_done', 'error'];
    if (!in_array($event, $allowedEvents, true)) {
        return;
    }

    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $json = json_encode(['message' => 'Erreur encodage JSON', 'message_fr' => 'Erreur encodage JSON', 'message_en' => 'JSON encoding error']);
    }

    $json = str_replace(["\n", "\r"], '', $json);
    echo "event: {$event}\n";
    echo "data: {$json}\n\n";
    flush();
}

// Cleanup probabiliste (1/10)
if (random_int(1, 10) === 1) {
    nettoyer_fichiers_expires(__DIR__ . '/data/details');
    nettoyer_fichiers_expires(__DIR__ . '/data/screenshots');
    nettoyer_fichiers_expires(__DIR__ . '/data/jobs');
}

// --- Validation CSRF (plateforme) ---
if (defined('PLATFORM_EMBEDDED')) {
    $tokenRecu = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($tokenRecu) || !hash_equals($_SESSION['_csrf_token'] ?? '', $tokenRecu)) {
        sseEvent('error', ['message' => 'Token CSRF invalide.', 'message_fr' => 'Token CSRF invalide.', 'message_en' => 'Invalid CSRF token.']);
        exit;
    }
}

// --- Parametres ---
$urlsTexte = trim($_POST['urls'] ?? '');
$uaType = $_POST['ua_type'] ?? 'smartphone';
$timeout = (int) ($_POST['timeout'] ?? 7);
$timeout = max(3, min(30, $timeout));
$ua = ($uaType === 'desktop') ? CHROME_DESKTOP_UA : CHROME_MOBILE_UA;

// --- Limite de taille ---
if (strlen($urlsTexte) > 100000) {
    sseEvent('error', ['message' => 'Données trop volumineuses (max 100 Ko).', 'message_fr' => 'Données trop volumineuses (max 100 Ko).', 'message_en' => 'Data too large (max 100 KB).']);
    exit;
}

// --- Parse URLs ---
$lignes = array_filter(array_map('trim', explode("\n", $urlsTexte)));
$urlsValides = [];
$urlsInvalides = [];

foreach ($lignes as $ligne) {
    if ($ligne === '') {
        continue;
    }
    // Auto-prefix https si absent
    if (!preg_match('#^https?://#i', $ligne)) {
        $ligne = 'https://' . $ligne;
    }
    if (valider_url($ligne)) {
        $urlsValides[] = $ligne;
    } else {
        $urlsInvalides[] = $ligne;
    }
}

// Max 50 URLs
$urlsValides = array_slice($urlsValides, 0, 50);
$total = count($urlsValides);

if ($total === 0) {
    sseEvent('error', ['message' => 'Aucune URL valide fournie.', 'message_fr' => 'Aucune URL valide fournie.', 'message_en' => 'No valid URL provided.']);
    exit;
}

sseEvent('validation', [
    'total' => $total,
    'valid' => $total,
    'invalid' => count($urlsInvalides),
    'skipped' => array_slice($urlsInvalides, 0, 10),
]);

// --- Creer le dossier du job ---
$jobId = bin2hex(random_bytes(12));
$jobDir = __DIR__ . '/data/jobs/' . $jobId;
mkdir($jobDir, 0755, true);

// Sauver les metadonnees du job (dont l'identifiant utilisateur pour le controle d'acces)
$metaDonnees = ['date_creation' => date('c')];
if (defined('PLATFORM_EMBEDDED') && class_exists('\\Auth')) {
    $userId = \Auth::id();
    if ($userId !== null) {
        $metaDonnees['user_id'] = $userId;
    }
}
file_put_contents($jobDir . '/meta.json', json_encode($metaDonnees, JSON_UNESCAPED_UNICODE));

$resultats = [];
$succeeded = 0;
$failed = 0;
$templates = [];

for ($i = 0; $i < $total; $i++) {
    if (connection_aborted()) {
        break;
    }

    $url = $urlsValides[$i];

    // Quota check par URL
    if (class_exists('\\Platform\\Module\\Quota')) {
        if (!\Platform\Module\Quota::trackerSiDisponible('js-rendering-checker')) {
            sseEvent('url_error', [
                'index' => $i,
                'url' => $url,
                'erreur' => 'Quota mensuel epuise.',
                'erreur_fr' => 'Quota mensuel epuise.',
                'erreur_en' => 'Monthly quota exhausted.',
            ]);
            $failed++;
            continue;
        }
    }

    sseEvent('url_start', ['index' => $i, 'url' => $url, 'total' => $total]);

    $resultat = analyser_url_complete($url, $ua, $timeout);

    if ($resultat['status'] !== 'ok') {
        sseEvent('url_error', [
            'index' => $i,
            'url' => $url,
            'erreur' => sanitiser_erreur($resultat['error'] ?? 'Erreur inconnue'),
            'erreur_fr' => sanitiser_erreur($resultat['error'] ?? 'Erreur inconnue'),
            'erreur_en' => sanitiser_erreur($resultat['error'] ?? 'Unknown error'),
        ]);
        $failed++;
        continue;
    }

    // Determiner le risque global
    $risqueGlobal = 'faible';
    $ordreRisque = ['critique' => 3, 'haut' => 2, 'moyen' => 1, 'faible' => 0];
    if ($resultat['comparaison']) {
        foreach ($resultat['comparaison'] as $zone => $donnees) {
            if (($ordreRisque[$donnees['risque'] ?? ''] ?? 0) > ($ordreRisque[$risqueGlobal] ?? 0)) {
                $risqueGlobal = $donnees['risque'];
            }
        }
    }

    // Zones problematiques
    $zonesProblematiques = [];
    if ($resultat['comparaison']) {
        foreach ($resultat['comparaison'] as $zone => $donnees) {
            if (in_array($donnees['statut'], ['js_seul', 'supprime', 'modifie'], true)) {
                $zonesProblematiques[] = $zone;
            }
        }
    }

    $urlHash = substr(md5($url), 0, 12);
    $template = $resultat['template'];

    // Sauver le detail complet
    $detailData = [
        'url' => $url,
        'zonesBrut' => $resultat['zonesBrut'],
        'zonesRendu' => $resultat['zonesRendu'],
        'comparaison' => $resultat['comparaison'],
        'score' => $resultat['score'],
        'recommandations' => $resultat['recommandations'],
        'compteurs' => $resultat['compteurs'],
        'htmlBrut' => mb_substr($resultat['htmlBrut'] ?? '', 0, 500000),
        'htmlRendu' => mb_substr($resultat['htmlRendu'] ?? '', 0, 500000),
    ];
    file_put_contents(
        $jobDir . '/' . $urlHash . '.json',
        json_encode($detailData, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
    );

    $resume = [
        'url' => $url,
        'urlHash' => $urlHash,
        'score' => $resultat['score'],
        'compteurs' => $resultat['compteurs'],
        'template' => $template,
        'risqueGlobal' => $risqueGlobal,
        'httpCode' => $resultat['httpCode'],
        'modeRawOnly' => $resultat['modeRawOnly'],
        'zonesProblematiques' => $zonesProblematiques,
    ];

    $resultats[] = $resume;
    $succeeded++;

    // Agreger par template
    if (!isset($templates[$template])) {
        $templates[$template] = ['urls' => 0, 'scores' => [], 'zones' => []];
    }
    $templates[$template]['urls']++;
    $templates[$template]['scores'][] = $resultat['score'] ?? 0;
    if ($resultat['comparaison']) {
        foreach ($resultat['comparaison'] as $zone => $donnees) {
            $statut = $donnees['statut'];
            $templates[$template]['zones'][$zone][$statut] = ($templates[$template]['zones'][$zone][$statut] ?? 0) + 1;
        }
    }

    sseEvent('url_done', $resume);

    $pct = (int) round(($i + 1) / $total * 100);
    sseEvent('progress', [
        'pct' => $pct,
        'message_fr' => ($i + 1) . '/' . $total . ' URLs analysees...',
        'message_en' => ($i + 1) . '/' . $total . ' URLs analyzed...',
    ]);
}

// --- Calculer les stats templates ---
$templatesFormatted = [];
foreach ($templates as $pattern => $data) {
    $scoreMoyen = count($data['scores']) > 0
        ? (int) round(array_sum($data['scores']) / count($data['scores']))
        : 0;
    $templatesFormatted[] = [
        'pattern' => $pattern,
        'urls' => $data['urls'],
        'scoreMoyen' => $scoreMoyen,
        'zones' => $data['zones'],
    ];
}
// Trier par score moyen croissant (pires templates en premier)
usort($templatesFormatted, fn($a, $b) => $a['scoreMoyen'] <=> $b['scoreMoyen']);

$scoreMoyenGlobal = $succeeded > 0
    ? (int) round(array_sum(array_column($resultats, 'score')) / $succeeded)
    : 0;

// --- Sauver le manifest ---
$manifest = [
    'jobId' => $jobId,
    'dateCreation' => date('c'),
    'total' => $total,
    'succeeded' => $succeeded,
    'failed' => $failed,
    'scoreMoyen' => $scoreMoyenGlobal,
    'urls' => $resultats,
    'templates' => $templatesFormatted,
];
file_put_contents(
    $jobDir . '/manifest.json',
    json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

// --- Generer le CSV recap ---
$csvPath = $jobDir . '/summary.csv';
$fp = fopen($csvPath, 'w');
fwrite($fp, "\xEF\xBB\xBF"); // BOM UTF-8
fputcsv($fp, ['URL', 'Score', 'Identiques', 'Modifiees', 'JS seul', 'Supprimees', 'Template', 'Risque', 'HTTP'], ';');
foreach ($resultats as $r) {
    fputcsv($fp, [
        $r['url'],
        $r['score'] ?? '',
        $r['compteurs']['identique'] ?? 0,
        $r['compteurs']['modifie'] ?? 0,
        $r['compteurs']['js_seul'] ?? 0,
        $r['compteurs']['supprime'] ?? 0,
        $r['template'],
        $r['risqueGlobal'],
        $r['httpCode'] ?? '',
    ], ';');
}
fclose($fp);

// --- Envoyer le resultat final ---
sseEvent('bulk_done', [
    'jobId' => $jobId,
    'total' => $total,
    'succeeded' => $succeeded,
    'failed' => $failed,
    'scoreMoyen' => $scoreMoyenGlobal,
    'templates' => $templatesFormatted,
    'csvUrl' => 'data/jobs/' . $jobId . '/summary.csv',
]);
