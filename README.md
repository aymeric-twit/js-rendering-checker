# JS Rendering Checker

**Compare le HTML brut (serveur) vs le HTML rendu (apres execution JavaScript) pour detecter les dependances JS en SEO.**

Google indexe en deux phases : (1) le **crawl** recupere le HTML brut du serveur, (2) le **rendu** execute le JavaScript via le Web Rendering Service (WRS). Un delai (secondes a jours) separe ces deux etapes. Si des elements SEO critiques (canonical, meta robots, donnees structurees, liens internes) n'existent que dans le DOM rendu, ils peuvent etre indexes avec retard — ou pas du tout.

JS Rendering Checker repond a la question : **"Quels elements SEO de ma page dependent de JavaScript ?"**

---

## Fonctionnalites

### Analyse SEO comparative
- **14 zones SEO** comparees : title, meta description, canonical, meta robots, H1-H3, donnees structurees (JSON-LD), liens internes/externes, images, Open Graph, Twitter Cards, nombre de mots
- **Statuts** par zone : identique, modifie, JS seul, supprime, absent
- **Niveaux de risque** : critique, haut, moyen, faible — bases sur l'impact SEO reel
- **Score global 0-100** avec penalites par zone problematique
- **Recommandations actionables** triees par severite

### Detail des differences
- **Modale de detail** par zone avec deux textareas (brut vs rendu)
- **Diff des elements** : liens ajoutes/supprimes par JS, images, headings, mots uniques
- **Diff HTML ligne par ligne** avec numeros de ligne, surlignage couleur (vert = ajoute, rouge = supprime, jaune = modifie), scroll synchronise et navigation entre differences

### Screenshots comparatifs
- **Capture fullpage** (page complete) via Browserless avec et sans JavaScript
- **3 modes de visualisation** : cote a cote, slider avant/apres, diff pixel (zones differentes en rouge)
- Conteneurs scrollables pour les pages longues

### Export et partage
- **Export CSV** du tableau comparatif
- **Copie TSV** du resume en un clic
- **Copie du HTML** brut et rendu
- **Hash SHA-256** des deux HTML pour preuve d'integrite

### Internationalisation
- Interface disponible en **francais** et **anglais**
- Traduction automatique via la plateforme SEO ou selection manuelle en standalone

---

## Capture d'ecran

```
┌─ Score: 72/100 ──┬─ Identiques: 7 ──┬─ Modifiees: 3 ──┬─ JS seul: 2 ──┐

| Zone               | HTML brut     | HTML rendu    | Statut    | Risque   |
|--------------------|---------------|---------------|-----------|----------|
| Canonical          | (absent)      | /page-js      | JS seul   | Critique |
| Title              | Titre ancien  | Titre nouveau | Modifie   | Moyen    |
| Donnees structurees| 0 schema(s)   | 2 schema(s)   | JS seul   | Haut     |
| Liens internes     | 12            | 45            | Modifie   | Moyen    |
| H1                 | Mon H1        | Mon H1        | Identique | —        |
```

---

## Installation

### Standalone

```bash
git clone https://github.com/aymeric-twit/js-rendering-checker.git
cd js-rendering-checker
cp .env.example .env
# Editer .env et ajouter votre cle Browserless
php -S localhost:8080
```

Ouvrir `http://localhost:8080` dans le navigateur.

### Plateforme SEO

1. Admin > Plugins > Installer via Git
2. URL : `https://github.com/aymeric-twit/js-rendering-checker.git`
3. Configurer `BROWSERLESS_API_KEY` dans le `.env` de la plateforme

---

## Configuration

### Variable d'environnement

