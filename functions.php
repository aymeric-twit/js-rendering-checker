<?php

/**
 * JS Rendering Checker — Logique metier
 *
 * Fonctions de fetch (brut/rendu), extraction des zones SEO,
 * comparaison et scoring.
 */

const CHROME_MOBILE_UA = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
const CHROME_DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

const TAILLE_MAX_HTML = 5 * 1024 * 1024; // 5 Mo

const MAX_URL_LENGTH = 2083;

// Domaines bloques pour simuler le WRS de Google (ads/analytics)
// Domaines tiers optionnels (NON bloques par defaut — le WRS de Google ne les bloque pas)
// Reserve pour une future option "Bloquer les scripts tiers"
const DOMAINES_TIERS_OPTIONNELS = [
    'google-analytics.com', 'googletagmanager.com', 'doubleclick.net',
    'googlesyndication.com', 'facebook.net', 'connect.facebook.net',
    'hotjar.com', 'clarity.ms', 'analytics.tiktok.com',
];

// --- Niveaux de risque par zone si js_seul ou modifie ---
const RISQUES_ZONES = [
    'canonical'            => ['js_seul' => 'critique', 'modifie' => 'critique'],
    'meta_robots'          => ['js_seul' => 'critique', 'modifie' => 'critique'],
    'hreflang'             => ['js_seul' => 'critique', 'modifie' => 'haut'],
    'title'                => ['js_seul' => 'haut',     'modifie' => 'moyen'],
    'donnees_structurees'  => ['js_seul' => 'haut',     'modifie' => 'moyen'],
    'h1'                   => ['js_seul' => 'haut',     'modifie' => 'moyen'],
    'nombre_mots'          => ['js_seul' => 'haut',     'modifie' => 'moyen'],
    'liens_internes'       => ['js_seul' => 'moyen',    'modifie' => 'faible'],
    'meta_description'     => ['js_seul' => 'moyen',    'modifie' => 'faible'],
    'h2'                   => ['js_seul' => 'moyen',    'modifie' => 'faible'],
    'h3'                   => ['js_seul' => 'moyen',    'modifie' => 'faible'],
    'images'               => ['js_seul' => 'faible',   'modifie' => 'faible'],
    'liens_externes'       => ['js_seul' => 'faible',   'modifie' => 'faible'],
    'og_tags'              => ['js_seul' => 'faible',   'modifie' => 'faible'],
    'twitter_tags'         => ['js_seul' => 'faible',   'modifie' => 'faible'],
    'meta_refresh'         => ['js_seul' => 'haut',     'modifie' => 'haut'],
    // x_robots_tag : header HTTP, pas de comparaison JS (informatif uniquement)
];

// --- Points soustraits du score par zone/statut (recalibre v2.1) ---
const PENALITES_SCORE = [
    'canonical'            => ['js_seul' => 25, 'modifie' => 15, 'supprime' => 25],
    'meta_robots'          => ['js_seul' => 25, 'modifie' => 15, 'supprime' => 25],
    'hreflang'             => ['js_seul' => 15, 'modifie' => 8,  'supprime' => 15],
    'title'                => ['js_seul' => 12, 'modifie' => 4,  'supprime' => 12],
    'donnees_structurees'  => ['js_seul' => 12, 'modifie' => 4,  'supprime' => 12],
    'h1'                   => ['js_seul' => 8,  'modifie' => 3,  'supprime' => 8],
    'nombre_mots'          => ['js_seul' => 5,  'modifie' => 0,  'supprime' => 5],
    'liens_internes'       => ['js_seul' => 5,  'modifie' => 2,  'supprime' => 5],
    'meta_description'     => ['js_seul' => 3,  'modifie' => 1,  'supprime' => 3],
    'h2'                   => ['js_seul' => 2,  'modifie' => 1,  'supprime' => 2],
    'h3'                   => ['js_seul' => 1,  'modifie' => 0,  'supprime' => 1],
    'images'               => ['js_seul' => 1,  'modifie' => 0,  'supprime' => 1],
    'liens_externes'       => ['js_seul' => 1,  'modifie' => 0,  'supprime' => 1],
    'og_tags'              => ['js_seul' => 1,  'modifie' => 0,  'supprime' => 1],
    'twitter_tags'         => ['js_seul' => 1,  'modifie' => 0,  'supprime' => 1],
    'meta_refresh'         => ['js_seul' => 8,  'modifie' => 4,  'supprime' => 8],
    // x_robots_tag : pas de penalite (header HTTP, non modifiable par JS)
];


/**
 * Valide une URL (anti-SSRF).
 */
function valider_url(string $url): bool
{
    if (strlen($url) > MAX_URL_LENGTH) {
        return false;
    }

    $url = trim($url);
    if ($url === '') {
        return false;
    }

    $parties = parse_url($url);
    if ($parties === false || !isset($parties['scheme'], $parties['host'])) {
        return false;
    }

    if (!in_array(strtolower($parties['scheme']), ['http', 'https'], true)) {
        return false;
    }

    $hote = strtolower($parties['host']);

    // Refuse localhost et variantes
    if (in_array($hote, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
        return false;
    }

    // Refuse les IPs privees
    $ip = gethostbyname($hote);
    if ($ip !== $hote) {
        $filtres = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $filtres) === false) {
            return false;
        }
    }

    return true;
}


function sanitiser_erreur(string $message): string
{
    $message = preg_replace('/token=[a-zA-Z0-9_-]+/', 'token=***', $message);
    $message = preg_replace('/https?:\/\/production-[a-z]+\.browserless\.io[^\s"]*/', '[Browserless API]', $message);
    return mb_substr($message, 0, 500);
}


function nettoyer_fichiers_expires(string $repertoire, int $ttlSecondes = 86400): int
{
    $compteur = 0;
    $maintenant = time();
    if (!is_dir($repertoire)) return 0;

    $items = new DirectoryIterator($repertoire);
    foreach ($items as $item) {
        if ($item->isDot()) continue;
        $age = $maintenant - $item->getMTime();
        if ($age <= $ttlSecondes) continue;

        if ($item->isDir()) {
            $sousItems = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($sousItems as $sousItem) {
                $sousItem->isDir() ? rmdir($sousItem->getPathname()) : unlink($sousItem->getPathname());
            }
            rmdir($item->getPathname());
            $compteur++;
        } else {
            unlink($item->getPathname());
            $compteur++;
        }
    }
    return $compteur;
}


/**
 * Recupere le HTML brut via Browserless /function avec JS desactive.
 * Fallback cURL si pas de cle API Browserless.
 *
 * @return array{status: string, html?: string, httpCode?: int, headers?: array<string,string>, urlFinale?: string, taille?: int, error?: string}
 */
