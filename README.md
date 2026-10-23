# PROS AUTO — Application de gestion d’atelier automobile

Application web complète de gestion d’atelier automobile : **PHP natif + MySQL**, conçue pour
**XAMPP (Apache + MySQL)** sous Windows. Interface **bilingue Français / Anglais** avec une logique de gestion adaptée au marché canadien.

## Fonctionnalités

| Module | Description |
|---|---|
| 🔐 Authentification | Connexion sécurisée (`password_hash`/`password_verify`), rôles **Administrateur** et **Mécanicien**, sessions durcies, anti force-brute, CSRF sur tous les formulaires |
| 📊 Tableau de bord | Statistiques (clients, véhicules par état, mécaniciens présents/absents), graphiques (véhicules par état, réparations par mois) |
| 👥 Clients | CRUD complet + recherche + filtres + fiche avec véhicules, devis et factures |
| 🚗 Véhicules | CRUD complet + fiche complète (inspections, réparations, **historique des états**) |
| 🔍 Inspections | Création d'inspection (problème, diagnostic, pièces à contrôler, photo) → véhicule passe à **« En cours »** |
| ⚙️ Affectations | Affectation véhicule → mécanicien (priorité, instructions) → véhicule passe à **« En réparation »** |
| 🔧 Réparations | Diagnostic, travaux, **pièces (qty × prix)**, main-d'œuvre, **coût total automatique** |
| ✅ Workflow d'états | `État initial → En cours → En réparation → Réparé → Livré` (+ `Annulé`), **chaque changement est historisé** dans `vehicle_status_history` |
| 🧑‍🔧 Espace mécanicien | Mes véhicules, travaux réalisés, pièces, observations, bouton **✓ Terminer la réparation** (avec confirmation) |
| ⏱️ Pointage | Entrée/Sortie (heure serveur exacte), tableau hebdomadaire, **retard** (vs 08:00 configurable), **heures supplémentaires**, **pauses**, présence en temps réel (🟢 Présent / 🟠 En pause / 🔴 Absent) |
| ⚠️ Anomalies | Détection : entrée sans sortie, sortie sans entrée, pointages consécutifs, journée incomplète, pointage oublié + notification admins |
| 📄 **Devis** | N° auto-incrémenté (`DEV-AAAA-NNNN`, modifiable), client + véhicule, **rubriques** (description, qté, prix unitaire, remise %, TVA %, total), calculs **HT / Remise / TVA / TTC** automatiques, **PDF professionnel**, impression, **envoi par e-mail** (HTML + PDF joint) |
| 🧾 **Factures** | Création depuis un **devis accepté** ou directement depuis un client, n° `FAC-AAAA-NNNN`, échéance, **encaissements** (payée / partiellement payée), PDF, impression, e-mail |
| 🔔 Notifications | Cloche dans la navbar + page : nouveau véhicule affecté, réparation terminée, véhicule livré, pointage incomplet… |
| ️ Recherche globale | Client, immatriculation, VIN, téléphone, véhicule, mécanicien — depuis la navbar |
| 📜 Journal d'activité | Toutes les actions sont journalisées (utilisateur, action, module, IP, horodatage) + export CSV |
| 🌐 Bilingue | Sélecteur **FR/EN** (mémorisé en session + cookie), tous les textes traduits via `includes/lang/fr.php` / `en.php` |

---

## Configuration de déploiement

### Variables d'environnement

Le projet prend désormais en charge une configuration de production via variables d'environnement (ou un serveur web / hébergement compatible). Copiez simplement le fichier `.env.example` vers `.env` et adaptez les valeurs avant un déploiement.

```bash
cp .env.example .env
```

Exemple :

```env
APP_ENV=production
APP_DEBUG=false
BASE_URL=https://votre-domaine.com/garage
DB_HOST=127.0.0.1
DB_NAME=garage_db1
DB_USER=garage_user
DB_PASS=MotDePasseTrèsFort
DB_CHARSET=utf8mb4
```

- `.env` est ignoré par Git via `.gitignore`.
- Les variables sont lues automatiquement par `config/config.php` et `config/database.php`.
- Si aucune variable n'est définie, le projet conserve les valeurs XAMPP par défaut pour un usage local.

### Déploiement en production

1. Importez `database.sql` sur votre MySQL.
2. Configurez vos variables d'environnement ou votre panel d'hébergement.
3. Vérifiez que `APP_DEBUG=false`.
4. Assurez-vous que le dossier `assets/uploads/` est accessible en écriture par PHP.
5. Si votre site tourne dans un sous-dossier, définissez `BASE_URL` avec le chemin exact du projet (par ex. `/garage` ou `https://exemple.com/garage`).

## Cloner le dépôt

```bash
git clone https://github.com/medtechinfo69-oss/garage.git
```

