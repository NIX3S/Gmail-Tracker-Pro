<div align="center">

# 📧 Gmail Tracker Pro

### Un système de tracking d'emails sécurisé avec dashboard protégé et documents trackés

*Pixel d'ouverture, suivi des clics, documents trackés transparents, dashboard regroupé avec recherche/filtre/catégories, stockage JSON ou MySQL interchangeable, authentification forte.*

***







</div>

***

# 📖 Présentation

**Gmail Tracker Pro** est un système complet de tracking d'emails conçu pour fonctionner avec Gmail, offrant un suivi précis des ouvertures, clics et consultations de documents, le tout avec un dashboard sécurisé et des fonctionnalités avancées de confidentialité.

Le projet remplace la version initiale en corrigeant les failles de sécurité et en ajoutant des fonctionnalités professionnelles :

- **Dashboard protégé par mot de passe** avec anti-bruteforce
- **Stockage interchangeable** : JSON (fichiers) ou MySQL (base de données)
- **Documents trackés transparents** : l'utilisateur choisit lui-même le fichier à tracker
- **Regroupement des envois identiques** : mêmes sujet + destinataire
- **Catégories, recherche et filtres** dans le dashboard
- **Suppression d'historique** avec confirmation et protection CSRF
- **Redirection signée** (HMAC) pour les clics, plus d'open redirect
- **Aperçu de documents corrigé** : PDF natif, images, Office Online pour .docx/.xlsx

L'ensemble est pensé pour un usage professionnel, avec une sécurité renforcée et une transparence totale sur ce qui est envoyé.

***

# ✨ Pourquoi Gmail Tracker Pro ?

Les outils de tracking d'emails existants posent plusieurs problèmes :

- dashboard public sans authentification
- stockage de données sensibles sans protection
- redirections non sécurisées (open redirect)
- substitution silencieuse de pièces jointes (tromperie)
- logs en fichiers texte parsés par regex (fragile)
- API stats sans authentification, CORS ouvert à tout le monde

Gmail Tracker Pro propose une approche différente :

- **Authentification obligatoire** : dashboard protégé par mot de passe + anti-bruteforce
- **Stockage sécurisé** : JSON avec flock ou MySQL, dossier `data/` protégé par .htaccess
- **Redirection signée** : HMAC côté serveur, l'extension ne connaît pas le secret
- **Documents trackés transparents** : l'utilisateur choisit le fichier, lien clairement labellisé
- **Vraie base de données** : tables structurées, requêtes riches, statistiques centralisées
- **API protégée** : session PHP, plus de CORS ouvert
- **Suppression sécurisée** : jeton CSRF + confirmation à chaque fois

***

# 🌟 Fonctionnalités

## 🔐 Dashboard protégé par mot de passe

- **Mot de passe unique** dont seul le hash est stocké dans `config.php`
- **Anti-bruteforce** : après 5 échecs depuis une même IP, verrouillage 5 minutes
- **Session PHP** avec cookie sécurisé
- **Affiche tous les emails trackés** (plus lié à un compte précis, usage mono-propriétaire)

> Le mot de passe du dashboard n'a **rien à voir** avec le token API de l'extension : le premier protège le dashboard humain, le second authentifie l'extension Chrome pour enregistrer les emails/documents.

***

## 💾 Choix du mode de stockage

Deux modes interchangeables via une simple constante dans `config.php` :

| Mode | Description |
|------|-------------|
| **`json`** (par défaut) | Fichiers `backend/data/*.json` verrouillés avec `flock` pour éviter les corruptions en accès concurrent. Zéro configuration, adapté à un usage perso / faible volume. |
| **`mysql`** | Vraie base de données (tables `emails`, `events`, `documents`, `users`). Recommandé pour monter en volume, faire des requêtes plus riches, etc. |

> Le reste du code ne connaît jamais le backend utilisé : tout passe par `storage()`, qui retourne soit `JsonStorage` soit `MysqlStorage` (les deux implémentent `StorageInterface`). Tu peux basculer de l'un à l'autre en changeant `STORAGE_MODE` — mais les données existantes ne sont pas migrées automatiquement.

***

## 📎 Documents trackés transparents

Remplace la substitution silencieuse de PDF de la version initiale.

**Fonctionnement** :

1. L'utilisateur clique sur **"Joindre un document tracké"** dans Gmail
2. Choisit lui-même le fichier
3. Le fichier est uploadé sur le serveur (`upload_document.php`)
4. Un lien clairement labellisé **📄 Voir \<nom\> (document suivi)** est inséré dans le corps de l'email