function fetch_brut(string $url, string $ua = CHROME_MOBILE_UA): array
{
    // --- Branche plateforme : utiliser ApiClient centralise ---
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\ApiClient')) {
        $config = \Platform\Http\HttpConfig::charger();
        $baseUrl = $config->browserlessUrl;
        $token = $config->browserlessToken;
        $timeout = $config->browserlessTimeout;

        if (empty($token)) {
            return fetch_brut_curl($url, $ua);
        }

        $jsCode = 'export default async ({ page }) => {'
            . 'await page.setJavaScriptEnabled(false);'
            . 'const res = await page.goto(' . json_encode($url) . ', { waitUntil: "networkidle2", timeout: 30000 });'
            . 'const html = await page.content();'
            . 'const status = res ? res.status() : 0;'
            . 'const finalUrl = page.url();'
            . 'return { data: { html, status, finalUrl }, type: "application/json" };'
            . '};';

        $apiUrl = rtrim($baseUrl, '/') . '/chromium/function'
            . '?timeout=' . ($timeout * 1000)
            . '&token=' . urlencode($token);

        $client = new \Platform\Http\ApiClient('render-checker');
        $reponse = $client->postRaw($apiUrl, $jsCode, ['Content-Type' => 'application/javascript']);

        if (!$reponse->estSucces()) {
            $extrait = mb_substr($reponse->body, 0, 300);
            return ['status' => 'error', 'error' => 'Browserless function HTTP ' . $reponse->statusCode . ' — ' . $extrait];
        }

        $data = $reponse->json();
        $html = $data['data']['html'] ?? $data['html'] ?? null;
        if (empty($html)) {
            $extrait = mb_substr($reponse->body, 0, 500);
            return ['status' => 'error', 'error' => 'Reponse Browserless invalide — ' . $extrait];
        }
        if (strlen($html) > TAILLE_MAX_HTML) {
            return ['status' => 'error', 'error' => 'HTML brut trop volumineux (> 5 Mo)'];
        }

        return [
            'status'    => 'ok',
            'html'      => $html,
            'httpCode'  => $data['data']['status'] ?? $data['status'] ?? 200,
            'headers'   => [],
            'urlFinale' => $data['data']['finalUrl'] ?? $data['finalUrl'] ?? $url,
            'taille'    => strlen($html),
        ];
    }

    // --- Branche standalone : code curl existant ---
    $cleApi = getenv('BROWSERLESS_API_KEY');

    // Fallback cURL si pas de cle Browserless
    if (empty($cleApi)) {
        return fetch_brut_curl($url, $ua);
    }

    // Code Puppeteer : desactiver JS, naviguer, recuperer le HTML
    $jsCode = 'export default async ({ page }) => {'
        . 'await page.setJavaScriptEnabled(false);'
        . 'const res = await page.goto(' . json_encode($url) . ', { waitUntil: "networkidle2", timeout: 30000 });'
        . 'const html = await page.content();'
        . 'const status = res ? res.status() : 0;'
        . 'const finalUrl = page.url();'
        . 'return { data: { html, status, finalUrl }, type: "application/json" };'
        . '};';

    $apiUrl = 'https://production-lon.browserless.io/chromium/function'
        . '?timeout=60000'
        . '&token=' . urlencode($cleApi);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 65,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/javascript'],
        CURLOPT_POSTFIELDS     => $jsCode,
    ]);

    $reponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    if ($reponse === false || $erreur !== '') {
        return ['status' => 'error', 'error' => 'Browserless function cURL : ' . $erreur];
    }

    if ($httpCode !== 200) {
        $extrait = mb_substr((string) $reponse, 0, 300);
        return ['status' => 'error', 'error' => 'Browserless function HTTP ' . $httpCode . ' — ' . $extrait];
    }

    $data = json_decode($reponse, true);

    // /function retourne {"data": {"html": "..."}} (imbriqué)
    $html = $data['data']['html'] ?? $data['html'] ?? null;
    if (empty($html)) {
        $extrait = mb_substr((string) $reponse, 0, 500);
        return ['status' => 'error', 'error' => 'Reponse Browserless invalide — ' . $extrait];
    }
    if (strlen($html) > TAILLE_MAX_HTML) {
        return ['status' => 'error', 'error' => 'HTML brut trop volumineux (> 5 Mo)'];
    }

    return [
        'status'    => 'ok',
        'html'      => $html,
        'httpCode'  => $data['data']['status'] ?? $data['status'] ?? 200,
        'headers'   => [],
        'urlFinale' => $data['data']['finalUrl'] ?? $data['finalUrl'] ?? $url,
        'taille'    => strlen($html),
    ];
}


/**
 * Fallback : fetch brut via cURL direct (sans Browserless).
 *
 * @return array{status: string, html?: string, httpCode?: int, headers?: array<string,string>, urlFinale?: string, taille?: int, error?: string}
 */
function fetch_brut_curl(string $url, string $ua): array
{
    // --- Branche plateforme : utiliser WebClient centralise ---
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\WebClient')) {
        $webClient = new \Platform\Http\WebClient('render-checker');
        $reponse = $webClient->fetch($url);

        if ($reponse->erreur !== null && $reponse->erreur !== '') {
            return ['status' => 'error', 'error' => 'WebClient : ' . $reponse->erreur];
        }

        $html = $reponse->body;
        if (strlen($html) > TAILLE_MAX_HTML) {
            return ['status' => 'error', 'error' => 'HTML trop volumineux (> 5 Mo)'];
        }

        return [
            'status'    => 'ok',
            'html'      => $html,
            'httpCode'  => $reponse->statusCode,
            'headers'   => $reponse->headers,
            'urlFinale' => $reponse->urlFinale ?? $url,
            'taille'    => strlen($html),
        ];
    }

    // --- Branche standalone : code curl existant ---
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HEADER         => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $reponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $urlFinale = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $tailleHeader = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $erreur = curl_error($ch);
    curl_close($ch);

    if ($reponse === false || $erreur !== '') {
        return ['status' => 'error', 'error' => 'cURL : ' . $erreur];
    }

    $headersTexte = substr($reponse, 0, $tailleHeader);
    $html = substr($reponse, $tailleHeader);

    if (strlen($html) > TAILLE_MAX_HTML) {
        return ['status' => 'error', 'error' => 'HTML trop volumineux (> 5 Mo)'];
    }

    $headers = [];
    foreach (explode("\r\n", $headersTexte) as $ligne) {
        if (str_contains($ligne, ':')) {
            [$cle, $valeur] = explode(':', $ligne, 2);
            $headers[strtolower(trim($cle))] = trim($valeur);
        }
    }

    return [
        'status'    => 'ok',
        'html'      => $html,
        'httpCode'  => $httpCode,
        'headers'   => $headers,
        'urlFinale' => $urlFinale,
        'taille'    => strlen($html),
    ];
}


