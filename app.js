/**
 * JS Rendering Checker — Frontend
 *
 * SSE streaming, rendu des resultats, exports.
 */

/* ======================================================================
   i18n
   ====================================================================== */

var langueActuelle = (function () {
    if (window.PLATFORM_LANG) return window.PLATFORM_LANG;
    var params = new URLSearchParams(window.location.search);
    if (params.get('lang')) return params.get('lang');
    return localStorage.getItem('render-checker-lang') || 'fr';
})();

function t(cle, params) {
    var dict = TRANSLATIONS[langueActuelle] || TRANSLATIONS['fr'];
    var texte = (dict && dict[cle]) || (TRANSLATIONS['fr'] && TRANSLATIONS['fr'][cle]) || cle;
    if (params) {
        Object.keys(params).forEach(function (k) {
            texte = texte.replace('{' + k + '}', params[k]);
        });
    }
    return texte;
}

function traduirePage() {
    document.querySelectorAll('[data-i18n]').forEach(function (el) {
        var cle = el.getAttribute('data-i18n');
        el.innerHTML = t(cle);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
        var cle = el.getAttribute('data-i18n-placeholder');
        el.placeholder = t(cle);
    });
}

/* ======================================================================
   Elements DOM
   ====================================================================== */

var formAnalyse = document.getElementById('formAnalyse');
var urlInput = document.getElementById('urlInput');
var btnAnalyser = document.getElementById('btnAnalyser');
var btnToggleFormulaire = document.getElementById('btnToggleFormulaire');
var progressionWrapper = document.getElementById('progressionWrapper');
var progressBar = document.getElementById('progressBar');
var progressPct = document.getElementById('progressPct');
var progressSteps = document.getElementById('progressSteps');
var infoRawOnly = document.getElementById('infoRawOnly');
var infoRawOnlyTexte = document.getElementById('infoRawOnlyTexte');
var alerteErreurs = document.getElementById('alerteErreurs');
var alerteErreursTexte = document.getElementById('alerteErreursTexte');
var resultats = document.getElementById('resultats');
var resultatsRawOnly = document.getElementById('resultatsRawOnly');

// KPI
var kpiScoreValeur = document.getElementById('kpiScoreValeur');
var kpiScore = document.getElementById('kpiScore');
var kpiIdentiques = document.getElementById('kpiIdentiques');
var kpiModifiees = document.getElementById('kpiModifiees');
var kpiJsSeul = document.getElementById('kpiJsSeul');

// Tableaux
var tbodyComparaison = document.getElementById('tbodyComparaison');
var tbodyRawOnly = document.getElementById('tbodyRawOnly');
var blocRecommandations = document.getElementById('blocRecommandations');
var listeRecommandations = document.getElementById('listeRecommandations');
var contenuStructurees = document.getElementById('contenuStructurees');
// Diff HTML panels (pas de pre simples)

// Export
var btnCopierResume = document.getElementById('btnCopierResume');
var btnExportCsv = document.getElementById('btnExportCsv');

// Etat
var isRunning = false;
var abortController = null;
var derniersResultats = null;

/* ======================================================================
   Base URL (plateforme)
   ====================================================================== */

var BASE_URL = window.MODULE_BASE_URL || '.';

/* ======================================================================
   Init
   ====================================================================== */

traduirePage();

// Langue select (standalone)
var langSelect = document.getElementById('lang-select');
if (langSelect) {
    langSelect.value = langueActuelle;
    langSelect.addEventListener('change', function () {
        langueActuelle = this.value;
        localStorage.setItem('render-checker-lang', langueActuelle);
        traduirePage();
    });
}

// Ctrl+Enter
document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        if (!isRunning) formAnalyse.dispatchEvent(new Event('submit'));
    }
});

// Tooltips Bootstrap
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });

/* ======================================================================
   Formulaire — lancement de l'analyse
   ====================================================================== */

// Le handler submit est defini plus bas dans la section Mode Toggle

function lancerAnalyse(url) {
    isRunning = true;
    masquerErreur();
    masquerResultats();

    // Cacher le mode d'emploi
    var hpInner = document.querySelector('#helpPanel .config-help-panel');
    if (hpInner) hpInner.classList.add('help-hidden');

    // Afficher progression inline + panneau
    var inlineProgress = document.getElementById('inlineProgress');
    if (inlineProgress) inlineProgress.style.display = '';
    setInlineProgress(0, t('progress.init') || 'Initialisation...');
    progressionWrapper.style.display = '';
    setProgress(0, '');
    progressSteps.innerHTML = '';

    // Desactiver le bouton
    btnAnalyser.disabled = true;
    btnAnalyser.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + t('btn.analyser');

    // Construire FormData
    var formData = new FormData(formAnalyse);

    // CSRF (plateforme) — chercher dans le formulaire, puis globalement
    var csrfToken = '';
    var csrfEl = formAnalyse.querySelector('input[name="_csrf_token"]')
              || document.querySelector('input[name="_csrf_token"]');
    if (csrfEl) {
        csrfToken = csrfEl.value;
        formData.set('_csrf_token', csrfToken);
    }

    // SSE via fetch + ReadableStream
    abortController = new AbortController();

    fetch(BASE_URL + '/process.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
        body: formData,
        signal: abortController.signal,
    }).then(function (response) {
        if (response.status === 429) {
            throw new Error(t('error.quota_epuise'));
        }
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function pump() {
            return reader.read().then(function (result) {
                if (result.done) {
                    finirAnalyse();
                    return;
                }

                buffer += decoder.decode(result.value, { stream: true });
                var lines = buffer.split('\n');
                buffer = lines.pop();

                var currentEvent = '';
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i];
                    if (line.indexOf('event: ') === 0) {
                        currentEvent = line.substring(7).trim();
                    } else if (line.indexOf('data: ') === 0) {
                        var jsonStr = line.substring(6);
                        try {
                            var data = JSON.parse(jsonStr);
                            traiterEvenement(currentEvent, data);
                        } catch (err) {
                            // ignorer les erreurs de parsing
                        }
                        currentEvent = '';
                    }
                }

                return pump();
            });
        }

        return pump();
    }).catch(function (err) {
        if (err.name === 'AbortError') return;
        afficherErreur(err.message || t('error.analyse_echouee'));
        finirAnalyse();
    });
}

/* ======================================================================
   Traitement des evenements SSE
   ====================================================================== */

function setInlineProgress(pct, label) {
    var bar = document.getElementById('inlineProgressBar');
    var pctEl = document.getElementById('inlineProgressPct');
    var labelEl = document.getElementById('inlineProgressLabel');
    if (bar) bar.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    if (labelEl) labelEl.textContent = label || '';
}
function hideInlineProgress() {
    var el = document.getElementById('inlineProgress');
    if (el) el.style.display = 'none';
}

function traiterEvenement(event, data) {
    switch (event) {
        case 'progress':
            setProgress(data.pct || 0, langueActuelle === 'fr' ? data.message_fr : data.message_en);
            setInlineProgress(data.pct || 0, langueActuelle === 'fr' ? data.message_fr : data.message_en);
            ajouterStep(data);
            break;

        case 'phase':
            ajouterStepPhase(data);
            if (data.phase === 'screenshots') {
                if (data.screenshotBrut) screenshotData.brut = BASE_URL + '/' + data.screenshotBrut;
                if (data.screenshotRendu) screenshotData.rendu = BASE_URL + '/' + data.screenshotRendu;
            }
            break;

        case 'info':
            var msg = langueActuelle === 'fr' ? data.message_fr : data.message_en;
            infoRawOnly.style.display = '';
            infoRawOnlyTexte.textContent = msg;
            break;

        case 'done':
            derniersResultats = data;
            detailUrl = data.detailUrl || null;
            detailsComplets = null; // reset cache
            afficherResultats(data);
            break;

        case 'error':
            var errMsg = data.message || t('error.analyse_echouee');
            if (data.code === 429) errMsg = t('error.quota_epuise');
            afficherErreur(errMsg);
            break;
    }
}

/* ======================================================================
   Progression
   ====================================================================== */

function setProgress(pct, message) {
    progressBar.style.width = pct + '%';
    progressPct.textContent = pct + '%';
}

function ajouterStep(data) {
    var phase = data.phase || '';
    if (phase === 'done') return;

    var icone = '🔄';
    if (phase.indexOf('_done') !== -1 || phase === 'render_skip') icone = '✅';
    if (phase === 'render_error') icone = '⚠️';

    var msg = langueActuelle === 'fr' ? data.message_fr : data.message_en;
    var div = document.createElement('div');
    div.className = 'step-item';
    div.innerHTML = '<span class="step-icon">' + icone + '</span><span>' + escapeHtml(msg) + '</span>';
    progressSteps.appendChild(div);
}