| Variable | Description | Obligatoire |
|----------|-------------|-------------|
| `BROWSERLESS_API_KEY` | Cle API [Browserless.io](https://www.browserless.io/) | Non (mode raw-only sans) |

Sans cle API, l'outil fonctionne en **mode HTML brut uniquement** (pas de rendu JS, pas de comparaison, pas de screenshots).

### Obtenir une cle Browserless

1. Creer un compte sur [browserless.io](https://www.browserless.io/signup/email?plan=free)
2. Plan gratuit : 1000 requetes/mois
3. Copier le token dans `.env`

---

## Architecture technique

### Structure des fichiers

```
js-rendering-checker/
├── module.json          # Configuration du plugin (slug, quota, routes)
├── boot.php             # Chargement .env + propagation variables
├── index.php            # Interface HTML (formulaire, resultats, modale)
├── functions.php        # Logique metier (fetch, parsing DOM, comparaison, scoring)
├── process.php          # Endpoint SSE (orchestre les phases d'analyse)
├── styles.css           # Styles (charte plateforme SEO)
├── app.js               # Frontend (SSE, diff, screenshots, export)
├── translations.js      # Traductions FR/EN
├── test-page.html       # Page de test avec elements JS pour validation
├── .env.example         # Template de configuration
├── .gitignore           # Exclusions Git
└── data/                # Donnees temporaires (screenshots, details JSON)
    ├── screenshots/     # Captures PNG
    └── details/         # Details complets des analyses
```

### Flux de donnees

```
Navigateur                    Serveur (process.php)
    │                              │
    ├── POST SSE ─────────────────►│
    │                              ├── Phase 1 : fetch_brut()
    │◄── event: progress ──────────┤   Browserless /function (JS off)
    │◄── event: phase (raw) ───────┤
    │                              ├── Phase 2 : fetch_rendu()
    │◄── event: progress ──────────┤   Browserless /content (JS on)
    │◄── event: phase (render) ────┤
    │                              ├── Phase 3 : comparer_zones()
    │◄── event: progress ──────────┤   Parse DOM + scoring
    │                              ├── Phase 4 : screenshots (optionnel)
    │◄── event: phase (screenshots)┤   Browserless /function
    │                              │
    │◄── event: done ──────────────┤   Resultats + hash SHA-256
    │                              │
    ├── GET details JSON ─────────►│   Chargement lazy des details
    │◄── JSON complet ─────────────┤
```

### API Browserless

| Endpoint | Usage | JS |
|----------|-------|----|
| `/chromium/function` (JS off) | HTML brut + screenshot sans JS | Desactive |
| `/chromium/content` | HTML rendu | Active |
| `/chromium/function` (JS on) | Screenshot avec JS | Active |

### Zones SEO et niveaux de risque

| Zone | JS seul | Modifie | Impact |
|------|---------|---------|--------|
| Canonical | Critique | Critique | Google utilise le canonical du HTML brut |
| Meta robots | Critique | Critique | noindex JS-only peut etre ignore |
| Title | Haut | Moyen | Indexe au crawl, mis a jour au rendu avec retard |
| Donnees structurees | Haut | Moyen | Rich snippets non garantis si JS-only |
| H1 | Haut | Moyen | Signal de pertinence fort |
| Contenu texte | Haut | Moyen | Delai d'indexation |
| Liens internes | Moyen | Faible | Decouverts au rendu mais avec retard |
| Meta description | Moyen | Faible | Google la reecrit souvent |
| H2-H3 | Moyen | Faible | Signal secondaire |
| Images | Faible | Faible | Lazy loading accepte |
| Open Graph / Twitter | Faible | Faible | Reseaux sociaux uniquement |

---

## Securite

- **Anti-SSRF** : validation d'URL stricte (HTTP/HTTPS uniquement, pas de localhost, pas d'IPs privees)
- **Taille max** : HTML limite a 5 Mo, details JSON a 500 Ko par source
- **Timeouts** : cURL 30s, Browserless 60s
- **CSRF** : token verifie automatiquement en mode plateforme

---

## Quota

- Mode : `url` (1 credit = 1 URL analysee)
- Quota par defaut : 100 analyses/mois
- Les screenshots comptent dans la meme analyse (pas de credit supplementaire)

---

## Developpement

### Page de test

Le fichier `test-page.html` contient une page avec des elements SEO statiques et d'autres injectes par JavaScript :

- Title, meta description, canonical **modifies par JS**
- JSON-LD Product **ajoute par JS**
- Liens internes et images **ajoutes par JS**
- Twitter Cards **injectees par JS**

Pour tester localement (note : Browserless ne peut pas acceder a localhost, utiliser un site public) :

```bash
php -S localhost:8080
# Tester avec https://www.izipizi.com/fr ou un autre site public
```

### Stack technique

- **Backend** : PHP 8.3, DOMDocument/DOMXPath pour le parsing HTML
- **Frontend** : Vanilla JS, Bootstrap 5.3, SSE (Server-Sent Events)
- **API externe** : Browserless.io (headless Chrome cloud)
- **Pas de dependance Composer** (zero vendor/)

---

## Licence

MIT