/**
 * Capture un screenshot via Browserless /screenshot (JS active) ou /function (JS desactive).
 *
 * @return array{status: string, base64?: string, error?: string}
 */
function capturer_screenshot(string $url, bool $avecJs = true): array
{
    // --- Branche plateforme : utiliser ApiClient centralise ---
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\ApiClient')) {
        $config = \Platform\Http\HttpConfig::charger();
        $baseUrl = $config->browserlessUrl;
        $token = $config->browserlessToken;
        $timeout = $config->browserlessTimeout;

        if (empty($token)) {
            return ['status' => 'unavailable'];
        }

        $urlJs = json_encode($url);
        $jsEnabled = $avecJs ? 'true' : 'false';

        $jsCode = 'export default async ({ page }) => {'
            . 'await page.setViewport({ width: 1280, height: 800 });'
            . 'await page.setJavaScriptEnabled(' . $jsEnabled . ');'
            . 'await page.goto(' . $urlJs . ', { waitUntil: "networkidle2", timeout: 30000 });'
            . ($avecJs ? 'await new Promise(r => setTimeout(r, 3000));' : '')
            . 'await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));'
            . 'await new Promise(r => setTimeout(r, 1000));'
            . 'await page.evaluate(() => window.scrollTo(0, 0));'
            . 'const buf = await page.screenshot({ fullPage: true, type: "png" });'
            . 'return { data: buf, type: "image/png" };'
            . '};';

        $apiUrl = rtrim($baseUrl, '/') . '/chromium/function'
            . '?timeout=' . ($timeout * 1000)
            . '&token=' . urlencode($token);

        $client = new \Platform\Http\ApiClient('render-checker');
        $reponse = $client->postRaw($apiUrl, $jsCode, ['Content-Type' => 'application/javascript']);

        if (!$reponse->estSucces() || $reponse->body === '') {
            return ['status' => 'error', 'error' => 'Screenshot brut HTTP ' . $reponse->statusCode];
        }

        $body = $reponse->body;

        // La reponse /function est du JSON : {"data": {"0": 137, "1": 80, ...}} (Buffer serialise)
        if (str_starts_with(trim($body), '{')) {
            $json = json_decode($body, true);
            $data = $json['data'] ?? null;

            if (is_array($data)) {
                ksort($data, SORT_NUMERIC);
                $values = array_values($data);
                $bin = '';
                foreach ($values as $byte) {
                    $bin .= chr((int) $byte);
                }
                $body = $bin;
            } elseif (is_string($data) && str_contains($data, ',')) {
                $bytes = array_map('intval', explode(',', $data));
                $body = pack('C*', ...$bytes);
            }
        }

        if (strlen($body) < 8 || substr($body, 0, 4) !== chr(137) . 'PNG') {
            return ['status' => 'error', 'error' => 'Screenshot sans JS : format invalide'];
        }

        return ['status' => 'ok', 'base64' => base64_encode($body)];
    }

    // --- Branche standalone : code curl existant ---
    $cleApi = getenv('BROWSERLESS_API_KEY');
    if (empty($cleApi)) {
        return ['status' => 'unavailable'];
    }

    // Les deux modes utilisent /function pour avoir fullPage + viewport controle
    $urlJs = json_encode($url);
    $jsEnabled = $avecJs ? 'true' : 'false';

    $jsCode = 'export default async ({ page }) => {'
        . 'await page.setViewport({ width: 1280, height: 800 });'
        . 'await page.setJavaScriptEnabled(' . $jsEnabled . ');'
        . 'await page.goto(' . $urlJs . ', { waitUntil: "networkidle2", timeout: 30000 });'
        . ($avecJs ? 'await new Promise(r => setTimeout(r, 3000));' : '')
        . 'await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));'
        . 'await new Promise(r => setTimeout(r, 1000));'
        . 'await page.evaluate(() => window.scrollTo(0, 0));'
        . 'const buf = await page.screenshot({ fullPage: true, type: "png" });'
        . 'return { data: buf, type: "image/png" };'
        . '};';

    $apiUrl = 'https://production-lon.browserless.io/chromium/function'
        . '?timeout=60000'
        . '&token=' . urlencode($cleApi);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 65,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/javascript'],
        CURLOPT_POSTFIELDS     => $jsCode,
    ]);

    $reponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($reponse)) {
        return ['status' => 'error', 'error' => 'Screenshot brut HTTP ' . $httpCode];
    }

    // La reponse /function est du JSON : {"data": {"0": 137, "1": 80, ...}} (Buffer serialise)
    if (str_starts_with(trim($reponse), '{')) {
        $json = json_decode($reponse, true);
        $data = $json['data'] ?? null;

        if (is_array($data)) {
            // Objet avec cles numeriques {"0": 137, "1": 80, ...}
            ksort($data, SORT_NUMERIC);
            $values = array_values($data);
            $bin = '';
            foreach ($values as $byte) {
                $bin .= chr((int) $byte);
            }
            $reponse = $bin;
        } elseif (is_string($data) && str_contains($data, ',')) {
            // CSV string "137,80,78,71,..."
            $bytes = array_map('intval', explode(',', $data));
            $reponse = pack('C*', ...$bytes);
        }
    }

    // Verifier que c'est bien un PNG (magic bytes: 137 80 78 71 = \x89PNG)
    if (strlen($reponse) < 8 || substr($reponse, 0, 4) !== chr(137) . 'PNG') {
        return ['status' => 'error', 'error' => 'Screenshot sans JS : format invalide'];
    }

    return ['status' => 'ok', 'base64' => base64_encode($reponse)];
}


/**
 * Recupere le HTML rendu via Browserless API.
 *
 * @return array{status: string, html?: string, tempsRendu?: int, error?: string}
 */