function ajouterStepPhase(data) {
    var phase = data.phase;
    var msg = '';

    if (phase === 'raw') {
        msg = 'HTTP ' + data.httpCode + ' — ' + formaterTaille(data.taille);
    } else if (phase === 'render') {
        msg = (data.tempsRendu / 1000).toFixed(1) + 's via Browserless';
    }

    if (msg) {
        var div = document.createElement('div');
        div.className = 'step-item';
        div.innerHTML = '<span class="step-icon">✅</span><span class="text-muted">' + escapeHtml(msg) + '</span>';
        progressSteps.appendChild(div);
    }
}

function finirAnalyse() {
    isRunning = false;
    abortController = null;
    btnAnalyser.disabled = false;
    btnAnalyser.innerHTML = '<i class="bi bi-play-fill me-1"></i> ' + t('btn.analyser');
    hideInlineProgress();

    // Re-afficher le mode d'emploi
    var hpInner = document.querySelector('#helpPanel .config-help-panel');
    if (hpInner) hpInner.classList.remove('help-hidden');

    // Replier le formulaire et montrer le bouton toggle
    var collapseEl = document.getElementById('collapseFormulaire');
    if (collapseEl) {
        var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
        bsCollapse.hide();
    }
    btnToggleFormulaire.classList.remove('d-none');
}

/* ======================================================================
   Affichage des resultats
   ====================================================================== */

function masquerResultats() {
    resultats.style.display = 'none';
    resultatsRawOnly.style.display = 'none';
    document.getElementById('resultatsBulk').style.display = 'none';
    document.getElementById('progressionBulkWrapper').style.display = 'none';
    document.getElementById('bulkDetailWrapper').style.display = 'none';
    infoRawOnly.style.display = 'none';
    progressionWrapper.style.display = 'none';
    tbodyComparaison.innerHTML = '';
    tbodyRawOnly.innerHTML = '';
    listeRecommandations.innerHTML = '';
    contenuStructurees.innerHTML = '';
    document.getElementById('diffPanelBrut').innerHTML = '';
    document.getElementById('diffPanelRendu').innerHTML = '';
    document.getElementById('hashBlock').style.display = 'none';
    diffHtmlLoaded = false;
    document.getElementById('screenshotBrutContainer').innerHTML = '';
    document.getElementById('screenshotRenduContainer').innerHTML = '';
    document.getElementById('tabScreenshotsLi').style.display = 'none';
    screenshotData.brut = null;
    screenshotData.rendu = null;
}

function afficherResultats(data) {
    if (data.modeRawOnly) {
        afficherResultatsRawOnly(data);
        return;
    }

    resultats.style.display = '';

    // KPI
    var score = data.score !== null ? data.score : 0;
    kpiScoreValeur.textContent = score + '/100';
    colorerScore(kpiScore, score);
    kpiIdentiques.textContent = data.compteurs.identique || 0;
    kpiModifiees.textContent = data.compteurs.modifie || 0;
    kpiJsSeul.textContent = (data.compteurs.js_seul || 0) + (data.compteurs.supprime || 0);

    // Hash SHA-256
    var hashBlock = document.getElementById('hashBlock');
    if (data.hashBrut && data.hashRendu) {
        hashBlock.style.display = '';
        document.getElementById('hashBrutValeur').textContent = data.hashBrut;
        document.getElementById('hashRenduValeur').textContent = data.hashRendu;
        var hashIcone = hashBlock.querySelector('i');
        var hashTexte = hashBlock.querySelector('[data-i18n="hash.identique"]');
        if (data.hashBrut === data.hashRendu) {
            hashIcone.className = 'bi bi-shield-check';
            hashIcone.style.color = 'var(--score-high)';
            hashTexte.textContent = t('hash.identique');
        } else {
            hashIcone.className = 'bi bi-shield-exclamation';
            hashIcone.style.color = 'var(--score-low)';
            hashTexte.textContent = t('hash.different');
        }
    } else {
        hashBlock.style.display = 'none';
    }

    // Tableau comparaison
    afficherTableauComparaison(data.comparaison, data.zonesBrut, data.zonesRendu);

    // Recommandations
    afficherRecommandations(data.recommandations);

    // Donnees structurees
    afficherDonneesStructurees(data.zonesBrut, data.zonesRendu);

    // HTML source diff — charge en lazy quand l'onglet est clique
    initDiffTab();

    // Screenshots (deja recus via events 'screenshot' separés)
    if (screenshotData.brut || screenshotData.rendu) {
        document.getElementById('tabScreenshotsLi').style.display = '';
        afficherModeCote();
    }

    // Masquer le panneau d'aide
    var helpPanel = document.getElementById('helpPanel');
    if (helpPanel) { var _chp = helpPanel.querySelector('.config-help-panel'); if (_chp) _chp.classList.add('help-hidden'); };
}

function afficherResultatsRawOnly(data) {
    resultatsRawOnly.style.display = '';

    var zones = data.zonesBrut;
    var tbody = tbodyRawOnly;
    tbody.innerHTML = '';

    var lignes = [
        ['title', zones.title || ''],
        ['meta_description', zones.meta_description || ''],
        ['canonical', zones.canonical || ''],
        ['meta_robots', zones.meta_robots || ''],
        ['h1', (zones.h1 || []).join(', ') || ''],
        ['h2', (zones.h2 || []).length + ' element(s)'],
        ['h3', (zones.h3 || []).length + ' element(s)'],
        ['donnees_structurees', (zones.donnees_structurees || []).length + ' schema(s)'],
        ['liens_internes', (typeof zones.liens_internes === 'number' ? zones.liens_internes : (zones.liens_internes || []).length) + ' lien(s)'],
        ['liens_externes', (typeof zones.liens_externes === 'number' ? zones.liens_externes : (zones.liens_externes || []).length) + ' lien(s)'],
        ['images', (typeof zones.images === 'number' ? zones.images : (zones.images || []).length) + ' image(s)'],
        ['nombre_mots', zones.nombre_mots || 0],
    ];

    lignes.forEach(function (l) {
        var tr = document.createElement('tr');
        var valeur = l[1] || '';
        var classe = valeur && valeur !== '0' && valeur !== '0 schema(s)' && valeur !== '0 lien(s)' && valeur !== '0 image(s)' ? 'valeur-presente' : 'valeur-absente';
        tr.innerHTML = '<td class="fw-600">' + t('zone.' + l[0]) + '</td>'
                      + '<td class="' + classe + '">' + escapeHtml(String(valeur)) + '</td>';
        tbody.appendChild(tr);
    });
}

/* ======================================================================
   Tableau comparaison
   ====================================================================== */

var ZONES_ORDRE = [
    'canonical', 'meta_robots', 'x_robots_tag', 'hreflang', 'title', 'meta_description',
    'h1', 'donnees_structurees', 'nombre_mots',
    'liens_internes', 'liens_externes',
    'h2', 'h3', 'images', 'og_tags', 'twitter_tags', 'meta_refresh'
];

// Cache des details complets (charge depuis le fichier JSON)
var detailsComplets = null;
var detailUrl = null;

function chargerDetails() {
    if (detailsComplets || !detailUrl) return Promise.resolve(detailsComplets);
    return fetch(BASE_URL + '/' + detailUrl)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            detailsComplets = data;
            return data;
        });
}

function afficherTableauComparaison(comparaison, zonesBrut, zonesRendu) {
    tbodyComparaison.innerHTML = '';

    ZONES_ORDRE.forEach(function (zone) {
        if (!comparaison[zone]) return;
        var c = comparaison[zone];

        var btnDetail = (c.statut !== 'absent' && c.statut !== 'identique')
            ? '<button class="btn-detail" data-zone="' + zone + '" title="' + t('detail.voir') + '"><i class="bi bi-eye"></i></button>'
            : '';

        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="fw-600">' + t('zone.' + zone) + '</td>'
            + '<td class="valeur-texte">' + formaterValeurZone(zone, c.brut) + '</td>'
            + '<td class="valeur-texte">' + formaterValeurZone(zone, c.rendu) + '</td>'
            + '<td>' + badgeStatut(c.statut) + '</td>'
            + '<td>' + badgeRisque(c.risque) + '</td>'
            + '<td>' + btnDetail + '</td>';
        tbodyComparaison.appendChild(tr);
    });

    // Delegated click sur les boutons detail
    tbodyComparaison.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-detail');
        if (!btn) return;
        var zone = btn.getAttribute('data-zone');
        ouvrirModalDetail(zone);
    });
}

/* ======================================================================
   Modale de detail par zone
   ====================================================================== */