## Installation (XAMPP — Windows)

### 1. Installer XAMPP
Téléchargez et installez [XAMPP](https://www.apachefriends.org/fr/index.html) (Apache + MySQL)
avec le chemin par défaut `C:\xampp`.

### 2. Démarrer les services
Ouvrez le **XAMPP Control Panel** et cliquez **Start** sur :
- **Apache**
- **MySQL**

### 3. Copier le projet
Copiez le dossier `garage1` dans :

```
C:\xampp\htdocs\
```

Vous devez obtenir : `C:\xampp\htdocs\garage1\`

### 4. Créer la base de données
1. Ouvrez `http://localhost/phpmyadmin`
2. Onglet **Importer** → choisir le fichier `C:\xampp\htdocs\garage1\database.sql` → **Exécuter**

   *ou via console* :
   ```bat
   C:\xampp\mysql\bin\mysql -u root < C:\xampp\htdocs\garage1\database.sql
   ```

La base `garage_db1` et tous les comptes initiaux sont créés automatiquement.

### 5. Configurer la connexion (si nécessaire)
Par défaut, le fichier `config/database.php` utilise :

| Constante | Valeur par défaut XAMPP |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_NAME` | `garage_db1` |
| `DB_USER` | `root` |
| `DB_PASS` | *(vide)* |

Modifiez-le seulement si vos identifiants diffèrent.

### 6. Ouvrir l'application

```
http://localhost/garage1/
```

### 7. Se connecter

| Rôle | Identifiant | Mot de passe |
|---|---|---|
| **Administrateur** | `admin` (ou `admin@prosauto.ca`) | `Admin@2026` |
| Mécanicien (démo) | `mohamed` | `Mech@2026` |
| Mécanicien (démo) | `ahmed` | `Mech@2026` |

> ⚠️ **Changez immédiatement ces mots de passe** (Admin → Utilisateurs → bouton 🔑).

---

## Comptes & rôles

- **Administrateur** : accès complet (clients, véhicules, inspections, affectations,
  réparations, devis, factures, pointage, anomalies, utilisateurs, journal, paramètres).
- **Mécanicien** : tableau de bord, **uniquement ses véhicules**, ses réparations,
  son pointage. Aucune page admin n'est accessible (contrôle de rôle sur chaque page).

## Workflow des véhicules

```
CLIENT → VÉHICULE → INSPECTION (état : En cours)
→ AFFECTATION AU MÉCANICIEN (état : En réparation)
→ RÉPARATION PAR LE MÉCANICIEN (état : Réparé)
→ CONTRÔLE + LIVRAISON PAR L'ADMIN (état : Livré)
```

Chaque changement d'état est enregistré dans `vehicle_status_history`
(date, heure, utilisateur, mécanicien, commentaire) — l'ancien état est **jamais écrasé**.

## Pointage

- Boutons 🟢 **ENTRÉE** / 🔴 **SORTIE** : l'heure exacte du serveur est enregistrée.
- Le système **empêche** deux entrées consécutives, deux sorties consécutives
  et une sortie sans entrée.
- Calculs automatiques : temps travaillé, **retard** (entrée après 08:00),
  **pauses** (interruption ≥ 30 min), **heures supplémentaires** (au-delà de 8 h).
- L'administrateur consulte la **présence actuelle**, l'**historique** (filtres +
  **export CSV**) et les **anomalies** détectées.

## Devis & Factures

- **Devis** : n° `DEV-2026-0001` (auto-incrémenté, modifiable, **jamais dupliqué**).
- **Facture** : depuis un devis (statut Accepté/Envoyé) ou directement depuis un client.
- Rubriques : description, quantité, prix unitaire HT, remise %, TVA % →
  totaux **HT / TVA / TTC** recalculés côté **serveur** (pas de confiance au client JS).
- **PDF professionnel** généré par un moteur PDF interne en PHP pur
  (aucune bibliothèque externe), avec le logo, le client, le véhicule, les lignes,
  les totaux, la TVA, la signature. **Impression** via le navigateur (Ctrl+P).
- **E-mail** : gabarit HTML professionnel + PDF en pièce jointe via `mail()`.
  Sur XAMPP, `mail()` est factice par défaut ; pour un envoi réel, configurez un
  SMTP (ex. `sendmail` de XAMPP ou un relais). Si l'envoi échoue, l'application
  le signale et propose le **téléchargement direct du PDF**.
- Recherche/filtres : numéro, client, véhicule, dates, statut.

## Bilingue (FR / EN)

- Sélecteur **FR / EN** dans la navbar (et sur la page de connexion).
- Le choix est **mémorisé** (session + cookie 30 jours).
- Toutes les chaînes proviennent de `includes/lang/fr.php` et `includes/lang/en.php`
  — pour ajouter une langue, créez un nouveau fichier dans ce dossier.
- Les **documents** (devis/factures) ont leur propre langue au moment de la création
  (PDF + e-mail dans la langue du document).

## Sécurité

- **PDO + requêtes préparées** pour 100 % des requêtes SQL (anti injection).
- `password_hash()` / `password_verify()` (bcrypt), mot de passe jamais stocké en clair.
- **CSRF** : jeton unique par session, vérifié sur chaque formulaire POST (sinon HTTP 403).
- **XSS** : toutes les sorties passent par `htmlspecialchars()` (helper `e()`).
- **Sessions durcies** : cookie `HttpOnly` + `SameSite=Lax`, `session_regenerate_id()`
  à la connexion, expiration d'inactivité (2 h).
- **Anti force-brute** : 5 échecs / 15 min → blocage temporaire (table `login_attempts`).
- **Contrôle d'accès** sur chaque page (`require_login()`, `require_admin()`),
  un mécanicien ne peut voir que **ses** véhicules.
- **Uploads** : extension + MIME réel + taille max (5 Mo), nom aléatoire,
  exécution PHP interdite dans `assets/uploads/` (.htaccess).
- **Apache** : `.htaccess` racine interdisant l'accès direct à `config/` et `includes/`.
- **En-têtes** : `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy: same-origin`, `Permissions-Policy`.
- **Journalisation** de toutes les actions importantes (utilisateur, IP, horodatage).
- Numéros de devis/facture **uniques** (contrainte `UNIQUE` + validation serveur).

## Structure du projet

```
garage1/
├── config/
│   ├── config.php          # réglages généraux, société, uploads, pointage
│   └── database.php        # connexion PDO + helpers SQL
├── includes/
│   ├── auth.php            # session sécurisée, rôles, langue, en-têtes
│   ├── functions.php       # helpers (i18n, états, pointage, documents, sécurité)
│   ├── pdf_writer.php      # moteur PDF PHP pur (Helvetica, JPEG, lignes…)
│   ├── doc_pdf.php         # mise en page devis/facture + gabarit e-mail
│   ├── mailer.php          # envoi e-mail HTML + PDF joint
│   ├── header.php          # navbar (recherche, cloche, langue, utilisateur)
│   ├── sidebar.php         # menu par rôle
│   ├── footer.php          # barre légale (copyright, accès sécurisé, nom de l'app) + modale de confirmation
│   └── lang/
│       ├── fr.php          # traductions françaises
│       └── en.php          # traductions anglaises
├── assets/
│   ├── css/style.css       # design (sidebar, cartes, badges, pied de page, impression)
│   ├── js/app.js           # lignes de documents, modales, pointage
│   ├── images/logo.png     # logo PROS AUTO
│   ├── uploads/            # fichiers uploadés (PHP désactivé)
│   └── vendor/             # Bootstrap 5 + Bootstrap Icons (locaux, hors-ligne)
├── admin/                  # espace administrateur
│   ├── dashboard.php
│   ├── clients/            # index, form, view
│   ├── vehicles/           # index, form, view
│   ├── inspections/        # index, form, view
│   ├── assignments/        # index, form
│   ├── repairs/            # index, view
│   ├── mechanics/          # index (CRUD)
│   ├── attendance/         # index (présence + semaine), history (CSV), anomalies
│   ├── users/              # index (CRUD + reset mot de passe)
│   ├── logs/               # journal + export CSV
│   ├── devis/              # index, form, view, pdf, send
│   ├── factures/           # index, form, view, pdf, send
│   └── settings.php        # société, TVA, pointage, devise
├── mechanic/               # espace mécanicien
│   ├── dashboard.php
│   ├── vehicles.php
│   ├── vehicle_view.php    # travaux, pièces, ✓ Terminer la réparation
│   ├── repairs.php
│   └── attendance.php      # pointage + semaine
├── notifications/          # liste + tout marquer lu
├── auth/
│   ├── login.php
│   └── logout.php
├── search.php              # recherche globale
├── index.php               # redirection login/dashboard
├── database.sql            # base complète + compte admin initial
├── .htaccess               # protection config/ includes/
└── README.md
```

## Dépannage

| Problème | Solution |
|---|---|
| « Erreur de connexion à la base » | Vérifiez `config/database.php` et que `database.sql` est importé |
| Les styles ne s'affichent pas | Vérifiez que `assets/vendor/` est bien copié |
| Les e-mails ne partent pas | `mail()` de XAMPP est factice : configurez un SMTP (voir plus haut) |
| Un mécanicien ne voit pas un véhicule | L'affectation doit être faite par l'administrateur (état « En réparation ») |
| PDF / e-mail refusés | Vérifiez les droits d'écriture du dossier PHP temp |