function fetch_rendu(string $url, string $ua = CHROME_MOBILE_UA, int $timeout = 7): array
{
    // --- Branche plateforme : utiliser ApiClient centralise ---
    if (defined('PLATFORM_EMBEDDED') && class_exists('\\Platform\\Http\\ApiClient')) {
        $config = \Platform\Http\HttpConfig::charger();
        $baseUrl = $config->browserlessUrl;
        $token = $config->browserlessToken;
        $blTimeout = $config->browserlessTimeout;

        if (empty($token)) {
            return ['status' => 'unavailable', 'error' => 'Cle API Browserless non configuree'];
        }

        $payload = [
            'url'            => $url,
            'gotoOptions'    => ['waitUntil' => 'networkidle2'],
            'waitForTimeout' => $timeout * 1000,
            'bestAttempt'    => true,
        ];

        $apiUrl = rtrim($baseUrl, '/') . '/chromium/content'
            . '?blockAds=false'
            . '&timeout=' . (($timeout + 15) * 1000)
            . '&token=' . urlencode($token);

        $client = new \Platform\Http\ApiClient('render-checker');
        $debut = microtime(true);
        $reponse = $client->postJson($apiUrl, $payload);
        $temps = (int) round((microtime(true) - $debut) * 1000);

        if (!$reponse->estSucces() || $reponse->body === '') {
            $extrait = mb_substr($reponse->body, 0, 300);
            return ['status' => 'error', 'error' => 'Browserless HTTP ' . $reponse->statusCode . ' — ' . $extrait];
        }

        $html = $reponse->body;
        if (strlen($html) > TAILLE_MAX_HTML) {
            return ['status' => 'error', 'error' => 'HTML rendu trop volumineux (> 5 Mo)'];
        }

        return ['status' => 'ok', 'html' => $html, 'tempsRendu' => $temps];
    }

    // --- Branche standalone : WRS simulation via /function ---
    $cleApi = getenv('BROWSERLESS_API_KEY');
    if (empty($cleApi)) {
        return ['status' => 'unavailable', 'error' => 'Cle API Browserless non configuree'];
    }

    $urlJs = json_encode($url);

    // Simulation fidele du WRS Google : pas de blocage de ressources,
    // networkidle2, timeout court (5-10s comme Google)
    $jsCode = 'export default async ({ page }) => {'
        . 'const res = await page.goto(' . $urlJs . ', { waitUntil: "networkidle2", timeout: ' . ($timeout * 1000) . ' });'
        . 'await new Promise(r => setTimeout(r, 2000));'
        . 'const html = await page.content();'
        . 'return { data: { html, status: res ? res.status() : 0 }, type: "application/json" };'
        . '};';

    $apiUrl = 'https://production-lon.browserless.io/chromium/function'
        . '?timeout=' . (($timeout + 10) * 1000)
        . '&token=' . urlencode($cleApi);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout + 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/javascript'],
        CURLOPT_POSTFIELDS     => $jsCode,
    ]);

    $debut = microtime(true);
    $reponse = curl_exec($ch);
    $temps = (int) round((microtime(true) - $debut) * 1000);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    if ($reponse === false || $erreur !== '') {
        return ['status' => 'error', 'error' => sanitiser_erreur('Browserless cURL : ' . $erreur)];
    }

    if ($httpCode !== 200 || $reponse === '') {
        $extrait = mb_substr((string) $reponse, 0, 300);
        return ['status' => 'error', 'error' => sanitiser_erreur('Browserless HTTP ' . $httpCode . ' — ' . $extrait)];
    }

    $data = json_decode($reponse, true);
    $html = $data['data']['html'] ?? $data['html'] ?? null;

    if (empty($html)) {
        return ['status' => 'error', 'error' => 'Reponse Browserless invalide'];
    }

    if (strlen($html) > TAILLE_MAX_HTML) {
        return ['status' => 'error', 'error' => 'HTML rendu trop volumineux (> 5 Mo)'];
    }

    return ['status' => 'ok', 'html' => $html, 'tempsRendu' => $temps];
}


/**
 * Parse un document HTML et cree un DOMDocument + DOMXPath.
 *
 * @return array{doc: DOMDocument, xpath: DOMXPath}
 */
function creer_dom(string $html): array
{
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);

    return ['doc' => $doc, 'xpath' => $xpath];
}


/**
 * Extrait toutes les zones SEO d'un document HTML.
 */