function ouvrirModalDetail(zone) {
    var modal = document.getElementById('modalDetail');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modal);

    document.getElementById('modalDetailTitre').textContent = t('zone.' + zone);

    // Badges
    var comp = derniersResultats.comparaison[zone];
    document.getElementById('modalDetailBadges').innerHTML = badgeStatut(comp.statut) + ' ' + badgeRisque(comp.risque);

    // Mettre un loading dans les textareas
    document.getElementById('modalTexteBrut').value = '...';
    document.getElementById('modalTexteRendu').value = '...';
    document.getElementById('modalCompteurBrut').textContent = '';
    document.getElementById('modalCompteurRendu').textContent = '';
    document.getElementById('modalDiffSection').style.display = 'none';

    bsModal.show();

    // Charger les details complets
    chargerDetails().then(function (details) {
        if (!details) return;
        var brut = details.zonesBrut || {};
        var rendu = details.zonesRendu || {};

        if (zone === 'nombre_mots') {
            // Cas special : afficher le texte et les mots qui different
            remplirModalMotsDiff(brut.texte_extrait || '', rendu.texte_extrait || '');
        } else {
            remplirModalZone(zone, brut[zone], rendu[zone]);
        }
    });
}

function remplirModalZone(zone, valBrut, valRendu) {
    var texteBrut = formaterDetailZone(zone, valBrut);
    var texteRendu = formaterDetailZone(zone, valRendu);

    document.getElementById('modalTexteBrut').value = texteBrut;
    document.getElementById('modalTexteRendu').value = texteRendu;

    // Compteurs
    var cBrut = compterElements(zone, valBrut);
    var cRendu = compterElements(zone, valRendu);
    document.getElementById('modalCompteurBrut').textContent = cBrut;
    document.getElementById('modalCompteurRendu').textContent = cRendu;

    // Diff : elements uniques de chaque cote
    var diff = calculerDiffZone(zone, valBrut, valRendu);
    var sectionDiff = document.getElementById('modalDiffSection');

    if (diff.uniqueBrut.length > 0 || diff.uniqueRendu.length > 0) {
        sectionDiff.style.display = '';

        var blocBrut = document.getElementById('modalUniqueBrut');
        var listeBrut = document.getElementById('modalListeUniqueBrut');
        if (diff.uniqueBrut.length > 0) {
            blocBrut.style.display = '';
            listeBrut.innerHTML = diff.uniqueBrut.map(function (item) {
                return '<div class="detail-diff-item detail-diff-item-brut">- ' + escapeHtml(item) + '</div>';
            }).join('');
        } else {
            blocBrut.style.display = 'none';
        }

        var blocRendu = document.getElementById('modalUniqueRendu');
        var listeRendu = document.getElementById('modalListeUniqueRendu');
        if (diff.uniqueRendu.length > 0) {
            blocRendu.style.display = '';
            listeRendu.innerHTML = diff.uniqueRendu.map(function (item) {
                return '<div class="detail-diff-item detail-diff-item-rendu">+ ' + escapeHtml(item) + '</div>';
            }).join('');
        } else {
            blocRendu.style.display = 'none';
        }
    } else {
        sectionDiff.style.display = 'none';
    }
}

function remplirModalMotsDiff(texteBrut, texteRendu) {
    var motsBrut = texteBrut.split(/\s+/).filter(Boolean);
    var motsRendu = texteRendu.split(/\s+/).filter(Boolean);

    document.getElementById('modalTexteBrut').value = texteBrut;
    document.getElementById('modalTexteRendu').value = texteRendu;
    document.getElementById('modalCompteurBrut').textContent = motsBrut.length + ' mots';
    document.getElementById('modalCompteurRendu').textContent = motsRendu.length + ' mots';

    // Trouver les mots uniques de chaque cote
    var setBrut = new Set(motsBrut);
    var setRendu = new Set(motsRendu);

    var uniqueBrut = motsBrut.filter(function (m) { return !setRendu.has(m); });
    var uniqueRendu = motsRendu.filter(function (m) { return !setBrut.has(m); });

    // Dedupliquer pour l'affichage
    uniqueBrut = Array.from(new Set(uniqueBrut));
    uniqueRendu = Array.from(new Set(uniqueRendu));

    var sectionDiff = document.getElementById('modalDiffSection');

    if (uniqueBrut.length > 0 || uniqueRendu.length > 0) {
        sectionDiff.style.display = '';

        var blocBrut = document.getElementById('modalUniqueBrut');
        var listeBrut = document.getElementById('modalListeUniqueBrut');
        if (uniqueBrut.length > 0) {
            blocBrut.style.display = '';
            listeBrut.innerHTML = uniqueBrut.map(function (m) {
                return '<div class="detail-diff-item detail-diff-item-brut">- ' + escapeHtml(m) + '</div>';
            }).join('');
        } else {
            blocBrut.style.display = 'none';
        }

        var blocRendu = document.getElementById('modalUniqueRendu');
        var listeRendu = document.getElementById('modalListeUniqueRendu');
        if (uniqueRendu.length > 0) {
            blocRendu.style.display = '';
            listeRendu.innerHTML = uniqueRendu.map(function (m) {
                return '<div class="detail-diff-item detail-diff-item-rendu">+ ' + escapeHtml(m) + '</div>';
            }).join('');
        } else {
            blocRendu.style.display = 'none';
        }
    } else {
        sectionDiff.style.display = 'none';
    }
}

function formaterDetailZone(zone, valeur) {
    if (valeur === null || valeur === undefined) return '(absent)';

    // Texte simple
    if (typeof valeur === 'string') return valeur || '(vide)';

    // Nombre
    if (typeof valeur === 'number') return String(valeur);

    // Tableau de strings (h1, h2, h3)
    if (Array.isArray(valeur) && valeur.length > 0 && typeof valeur[0] === 'string') {
        return valeur.join('\n');
    }

    // Tableau d'objets liens [{href, ancre, nofollow}]
    if (Array.isArray(valeur) && valeur.length > 0 && valeur[0].href !== undefined) {
        return valeur.map(function (l) {
            var nf = l.nofollow ? ' [nofollow]' : '';
            return l.href + (l.ancre ? '  "' + l.ancre + '"' : '') + nf;
        }).join('\n');
    }

    // Tableau d'objets images [{src, alt, lazy}]
    if (Array.isArray(valeur) && valeur.length > 0 && valeur[0].src !== undefined) {
        return valeur.map(function (img) {
            var lazy = img.lazy ? ' [lazy]' : '';
            return img.src + (img.alt ? '  alt="' + img.alt + '"' : '  (pas d\'alt)') + lazy;
        }).join('\n');
    }

    // Tableau d'objets donnees structurees [{type, json}]
    if (Array.isArray(valeur) && valeur.length > 0 && valeur[0].type !== undefined) {
        return valeur.map(function (s) {
            return '--- ' + s.type + ' ---\n' + formaterJson(s.json);
        }).join('\n\n');
    }

    // Tableau hreflang [{lang, href}]
    if (Array.isArray(valeur) && valeur.length > 0 && valeur[0].lang !== undefined) {
        return valeur.map(function (h) { return h.lang + ': ' + h.href; }).join('\n');
    }

    // Objet (og_tags, twitter_tags)
    if (typeof valeur === 'object' && !Array.isArray(valeur)) {
        return Object.keys(valeur).map(function (k) {
            return k + ': ' + valeur[k];
        }).join('\n');
    }

    // Tableau vide
    if (Array.isArray(valeur) && valeur.length === 0) return '(vide)';

    return JSON.stringify(valeur, null, 2);
}

function compterElements(zone, valeur) {
    if (valeur === null || valeur === undefined) return '0';
    if (typeof valeur === 'string') return valeur ? '1' : '0';
    if (typeof valeur === 'number') return String(valeur);
    if (Array.isArray(valeur)) return String(valeur.length);
    if (typeof valeur === 'object') return String(Object.keys(valeur).length);
    return '0';
}

