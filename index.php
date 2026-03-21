<?php

/**
 * JS Rendering Checker — Comparaison HTML brut vs rendu JS
 *
 * Interface : formulaire URL, progression SSE, resultats comparatifs.
 */

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JS Rendering Checker — HTML brut vs rendu JS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="styles.css" rel="stylesheet">
</head>
<body>

<!-- Navbar (supprimee automatiquement en mode embedded) -->
<nav class="navbar mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <span class="navbar-brand mb-0 h1">
            <i class="bi bi-filetype-html"></i> <span data-i18n="nav.titre">JS Rendering Checker</span>
            <span class="d-block d-sm-inline ms-sm-2" data-i18n="nav.soustitre">HTML brut vs rendu JS</span>
        </span>
        <?php if (!defined('PLATFORM_EMBEDDED')): ?>
        <select id="lang-select" class="form-select form-select-sm" style="width:auto; background-color:rgba(255,255,255,0.15); color:#fff; border-color:rgba(255,255,255,0.3); font-size:0.8rem;">
            <option value="fr">FR</option>
            <option value="en">EN</option>
        </select>
        <?php endif; ?>
    </div>
</nav>

<div class="container-fluid pb-5 px-lg-4">

    <!-- Alerte erreurs -->
    <div id="alerteErreurs" class="alert alert-danger mb-4" style="display: none;">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <span id="alerteErreursTexte"></span>
    </div>

    <div class="row g-4">

    <!-- Colonne configuration -->
    <div class="col-lg-8" id="colConfig">

    <!-- Card Configuration -->
    <div class="card mb-4" id="cardFormulaire">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold" data-i18n="form.titre_config"><i class="bi bi-gear me-2"></i>Configuration</h6>
            <button type="button" class="config-toggle" data-bs-toggle="collapse" data-bs-target="#configBody" aria-expanded="true"><i class="bi bi-chevron-down"></i></button>
        </div>
        <div class="collapse show" id="configBody">
            <div class="card-body">
                <form id="formAnalyse" method="POST" onsubmit="return false;">

                    <!-- Toggle mode -->
                    <div class="btn-group mb-3" role="group" id="modeToggle">
                        <input type="radio" class="btn-check" name="mode" id="modeSingle" value="single" checked>
                        <label class="btn btn-outline-secondary btn-sm" for="modeSingle" data-i18n="form.mode_single">URL unique</label>
                        <input type="radio" class="btn-check" name="mode" id="modeBulk" value="bulk">
                        <label class="btn btn-outline-secondary btn-sm" for="modeBulk" data-i18n="form.mode_bulk">Multi-URL</label>
                    </div>

                    <!-- URL unique -->
                    <div class="mb-3" id="singleUrlSection">
                        <label for="urlInput" class="form-label">
                            <span data-i18n="form.label_url">URL a analyser</span>
                        </label>
                        <input type="url" class="form-control" id="urlInput" name="url"
                               data-i18n-placeholder="form.placeholder_url" placeholder="https://example.com/page">
                    </div>

                    <!-- Multi-URL -->
                    <div class="mb-3" id="bulkUrlSection" style="display: none;">
                        <label for="urlsBulk" class="form-label">
                            <span data-i18n="form.label_urls_bulk">URLs a analyser</span>
                            <span class="text-muted small">(une par ligne, max 50)</span>
                        </label>
                        <textarea class="form-control font-monospace" id="urlsBulk" name="urls" rows="6"
                                  data-i18n-placeholder="form.placeholder_urls_bulk"
                                  placeholder="https://example.com/page1&#10;https://example.com/page2&#10;https://example.com/page3"></textarea>
                        <div class="mt-2">
                            <label class="form-label small text-muted" data-i18n="form.ou_csv">ou importer un fichier CSV</label>
                            <input type="file" class="form-control form-control-sm" id="csvUpload" accept=".csv,.txt" style="max-width: 300px;">
                        </div>
                        <div class="small text-muted mt-1" id="bulkUrlCount"></div>
                    </div>

                    <!-- Options avancees (collapse) -->
                    <div class="mb-3">
                        <a class="text-decoration-none small fw-600" data-bs-toggle="collapse" href="#optionsAvancees" role="button" aria-expanded="false" aria-controls="optionsAvancees">
                            <i class="bi bi-sliders me-1"></i> <span data-i18n="form.options_avancees">Options avancees</span>
                            <i class="bi bi-chevron-down ms-1" style="font-size: 0.7rem;"></i>
                        </a>
                        <div class="collapse mt-2" id="optionsAvancees">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label form-label-sm" data-i18n="form.label_ua">User-Agent</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="ua_type" id="uaSmartphone" value="smartphone" checked>
                                        <label class="form-check-label small" for="uaSmartphone" data-i18n="form.ua_smartphone">Chrome Mobile (recommande)</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="ua_type" id="uaDesktop" value="desktop">
                                        <label class="form-check-label small" for="uaDesktop" data-i18n="form.ua_desktop">Chrome Desktop</label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label for="timeoutJs" class="form-label form-label-sm" data-i18n="form.label_timeout">Timeout JS (secondes)</label>
                                    <input type="number" class="form-control form-control-sm" id="timeoutJs" name="timeout" value="10" min="3" max="30" style="width: 100px;">
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="screenshots" id="checkScreenshots" value="1">
                                        <label class="form-check-label small" for="checkScreenshots" data-i18n="form.screenshots">Screenshots comparatifs</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bouton analyser -->
                    <div class="d-flex align-items-center gap-2">
                        <button type="submit" class="btn btn-primary" id="btnAnalyser">
                            <i class="bi bi-play-fill me-1"></i> <span data-i18n="btn.analyser">Analyser</span>
                        </button>
                        <span class="text-muted small" id="raccourciHint" data-i18n="form.raccourci_hint">Ctrl+Entree pour lancer</span>
                    </div>

                    <!-- Barre de progression inline -->
                    <div id="inlineProgress" class="mt-3" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="fw-semibold" id="inlineProgressLabel" style="color: var(--brand-dark);"></small>
                            <small class="text-muted" id="inlineProgressPct">0%</small>
                        </div>
                        <div class="progress" style="height: 6px; border-radius: 3px;">
                            <div class="progress-bar" id="inlineProgressBar" role="progressbar" style="width: 0%; background: var(--brand-teal); transition: width 0.3s;"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Info raw-only -->
    <div id="infoRawOnly" class="alert mb-4" style="display: none; background: var(--brand-teal-light); border: 1px solid var(--brand-teal); border-radius: 8px; color: var(--brand-dark);">
        <i class="bi bi-info-circle me-2"></i>
        <span id="infoRawOnlyTexte"></span>
    </div>

    </div><!-- /.colConfig -->

    <!-- Panneau d'aide -->
    <div class="col-lg-4" id="helpPanel">
        <div id="platformCreditsSlot" class="mb-3"></div>
        <div class="config-help-panel">
            <div class="help-title mb-2" data-i18n="help.titre_comment">
                <i class="bi bi-info-circle me-1"></i> Comment ca marche
            </div>
            <ul>
                <li data-i18n="help.etape1">Saisissez l'URL de la page a analyser.</li>
                <li data-i18n="help.etape2">L'outil recupere le <strong>HTML brut</strong> (comme Googlebot au crawl).</li>
                <li data-i18n="help.etape3">Puis le <strong>HTML rendu</strong> apres execution JavaScript (comme le WRS de Google).</li>
                <li data-i18n="help.etape4">Les zones SEO critiques sont comparees avec un niveau de risque.</li>
            </ul>
            <hr>
            <div class="help-title mb-2" data-i18n="help.titre_zones">
                <i class="bi bi-layers me-1"></i> Zones analysees
            </div>
            <ul>
                <li data-i18n="help.zone_title">Title, Meta description, Canonical</li>
                <li data-i18n="help.zone_robots">Meta robots, Hreflang</li>
                <li data-i18n="help.zone_headings">Headings (H1, H2, H3)</li>
                <li data-i18n="help.zone_jsonld">Donnees structurees (JSON-LD)</li>
                <li data-i18n="help.zone_liens">Liens internes/externes, Images</li>
                <li data-i18n="help.zone_og">Open Graph, Twitter Cards</li>
            </ul>
            <hr>
            <div class="help-title mb-2" data-i18n="help.titre_quota">
                <i class="bi bi-speedometer2 me-1"></i> Quota
            </div>
            <ul class="mb-0">
                <li data-i18n="help.quota_credit">1 analyse = <strong>1 credit</strong></li>
            </ul>
        </div>
    </div><!-- /.col-lg-4 -->

    </div><!-- /.row -->

    <!-- Resultats (pleine largeur) -->
    <div id="resultats" style="display: none;">

        <!-- KPI Row -->
        <div class="row g-3 mb-4" id="kpiRow">
            <div class="col-6 col-md-3">
                <div class="kpi-card" id="kpiScore">
                    <div class="kpi-value" id="kpiScoreValeur">—</div>
                    <div class="kpi-label" data-i18n="kpi.score">Score</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card kpi-green">
                    <div class="kpi-value" id="kpiIdentiques">0</div>
                    <div class="kpi-label" data-i18n="kpi.identiques">Identiques</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card">
                    <div class="kpi-value" id="kpiModifiees" style="color: var(--score-mid);">0</div>
                    <div class="kpi-label" data-i18n="kpi.modifiees">Modifiees</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="kpi-card kpi-red">
                    <div class="kpi-value" id="kpiJsSeul">0</div>
                    <div class="kpi-label" data-i18n="kpi.js_seul">JS seul</div>
                </div>
            </div>
        </div>

        <!-- Hash SHA-256 (visible si 0 differences) -->
        <div id="hashBlock" class="mb-4" style="display: none;">
            <div class="card">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center gap-2 small">
                        <i class="bi bi-shield-check" style="color: var(--score-high); font-size: 1.1rem;"></i>
                        <span class="fw-600" data-i18n="hash.identique">HTML brut et rendu identiques</span>
                    </div>
                    <div class="mt-1" style="font-family: 'SF Mono', monospace; font-size: 11px; color: var(--text-muted);">
                        <div>SHA-256 brut : <code id="hashBrutValeur"></code></div>
                        <div>SHA-256 rendu : <code id="hashRenduValeur"></code></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglets resultats -->
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <ul class="nav nav-tabs mb-0" id="onglets" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-resume" data-bs-toggle="tab" data-bs-target="#panel-resume" type="button" role="tab" data-i18n="tab.resume">Resume</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-structurees" data-bs-toggle="tab" data-bs-target="#panel-structurees" type="button" role="tab" data-i18n="tab.structurees">Donnees structurees</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-source" data-bs-toggle="tab" data-bs-target="#panel-source" type="button" role="tab" data-i18n="tab.source">HTML source</button>
                        </li>
                        <li class="nav-item" role="presentation" id="tabScreenshotsLi" style="display: none;">
                            <button class="nav-link" id="tab-screenshots" data-bs-toggle="tab" data-bs-target="#panel-screenshots" type="button" role="tab" data-i18n="tab.screenshots">Screenshots</button>
                        </li>
                    </ul>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCopierResume" title="Copier le resume">
                            <i class="bi bi-clipboard me-1"></i> <span data-i18n="btn.copier">Copier</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExportCsv" title="Exporter en CSV">
                            <i class="bi bi-download me-1"></i> CSV
                        </button>
                    </div>
                </div>
            </div>

            <div class="tab-content">

                <!-- Onglet Resume -->
                <div class="tab-pane fade show active" id="panel-resume" role="tabpanel">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="tableComparaison">
                                <thead>
                                    <tr>
                                        <th data-i18n="table.zone">Zone</th>
                                        <th data-i18n="table.html_brut">HTML brut</th>
                                        <th data-i18n="table.html_rendu">HTML rendu</th>
                                        <th data-i18n="table.statut">Statut</th>
                                        <th data-i18n="table.risque">Risque</th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyComparaison"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recommandations -->
                    <div id="blocRecommandations" class="card-body border-top" style="display: none;">
                        <h6 class="fw-bold mb-3" data-i18n="reco.titre"><i class="bi bi-lightbulb me-2"></i>Recommandations</h6>
                        <div id="listeRecommandations"></div>
                    </div>
                </div>

                <!-- Onglet Donnees structurees -->
                <div class="tab-pane fade" id="panel-structurees" role="tabpanel">
                    <div class="card-body" id="contenuStructurees">
                        <p class="text-muted small" data-i18n="structurees.vide">Aucune donnee structuree detectee.</p>
                    </div>
                </div>

                <!-- Onglet HTML source — Diff -->
                <div class="tab-pane fade" id="panel-source" role="tabpanel">
                    <div class="card-body">
                        <!-- Toolbar diff -->
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="small" id="diffCompteur"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDiffPrev" disabled>
                                    <i class="bi bi-chevron-up"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDiffNext" disabled>
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-copier-source" data-cible="brut">
                                    <i class="bi bi-clipboard me-1"></i> <span data-i18n="source.copier_brut">Copier brut</span>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary btn-copier-source" data-cible="rendu">
                                    <i class="bi bi-clipboard me-1"></i> <span data-i18n="source.copier_rendu">Copier rendu</span>
                                </button>
                            </div>
                        </div>
                        <!-- Diff panels -->
                        <div class="diff-wrapper" id="diffWrapper">
                            <div class="diff-header">
                                <div class="diff-header-col" data-i18n="source.brut">HTML brut</div>
                                <div class="diff-header-col" data-i18n="source.rendu">HTML rendu</div>
                            </div>
                            <div class="diff-body" id="diffBody">
                                <div class="diff-panel" id="diffPanelBrut"></div>
                                <div class="diff-panel" id="diffPanelRendu"></div>
                            </div>
                        </div>
                        <div id="diffLoading" class="text-center text-muted small py-3" style="display: none;">
                            <span class="spinner-border spinner-border-sm me-1"></span> <span data-i18n="source.chargement">Chargement du HTML...</span>
                        </div>
                    </div>
                </div>

                <!-- Onglet Screenshots -->
                <div class="tab-pane fade" id="panel-screenshots" role="tabpanel">
                    <div class="card-body">
                        <!-- Mode selector -->
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary active" id="btnModeCote" data-i18n="screenshots.mode_cote">Cote a cote</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnModeSlider" data-i18n="screenshots.mode_slider">Slider</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnModeDiff" data-i18n="screenshots.mode_diff">Differences</button>
                        </div>

                        <!-- Mode Cote a cote -->
                        <div id="screenshotModeCote">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="fw-bold small mb-2"><i class="bi bi-code-slash me-1"></i> <span data-i18n="screenshots.brut">Sans JavaScript</span></div>
                                    <div id="screenshotBrutContainer" class="screenshot-container"></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="fw-bold small mb-2"><i class="bi bi-play-fill me-1"></i> <span data-i18n="screenshots.rendu">Avec JavaScript</span></div>
                                    <div id="screenshotRenduContainer" class="screenshot-container"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Mode Slider -->
                        <div id="screenshotModeSlider" style="display: none;">
                            <div class="screenshot-slider-wrapper" id="sliderWrapper">
                                <div class="slider-img-container" id="sliderContainer">
                                    <img id="sliderImgRendu" class="slider-img-back" src="" alt="">
                                    <div class="slider-img-clip" id="sliderClip">
                                        <img id="sliderImgBrut" class="slider-img-front" src="" alt="">
                                    </div>
                                    <div class="slider-handle" id="sliderHandle">
                                        <div class="slider-handle-line"></div>
                                        <div class="slider-handle-grip"><i class="bi bi-arrows"></i></div>
                                        <div class="slider-handle-line"></div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <span class="small fw-600 text-muted" data-i18n="screenshots.brut">Sans JavaScript</span>
                                    <span class="small fw-600 text-muted" data-i18n="screenshots.rendu">Avec JavaScript</span>
                                </div>
                            </div>
                        </div>

                        <!-- Mode Diff -->
                        <div id="screenshotModeDiff" style="display: none;">
                            <div class="mb-2">
                                <span class="small text-muted" data-i18n="screenshots.diff_legende">Les zones en rouge indiquent les differences entre les deux rendus.</span>
                            </div>
                            <div class="screenshot-container" id="screenshotDiffContainer"></div>
                            <canvas id="canvasDiff" style="display: none;"></canvas>
                            <canvas id="canvasBrut" style="display: none;"></canvas>
                            <canvas id="canvasRendu" style="display: none;"></canvas>
                        </div>
                    </div>
                </div>

            </div><!-- /.tab-content -->
        </div>

    </div><!-- /#resultats -->

    <!-- Resultats Bulk (pleine largeur) -->
    <div id="resultatsBulk" style="display: none;">

        <!-- Bulk KPI Row -->
        <div class="row g-3 mb-4" id="bulkKpiRow">
            <div class="col-4 col-md-2">
                <div class="kpi-card kpi-teal">
                    <div class="kpi-value" id="bulkKpiTotal">0</div>
                    <div class="kpi-label" data-i18n="bulk.kpi_total">URLs</div>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="kpi-card">
                    <div class="kpi-value" id="bulkKpiScoreMoyen">—</div>
                    <div class="kpi-label" data-i18n="bulk.kpi_score_moyen">Score moyen</div>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="kpi-card kpi-red">
                    <div class="kpi-value" id="bulkKpiCritiques">0</div>
                    <div class="kpi-label" data-i18n="bulk.kpi_critiques">Critiques</div>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="kpi-card">
                    <div class="kpi-value" id="bulkKpiJsDependant" style="color: var(--score-mid);">0</div>
                    <div class="kpi-label" data-i18n="bulk.kpi_js_dependent">JS-dependent</div>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="kpi-card">
                    <div class="kpi-value" id="bulkKpiTemplates">0</div>
                    <div class="kpi-label" data-i18n="bulk.kpi_templates">Templates</div>
                </div>
            </div>
            <div class="col-4 col-md-2">
                <div class="kpi-card">
                    <div class="kpi-value" id="bulkKpiErreurs" style="color: var(--text-muted);">0</div>
                    <div class="kpi-label" data-i18n="bulk.kpi_erreurs">Erreurs</div>
                </div>
            </div>
        </div>

        <!-- Onglets Bulk -->
        <div class="card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <ul class="nav nav-tabs mb-0" id="ongletsBulk" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-bulk-urls" data-bs-toggle="tab" data-bs-target="#panel-bulk-urls" type="button" role="tab" data-i18n="bulk.tab_urls">URLs</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-bulk-templates" data-bs-toggle="tab" data-bs-target="#panel-bulk-templates" type="button" role="tab" data-i18n="bulk.tab_templates">Templates</button>
                        </li>
                    </ul>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnExportBulkCsv">
                        <i class="bi bi-download me-1"></i> CSV
                    </button>
                </div>
            </div>

            <div class="tab-content">
                <!-- URLs summary table -->
                <div class="tab-pane fade show active" id="panel-bulk-urls" role="tabpanel">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0" id="tableBulk">
                                <thead>
                                    <tr>
                                        <th data-i18n="bulk.col_url">URL</th>
                                        <th style="width:80px;" data-i18n="bulk.col_score">Score</th>
                                        <th style="width:60px;" title="Identiques">Id.</th>
                                        <th style="width:60px;" title="Modifiees">Mod.</th>
                                        <th style="width:60px;" title="JS seul">JS</th>
                                        <th data-i18n="bulk.col_template">Template</th>
                                        <th style="width:80px;" data-i18n="bulk.col_risque">Risque</th>
                                        <th style="width:50px;"></th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyBulk"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Template grouping -->
                <div class="tab-pane fade" id="panel-bulk-templates" role="tabpanel">
                    <div class="card-body" id="templateGrouping"></div>
                </div>
            </div>
        </div>

        <!-- Detail d'une URL (charge dynamiquement) -->
        <div id="bulkDetailWrapper" style="display: none;">
            <div class="d-flex align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRetourBulk">
                    <i class="bi bi-arrow-left me-1"></i> <span data-i18n="bulk.retour">Retour a la liste</span>
                </button>
                <span class="small text-muted" id="bulkDetailUrl"></span>
            </div>
            <div id="bulkDetailContent"></div>
        </div>
    </div>

    <!-- Progression Bulk -->
    <div id="progressionBulkWrapper" class="card mb-4" style="display: none;">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold small" data-i18n="bulk.progress_titre">Analyse multi-URL en cours</span>
                <span class="small text-muted" id="bulkProgressPct">0%</span>
            </div>
            <div class="progress mb-3" style="height: 8px;">
                <div class="progress-bar" id="bulkProgressBar" role="progressbar" style="width: 0%; background: var(--brand-teal);"></div>
            </div>
            <div id="bulkProgressLog" class="small" style="max-height: 200px; overflow-y: auto;"></div>
        </div>
    </div>

    <!-- Raw-only : zones SEO brutes (pleine largeur) -->
    <div id="resultatsRawOnly" style="display: none;">
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0 fw-bold" data-i18n="raw.titre"><i class="bi bi-code-slash me-2"></i>Zones SEO detectees (HTML brut)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="tableRawOnly">
                        <thead>
                            <tr>
                                <th data-i18n="table.zone">Zone</th>
                                <th data-i18n="table.valeur">Valeur</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyRawOnly"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Modale detail de zone -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold" id="modalDetailTitre"></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <!-- Badges statut/risque -->
                <div class="d-flex gap-2 mb-3" id="modalDetailBadges"></div>

                <!-- Deux colonnes : brut vs rendu -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small" data-i18n="source.brut">HTML brut</span>
                            <span class="badge bg-secondary" id="modalCompteurBrut"></span>
                        </div>
                        <textarea class="form-control font-monospace detail-textarea" id="modalTexteBrut" readonly rows="18"></textarea>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small" data-i18n="source.rendu">HTML rendu</span>
                            <span class="badge bg-secondary" id="modalCompteurRendu"></span>
                        </div>
                        <textarea class="form-control font-monospace detail-textarea" id="modalTexteRendu" readonly rows="18"></textarea>
                    </div>
                </div>

                <!-- Elements uniquement dans un cote -->
                <div id="modalDiffSection" class="mt-3" style="display: none;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div id="modalUniqueBrut" style="display: none;">
                                <div class="fw-bold small mb-1" style="color: var(--score-low);" data-i18n="detail.unique_brut">Uniquement dans HTML brut (supprime par JS)</div>
                                <div class="detail-diff-list" id="modalListeUniqueBrut"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div id="modalUniqueRendu" style="display: none;">
                                <div class="fw-bold small mb-1" style="color: var(--score-low);" data-i18n="detail.unique_rendu">Uniquement dans HTML rendu (ajoute par JS)</div>
                                <div class="detail-diff-list" id="modalListeUniqueRendu"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="translations.js"></script>
<script src="app.js"></script>
</body>
</html>