function extraire_zones_seo(string $html, string $urlBase = ''): array
{
    $dom = creer_dom($html);
    $doc = $dom['doc'];
    $xpath = $dom['xpath'];

    $resultat = [
        'title'               => '',
        'meta_description'    => '',
        'canonical'           => '',
        'meta_robots'         => '',
        'h1'                  => [],
        'h2'                  => [],
        'h3'                  => [],
        'donnees_structurees' => [],
        'liens_internes'      => [],
        'liens_externes'      => [],
        'images'              => [],
        'og_tags'             => [],
        'twitter_tags'        => [],
        'hreflang'            => [],
        'meta_refresh'        => null,
        'x_robots_tag'        => '',
        'nombre_mots'         => 0,
        'texte_extrait'       => '',
    ];

    // Title
    $titles = $doc->getElementsByTagName('title');
    if ($titles->length > 0) {
        $resultat['title'] = trim($titles->item(0)->textContent);
    }

    // Meta tags
    $metas = $doc->getElementsByTagName('meta');
    foreach ($metas as $meta) {
        $name = strtolower($meta->getAttribute('name'));
        $property = strtolower($meta->getAttribute('property'));
        $content = $meta->getAttribute('content');

        if ($name === 'description') {
            $resultat['meta_description'] = trim($content);
        } elseif ($name === 'robots') {
            $resultat['meta_robots'] = trim($content);
        } elseif (str_starts_with($property, 'og:')) {
            $resultat['og_tags'][$property] = trim($content);
        } elseif (str_starts_with($name, 'twitter:') || str_starts_with($property, 'twitter:')) {
            $cle = $name ?: $property;
            $resultat['twitter_tags'][$cle] = trim($content);
        }
    }

    // Meta refresh
    $resultat['meta_refresh'] = extraire_meta_refresh($doc);

    // Canonical + hreflang
    $links = $doc->getElementsByTagName('link');
    foreach ($links as $link) {
        $rel = strtolower($link->getAttribute('rel'));
        if ($rel === 'canonical') {
            $resultat['canonical'] = trim($link->getAttribute('href'));
        } elseif ($rel === 'alternate' && $link->getAttribute('hreflang') !== '') {
            $resultat['hreflang'][] = [
                'lang' => $link->getAttribute('hreflang'),
                'href' => $link->getAttribute('href'),
            ];
        }
    }

    // Headings
    foreach (['h1', 'h2', 'h3'] as $tag) {
        $nodes = $doc->getElementsByTagName($tag);
        foreach ($nodes as $node) {
            $texte = trim($node->textContent);
            if ($texte !== '') {
                $resultat[$tag][] = $texte;
            }
        }
    }

    // JSON-LD
    $resultat['donnees_structurees'] = extraire_jsonld($doc);

    // Liens
    $hoteBase = parse_url($urlBase, PHP_URL_HOST) ?? '';
    $anchors = $doc->getElementsByTagName('a');
    foreach ($anchors as $a) {
        $href = trim($a->getAttribute('href'));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            continue;
        }

        $ancre = trim($a->textContent);
        $rel = strtolower($a->getAttribute('rel'));
        $nofollow = str_contains($rel, 'nofollow');

        $lienHote = parse_url($href, PHP_URL_HOST);
        $estInterne = ($lienHote === null || $lienHote === '' || $lienHote === $hoteBase);

        $donnees = ['href' => $href, 'ancre' => mb_substr($ancre, 0, 200), 'nofollow' => $nofollow];

        if ($estInterne) {
            $resultat['liens_internes'][] = $donnees;
        } else {
            $resultat['liens_externes'][] = $donnees;
        }
    }

    // Images
    $resultat['images'] = extraire_images($doc);

    // Supprimer les <noscript> et <script> avant extraction du texte
    foreach (['noscript', 'script', 'style'] as $tagASupprimer) {
        $nodes = $doc->getElementsByTagName($tagASupprimer);
        $aSupprimer = [];
        foreach ($nodes as $node) {
            $aSupprimer[] = $node;
        }
        foreach ($aSupprimer as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Texte du body
    $body = $doc->getElementsByTagName('body');
    if ($body->length > 0) {
        $texteComplet = trim($body->item(0)->textContent);
        $texteComplet = preg_replace('/\s+/', ' ', $texteComplet);
        $mots = preg_split('/\s+/', $texteComplet, -1, PREG_SPLIT_NO_EMPTY);
        $resultat['nombre_mots'] = count($mots);
        $resultat['texte_extrait'] = mb_substr($texteComplet, 0, 500);
    }

    return $resultat;
}


/**
 * Extrait les blocs JSON-LD.
 *
 * @return array<int, array{type: string, json: string}>
 */
function extraire_jsonld(DOMDocument $doc): array
{
    $resultats = [];
    $scripts = $doc->getElementsByTagName('script');

    foreach ($scripts as $script) {
        if (strtolower($script->getAttribute('type')) === 'application/ld+json') {
            $contenu = trim($script->textContent);
            if ($contenu === '') {
                continue;
            }

            $decode = json_decode($contenu, true);
            $type = '(inconnu)';
            if (is_array($decode)) {
                $type = $decode['@type'] ?? ($decode['@graph'][0]['@type'] ?? '(inconnu)');
            }

            $resultats[] = [
                'type' => $type,
                'json' => $contenu,
            ];
        }
    }

    return $resultats;
}


/**
 * Extrait les images avec src, alt, et detection lazy.
 *
 * @return array<int, array{src: string, alt: string, lazy: bool}>
 */
function extraire_images(DOMDocument $doc): array
{
    $resultats = [];
    $imgs = $doc->getElementsByTagName('img');

    foreach ($imgs as $img) {
        $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
        $alt = trim($img->getAttribute('alt'));
        $lazy = $img->getAttribute('loading') === 'lazy'
             || $img->getAttribute('data-src') !== '';

        if ($src !== '') {
            $resultats[] = ['src' => $src, 'alt' => $alt, 'lazy' => $lazy];
        }
    }

    return $resultats;
}


function extraire_meta_refresh(DOMDocument $doc): ?array
{
    $metas = $doc->getElementsByTagName('meta');
    foreach ($metas as $meta) {
        $httpEquiv = strtolower($meta->getAttribute('http-equiv'));
        if ($httpEquiv === 'refresh') {
            $content = trim($meta->getAttribute('content'));
            $delai = 0;
            $urlCible = null;
            if (preg_match('/^(\d+)\s*;?\s*url\s*=\s*(.+)$/i', $content, $m)) {
                $delai = (int) $m[1];
                $urlCible = trim($m[2], " '\"");
            } elseif (ctype_digit($content)) {
                $delai = (int) $content;
            }
            return ['delai' => $delai, 'url' => $urlCible, 'content' => $content];
        }
    }
    return null;
}


/**
 * Compare les zones SEO du HTML brut vs rendu.
 *
 * @return array<string, array{statut: string, risque: string, brut: mixed, rendu: mixed}>
 */
function comparer_zones(array $brut, array $rendu): array
{
    $comparaison = [];

    // Zones textuelles simples
    $zonesTexte = ['title', 'meta_description', 'canonical', 'meta_robots'];
    foreach ($zonesTexte as $zone) {
        $comparaison[$zone] = comparer_valeur_texte($brut[$zone], $rendu[$zone], $zone);
    }

    // Zones tableaux (headings)
    foreach (['h1', 'h2', 'h3'] as $zone) {
        $comparaison[$zone] = comparer_valeur_tableau($brut[$zone], $rendu[$zone], $zone);
    }

    // Donnees structurees (comparaison par nombre et types)
    $comparaison['donnees_structurees'] = comparer_donnees_structurees($brut['donnees_structurees'], $rendu['donnees_structurees']);

    // Liens internes (comparaison par nombre)
    $comparaison['liens_internes'] = comparer_nombre($brut['liens_internes'], $rendu['liens_internes'], 'liens_internes');

    // Liens externes
    $comparaison['liens_externes'] = comparer_nombre($brut['liens_externes'], $rendu['liens_externes'], 'liens_externes');

    // Images
    $comparaison['images'] = comparer_nombre($brut['images'], $rendu['images'], 'images');

    // Nombre de mots
    $comparaison['nombre_mots'] = comparer_nombre_mots($brut['nombre_mots'], $rendu['nombre_mots']);

    // OG tags
    $comparaison['og_tags'] = comparer_valeur_tableau_assoc($brut['og_tags'], $rendu['og_tags'], 'og_tags');

    // Twitter tags
    $comparaison['twitter_tags'] = comparer_valeur_tableau_assoc($brut['twitter_tags'], $rendu['twitter_tags'], 'twitter_tags');

    // Hreflang
    $comparaison['hreflang'] = comparer_hreflang($brut['hreflang'] ?? [], $rendu['hreflang'] ?? []);

    // Meta refresh
    $comparaison['meta_refresh'] = comparer_meta_refresh($brut['meta_refresh'], $rendu['meta_refresh']);

    // X-Robots-Tag (if available in headers)
    if (isset($brut['x_robots_tag']) || isset($rendu['x_robots_tag'])) {
        $comparaison['x_robots_tag'] = comparer_valeur_texte(
            $brut['x_robots_tag'] ?? '',
            $rendu['x_robots_tag'] ?? '',
            'x_robots_tag'
        );
    }

    return $comparaison;
}


/**
 * Compare une valeur texte brut vs rendu.
 */
function comparer_valeur_texte(string $brut, string $rendu, string $zone): array
{
    if ($brut === '' && $rendu === '') {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut === '' && $rendu !== '') {
        return ['statut' => 'js_seul', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'moyen', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut !== '' && $rendu === '') {
        return ['statut' => 'supprime', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'moyen', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut === $rendu) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    return ['statut' => 'modifie', 'risque' => RISQUES_ZONES[$zone]['modifie'] ?? 'faible', 'brut' => $brut, 'rendu' => $rendu];
}


/**
 * Compare des tableaux de valeurs (headings).
 */
function comparer_valeur_tableau(array $brut, array $rendu, string $zone): array
{
    if (empty($brut) && empty($rendu)) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }
    if (empty($brut) && !empty($rendu)) {
        return ['statut' => 'js_seul', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'moyen', 'brut' => $brut, 'rendu' => $rendu];
    }
    if (!empty($brut) && empty($rendu)) {
        return ['statut' => 'supprime', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'moyen', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut === $rendu) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    return ['statut' => 'modifie', 'risque' => RISQUES_ZONES[$zone]['modifie'] ?? 'faible', 'brut' => $brut, 'rendu' => $rendu];
}


/**
 * Compare des tableaux associatifs (OG, Twitter).
 */
function comparer_valeur_tableau_assoc(array $brut, array $rendu, string $zone): array
{
    if (empty($brut) && empty($rendu)) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }
    if (empty($brut) && !empty($rendu)) {
        return ['statut' => 'js_seul', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'faible', 'brut' => $brut, 'rendu' => $rendu];
    }
    if (!empty($brut) && empty($rendu)) {
        return ['statut' => 'supprime', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'faible', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut === $rendu) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    return ['statut' => 'modifie', 'risque' => RISQUES_ZONES[$zone]['modifie'] ?? 'faible', 'brut' => $brut, 'rendu' => $rendu];
}


/**
 * Compare des listes par nombre (liens, images).
 */
function comparer_nombre(array $brut, array $rendu, string $zone): array
{
    $nBrut = count($brut);
    $nRendu = count($rendu);

    if ($nBrut === 0 && $nRendu === 0) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $nBrut, 'rendu' => $nRendu];
    }
    if ($nBrut === 0 && $nRendu > 0) {
        return ['statut' => 'js_seul', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'moyen', 'brut' => $nBrut, 'rendu' => $nRendu];
    }
    if ($nBrut > 0 && $nRendu === 0) {
        return ['statut' => 'supprime', 'risque' => RISQUES_ZONES[$zone]['js_seul'] ?? 'moyen', 'brut' => $nBrut, 'rendu' => $nRendu];
    }
    if ($nBrut === $nRendu) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $nBrut, 'rendu' => $nRendu];
    }

    return ['statut' => 'modifie', 'risque' => RISQUES_ZONES[$zone]['modifie'] ?? 'faible', 'brut' => $nBrut, 'rendu' => $nRendu];
}