function calculerDiffZone(zone, valBrut, valRendu) {
    var uniqueBrut = [];
    var uniqueRendu = [];

    // Tableaux de liens/images : comparer par href/src
    if (Array.isArray(valBrut) && Array.isArray(valRendu)) {
        if (valBrut.length > 0 && valBrut[0] && valBrut[0].href !== undefined) {
            var hrefsBrut = new Set(valBrut.map(function (l) { return l.href; }));
            var hrefsRendu = new Set(valRendu.map(function (l) { return l.href; }));
            valBrut.forEach(function (l) {
                if (!hrefsRendu.has(l.href)) uniqueBrut.push(l.href + (l.ancre ? '  "' + l.ancre + '"' : ''));
            });
            valRendu.forEach(function (l) {
                if (!hrefsBrut.has(l.href)) uniqueRendu.push(l.href + (l.ancre ? '  "' + l.ancre + '"' : ''));
            });
        } else if (valBrut.length > 0 && valBrut[0] && valBrut[0].src !== undefined) {
            var srcsBrut = new Set(valBrut.map(function (i) { return i.src; }));
            var srcsRendu = new Set(valRendu.map(function (i) { return i.src; }));
            valBrut.forEach(function (i) {
                if (!srcsRendu.has(i.src)) uniqueBrut.push(i.src);
            });
            valRendu.forEach(function (i) {
                if (!srcsBrut.has(i.src)) uniqueRendu.push(i.src);
            });
        } else if (valBrut.length > 0 && typeof valBrut[0] === 'string') {
            // Headings — normaliser les espaces pour la comparaison
            function normaliser(s) { return s.replace(/\s+/g, ' ').trim(); }
            var setBrut = new Set(valBrut.map(normaliser));
            var setRendu = new Set(valRendu.map(normaliser));
            valBrut.forEach(function (v) { if (!setRendu.has(normaliser(v))) uniqueBrut.push(v); });
            valRendu.forEach(function (v) { if (!setBrut.has(normaliser(v))) uniqueRendu.push(v); });
        } else if (valBrut.length > 0 && valBrut[0] && valBrut[0].type !== undefined) {
            // Donnees structurees
            var typesBrut = new Set(valBrut.map(function (s) { return s.type; }));
            var typesRendu = new Set(valRendu.map(function (s) { return s.type; }));
            valBrut.forEach(function (s) { if (!typesRendu.has(s.type)) uniqueBrut.push(s.type); });
            valRendu.forEach(function (s) { if (!typesBrut.has(s.type)) uniqueRendu.push(s.type); });
        }
    }

    // OG/Twitter tags : comparer les clés
    if (valBrut && valRendu && typeof valBrut === 'object' && !Array.isArray(valBrut)) {
        var clesBrut = Object.keys(valBrut);
        var clesRendu = Object.keys(valRendu);
        clesBrut.forEach(function (k) {
            if (!(k in valRendu)) uniqueBrut.push(k + ': ' + valBrut[k]);
            else if (valBrut[k] !== valRendu[k]) {
                uniqueBrut.push(k + ': ' + valBrut[k]);
                uniqueRendu.push(k + ': ' + valRendu[k]);
            }
        });
        clesRendu.forEach(function (k) {
            if (!(k in valBrut)) uniqueRendu.push(k + ': ' + valRendu[k]);
        });
    }

    // Texte simple : si different
    if (typeof valBrut === 'string' && typeof valRendu === 'string' && valBrut !== valRendu) {
        if (valBrut && !valRendu) uniqueBrut.push(valBrut);
        else if (!valBrut && valRendu) uniqueRendu.push(valRendu);
        // Si les deux existent mais sont differents, les textareas suffisent
    }

    return { uniqueBrut: uniqueBrut, uniqueRendu: uniqueRendu };
}

function formaterValeurZone(zone, valeur) {
    if (valeur === null || valeur === undefined) return '<span class="valeur-absente">—</span>';

    // Nombre (liens, images, mots)
    if (typeof valeur === 'number') {
        if (valeur === 0) return '<span class="valeur-absente">0</span>';
        var unite = '';
        if (zone === 'liens_internes' || zone === 'liens_externes') unite = ' lien(s)';
        else if (zone === 'images') unite = ' image(s)';
        else if (zone === 'nombre_mots') unite = ' mot(s)';
        return '<span class="valeur-presente">' + valeur + unite + '</span>';
    }

    // Tableau (headings, donnees structurees)
    if (Array.isArray(valeur)) {
        if (valeur.length === 0) return '<span class="valeur-absente">' + t('valeur.absent') + '</span>';
        // Donnees structurees
        if (valeur[0] && valeur[0].type) {
            return '<span class="valeur-presente">' + valeur.length + ' schema(s)</span>';
        }
        return '<span class="valeur-presente">' + escapeHtml(valeur.join(', ').substring(0, 100)) + '</span>';
    }

    // Objet (og_tags, twitter_tags)
    if (typeof valeur === 'object') {
        var keys = Object.keys(valeur);
        if (keys.length === 0) return '<span class="valeur-absente">' + t('valeur.absent') + '</span>';
        return '<span class="valeur-presente">' + keys.length + ' tag(s)</span>';
    }

    // Texte
    if (valeur === '') return '<span class="valeur-absente">' + t('valeur.absent') + '</span>';
    return '<span class="valeur-presente" title="' + escapeAttr(valeur) + '">' + escapeHtml(valeur.substring(0, 80)) + (valeur.length > 80 ? '...' : '') + '</span>';
}

function badgeStatut(statut) {
    var classes = {
        'identique': 'badge-identique',
        'modifie': 'badge-modifie',
        'js_seul': 'badge-js-seul',
        'supprime': 'badge-supprime',
        'absent': 'badge-absent'
    };
    var cls = classes[statut] || 'badge-absent';
    return '<span class="' + cls + '">' + t('statut.' + statut) + '</span>';
}

function badgeRisque(risque) {
    if (!risque) return '<span class="text-muted">—</span>';
    var classes = {
        'critique': 'badge-risque-critique',
        'haut': 'badge-risque-haut',
        'moyen': 'badge-risque-moyen',
        'faible': 'badge-risque-faible'
    };
    var cls = classes[risque] || '';
    return '<span class="' + cls + '">' + t('risque.' + risque) + '</span>';
}

/* ======================================================================
   Recommandations
   ====================================================================== */

function afficherRecommandations(recommandations) {
    if (!recommandations || recommandations.length === 0) {
        blocRecommandations.style.display = 'none';
        return;
    }

    blocRecommandations.style.display = '';
    listeRecommandations.innerHTML = '';

    var icones = { 'critique': '🔴', 'haut': '🟠', 'moyen': '⚠️', 'faible': 'ℹ️' };

    recommandations.forEach(function (reco) {
        var div = document.createElement('div');
        div.className = 'recommandation-item recommandation-' + reco.risque;
        var message = langueActuelle === 'fr' ? reco.message_fr : reco.message_en;
        div.innerHTML = '<span>' + (icones[reco.risque] || '') + '</span><span>' + escapeHtml(message) + '</span>';
        listeRecommandations.appendChild(div);
    });
}

/* ======================================================================
   Donnees structurees
   ====================================================================== */

function afficherDonneesStructurees(zonesBrut, zonesRendu) {
    var schemasBrut = zonesBrut.donnees_structurees || [];
    var schemasRendu = (zonesRendu && zonesRendu.donnees_structurees) ? zonesRendu.donnees_structurees : [];

    if (schemasBrut.length === 0 && schemasRendu.length === 0) {
        contenuStructurees.innerHTML = '<p class="text-muted small">' + t('structurees.vide') + '</p>';
        return;
    }

    var html = '';

    // Schemas du brut
    schemasBrut.forEach(function (schema) {
        html += '<div class="schema-card">'
            + '<div class="schema-header">'
            + '<span class="schema-badge-brut">' + t('structurees.brut') + '</span>'
            + '<span>' + escapeHtml(schema.type) + '</span>'
            + '</div>'
            + '<div class="schema-body"><pre>' + escapeHtml(formaterJson(schema.json)) + '</pre></div>'
            + '</div>';
    });

    // Schemas du rendu (seulement ceux qui ne sont pas dans le brut)
    var jsonsBrut = schemasBrut.map(function (s) { return s.json; });
    schemasRendu.forEach(function (schema) {
        var estNouveau = jsonsBrut.indexOf(schema.json) === -1;
        var badge = estNouveau ? '<span class="schema-badge-js">' + t('structurees.ajoute_js') + '</span>' : '<span class="schema-badge-rendu">' + t('structurees.rendu') + '</span>';
        html += '<div class="schema-card">'
            + '<div class="schema-header">'
            + badge
            + '<span>' + escapeHtml(schema.type) + '</span>'
            + '</div>'
            + '<div class="schema-body"><pre>' + escapeHtml(formaterJson(schema.json)) + '</pre></div>'
            + '</div>';
    });

    contenuStructurees.innerHTML = html;
}

function formaterJson(jsonStr) {
    try {
        return JSON.stringify(JSON.parse(jsonStr), null, 2);
    } catch (e) {
        return jsonStr;
    }
}

/* ======================================================================
   Screenshots — modes cote-a-cote, slider, diff
   ====================================================================== */

var screenshotData = { brut: null, rendu: null };

function afficherScreenshots(brutBase64, renduBase64) {
    var tabLi = document.getElementById('tabScreenshotsLi');

    if (!brutBase64 && !renduBase64) {
        tabLi.style.display = 'none';
        return;
    }

    tabLi.style.display = '';
    screenshotData.brut = brutBase64 ? 'data:image/png;base64,' + brutBase64 : null;
    screenshotData.rendu = renduBase64 ? 'data:image/png;base64,' + renduBase64 : null;

    // Cote a cote par defaut
    afficherModeCote();
}

