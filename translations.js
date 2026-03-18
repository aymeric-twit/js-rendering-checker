/**
 * JS Rendering Checker — Traductions FR/EN
 */
var TRANSLATIONS = {
    fr: {
        // Navbar
        'nav.titre': 'JS Rendering Checker',
        'nav.soustitre': 'HTML brut vs rendu JS',

        // Card formulaire
        'form.titre_config': '<i class="bi bi-gear me-2"></i>Configuration',
        'form.label_url': 'URL a analyser',
        'form.placeholder_url': 'https://example.com/page',
        'form.options_avancees': 'Options avancees',
        'form.label_ua': 'User-Agent',
        'form.ua_smartphone': 'Chrome Mobile (recommande)',
        'form.ua_desktop': 'Chrome Desktop',
        'form.label_timeout': 'Timeout JS (secondes)',
        'form.raccourci_hint': 'Ctrl+Entree pour lancer',
        'btn.analyser': 'Analyser',
        'btn.replier': 'Replier',
        'btn.deplier': 'Deplier',
        'btn.copier': 'Copier',
        'btn.copie': 'Copie !',

        // Progression
        'progress.titre': 'Analyse en cours',

        // Info raw-only
        'info.raw_only': 'Mode HTML brut uniquement — la comparaison avec le rendu JS n\'est pas disponible.',

        // KPI
        'kpi.score': 'Score',
        'kpi.identiques': 'Identiques',
        'kpi.modifiees': 'Modifiees',
        'kpi.js_seul': 'JS seul',

        // Onglets
        'tab.resume': 'Resume',
        'tab.structurees': 'Donnees structurees',
        'tab.source': 'HTML source',

        // Tableau comparaison
        'table.zone': 'Zone',
        'table.html_brut': 'HTML brut',
        'table.html_rendu': 'HTML rendu',
        'table.statut': 'Statut',
        'table.risque': 'Risque',
        'table.valeur': 'Valeur',

        // Noms des zones
        'zone.title': 'Title',
        'zone.meta_description': 'Meta description',
        'zone.canonical': 'Canonical',
        'zone.meta_robots': 'Meta robots',
        'zone.h1': 'H1',
        'zone.h2': 'H2',
        'zone.h3': 'H3',
        'zone.donnees_structurees': 'Donnees structurees',
        'zone.liens_internes': 'Liens internes',
        'zone.liens_externes': 'Liens externes',
        'zone.images': 'Images',
        'zone.nombre_mots': 'Nombre de mots',
        'zone.og_tags': 'Open Graph',
        'zone.twitter_tags': 'Twitter Cards',

        // Statuts
        'statut.identique': 'Identique',
        'statut.modifie': 'Modifie',
        'statut.js_seul': 'JS seul',
        'statut.supprime': 'Supprime',
        'statut.absent': 'Absent',

        // Risques
        'risque.critique': 'Critique',
        'risque.haut': 'Haut',
        'risque.moyen': 'Moyen',
        'risque.faible': 'Faible',

        // Recommandations
        'reco.titre': '<i class="bi bi-lightbulb me-2"></i>Recommandations',

        // Donnees structurees
        'structurees.vide': 'Aucune donnee structuree detectee.',
        'structurees.brut': 'HTML brut',
        'structurees.rendu': 'HTML rendu',
        'structurees.type': 'Type',
        'structurees.aucun': 'Aucun schema',
        'structurees.schemas': 'schema(s)',
        'structurees.ajoute_js': 'Ajoute par JS',

        // HTML source
        'source.brut': 'HTML brut',
        'source.rendu': 'HTML rendu',
        'source.copier_brut': 'Copier brut',
        'source.copier_rendu': 'Copier rendu',
        'source.chargement': 'Chargement du HTML...',
        'source.differences': 'difference(s)',

        // Raw-only
        'raw.titre': '<i class="bi bi-code-slash me-2"></i>Zones SEO detectees (HTML brut)',

        // Panneau d'aide
        'help.titre_comment': '<i class="bi bi-info-circle me-1"></i> Comment ca marche',
        'help.etape1': 'Saisissez l\'URL de la page a analyser.',
        'help.etape2': 'L\'outil recupere le <strong>HTML brut</strong> (comme Googlebot au crawl).',
        'help.etape3': 'Puis le <strong>HTML rendu</strong> apres execution JavaScript (comme le WRS de Google).',
        'help.etape4': 'Les zones SEO critiques sont comparees avec un niveau de risque.',
        'help.titre_zones': '<i class="bi bi-layers me-1"></i> Zones analysees',
        'help.zone_title': 'Title, Meta description, Canonical',
        'help.zone_robots': 'Meta robots, Hreflang',
        'help.zone_headings': 'Headings (H1, H2, H3)',
        'help.zone_jsonld': 'Donnees structurees (JSON-LD)',
        'help.zone_liens': 'Liens internes/externes, Images',
        'help.zone_og': 'Open Graph, Twitter Cards',
        'help.titre_quota': '<i class="bi bi-speedometer2 me-1"></i> Quota',
        'help.quota_credit': '1 analyse = <strong>1 credit</strong>',

        // Screenshots
        'form.screenshots': 'Screenshots comparatifs',
        'tab.screenshots': 'Screenshots',
        'screenshots.brut': 'Sans JavaScript',
        'screenshots.rendu': 'Avec JavaScript',
        'screenshots.non_disponible': 'Non disponible',
        'screenshots.mode_cote': 'Cote a cote',
        'screenshots.mode_slider': 'Slider',
        'screenshots.mode_diff': 'Differences',
        'screenshots.diff_legende': 'Les zones en rouge indiquent les differences entre les deux rendus.',
        'screenshots.diff_calcul': 'Calcul des differences en cours...',

        // Hash
        'hash.identique': 'HTML brut et rendu identiques',
        'hash.different': 'HTML brut et rendu differents',

        // Detail modale
        'detail.voir': 'Voir le detail',
        'detail.unique_brut': 'Uniquement dans HTML brut (supprime par JS)',
        'detail.unique_rendu': 'Uniquement dans HTML rendu (ajoute par JS)',

        // Erreurs
        'error.url_requise': 'Veuillez saisir une URL.',
        'error.quota_epuise': 'Quota mensuel epuise.',
        'error.analyse_echouee': 'L\'analyse a echoue.',

        // Valeurs
        'valeur.present': 'Present',
        'valeur.absent': 'Absent',
        'valeur.schemas': '{n} schema(s)',
        'valeur.liens': '{n} lien(s)',
        'valeur.images': '{n} image(s)',
        'valeur.mots': '{n} mot(s)',
        'valeur.tags': '{n} tag(s)',

        // CSV
        'csv.zone': 'Zone',
        'csv.html_brut': 'HTML brut',
        'csv.html_rendu': 'HTML rendu',
        'csv.statut': 'Statut',
        'csv.risque': 'Risque',

        // Mode toggle
        'form.mode_single': 'URL unique',
        'form.mode_bulk': 'Multi-URL',
        'form.label_urls_bulk': 'URLs a analyser',
        'form.placeholder_urls_bulk': 'https://example.com/page1\nhttps://example.com/page2\nhttps://example.com/page3',
        'form.ou_csv': 'ou importer un fichier CSV',

        // Bulk KPI
        'bulk.kpi_total': 'URLs',
        'bulk.kpi_score_moyen': 'Score moyen',
        'bulk.kpi_critiques': 'Critiques',
        'bulk.kpi_js_dependent': 'JS-dependent',
        'bulk.kpi_templates': 'Templates',
        'bulk.kpi_erreurs': 'Erreurs',

        // Bulk onglets
        'bulk.tab_urls': 'URLs',
        'bulk.tab_templates': 'Templates',
        'bulk.col_url': 'URL',
        'bulk.col_score': 'Score',
        'bulk.col_template': 'Template',
        'bulk.col_risque': 'Risque',
        'bulk.retour': 'Retour a la liste',
        'bulk.progress_titre': 'Analyse multi-URL en cours',
        'bulk.detail_voir': 'Voir le detail',
        'bulk.template_urls': '{n} URL(s)',
        'bulk.template_score_moyen': 'Score moyen : {score}/100',
        'bulk.aucun_template': 'Aucun template detecte.',

        // Nouvelles zones SEO
        'zone.hreflang': 'Hreflang',
        'zone.x_robots_tag': 'X-Robots-Tag',
        'zone.meta_refresh': 'Meta refresh',
    },
    en: {
        // Navbar
        'nav.titre': 'JS Rendering Checker',
        'nav.soustitre': 'Raw HTML vs JS rendered',

        // Card formulaire
        'form.titre_config': '<i class="bi bi-gear me-2"></i>Configuration',
        'form.label_url': 'URL to analyze',
        'form.placeholder_url': 'https://example.com/page',
        'form.options_avancees': 'Advanced options',
        'form.label_ua': 'User-Agent',
        'form.ua_smartphone': 'Chrome Mobile (recommended)',
        'form.ua_desktop': 'Chrome Desktop',
        'form.label_timeout': 'JS timeout (seconds)',
        'form.raccourci_hint': 'Ctrl+Enter to run',
        'btn.analyser': 'Analyze',
        'btn.replier': 'Collapse',
        'btn.deplier': 'Expand',
        'btn.copier': 'Copy',
        'btn.copie': 'Copied!',

        // Progression
        'progress.titre': 'Analysis in progress',

        // Info raw-only
        'info.raw_only': 'Raw HTML only mode — comparison with JS rendered HTML is not available.',

        // KPI
        'kpi.score': 'Score',
        'kpi.identiques': 'Identical',
        'kpi.modifiees': 'Modified',
        'kpi.js_seul': 'JS only',

        // Onglets
        'tab.resume': 'Summary',
        'tab.structurees': 'Structured data',
        'tab.source': 'HTML source',

        // Tableau comparaison
        'table.zone': 'Zone',
        'table.html_brut': 'Raw HTML',
        'table.html_rendu': 'Rendered HTML',
        'table.statut': 'Status',
        'table.risque': 'Risk',
        'table.valeur': 'Value',

        // Noms des zones
        'zone.title': 'Title',
        'zone.meta_description': 'Meta description',
        'zone.canonical': 'Canonical',
        'zone.meta_robots': 'Meta robots',
        'zone.h1': 'H1',
        'zone.h2': 'H2',
        'zone.h3': 'H3',
        'zone.donnees_structurees': 'Structured data',
        'zone.liens_internes': 'Internal links',
        'zone.liens_externes': 'External links',
        'zone.images': 'Images',
        'zone.nombre_mots': 'Word count',
        'zone.og_tags': 'Open Graph',
        'zone.twitter_tags': 'Twitter Cards',

        // Statuts
        'statut.identique': 'Identical',
        'statut.modifie': 'Modified',
        'statut.js_seul': 'JS only',
        'statut.supprime': 'Removed',
        'statut.absent': 'Absent',

        // Risques
        'risque.critique': 'Critical',
        'risque.haut': 'High',
        'risque.moyen': 'Medium',
        'risque.faible': 'Low',

        // Recommandations
        'reco.titre': '<i class="bi bi-lightbulb me-2"></i>Recommendations',

        // Donnees structurees
        'structurees.vide': 'No structured data detected.',
        'structurees.brut': 'Raw HTML',
        'structurees.rendu': 'Rendered HTML',
        'structurees.type': 'Type',
        'structurees.aucun': 'No schema',
        'structurees.schemas': 'schema(s)',
        'structurees.ajoute_js': 'Added by JS',

        // HTML source
        'source.brut': 'Raw HTML',
        'source.rendu': 'Rendered HTML',
        'source.copier_brut': 'Copy raw',
        'source.copier_rendu': 'Copy rendered',
        'source.chargement': 'Loading HTML...',
        'source.differences': 'difference(s)',

        // Raw-only
        'raw.titre': '<i class="bi bi-code-slash me-2"></i>SEO zones detected (raw HTML)',

        // Panneau d'aide
        'help.titre_comment': '<i class="bi bi-info-circle me-1"></i> How it works',
        'help.etape1': 'Enter the URL of the page to analyze.',
        'help.etape2': 'The tool fetches the <strong>raw HTML</strong> (as Googlebot sees it at crawl time).',
        'help.etape3': 'Then the <strong>rendered HTML</strong> after JavaScript execution (as Google\'s WRS).',
        'help.etape4': 'Critical SEO zones are compared with a risk level.',
        'help.titre_zones': '<i class="bi bi-layers me-1"></i> Analyzed zones',
        'help.zone_title': 'Title, Meta description, Canonical',
        'help.zone_robots': 'Meta robots, Hreflang',
        'help.zone_headings': 'Headings (H1, H2, H3)',
        'help.zone_jsonld': 'Structured data (JSON-LD)',
        'help.zone_liens': 'Internal/external links, Images',
        'help.zone_og': 'Open Graph, Twitter Cards',
        'help.titre_quota': '<i class="bi bi-speedometer2 me-1"></i> Quota',
        'help.quota_credit': '1 analysis = <strong>1 credit</strong>',

        // Screenshots
        'form.screenshots': 'Comparative screenshots',
        'tab.screenshots': 'Screenshots',
        'screenshots.brut': 'Without JavaScript',
        'screenshots.rendu': 'With JavaScript',
        'screenshots.non_disponible': 'Not available',
        'screenshots.mode_cote': 'Side by side',
        'screenshots.mode_slider': 'Slider',
        'screenshots.mode_diff': 'Differences',
        'screenshots.diff_legende': 'Red areas indicate differences between the two renders.',
        'screenshots.diff_calcul': 'Computing differences...',

        // Hash
        'hash.identique': 'Raw and rendered HTML are identical',
        'hash.different': 'Raw and rendered HTML differ',

        // Detail modale
        'detail.voir': 'View details',
        'detail.unique_brut': 'Only in raw HTML (removed by JS)',
        'detail.unique_rendu': 'Only in rendered HTML (added by JS)',

        // Erreurs
        'error.url_requise': 'Please enter a URL.',
        'error.quota_epuise': 'Monthly quota exceeded.',
        'error.analyse_echouee': 'Analysis failed.',

        // Valeurs
        'valeur.present': 'Present',
        'valeur.absent': 'Absent',
        'valeur.schemas': '{n} schema(s)',
        'valeur.liens': '{n} link(s)',
        'valeur.images': '{n} image(s)',
        'valeur.mots': '{n} word(s)',
        'valeur.tags': '{n} tag(s)',

        // CSV
        'csv.zone': 'Zone',
        'csv.html_brut': 'Raw HTML',
        'csv.html_rendu': 'Rendered HTML',
        'csv.statut': 'Status',
        'csv.risque': 'Risk',

        // Mode toggle
        'form.mode_single': 'Single URL',
        'form.mode_bulk': 'Multi-URL',
        'form.label_urls_bulk': 'URLs to analyze',
        'form.placeholder_urls_bulk': 'https://example.com/page1\nhttps://example.com/page2\nhttps://example.com/page3',
        'form.ou_csv': 'or import a CSV file',

        // Bulk KPI
        'bulk.kpi_total': 'URLs',
        'bulk.kpi_score_moyen': 'Avg. Score',
        'bulk.kpi_critiques': 'Critical',
        'bulk.kpi_js_dependent': 'JS-dependent',
        'bulk.kpi_templates': 'Templates',
        'bulk.kpi_erreurs': 'Errors',

        // Bulk onglets
        'bulk.tab_urls': 'URLs',
        'bulk.tab_templates': 'Templates',
        'bulk.col_url': 'URL',
        'bulk.col_score': 'Score',
        'bulk.col_template': 'Template',
        'bulk.col_risque': 'Risk',
        'bulk.retour': 'Back to list',
        'bulk.progress_titre': 'Multi-URL analysis in progress',
        'bulk.detail_voir': 'View details',
        'bulk.template_urls': '{n} URL(s)',
        'bulk.template_score_moyen': 'Average score: {score}/100',
        'bulk.aucun_template': 'No template detected.',

        // Nouvelles zones SEO
        'zone.hreflang': 'Hreflang',
        'zone.x_robots_tag': 'X-Robots-Tag',
        'zone.meta_refresh': 'Meta refresh',
    }
};