/**
 * Compare le nombre de mots.
 */
function comparer_nombre_mots(int $brut, int $rendu): array
{
    if ($brut === 0 && $rendu === 0) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut === 0 && $rendu > 0) {
        return ['statut' => 'js_seul', 'risque' => 'haut', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut > 0 && $rendu === 0) {
        return ['statut' => 'supprime', 'risque' => 'haut', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brut === $rendu) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    // Seuil de 30% : les petites variations (menus JS, footers) ne penalisent pas
    $variation = abs($rendu - $brut) / max($brut, 1);
    if ($variation < 0.30) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    return ['statut' => 'modifie', 'risque' => 'moyen', 'brut' => $brut, 'rendu' => $rendu];
}


/**
 * Compare les donnees structurees.
 */
function comparer_donnees_structurees(array $brut, array $rendu): array
{
    $nBrut = count($brut);
    $nRendu = count($rendu);

    if ($nBrut === 0 && $nRendu === 0) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu, 'nombre_brut' => 0, 'nombre_rendu' => 0];
    }
    if ($nBrut === 0 && $nRendu > 0) {
        return ['statut' => 'js_seul', 'risque' => 'haut', 'brut' => $brut, 'rendu' => $rendu, 'nombre_brut' => 0, 'nombre_rendu' => $nRendu];
    }
    if ($nBrut > 0 && $nRendu === 0) {
        return ['statut' => 'supprime', 'risque' => 'haut', 'brut' => $brut, 'rendu' => $rendu, 'nombre_brut' => $nBrut, 'nombre_rendu' => 0];
    }

    // Comparer les types
    $typesBrut = array_map(fn($s) => $s['type'], $brut);
    $typesRendu = array_map(fn($s) => $s['type'], $rendu);
    sort($typesBrut);
    sort($typesRendu);

    if ($typesBrut === $typesRendu && $nBrut === $nRendu) {
        // Memes types, comparer le contenu JSON
        $jsonsBrut = array_map(fn($s) => $s['json'], $brut);
        $jsonsRendu = array_map(fn($s) => $s['json'], $rendu);
        sort($jsonsBrut);
        sort($jsonsRendu);

        if ($jsonsBrut === $jsonsRendu) {
            return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu, 'nombre_brut' => $nBrut, 'nombre_rendu' => $nRendu];
        }
    }

    return ['statut' => 'modifie', 'risque' => 'moyen', 'brut' => $brut, 'rendu' => $rendu, 'nombre_brut' => $nBrut, 'nombre_rendu' => $nRendu];
}


function comparer_hreflang(array $brut, array $rendu): array
{
    if (empty($brut) && empty($rendu)) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    // Normaliser : trier par lang
    $norm = function (array $items): array {
        $result = [];
        foreach ($items as $item) {
            $result[$item['lang']] = $item['href'];
        }
        ksort($result);
        return $result;
    };

    $brutNorm = $norm($brut);
    $renduNorm = $norm($rendu);

    if (empty($brutNorm) && !empty($renduNorm)) {
        return ['statut' => 'js_seul', 'risque' => RISQUES_ZONES['hreflang']['js_seul'] ?? 'critique', 'brut' => $brut, 'rendu' => $rendu];
    }
    if (!empty($brutNorm) && empty($renduNorm)) {
        return ['statut' => 'supprime', 'risque' => RISQUES_ZONES['hreflang']['js_seul'] ?? 'critique', 'brut' => $brut, 'rendu' => $rendu];
    }
    if ($brutNorm === $renduNorm) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    return ['statut' => 'modifie', 'risque' => RISQUES_ZONES['hreflang']['modifie'] ?? 'haut', 'brut' => $brut, 'rendu' => $rendu];
}