function afficherModeCote() {
    document.getElementById('screenshotModeCote').style.display = '';
    document.getElementById('screenshotModeSlider').style.display = 'none';
    document.getElementById('screenshotModeDiff').style.display = 'none';

    var containerBrut = document.getElementById('screenshotBrutContainer');
    var containerRendu = document.getElementById('screenshotRenduContainer');

    containerBrut.innerHTML = screenshotData.brut
        ? '<img src="' + screenshotData.brut + '" alt="Sans JS">'
        : '<p class="text-muted small p-3">' + t('screenshots.non_disponible') + '</p>';
    containerRendu.innerHTML = screenshotData.rendu
        ? '<img src="' + screenshotData.rendu + '" alt="Avec JS">'
        : '<p class="text-muted small p-3">' + t('screenshots.non_disponible') + '</p>';
}

function afficherModeSlider() {
    if (!screenshotData.brut || !screenshotData.rendu) return;

    document.getElementById('screenshotModeCote').style.display = 'none';
    document.getElementById('screenshotModeSlider').style.display = '';
    document.getElementById('screenshotModeDiff').style.display = 'none';

    var imgBrut = document.getElementById('sliderImgBrut');
    var imgRendu = document.getElementById('sliderImgRendu');
    imgBrut.src = screenshotData.brut;
    imgRendu.src = screenshotData.rendu;

    // Attendre le chargement pour fixer la largeur
    imgRendu.onload = function () {
        var container = document.getElementById('sliderContainer');
        var clip = document.getElementById('sliderClip');
        var handle = document.getElementById('sliderHandle');
        var w = container.offsetWidth;

        // La largeur de l'image front doit etre celle du container
        imgBrut.style.width = w + 'px';

        // Initialiser a 50%
        clip.style.width = '50%';
        handle.style.left = '50%';

        initSliderDrag(container, clip, handle);
    };
}

function initSliderDrag(container, clip, handle) {
    var dragging = false;

    function updatePosition(clientX) {
        var rect = container.getBoundingClientRect();
        var x = clientX - rect.left;
        var pct = Math.max(0, Math.min(100, (x / rect.width) * 100));
        clip.style.width = pct + '%';
        handle.style.left = pct + '%';
    }

    container.addEventListener('mousedown', function (e) {
        dragging = true;
        updatePosition(e.clientX);
        e.preventDefault();
    });
    document.addEventListener('mousemove', function (e) {
        if (dragging) updatePosition(e.clientX);
    });
    document.addEventListener('mouseup', function () {
        dragging = false;
    });

    // Touch
    container.addEventListener('touchstart', function (e) {
        dragging = true;
        updatePosition(e.touches[0].clientX);
    }, { passive: true });
    document.addEventListener('touchmove', function (e) {
        if (dragging) updatePosition(e.touches[0].clientX);
    }, { passive: true });
    document.addEventListener('touchend', function () {
        dragging = false;
    });
}

function afficherModeDiff() {
    if (!screenshotData.brut || !screenshotData.rendu) return;

    document.getElementById('screenshotModeCote').style.display = 'none';
    document.getElementById('screenshotModeSlider').style.display = 'none';
    document.getElementById('screenshotModeDiff').style.display = '';

    var container = document.getElementById('screenshotDiffContainer');
    container.innerHTML = '<p class="text-muted small p-3">' + t('screenshots.diff_calcul') + '</p>';

    var imgA = new Image();
    var imgB = new Image();
    var loaded = 0;

    function onBothLoaded() {
        loaded++;
        if (loaded < 2) return;

        var w = Math.max(imgA.width, imgB.width);
        var h = Math.max(imgA.height, imgB.height);

        var cA = document.getElementById('canvasBrut');
        var cB = document.getElementById('canvasRendu');
        var cD = document.getElementById('canvasDiff');

        cA.width = w; cA.height = h;
        cB.width = w; cB.height = h;
        cD.width = w; cD.height = h;

        var ctxA = cA.getContext('2d');
        var ctxB = cB.getContext('2d');
        var ctxD = cD.getContext('2d');

        // Fond blanc
        ctxA.fillStyle = '#fff'; ctxA.fillRect(0, 0, w, h);
        ctxB.fillStyle = '#fff'; ctxB.fillRect(0, 0, w, h);

        ctxA.drawImage(imgA, 0, 0);
        ctxB.drawImage(imgB, 0, 0);

        var dataA = ctxA.getImageData(0, 0, w, h);
        var dataB = ctxB.getImageData(0, 0, w, h);
        var dataDiff = ctxD.createImageData(w, h);

        var pixelsA = dataA.data;
        var pixelsB = dataB.data;
        var pixelsD = dataDiff.data;
        var diffCount = 0;
        var totalPixels = w * h;
        var seuil = 30; // tolerance de difference par canal

        for (var i = 0; i < pixelsA.length; i += 4) {
            var dr = Math.abs(pixelsA[i] - pixelsB[i]);
            var dg = Math.abs(pixelsA[i + 1] - pixelsB[i + 1]);
            var db = Math.abs(pixelsA[i + 2] - pixelsB[i + 2]);

            if (dr > seuil || dg > seuil || db > seuil) {
                // Pixel different : rouge semi-transparent sur le rendu
                pixelsD[i] = 239;      // R
                pixelsD[i + 1] = 68;   // G
                pixelsD[i + 2] = 68;   // B
                pixelsD[i + 3] = 180;  // A
                diffCount++;
            } else {
                // Pixel identique : image rendue en gris attenue
                var gris = Math.round((pixelsB[i] + pixelsB[i + 1] + pixelsB[i + 2]) / 3);
                pixelsD[i] = gris;
                pixelsD[i + 1] = gris;
                pixelsD[i + 2] = gris;
                pixelsD[i + 3] = 120;
            }
        }

        ctxD.putImageData(dataDiff, 0, 0);

        var pctDiff = ((diffCount / totalPixels) * 100).toFixed(1);
        container.innerHTML = '<div class="small mb-2 fw-600" style="color: var(--score-low);">'
            + diffCount.toLocaleString() + ' pixels differents (' + pctDiff + '% de l\'image)'
            + '</div>'
            + '<img src="' + cD.toDataURL('image/png') + '" alt="Diff">';
    }

    imgA.onload = onBothLoaded;
    imgB.onload = onBothLoaded;
    imgA.src = screenshotData.brut;
    imgB.src = screenshotData.rendu;
}

// Boutons de mode
document.getElementById('btnModeCote').addEventListener('click', function () {
    setScreenshotMode(this, afficherModeCote);
});
document.getElementById('btnModeSlider').addEventListener('click', function () {
    setScreenshotMode(this, afficherModeSlider);
});
document.getElementById('btnModeDiff').addEventListener('click', function () {
    setScreenshotMode(this, afficherModeDiff);
});

function setScreenshotMode(btn, fn) {
    document.querySelectorAll('#panel-screenshots .btn-outline-secondary').forEach(function (b) {
        b.classList.remove('active');
    });
    btn.classList.add('active');
    fn();
}

/* ======================================================================
   HTML Source Diff
   ====================================================================== */

var diffHtmlLoaded = false;
var diffPositions = []; // indices des lignes avec differences
var diffCurrentIdx = -1;
var htmlBrutComplet = '';
var htmlRenduComplet = '';

function initDiffTab() {
    diffHtmlLoaded = false;
    document.getElementById('diffPanelBrut').innerHTML = '';
    document.getElementById('diffPanelRendu').innerHTML = '';
    document.getElementById('diffCompteur').textContent = '';
    document.getElementById('btnDiffPrev').disabled = true;
    document.getElementById('btnDiffNext').disabled = true;

    // Charger le diff quand l'onglet est active
    var tabSource = document.getElementById('tab-source');
    tabSource.addEventListener('shown.bs.tab', function handler() {
        if (!diffHtmlLoaded) {
            chargerEtAfficherDiff();
        }
        tabSource.removeEventListener('shown.bs.tab', handler);
    });
}

function chargerEtAfficherDiff() {
    var loading = document.getElementById('diffLoading');
    loading.style.display = '';

    chargerDetails().then(function (details) {
        loading.style.display = 'none';
        if (!details) return;

        htmlBrutComplet = details.htmlBrut || '';
        htmlRenduComplet = details.htmlRendu || '';

        var lignesBrut = htmlBrutComplet.split('\n');
        var lignesRendu = htmlRenduComplet.split('\n');

        var diff = calculerDiffLignes(lignesBrut, lignesRendu);
        afficherDiffPanneaux(diff);
        diffHtmlLoaded = true;

        // Scroll synchronise
        var body = document.getElementById('diffBody');
        var panels = body.querySelectorAll('.diff-panel');
        if (panels.length === 2) {
            var syncing = false;
            panels[0].addEventListener('scroll', function () {
                if (!syncing) { syncing = true; panels[1].scrollTop = panels[0].scrollTop; syncing = false; }
            });
            panels[1].addEventListener('scroll', function () {
                if (!syncing) { syncing = true; panels[0].scrollTop = panels[1].scrollTop; syncing = false; }
            });
        }
    });
}