**Suivi** :

- savoir si/quand/combien de temps le document a été consulté
- `viewer.php` + `api_event.php` enregistrent les évènements (ouverture, temps passé, scroll)
- **Aperçu corrigé** : lecteur PDF natif pour les `.pdf`, affichage direct pour les images, **Microsoft Office Online** pour les `.docx`/`.xlsx`
- Bouton "Télécharger" toujours visible en secours si l'aperçu échoue

> ⚠️ **Le rendu Office Online nécessite que ton backend soit accessible publiquement en HTTPS** (ne fonctionnera pas en test local sur `http://127.0.0.1`, l'aperçu affichera une erreur mais le tracking continuera de fonctionner).

***

## 📊 Dashboard enrichi

### Regroupement des envois identiques

La page principale (`dashboard/index.php`) regroupe les emails ayant le **même sujet + destinataire** (par exemple si tu as cliqué deux fois sur "Envoyer avec tracking" sur le même brouillon), avec :

- ouvertures/clics/documents sommés sur le groupe
- chaque envoi individuel consultable en dépliant la carte

***

### Page de détail par email

`dashboard/detail.php?id=<tracking_id>` affiche :

- liste des liens cliqués avec leur nombre de clics chacun
- documents joints avec lien direct
- profondeur de lecture maximale atteinte
- temps total passé sur les documents
- historique chronologique complet (dépliable) de tous les évènements

***

### Catégories, recherche et filtres

- **Catégoriser un envoi** : champ texte "Catégorie (optionnel)" à côté des boutons dans Gmail (ex: `Prospection`, `Email important`, `Marketing`...)
  - autocomplete avec les catégories déjà utilisées (mémorisées localement dans l'extension)
  - champ libre : tu tapes ce que tu veux, aucune liste figée
- **Recherche** : barre de recherche filtre par sujet (recherche "contient")
- **Filtre par catégorie** : menu déroulant
- Le regroupement tient compte de la catégorie : un même sujet/destinataire envoyé sous deux catégories différentes donne deux cartes distinctes

***

### Suppression d'historique

Trois niveaux de suppression, **avec confirmation à chaque fois** :

| Action | Description |
|--------|-------------|
| **🗑 Vider tout l'historique** (bouton en haut) | Supprime tous les emails, évènements, documents et fichiers uploadés, tous comptes/catégories confondus |
| **Supprimer cette catégorie** (apparaît quand un filtre catégorie est actif) | Supprime uniquement les emails de cette catégorie |
| **Supprimer** sur chaque envoi individuel | Supprime un seul email tracké et son historique |

> Ces actions sont protégées par un **jeton CSRF** en plus de la session (`csrf_token()`/`verify_csrf_token()` dans `auth.php`) et passent par `dashboard/delete.php`.

***

## 🔒 Sécurité renforcée

### Ce qui a été supprimé de la version initiale

| Élément | Pourquoi supprimé |
|---------|-----------------|
| **Open redirect** dans `click.php` | Redirigeait vers n'importe quelle URL passée en paramètre |
| **Logs en fichiers texte parsés par regex** (`logs/*.txt`, `extract_data.php`) | Fragile, remplacé par une vraie base MySQL |
| **API stats sans authentification, CORS ouvert** (`extract_data.php` avec `Access-Control-Allow-Origin: *` + `Allow-Credentials: true`) | Combinaison invalide et dangereuse |
| **Dashboard public sans login** | Remplacé par `dashboard/login.php` + `dashboard/index.php`, protégés par session PHP |

***

### Nouveau : redirection signée (HMAC)

`click.php` utilise maintenant une redirection signée :

- **HMAC côté serveur** avec `APP_SECRET`
- **L'extension ne connaît pas le secret**
- Impossible de forger une URL de redirection valide

***

### Anti-bruteforce

Après 5 échecs de connexion depuis une même IP :

- verrouillage 5 minutes
- fichier dédié `data/.login_attempts.json` (indépendant des données de tracking)
- fonctionne quel que soit le `STORAGE_MODE` choisi

***

# 🏗️ Architecture

```text
backend/
│
├── config.php              # Choix STORAGE_MODE, DB + secrets (à remplir, ne jamais exposer)
├── stats_helper.php        # Calcul des statistiques (opens/clics/documents)
│
├── storage/
│   ├── StorageInterface.php  # Contrat commun aux deux backends
│   ├── MysqlStorage.php      # Implémentation MySQL
│   └── JsonStorage.php       # Implémentation fichiers JSON (flock)
│
├── data/                   # Fichiers JSON (mode STORAGE_MODE='json') — protégé par .htaccess
├── schema.sql              # À importer via phpMyAdmin (mode 'mysql' uniquement)
│
├── auth.php                # Session dashboard
├── api_auth.php            # Token API pour l'extension
├── create_user.php         # CLI : crée un compte (mot de passe dashboard + token API)
│
├── track.php               # Pixel d'ouverture
├── click.php               # Clic sur lien, redirection signée
├── register_email.php      # Enregistre un email tracké + signe les liens
├── upload_document.php     # Upload explicite d'un document tracké
├── viewer.php / download.php  # Affichage du document + tracking scroll/temps
├── api_event.php           # Reçoit les évènements du viewer (scroll, temps, fermeture)
├── api_stats.php           # API JSON protégée par session
│
├── dashboard/
│   ├── login.php
│   ├── index.php           # Liste groupée + recherche/filtre
│   ├── detail.php          # Détail par email
│   ├── delete.php          # Suppression (CSRF + confirmation)
│   └── logout.php
│
└── uploads/                # Fichiers uploadés (exécution PHP désactivée via .htaccess)


extension/
├── manifest.json
├── background.js
├── content.js
├── popup.*
└── options.*
```

***

# 🧩 Flux de tracking

```text
Utilisateur compose un email dans Gmail
            │
            │ Clique sur "Envoyer avec tracking"
            ▼
Extension → register_email.php
            │
            │ Enregistre l'email, signe les liens
            ▼
Email envoyé avec pixel d'ouverture + liens trackés
            │
            │ Destinataire ouvre l'email
            ▼
track.php (pixel d'ouverture)
            │
            │ Destinataire clique sur un lien
            ▼
click.php (vérification HMAC, redirection signée)
            │
            │ Destinataire clique sur un document tracké
            ▼
viewer.php (aperçu PDF/images/Office Online)
            │
            │ api_event.php (scroll, temps, fermeture)
            ▼
Dashboard (dashboard/index.php, detail.php)
            │
            │ Authentification requise
            ▼
Affichage des statistiques (opens, clics, documents, temps)
```

***

# 📋 Prérequis

Avant de déployer Gmail Tracker Pro, assurez-vous de disposer de :

| Logiciel | Version recommandée |
|----------|--------------------|
| PHP | 8.0+ |
| MySQL | 5.7+ (optionnel, pour le mode 'mysql') |
| Serveur Web | Apache avec mod_rewrite/mod_authz_core (pour .htaccess) |
| HTTPS | requis pour l'aperçu Office Online des documents |

***

# 🚀 Déploiement

## 1. Choisir le mode de stockage

Dans `config.php` :

```php
define('STORAGE_MODE', 'json'); // 'mysql' ou 'json'
```

### Mode `json`

- rien à faire de plus
- le dossier `backend/data/` se remplit tout seul au premier appel
- vérifiez qu'il est bien accessible en écriture par PHP : `chmod 770 backend/data`

***

### Mode `mysql`

1. Créez une base MySQL sur votre hébergeur
2. Importez `schema.sql` via phpMyAdmin
3. Renseignez `DB_HOST/DB_NAME/DB_USER/DB_PASS` dans `config.php`

***

## 2. Configurer `config.php`

Générez les secrets :

```bash
# APP_SECRET
php -r "echo bin2hex(random_bytes(32));"

# DASHBOARD_PASSWORD_HASH
php -r "echo password_hash('ton_mot_de_passe', PASSWORD_DEFAULT);"
```

Renseignez dans `config.php` :

| Constante | Description |
|-----------|-------------|
| `APP_SECRET` | Secret pour HMAC (redirections signées) |
| `DASHBOARD_PASSWORD_HASH` | Hash du mot de passe du dashboard |
| `APP_BASE_URL` | URL de base de votre backend (sans slash final) |
| `DB_HOST/DB_NAME/DB_USER/DB_PASS` | (mode 'mysql' uniquement) |

> ⚠️ **`config.php`, `schema.sql`, `create_user.php` doivent rester hors d'atteinte publique** (le `.htaccess` fourni bloque leur accès direct si Apache + mod_rewrite/mod_authz_core sont actifs).

***

## 3. Upload du backend

Uploadez le dossier `backend/` sur votre hébergement (à la racine du domaine, ex: `statistics.ct.ws`).

***

## 4. Créer le compte technique

```bash
php create_user.php extension
```

Notez le **token API** affiché — il ne sera plus jamais montré.

> En SSH si dispo, sinon exécutez-le une fois via un script CLI local pointant sur les mêmes données.

***

## 5. Installer l'extension

1. Allez sur `chrome://extensions`
2. Activez le **"Mode développeur"**
3. Cliquez sur **"Charger l'extension non empaquetée"**
4. Sélectionnez le dossier `extension/`

***

## 6. Configurer l'extension

1. Ouvrez les options de l'extension (clic droit sur l'icône → Options)
2. Renseignez **l'URL de ton backend** (doit être identique à `APP_BASE_URL` dans `config.php`, sans slash final)
3. Collez le **token API**
4. Cliquez sur **"Enregistrer"** : Chrome affiche une popup demandant d'autoriser l'accès à ce domaine précis — **acceptez-la** (c'est normal et attendu)
5. Cliquez sur **"Tester la connexion"** : vous devez voir un message vert avec un JSON en retour

> Si vous voyez un message rouge, lisez-le : il vous dit exactement quoi vérifier (URL, fichier manquant, réponse HTML au lieu de JSON, permission manquante...).

***

## 7. Utiliser dans Gmail

1. Allez sur Gmail, ouvrez un brouillon
2. Deux boutons apparaissent :
   - **"Envoyer avec tracking"**
   - **"Joindre un document tracké"**
3. Pour catégoriser : champ texte "Catégorie (optionnel)" à côté des boutons (autocomplete avec les catégories déjà utilisées)

***

## 8. Accéder au dashboard

Ouvrez `<APP_BASE_URL>/dashboard/login.php`, entrez le mot de passe choisi à l'étape 2.

***

# 🔧 Dépannage

## "Unexpected token '<' is not valid JSON"

Ce message veut dire que le serveur a répondu avec une page HTML (page d'erreur 404/500) au lieu du JSON attendu.

### Causes possibles (dans l'ordre à vérifier) :

1. **L'URL configurée dans l'extension ne correspond pas à l'endroit réel où est déployé `backend/`**
   - Ouvrez les options de l'extension → renseignez l'URL exacte → cliquez sur **"Tester la connexion"**
   - Le message d'erreur affiché donne directement le code HTTP reçu et le début de la réponse

2. **Vérifiez manuellement dans un navigateur**
   - Ouvrez `<APP_BASE_URL>/register_email.php` directement
   - Vous devez voir un petit JSON du type `{"status":"error","message":"Non authentifie"}`
   - Si vous voyez une

Si vous voyez à la place la page d'accueil "coming soon" d'InfinityFree ou une erreur 404 stylisée, c'est que `backend/` n'a pas encore été uploadé à la racine de ce domaine précis, ou dans le mauvais sous-dossier.

> Les endpoints `register_email.php`, `upload_document.php`, `api_event.php` et `api_stats.php` sont protégés par un filet de sécurité (`install_json_error_boundary()` dans `config.php`) qui garantit une réponse JSON propre même en cas de bug interne côté serveur — donc si le problème persiste après ça, c'est très probablement un problème d'URL/déploiement, pas de code.

***

## Erreur CORS ("has been blocked by CORS policy")

Ça arrive quand l'extension n'a pas (ou plus) la permission Chrome d'accéder au domaine configuré.

**Solution** :

1. Ouvrez les options de l'extension
2. Vérifiez l'URL
3. Cliquez à nouveau sur **"Enregistrer"** — une popup Chrome doit apparaître pour accorder la permission sur ce domaine

Si vous ne voyez pas cette popup :

- Allez dans `chrome://extensions` → détails de l'extension
- Vérifiez les **"Autorisations du site"**
- Ajoutez le domaine manuellement si besoin

> Si vous changez d'URL (par exemple vous passez d'un test en local `http://127.0.0.1:8000` à votre hébergement final), il faut refaire cette étape "Enregistrer" pour la nouvelle URL : chaque domaine a sa propre permission, elles ne sont pas cumulées automatiquement.

***

## "Uncaught Error: Extension context invalidated"

Ça arrive quand vous rechargez/mettez à jour l'extension (`chrome://extensions` → bouton recharger) alors qu'un onglet Gmail était resté ouvert avec l'ancienne version du script.

**Solution** : recharger simplement l'onglet Gmail (F5) après chaque modification de l'extension.

> Le content script détecte maintenant ce cas et affiche une alerte explicite au lieu de planter silencieusement en boucle dans la console.

***

# ⚠️ Points à garder en tête

| Point | Description |
|-------|-------------|
| **Fichiers sensibles** | `config.php`, `schema.sql`, `create_user.php` doivent rester hors d'atteinte publique (le `.htaccess` fourni bloque leur accès direct si Apache + mod_rewrite/mod_authz_core sont actifs) |
| **RGPD** | Le tracking d'ouverture/clic dans des emails a des implications légales selon votre usage et vos destinataires : informez-vous sur les obligations qui s'appliquent à votre cas avant un usage à plus grande échelle que du perso |
| **Icônes** | Les icônes fournies dans `extension/icons/` sont des placeholders unis, remplacez-les par un vrai visuel |
| **Permissions Chrome** | Le manifest déclare `optional_host_permissions` plutôt qu'une URL figée : Chrome demande la permission d'accès réseau au moment où vous enregistrez une URL dans les options (popup native), pour n'importe quel domaine — plus besoin de modifier `manifest.json` à la main quand vous changez d'hébergement ou que vous testez en local |
| **HTTPS requis** | L'aperçu Office Online des documents (.docx/.xlsx) nécessite que votre backend soit accessible publiquement en HTTPS (ne fonctionnera pas en test local sur `http://127.0.0.1`) |

***

# 🛣️ Roadmap

## Fonctionnalités actuelles

- [x] Pixel d'ouverture d'emails
- [x] Suivi des clics sur liens (redirection signée HMAC)
- [x] Documents trackés transparents (upload explicite)
- [x] Dashboard protégé par mot de passe + anti-bruteforce
- [x] Stockage interchangeable JSON/MySQL
- [x] Regroupement des envois identiques (sujet + destinataire)
- [x] Catégories, recherche et filtres dans le dashboard
- [x] Suppression d'historique (tout, par catégorie, par email) avec confirmation + CSRF
- [x] Page de détail par email (liens cliqués, documents, temps, historique)
- [x] Aperçu de documents corrigé (PDF natif, images, Office Online)
- [x] API stats protégée par session
- [x] Fichier d'upload protégé (exécution PHP désactivée)

***

## Évolutions prévues

- [ ] Migration automatique des données JSON → MySQL (et vice-versa)
- [ ] Export des statistiques en CSV/Excel
- [ ] Alertes de consultation (notification quand un email important est ouvert)
- [ ] Support de plusieurs comptes avec vues cloisonnées
- [ ] Statistiques avancées (meilleur moment pour envoyer, taux de réponse...)
- [ ] Intégration avec d'autres providers que Gmail (Outlook, Proton...)
- [ ] API publique documentée pour interrogation externe

***

# 💡 Bonnes pratiques

Quelques recommandations pour tirer le meilleur parti de Gmail Tracker Pro :

- **tester en local avant déploiement** : vérifiez le flux complet (envoi → ouverture → clic → dashboard) avant de mettre en production
- **utiliser le mode MySQL pour un volume important** : plus robuste pour les requêtes complexes et l'accès concurrent
- **garder `config.php` hors d'atteinte publique** : vérifiez que votre hébergeur respecte bien les `.htaccess`
- **changer régulièrement le mot de passe du dashboard** : générez un nouveau hash avec `password_hash()` et mettez à jour `config.php`
- **catégoriser systématiquement les envois importants** : facilite la recherche et le filtrage ultérieur
- **supprimer l'historique régulièrement** : garde le dashboard propre et réduit le volume de données sensibles stockées
- **informer les destinataires** : selon la législation de votre pays, vous pouvez avoir l'obligation d'informer que l'email contient un pixel de tracking

***

# ❓ FAQ

## Pourquoi deux authentifications (dashboard + token API) ?

Le **mot de passe du dashboard** protège l'accès humain aux statistiques.

Le **token API** authentifie l'extension Chrome pour enregistrer les emails/documents.

Les deux sont indépendants : le premier est hashé et stocké dans `config.php`, le second est généré une fois par `create_user.php` et stocké dans les options de l'extension.

***

## Puis-je utiliser Gmail Tracker Pro sans MySQL ?

Oui.

Le mode `json` (par défaut) fonctionne sans aucune base de données. Les données sont stockées dans des fichiers `backend/data/*.json` verrouillés avec `flock()` pour éviter les corruptions en accès concurrent.

> Adaptable à un usage perso / faible volume. Pour monter en volume, passez en mode `mysql`.

***

## Les données sont-elles envoyées vers un serveur externe ?

Non.

Tout est stocké sur votre propre serveur (fichiers JSON ou base MySQL). Seul l'aperçu Office Online des documents (.docx/.xlsx) transite par les serveurs Microsoft, mais le tracking (ouverture, temps, scroll) est enregistré indépendamment du succès de l'aperçu.

***

## Que se passe-t-il si je change d'URL de backend ?

Vous devez :

1. Mettre à jour `APP_BASE_URL` dans `config.php`
2. Reconfigurer l'extension avec la nouvelle URL (options → "Enregistrer")
3. Accepter la nouvelle permission Chrome pour ce domaine

> Chaque domaine a sa propre permission, elles ne sont pas cumulées automatiquement.

***

## Puis-je cloisonner plusieurs comptes avec des vues séparées ?

Actuellement non.

Le dashboard affiche **tous les emails trackés**, peu importe le token d'extension qui les a enregistrés — cohérent avec un usage à un seul propriétaire.

> Si vous voulez un jour cloisonner plusieurs comptes avec des vues séparées, c'est une évolution possible (modification de la structure de la base et du dashboard).

***

## Comment migrer de JSON vers MySQL (ou vice-versa) ?

Actuellement, il n'y a **pas de migration automatique**.

Pour changer de mode :

1. Changez `STORAGE_MODE` dans `config.php`
2. Les nouvelles données iront dans le nouveau backend
3. Les anciennes données restent dans l'ancien format

> Une migration automatique est prévue dans la roadmap.

***

# 📚 Documentation

| Fichier | Description |
|---------|-------------|
| **config.php** | Choix STORAGE_MODE, DB + secrets (à remplir, ne jamais exposer) |
| **storage/StorageInterface.php** | Contrat commun aux deux backends |
| **storage/MysqlStorage.php** | Implémentation MySQL |
| **storage/JsonStorage.php** | Implémentation fichiers JSON (flock) |
| **schema.sql** | À importer via phpMyAdmin (mode 'mysql' uniquement) |
| **auth.php** | Session dashboard, anti-bruteforce, CSRF |
| **api_auth.php** | Token API pour l'extension |
| **create_user.php** | CLI : crée un compte (mot de passe dashboard + token API) |
| **track.php** | Pixel d'ouverture |
| **click.php** | Clic sur lien, redirection signée (HMAC) |
| **register_email.php** | Enregistre un email tracké + signe les liens |
| **upload_document.php** | Upload explicite d'un document tracké |
| **viewer.php / download.php** | Affichage du document + tracking scroll/temps |
| **api_event.php** | Reçoit les évènements du viewer (scroll, temps, fermeture) |
| **api_stats.php** | API JSON protégée par session |
| **dashboard/index.php** | Liste groupée + recherche/filtre/catégories |
| **dashboard/detail.php** | Détail par email (liens, documents, historique) |
| **dashboard/delete.php** | Suppression (CSRF + confirmation) |
| **stats_helper.php** | Calcul des statistiques à partir des évènements bruts |

***

# 🙏 Remerciements

Gmail Tracker Pro s'inspire de plusieurs projets et standards de tracking d'emails.

Merci notamment aux communautés :

- [HubSpot Sales](https://www.hubspot.com/products/sales) (pour l'idée des documents trackés transparents)
- [DocSend](https://www.docsend.com/) (pour le principe de suivi de consultation de documents)
- [Mailtrack](https://mailtrack.io/) (pour le tracking d'emails Gmail)
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) (pour les bonnes pratiques d'envoi d'emails)

***

# 📄 Licence

Ce projet est distribué sous licence **MIT**.

Vous êtes libre de :

- utiliser
- modifier
- distribuer
- adapter

le projet conformément aux conditions de cette licence.

***

# ❤️ À propos

Gmail Tracker Pro est avant tout un outil personnel conçu pour suivre l'engagement de ses emails (ouvertures, clics, consultations de documents) de manière professionnelle, sans dépendre de services SaaS coûteux et avec une sécurité renforcée.

L'objectif est de proposer une solution complète, sécurisée et transparente, permettant de tracker des emails depuis Gmail, de consulter des statistiques détaillées dans un dashboard protégé, et de suivre la consultation de documents trackés sans tromperie sur ce qui est envoyé.

Le projet continuera d'évoluer au rythme des besoins et des nouvelles fonctionnalités utiles.

***

<div align="center">

## ⭐ Si ce projet vous plaît, n'hésitez pas à lui attribuer une étoile sur GitHub !

**Bon tracking avec Gmail Tracker Pro ! 📧**

</div>