function comparer_meta_refresh(?array $brut, ?array $rendu): array
{
    if ($brut === null && $rendu === null) {
        return ['statut' => 'absent', 'risque' => '', 'brut' => null, 'rendu' => null];
    }
    if ($brut === null && $rendu !== null) {
        return ['statut' => 'js_seul', 'risque' => RISQUES_ZONES['meta_refresh']['js_seul'] ?? 'haut', 'brut' => null, 'rendu' => $rendu];
    }
    if ($brut !== null && $rendu === null) {
        return ['statut' => 'supprime', 'risque' => RISQUES_ZONES['meta_refresh']['js_seul'] ?? 'haut', 'brut' => $brut, 'rendu' => null];
    }
    if ($brut['content'] === $rendu['content']) {
        return ['statut' => 'identique', 'risque' => '', 'brut' => $brut, 'rendu' => $rendu];
    }

    return ['statut' => 'modifie', 'risque' => RISQUES_ZONES['meta_refresh']['modifie'] ?? 'haut', 'brut' => $brut, 'rendu' => $rendu];
}


function detecter_template(string $url): string
{
    $parties = parse_url($url);
    $chemin = $parties['path'] ?? '/';
    $chemin = rtrim($chemin, '/');
    if ($chemin === '') $chemin = '/';

    $segments = explode('/', trim($chemin, '/'));
    $segmentsNormalises = [];

    foreach ($segments as $segment) {
        if ($segment === '') continue;

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)) {
            $segmentsNormalises[] = '{uuid}';
        } elseif (ctype_digit($segment)) {
            $segmentsNormalises[] = '{id}';
        } elseif (preg_match('/^[0-9a-f]{8,}$/i', $segment)) {
            $segmentsNormalises[] = '{hash}';
        } elseif (preg_match('/^.+-\d+$/', $segment)) {
            $segmentsNormalises[] = '{slug}';
        } elseif (preg_match('/^\d{4}[-\/]?\d{2}[-\/]?\d{2}$/', $segment)) {
            $segmentsNormalises[] = '{date}';
        } elseif (preg_match('/^.+\.(html|htm|php|asp)$/i', $segment)) {
            $segmentsNormalises[] = '{page}';
        } else {
            $segmentsNormalises[] = $segment;
        }
    }

    return '/' . implode('/', $segmentsNormalises);
}


function analyser_url_complete(string $url, string $ua, int $timeout): array
{
    $brutResultat = fetch_brut($url, $ua);
    if ($brutResultat['status'] !== 'ok') {
        return ['status' => 'error', 'error' => $brutResultat['error'] ?? 'Erreur fetch brut', 'url' => $url];
    }

    $renduResultat = fetch_rendu($url, $ua, $timeout);
    $modeRawOnly = ($renduResultat['status'] !== 'ok');

    $zonesBrut = extraire_zones_seo($brutResultat['html'], $url);

    // Propager x_robots_tag depuis headers HTTP
    if (!empty($brutResultat['headers']['x-robots-tag'])) {
        $zonesBrut['x_robots_tag'] = $brutResultat['headers']['x-robots-tag'];
    }

    $zonesRendu = null;
    $comparaison = null;
    $score = null;
    $recommandations = null;
    $compteurs = ['identique' => 0, 'modifie' => 0, 'js_seul' => 0, 'supprime' => 0, 'absent' => 0];

    if (!$modeRawOnly) {
        $zonesRendu = extraire_zones_seo($renduResultat['html'], $url);
        $comparaison = comparer_zones($zonesBrut, $zonesRendu);
        $score = calculer_score($comparaison);
        $recommandations = generer_recommandations($comparaison);

        foreach ($comparaison as $donnees) {
            $compteurs[$donnees['statut']] = ($compteurs[$donnees['statut']] ?? 0) + 1;
        }
    }

    return [
        'status'          => 'ok',
        'url'             => $url,
        'modeRawOnly'     => $modeRawOnly,
        'httpCode'        => $brutResultat['httpCode'],
        'tailleBrut'      => $brutResultat['taille'],
        'tempsRendu'      => $renduResultat['tempsRendu'] ?? null,
        'zonesBrut'       => $zonesBrut,
        'zonesRendu'      => $zonesRendu,
        'comparaison'     => $comparaison,
        'score'           => $score,
        'recommandations' => $recommandations,
        'compteurs'       => $compteurs,
        'htmlBrut'        => $brutResultat['html'],
        'htmlRendu'       => $renduResultat['html'] ?? null,
        'template'        => detecter_template($url),
    ];
}


/**
 * Calcule le score global (0-100).
 */
function calculer_score(array $comparaison): int
{
    $score = 100;

    foreach ($comparaison as $zone => $donnees) {
        $statut = $donnees['statut'];
        if (isset(PENALITES_SCORE[$zone][$statut])) {
            $score -= PENALITES_SCORE[$zone][$statut];
        }
    }

    return max(0, min(100, $score));
}


/**
 * Genere des recommandations textuelles actionables.
 *
 * @return array<int, array{zone: string, risque: string, message_fr: string, message_en: string}>
 */