function calculerDiffLignes(lignesA, lignesB) {
    var maxLen = Math.max(lignesA.length, lignesB.length);
    var result = [];
    diffPositions = [];

    for (var i = 0; i < maxLen; i++) {
        var a = i < lignesA.length ? lignesA[i] : null;
        var b = i < lignesB.length ? lignesB[i] : null;

        var statut;
        if (a === null) {
            statut = 'added';
            diffPositions.push(result.length);
        } else if (b === null) {
            statut = 'removed';
            diffPositions.push(result.length);
        } else if (a === b) {
            statut = 'same';
        } else {
            statut = 'modified';
            diffPositions.push(result.length);
        }

        result.push({ ligneA: a, ligneB: b, numA: a !== null ? i + 1 : '', numB: b !== null ? i + 1 : '', statut: statut });
    }

    // Compteur
    var nbDiff = diffPositions.length;
    document.getElementById('diffCompteur').innerHTML = '<strong>' + nbDiff + '</strong> ' + t('source.differences');
    document.getElementById('btnDiffPrev').disabled = nbDiff === 0;
    document.getElementById('btnDiffNext').disabled = nbDiff === 0;
    diffCurrentIdx = -1;

    return result;
}

function afficherDiffPanneaux(diff) {
    var panelBrut = document.getElementById('diffPanelBrut');
    var panelRendu = document.getElementById('diffPanelRendu');

    var htmlB = '';
    var htmlR = '';

    for (var i = 0; i < diff.length; i++) {
        var d = diff[i];
        var classeBrut = '';
        var classeRendu = '';

        if (d.statut === 'added') {
            classeBrut = 'diff-line-empty';
            classeRendu = 'diff-line-added';
        } else if (d.statut === 'removed') {
            classeBrut = 'diff-line-removed';
            classeRendu = 'diff-line-empty';
        } else if (d.statut === 'modified') {
            classeBrut = 'diff-line-modified';
            classeRendu = 'diff-line-modified';
        }

        htmlB += '<div class="diff-line ' + classeBrut + '" data-idx="' + i + '">'
            + '<span class="diff-line-num">' + d.numA + '</span>'
            + '<span class="diff-line-content">' + escapeHtml(d.ligneA || '') + '</span>'
            + '</div>';

        htmlR += '<div class="diff-line ' + classeRendu + '" data-idx="' + i + '">'
            + '<span class="diff-line-num">' + d.numB + '</span>'
            + '<span class="diff-line-content">' + escapeHtml(d.ligneB || '') + '</span>'
            + '</div>';
    }

    panelBrut.innerHTML = htmlB;
    panelRendu.innerHTML = htmlR;
}

function naviguerDiff(direction) {
    if (diffPositions.length === 0) return;

    diffCurrentIdx += direction;
    if (diffCurrentIdx < 0) diffCurrentIdx = diffPositions.length - 1;
    if (diffCurrentIdx >= diffPositions.length) diffCurrentIdx = 0;

    var idx = diffPositions[diffCurrentIdx];

    // Retirer les highlights precedents
    document.querySelectorAll('.diff-line-highlight').forEach(function (el) {
        el.classList.remove('diff-line-highlight');
    });

    // Highlight
    document.querySelectorAll('.diff-line[data-idx="' + idx + '"]').forEach(function (el) {
        el.classList.add('diff-line-highlight');
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    document.getElementById('diffCompteur').innerHTML =
        '<strong>' + (diffCurrentIdx + 1) + '/' + diffPositions.length + '</strong> ' + t('source.differences');
}

// Boutons navigation diff
document.getElementById('btnDiffNext').addEventListener('click', function () { naviguerDiff(1); });
document.getElementById('btnDiffPrev').addEventListener('click', function () { naviguerDiff(-1); });

// Copier HTML source
document.querySelectorAll('.btn-copier-source').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var cible = this.getAttribute('data-cible');
        var texte = cible === 'brut' ? htmlBrutComplet : htmlRenduComplet;
        if (!texte) return;
        navigator.clipboard.writeText(texte).then(function () {
            var icon = btn.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-check';
                setTimeout(function () { icon.className = 'bi bi-clipboard me-1'; }, 1500);
            }
        });
    });
});

/* ======================================================================
   Score couleur
   ====================================================================== */

function colorerScore(el, score) {
    el.querySelector('.kpi-value').style.color =
        score >= 80 ? 'var(--score-high)' :
        score >= 50 ? 'var(--score-mid)' :
        'var(--score-low)';
}

/* ======================================================================
   Export CSV
   ====================================================================== */

btnExportCsv.addEventListener('click', function () {
    if (!derniersResultats || !derniersResultats.comparaison) return;

    var lignes = [];
    var sep = ';';
    lignes.push([t('csv.zone'), t('csv.html_brut'), t('csv.html_rendu'), t('csv.statut'), t('csv.risque')].join(sep));

    ZONES_ORDRE.forEach(function (zone) {
        var c = derniersResultats.comparaison[zone];
        if (!c) return;
        lignes.push([
            t('zone.' + zone),
            csvValeur(c.brut),
            csvValeur(c.rendu),
            t('statut.' + c.statut),
            c.risque ? t('risque.' + c.risque) : ''
        ].join(sep));
    });

    var bom = '\uFEFF';
    var blob = new Blob([bom + lignes.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var lien = document.createElement('a');
    lien.href = URL.createObjectURL(blob);
    lien.download = 'render-checker-' + new Date().toISOString().slice(0, 10) + '.csv';
    lien.click();
    URL.revokeObjectURL(lien.href);
});

function csvValeur(val) {
    if (val === null || val === undefined) return '';
    if (typeof val === 'number') return String(val);
    if (Array.isArray(val)) return val.length + ' element(s)';
    if (typeof val === 'object') return Object.keys(val).length + ' tag(s)';
    return '"' + String(val).replace(/"/g, '""') + '"';
}

/* ======================================================================
   Copier resume
   ====================================================================== */

btnCopierResume.addEventListener('click', function () {
    if (!derniersResultats || !derniersResultats.comparaison) return;

    var lignes = [];
    lignes.push('JS Rendering Checker — ' + derniersResultats.url);
    lignes.push('Score: ' + derniersResultats.score + '/100');
    lignes.push('');
    lignes.push(t('csv.zone') + '\t' + t('csv.html_brut') + '\t' + t('csv.html_rendu') + '\t' + t('csv.statut') + '\t' + t('csv.risque'));

    ZONES_ORDRE.forEach(function (zone) {
        var c = derniersResultats.comparaison[zone];
        if (!c) return;
        lignes.push(t('zone.' + zone) + '\t' + csvValeur(c.brut) + '\t' + csvValeur(c.rendu) + '\t' + t('statut.' + c.statut) + '\t' + (c.risque ? t('risque.' + c.risque) : ''));
    });

    navigator.clipboard.writeText(lignes.join('\n')).then(function () {
        var original = btnCopierResume.innerHTML;
        btnCopierResume.innerHTML = '<i class="bi bi-check me-1"></i> ' + t('btn.copie');
        setTimeout(function () { btnCopierResume.innerHTML = original; }, 1500);
    });
});

/* ======================================================================
   Copier source HTML
   ====================================================================== */

document.querySelectorAll('.btn-copier-source').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var cible = document.getElementById(this.getAttribute('data-cible'));
        if (!cible) return;
        navigator.clipboard.writeText(cible.textContent).then(function () {
            var icon = btn.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-check';
                setTimeout(function () { icon.className = 'bi bi-clipboard'; }, 1500);
            }
        });
    });
});

/* ======================================================================
   Utilitaires
   ====================================================================== */

function afficherErreur(message) {
    alerteErreurs.style.display = '';
    alerteErreursTexte.textContent = message;
}

function masquerErreur() {
    alerteErreurs.style.display = 'none';
    alerteErreursTexte.textContent = '';
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function escapeAttr(str) {
    return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function formaterTaille(octets) {
    if (octets < 1024) return octets + ' o';
    if (octets < 1024 * 1024) return (octets / 1024).toFixed(1) + ' Ko';
    return (octets / (1024 * 1024)).toFixed(2) + ' Mo';
}

function tronquerHtml(str) {
    if (!str) return '';
    return str.length > 2000 ? str.substring(0, 2000) + '...' : str;
}

/* ======================================================================
   Mode Toggle (Single / Bulk)
   ====================================================================== */

var currentMode = 'single';
var bulkJobId = null;
var bulkCsvUrl = null;
var bulkResultats = [];

document.getElementById('modeSingle').addEventListener('change', function () {
    currentMode = 'single';
    document.getElementById('singleUrlSection').style.display = '';
    document.getElementById('bulkUrlSection').style.display = 'none';
    document.getElementById('checkScreenshots').parentElement.style.display = '';
});

document.getElementById('modeBulk').addEventListener('change', function () {
    currentMode = 'bulk';
    document.getElementById('singleUrlSection').style.display = 'none';
    document.getElementById('bulkUrlSection').style.display = '';
    document.getElementById('checkScreenshots').parentElement.style.display = 'none';
});

// CSV upload
document.getElementById('csvUpload').addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (ev) {
        var texte = ev.target.result;
        var lignes = texte.split(/[\r\n]+/).filter(Boolean);
        // Prendre la premiere colonne si CSV
        var urls = lignes.map(function (l) {
            return l.split(/[;,\t]/)[0].trim();
        }).filter(function (u) {
            return u && u.indexOf('.') !== -1;
        });
        document.getElementById('urlsBulk').value = urls.join('\n');
        document.getElementById('bulkUrlCount').textContent = urls.length + ' URL(s) importees';
    };
    reader.readAsText(file);
});

// URL count live
document.getElementById('urlsBulk').addEventListener('input', function () {
    var lignes = this.value.split('\n').filter(function (l) { return l.trim() !== ''; });
    document.getElementById('bulkUrlCount').textContent = lignes.length + ' URL(s)';
});

/* ======================================================================
   Override form submit pour gerer le mode bulk
   ====================================================================== */

formAnalyse.addEventListener('submit', function (e) {
    e.preventDefault();
    if (isRunning) return;

    if (currentMode === 'bulk') {
        var urls = document.getElementById('urlsBulk').value.trim();
        if (!urls) {
            afficherErreur(t('error.url_requise'));
            return;
        }
        lancerAnalyseBulk(urls);
    } else {
        var url = urlInput.value.trim();
        if (!url) {
            afficherErreur(t('error.url_requise'));
            return;
        }
        lancerAnalyse(url);
    }
});

/* ======================================================================
   Analyse Bulk — SSE
   ====================================================================== */

function lancerAnalyseBulk(urlsTexte) {
    isRunning = true;
    masquerErreur();
    masquerResultats();
    bulkResultats = [];
    bulkJobId = null;
    bulkCsvUrl = null;

    // Masquer les resultats single, montrer la progression bulk
    resultats.style.display = 'none';
    document.getElementById('resultatsBulk').style.display = 'none';
    var progressWrapper = document.getElementById('progressionBulkWrapper');
    progressWrapper.style.display = '';
    document.getElementById('bulkProgressBar').style.width = '0%';
    document.getElementById('bulkProgressPct').textContent = '0%';
    document.getElementById('bulkProgressLog').innerHTML = '';
    document.getElementById('tbodyBulk').innerHTML = '';

    btnAnalyser.disabled = true;
    btnAnalyser.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + t('btn.analyser');

    var formData = new FormData();
    formData.append('urls', urlsTexte);
    formData.append('ua_type', document.querySelector('input[name="ua_type"]:checked').value);
    formData.append('timeout', document.getElementById('timeoutJs').value);

    // CSRF — chercher dans le formulaire principal, puis globalement
    var csrfEl = document.getElementById('formAnalyse').querySelector('input[name="_csrf_token"]')
              || document.querySelector('input[name="_csrf_token"]');
    var csrfToken = csrfEl ? csrfEl.value : '';
    if (csrfToken) formData.set('_csrf_token', csrfToken);

    abortController = new AbortController();

    fetch(BASE_URL + '/process_bulk.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken },
        body: formData,
        signal: abortController.signal,
    }).then(function (response) {
        if (response.status === 429) throw new Error(t('error.quota_epuise'));
        if (!response.ok) throw new Error('HTTP ' + response.status);

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var buffer = '';

        function pump() {
            return reader.read().then(function (result) {
                if (result.done) { finirAnalyseBulk(); return; }
                buffer += decoder.decode(result.value, { stream: true });
                var lines = buffer.split('\n');
                buffer = lines.pop();
                var currentEvent = '';
                for (var i = 0; i < lines.length; i++) {
                    var line = lines[i];
                    if (line.indexOf('event: ') === 0) {
                        currentEvent = line.substring(7).trim();
                    } else if (line.indexOf('data: ') === 0) {
                        try {
                            var data = JSON.parse(line.substring(6));
                            traiterEvenementBulk(currentEvent, data);
                        } catch (err) {}
                        currentEvent = '';
                    }
                }
                return pump();
            });
        }
        return pump();
    }).catch(function (err) {
        if (err.name === 'AbortError') return;
        afficherErreur(err.message || t('error.analyse_echouee'));
        finirAnalyseBulk();
    });
}

function traiterEvenementBulk(event, data) {
    var log = document.getElementById('bulkProgressLog');

    switch (event) {
        case 'validation':
            if (data.invalid > 0) {
                log.innerHTML += '<div class="text-muted"><small>' + data.invalid + ' URL(s) invalide(s) ignoree(s)</small></div>';
            }
            log.innerHTML += '<div><small>' + data.total + ' URL(s) a analyser</small></div>';
            break;

        case 'url_start':
            log.innerHTML += '<div class="step-item"><span class="step-icon">🔄</span><small>' + escapeHtml(data.url) + '</small></div>';
            log.scrollTop = log.scrollHeight;
            break;

        case 'url_done':
            bulkResultats.push(data);
            ajouterLigneBulk(data);
            // Mettre a jour la derniere ligne du log
            var lastStep = log.querySelector('.step-item:last-child .step-icon');
            if (lastStep) lastStep.textContent = '✅';
            break;

        case 'url_error':
            log.innerHTML += '<div class="step-item"><span class="step-icon">❌</span><small class="text-danger">' + escapeHtml(data.url) + ' — ' + escapeHtml(data.erreur) + '</small></div>';
            log.scrollTop = log.scrollHeight;
            break;

        case 'progress':
            document.getElementById('bulkProgressBar').style.width = data.pct + '%';
            document.getElementById('bulkProgressPct').textContent = data.pct + '%';
            break;

        case 'bulk_done':
            bulkJobId = data.jobId;
            bulkCsvUrl = data.csvUrl;
            afficherResultatsBulk(data);
            break;

        case 'error':
            afficherErreur(data.message || t('error.analyse_echouee'));
            break;
    }
}

function finirAnalyseBulk() {
    isRunning = false;
    abortController = null;
    btnAnalyser.disabled = false;
    btnAnalyser.innerHTML = '<i class="bi bi-play-fill me-1"></i> ' + t('btn.analyser');
    hideInlineProgress();

    // Re-afficher le mode d'emploi
    var hpInner = document.querySelector('#helpPanel .config-help-panel');
    if (hpInner) hpInner.classList.remove('help-hidden');

    var collapseEl = document.getElementById('collapseFormulaire');
    if (collapseEl) {
        var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });
        bsCollapse.hide();
    }
    btnToggleFormulaire.classList.remove('d-none');
}

/* ======================================================================
   Affichage resultats Bulk
   ====================================================================== */

function ajouterLigneBulk(data) {
    var tbody = document.getElementById('tbodyBulk');
    var tr = document.createElement('tr');
    tr.setAttribute('data-url-hash', data.urlHash);

    var scoreColor = (data.score >= 80) ? 'var(--score-high)' : (data.score >= 50) ? 'var(--score-mid)' : 'var(--score-low)';
    var risqueBadge = badgeRisque(data.risqueGlobal);

    var urlTruncated = data.url.length > 60 ? data.url.substring(0, 60) + '...' : data.url;

    tr.innerHTML = '<td class="url-cell"><code title="' + escapeAttr(data.url) + '">' + escapeHtml(urlTruncated) + '</code></td>'
        + '<td><strong style="color:' + scoreColor + '">' + (data.score !== null ? data.score : '—') + '</strong></td>'
        + '<td>' + (data.compteurs ? data.compteurs.identique || 0 : '') + '</td>'
        + '<td>' + (data.compteurs ? data.compteurs.modifie || 0 : '') + '</td>'
        + '<td>' + (data.compteurs ? (data.compteurs.js_seul || 0) + (data.compteurs.supprime || 0) : '') + '</td>'
        + '<td><code class="small">' + escapeHtml(data.template || '/') + '</code></td>'
        + '<td>' + risqueBadge + '</td>'
        + '<td><button class="btn-detail btn-detail-bulk" data-job-id="' + (bulkJobId || '') + '" data-url-hash="' + data.urlHash + '" data-url="' + escapeAttr(data.url) + '"><i class="bi bi-eye"></i></button></td>';

    tbody.appendChild(tr);
}