function generer_recommandations(array $comparaison): array
{
    $recommandations = [];

    $messages = [
        'canonical' => [
            'js_seul'  => [
                'fr' => 'Canonical injecte par JavaScript — risque d\'indexation avec un canonical incorrect ou absent au crawl.',
                'en' => 'Canonical injected by JavaScript — risk of indexation with incorrect or missing canonical at crawl time.',
            ],
            'modifie'  => [
                'fr' => 'Canonical modifie par JavaScript — Google utilise en priorite le canonical du HTML brut.',
                'en' => 'Canonical modified by JavaScript — Google primarily uses the canonical from raw HTML.',
            ],
            'supprime' => [
                'fr' => 'Canonical present dans le HTML brut mais supprime par JavaScript.',
                'en' => 'Canonical present in raw HTML but removed by JavaScript.',
            ],
        ],
        'meta_robots' => [
            'js_seul'  => [
                'fr' => 'Meta robots injecte par JavaScript — la directive peut etre ignoree par Google au crawl initial.',
                'en' => 'Meta robots injected by JavaScript — the directive may be ignored by Google at initial crawl.',
            ],
            'modifie'  => [
                'fr' => 'Meta robots modifie par JavaScript — comportement imprevisible selon le timing du rendu.',
                'en' => 'Meta robots modified by JavaScript — unpredictable behavior depending on render timing.',
            ],
            'supprime' => [
                'fr' => 'Meta robots present dans le HTML brut mais supprime par JavaScript.',
                'en' => 'Meta robots present in raw HTML but removed by JavaScript.',
            ],
        ],
        'title' => [
            'js_seul'  => [
                'fr' => 'Title genere par JavaScript — risque de title manquant ou generique dans les SERPs avant le rendu.',
                'en' => 'Title generated by JavaScript — risk of missing or generic title in SERPs before rendering.',
            ],
            'modifie'  => [
                'fr' => 'Title modifie par JavaScript — le title des SERPs peut varier selon le timing du rendu.',
                'en' => 'Title modified by JavaScript — SERP title may vary depending on render timing.',
            ],
        ],
        'donnees_structurees' => [
            'js_seul'  => [
                'fr' => 'Donnees structurees (JSON-LD) absentes du HTML brut — rich snippets non garantis avant le rendu.',
                'en' => 'Structured data (JSON-LD) missing from raw HTML — rich snippets not guaranteed before rendering.',
            ],
            'modifie'  => [
                'fr' => 'Donnees structurees modifiees par JavaScript — les schemas peuvent etre indexes avec retard.',
                'en' => 'Structured data modified by JavaScript — schemas may be indexed with delay.',
            ],
        ],
        'h1' => [
            'js_seul'  => [
                'fr' => 'H1 genere par JavaScript — signal de pertinence fort absent au crawl initial.',
                'en' => 'H1 generated by JavaScript — strong relevance signal missing at initial crawl.',
            ],
            'modifie'  => [
                'fr' => 'H1 modifie par JavaScript — le signal de pertinence peut etre altere.',
                'en' => 'H1 modified by JavaScript — the relevance signal may be altered.',
            ],
        ],
        'liens_internes' => [
            'js_seul'  => [
                'fr' => 'Liens internes generes uniquement par JavaScript — decouverte des pages retardee.',
                'en' => 'Internal links generated only by JavaScript — page discovery delayed.',
            ],
            'modifie'  => [
                'fr' => 'Liens internes modifies par JavaScript — certains liens decouvertes avec retard.',
                'en' => 'Internal links modified by JavaScript — some links discovered with delay.',
            ],
        ],
        'nombre_mots' => [
            'js_seul'  => [
                'fr' => 'Contenu textuel genere entierement par JavaScript — indexation du contenu retardee.',
                'en' => 'Text content generated entirely by JavaScript — content indexation delayed.',
            ],
            'modifie'  => [
                'fr' => 'Contenu textuel modifie par JavaScript — une partie du contenu indexee avec retard.',
                'en' => 'Text content modified by JavaScript — some content indexed with delay.',
            ],
        ],
        'meta_description' => [
            'js_seul'  => [
                'fr' => 'Meta description injectee par JavaScript — Google la reecrit souvent, mais mieux vaut la fournir en HTML brut.',
                'en' => 'Meta description injected by JavaScript — Google often rewrites it, but better to provide it in raw HTML.',
            ],
        ],
        'hreflang' => [
            'js_seul'  => [
                'fr' => 'Hreflang injecte par JavaScript — risque majeur pour le SEO international, Google peut ignorer les balises.',
                'en' => 'Hreflang injected by JavaScript — major risk for international SEO, Google may ignore the tags.',
            ],
            'modifie'  => [
                'fr' => 'Hreflang modifie par JavaScript — les correspondances linguistiques peuvent etre incorrectes au crawl.',
                'en' => 'Hreflang modified by JavaScript — language mappings may be incorrect at crawl time.',
            ],
        ],
        'x_robots_tag' => [
            'js_seul'  => [
                'fr' => 'X-Robots-Tag absent des headers HTTP mais present apres rendu — la directive sera ignoree.',
                'en' => 'X-Robots-Tag missing from HTTP headers but present after render — directive will be ignored.',
            ],
        ],
        'meta_refresh' => [
            'js_seul'  => [
                'fr' => 'Redirection meta refresh injectee par JavaScript — preferer une redirection HTTP 301.',
                'en' => 'Meta refresh redirect injected by JavaScript — prefer an HTTP 301 redirect.',
            ],
            'modifie'  => [
                'fr' => 'Redirection meta refresh modifiee par JavaScript — URL de destination differente.',
                'en' => 'Meta refresh redirect modified by JavaScript — different destination URL.',
            ],
        ],
        'h2' => [
            'js_seul'  => [
                'fr' => 'H2 generes par JavaScript — structure semantique absente au crawl initial.',
                'en' => 'H2 generated by JavaScript — semantic structure missing at initial crawl.',
            ],
        ],
        'h3' => [
            'js_seul'  => [
                'fr' => 'H3 generes par JavaScript — structure semantique secondaire absente au crawl.',
                'en' => 'H3 generated by JavaScript — secondary semantic structure missing at crawl.',
            ],
        ],
        'images' => [
            'js_seul'  => [
                'fr' => 'Images injectees par JavaScript — les images lazy-loaded sont generalement acceptees par Google.',
                'en' => 'Images injected by JavaScript — lazy-loaded images are generally accepted by Google.',
            ],
        ],
        'liens_externes' => [
            'js_seul'  => [
                'fr' => 'Liens externes generes par JavaScript — impact faible sur le SEO.',
                'en' => 'External links generated by JavaScript — low impact on SEO.',
            ],
        ],
        'og_tags' => [
            'js_seul'  => [
                'fr' => 'Balises Open Graph injectees par JavaScript — les reseaux sociaux peuvent ne pas les voir.',
                'en' => 'Open Graph tags injected by JavaScript — social networks may not see them.',
            ],
        ],
        'twitter_tags' => [
            'js_seul'  => [
                'fr' => 'Balises Twitter Cards injectees par JavaScript — Twitter peut ne pas les voir.',
                'en' => 'Twitter Card tags injected by JavaScript — Twitter may not see them.',
            ],
        ],
    ];

    foreach ($comparaison as $zone => $donnees) {
        $statut = $donnees['statut'];
        if (in_array($statut, ['identique', 'absent'], true)) {
            continue;
        }

        if (isset($messages[$zone][$statut])) {
            $recommandations[] = [
                'zone'       => $zone,
                'risque'     => $donnees['risque'],
                'message_fr' => $messages[$zone][$statut]['fr'],
                'message_en' => $messages[$zone][$statut]['en'],
            ];
        }
    }

    // Trier par risque (critique > haut > moyen > faible)
    $ordreRisque = ['critique' => 0, 'haut' => 1, 'moyen' => 2, 'faible' => 3];
    usort($recommandations, function ($a, $b) use ($ordreRisque) {
        return ($ordreRisque[$a['risque']] ?? 4) <=> ($ordreRisque[$b['risque']] ?? 4);
    });

    return $recommandations;
}