function afficherResultatsBulk(data) {
    var container = document.getElementById('resultatsBulk');
    container.style.display = '';

    // Masquer le panneau d'aide
    var helpPanel = document.getElementById('helpPanel');
    if (helpPanel) { var _chp = helpPanel.querySelector('.config-help-panel'); if (_chp) _chp.classList.add('help-hidden'); };

    // KPI
    document.getElementById('bulkKpiTotal').textContent = data.total;
    document.getElementById('bulkKpiScoreMoyen').textContent = data.scoreMoyen + '/100';
    var scoreEl = document.getElementById('bulkKpiScoreMoyen');
    scoreEl.style.color = data.scoreMoyen >= 80 ? 'var(--score-high)' : data.scoreMoyen >= 50 ? 'var(--score-mid)' : 'var(--score-low)';

    var critiques = bulkResultats.filter(function (r) { return r.risqueGlobal === 'critique'; }).length;
    var jsDep = bulkResultats.filter(function (r) { return r.compteurs && (r.compteurs.js_seul > 0 || r.compteurs.supprime > 0); }).length;
    document.getElementById('bulkKpiCritiques').textContent = critiques;
    document.getElementById('bulkKpiJsDependant').textContent = jsDep;
    document.getElementById('bulkKpiTemplates').textContent = data.templates ? data.templates.length : 0;
    document.getElementById('bulkKpiErreurs').textContent = data.failed;

    // Mettre a jour les boutons detail avec le jobId
    document.querySelectorAll('.btn-detail-bulk').forEach(function (btn) {
        btn.setAttribute('data-job-id', data.jobId);
    });

    // Templates
    afficherTemplateGrouping(data.templates || []);
}

function afficherTemplateGrouping(templates) {
    var container = document.getElementById('templateGrouping');
    if (!templates.length) {
        container.innerHTML = '<p class="text-muted small">' + t('bulk.aucun_template') + '</p>';
        return;
    }

    var html = '';
    templates.forEach(function (tpl) {
        var scoreColor = tpl.scoreMoyen >= 80 ? 'var(--score-high)' : tpl.scoreMoyen >= 50 ? 'var(--score-mid)' : 'var(--score-low)';

        html += '<div class="template-card mb-3">';
        html += '<div class="d-flex justify-content-between align-items-center mb-2">';
        html += '<div><code class="fw-bold">' + escapeHtml(tpl.pattern) + '</code> <span class="badge bg-secondary ms-2">' + tpl.urls + ' URL(s)</span></div>';
        html += '<span class="fw-bold" style="color:' + scoreColor + '">' + tpl.scoreMoyen + '/100</span>';
        html += '</div>';

        // Zones problematiques
        if (tpl.zones) {
            html += '<div class="template-zones">';
            var zonesOrdre = ['canonical', 'meta_robots', 'hreflang', 'title', 'donnees_structurees', 'h1', 'nombre_mots', 'liens_internes', 'meta_description'];
            zonesOrdre.forEach(function (zone) {
                if (!tpl.zones[zone]) return;
                var stats = tpl.zones[zone];
                var jsSeul = stats.js_seul || 0;
                var modifie = stats.modifie || 0;
                var supprime = stats.supprime || 0;
                var identique = stats.identique || 0;
                if (jsSeul === 0 && modifie === 0 && supprime === 0) return;

                var parts = [];
                if (jsSeul > 0) parts.push('<span class="text-danger">' + jsSeul + ' JS seul</span>');
                if (modifie > 0) parts.push('<span style="color:var(--score-mid)">' + modifie + ' modifie</span>');
                if (supprime > 0) parts.push('<span class="text-danger">' + supprime + ' supprime</span>');
                if (identique > 0) parts.push('<span class="text-success">' + identique + ' identique</span>');

                html += '<div class="template-zone-row"><span class="template-zone-name">' + t('zone.' + zone) + '</span> : ' + parts.join(', ') + '</div>';
            });
            html += '</div>';
        }
        html += '</div>';
    });

    container.innerHTML = html;
}

// Delegated click pour detail bulk
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-detail-bulk');
    if (!btn) return;
    var jobId = btn.getAttribute('data-job-id');
    var urlHash = btn.getAttribute('data-url-hash');
    var url = btn.getAttribute('data-url');
    if (!jobId || !urlHash) return;
    ouvrirDetailBulk(jobId, urlHash, url);
});

function ouvrirDetailBulk(jobId, urlHash, url) {
    var wrapper = document.getElementById('bulkDetailWrapper');
    var content = document.getElementById('bulkDetailContent');
    document.getElementById('bulkDetailUrl').textContent = url;
    content.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm me-2"></span>Chargement...</div>';
    wrapper.style.display = '';

    // Masquer le tableau et les KPI
    document.getElementById('bulkKpiRow').style.display = 'none';
    document.querySelectorAll('#resultatsBulk > .card').forEach(function (c) { c.style.display = 'none'; });

    fetch(BASE_URL + '/job_detail.php?jobId=' + jobId + '&urlHash=' + urlHash)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                content.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error) + '</div>';
                return;
            }

            // Stocker les details pour que la modale puisse y acceder
            detailsComplets = data;

            // Tableau avec bouton oeil (meme pattern que le mode single)
            var html = '<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr>'
                + '<th>' + t('table.zone') + '</th><th>' + t('table.html_brut') + '</th><th>' + t('table.html_rendu') + '</th><th>' + t('table.statut') + '</th><th>' + t('table.risque') + '</th><th style="width:50px;"></th></tr></thead><tbody id="tbodyBulkDetail">';
            if (data.comparaison) {
                ZONES_ORDRE.forEach(function (zone) {
                    var c = data.comparaison[zone];
                    if (!c) return;
                    var btnDetail = (c.statut !== 'absent' && c.statut !== 'identique')
                        ? '<button class="btn-detail" data-zone="' + zone + '" title="' + t('detail.voir') + '"><i class="bi bi-eye"></i></button>'
                        : '';
                    html += '<tr><td class="fw-600">' + t('zone.' + zone) + '</td>'
                        + '<td class="valeur-texte">' + formaterValeurZone(zone, c.brut) + '</td>'
                        + '<td class="valeur-texte">' + formaterValeurZone(zone, c.rendu) + '</td>'
                        + '<td>' + badgeStatut(c.statut) + '</td>'
                        + '<td>' + badgeRisque(c.risque) + '</td>'
                        + '<td>' + btnDetail + '</td></tr>';
                });
            }
            html += '</tbody></table></div>';

            // Recommandations
            if (data.recommandations && data.recommandations.length > 0) {
                html += '<div class="mt-3"><h6 class="fw-bold mb-2"><i class="bi bi-lightbulb me-2"></i>' + t('reco.titre') + '</h6>';
                var icones = { 'critique': '🔴', 'haut': '🟠', 'moyen': '⚠️', 'faible': 'ℹ️' };
                data.recommandations.forEach(function (reco) {
                    var msg = langueActuelle === 'fr' ? reco.message_fr : reco.message_en;
                    html += '<div class="recommandation-item recommandation-' + reco.risque + '"><span>' + (icones[reco.risque] || '') + '</span><span>' + escapeHtml(msg) + '</span></div>';
                });
                html += '</div>';
            }

            content.innerHTML = html;

            // Attacher le click handler pour les boutons oeil dans le detail bulk
            var tbodyBulkDetail = document.getElementById('tbodyBulkDetail');
            if (tbodyBulkDetail) {
                tbodyBulkDetail.addEventListener('click', function (e) {
                    var btn = e.target.closest('.btn-detail');
                    if (!btn) return;
                    var zone = btn.getAttribute('data-zone');
                    ouvrirModalDetail(zone);
                });
            }
        })
        .catch(function (err) {
            content.innerHTML = '<div class="alert alert-danger">' + escapeHtml(err.message) + '</div>';
        });
}

// Retour a la liste bulk
document.getElementById('btnRetourBulk').addEventListener('click', function () {
    document.getElementById('bulkDetailWrapper').style.display = 'none';
    document.getElementById('bulkKpiRow').style.display = '';
    document.querySelectorAll('#resultatsBulk > .card').forEach(function (c) { c.style.display = ''; });
});

// Export CSV bulk
document.getElementById('btnExportBulkCsv').addEventListener('click', function () {
    if (!bulkCsvUrl) return;
    window.location.href = BASE_URL + '/' + bulkCsvUrl;
});
