# AGENTS.md — Règles du projet EcclesiaCRM

Ce document doit être lu et suivi par tout agent travaillant sur ce dépôt.

## Environnement

- **OS hôte** : Windows. Le code vit dans WSL (distro `Ubuntu`), dossier
  `/home/khbgbgk/ecclesiacrm`. En PowerShell, utiliser
  `wsl -d Ubuntu -u root -e bash -lc "..."` pour exécuter du bash dans WSL.
- **Écriture de fichiers** : l'écriture directe via les outils d'édition sur un
  chemin `\\wsl.localhost\...` peut échouer. Solution fiable : écrire un script
  bash dans `docker/tmp_*.sh` puis l'exécuter via
  `wsl -d Ubuntu -u root -e bash -lc "bash /home/khbgbgk/ecclesiacrm/docker/tmp_*.sh"`.
  Pour injecter du code PHP/SQL avec backslashes, utiliser un heredoc
  **python3** avec `r"""..."""` dans le script bash (les heredocs bash directs
  sont cassés par le parsing PowerShell).
- **Conteneurs** (docker compose, projet `lamp_ecclesiacrm`) :
  - Web/PHP : `lamp_ecclesiacrm-php82` (PHP 8.3, Apache, docroot `/var/www/html`,
    source montée depuis `/home/khbgbgk/ecclesiacrm/src`).
    - Vérif syntaxe : `docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/...`
    - Composer : `docker exec lamp_ecclesiacrm-php82 composer ...` (binaire
      `/usr/local/bin/composer`).
  - Base : `lamp_ecclesiacrm-mariadb106` (MariaDB 10.6.28, base `docker`).
    - `docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SQL"`
    - Ou via fichier : `docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker < f.sql`
  - phpMyAdmin : http://localhost:8080 (root / tiger).
- **Identifiants de test fournis par l'utilisateur** : admin / `test12!`.
  Le mot de passe admin est stocké `sha256(password . personId)` (admin personId = 1).

## Connexion / test HTTP

- Login : `POST http://localhost/session/login` avec `User=...&Password=...` (pas de CSRF
  sur cette route), puis suivre avec un cookie jar (`curl -c cj.txt -b cj.txt`).
- Les routes v2 (ex. `/v2/system/sms`, `/v2/systemsettings`) s'affichent ensuite.
- Les routes **API** (`/api/...`) exigent `Authorization: Bearer <jwtToken>` ;
  le token est `usr_jwt_token` en base (table `user_usr`, colonne `usr_jwt_token`)
  ou `window.CRM.jwtToken` côté navigateur. Un curl sans Bearer renvoie une
  exception JwtAuth "Token not found" — ce n'est PAS preuve que la route est cassée.

## Modifications validées à conserver (changements custom de cette fork)

1. **Menu latéral "Administration"** (visible pour les admins) dans
   `src/EcclesiaCRM/MenuBar/MenuBar.php` (fin de `createMenuBar()`) :
   General Settings (`v2/systemsettings`), System Users, Plugins, System Infos,
   Backup, Restore.
2. **Communication > SMS** :
   - Page : `src/v2/templates/system/sms.php` (config API Key/Secret/From +
     bouton envoi test), route `GET /v2/system/sms`.
   - Envoi : `src/EcclesiaCRM/dto/Notification.php`, méthode `sendSMS()` :
     POST Guzzle direct sur `https://rest.nexmo.com/sms/json` (pas de dépendance
     composer Nexmo — `nexmo/client` est incompatible avec PHP 8.2/8.3).
   - API : `POST /api/system/testSMS` et `POST /api/system/saveSMSSettings`
     dans `src/EcclesiaCRM/APIControllers/SystemController.php`.
   - ConfigItems : `sNexmoAPIKey`, `sNexmoAPISecret`, `sNexmoFromNumber`
     (catégorie `Integration`, dans `src/EcclesiaCRM/dto/SystemConfig.php`).
   - `addCommunicationMenu()` s'affiche si (email activé) OU (admin) ; l'item
     SMS est réservé aux admins.
3. **Types de soin pastoral "Baptême" et "Mariage" visibles** :
   `src/mysql/install/Install.sql` et `src/mysql/install/languages/fr_FR.sql`
   passent ces deux types à `pst_cr_tp_visible = 1` (au lieu de 0). Le "Relation
   d'aide" reste masqué. Ils sont ensuite modifiables depuis le menu
   Pastoral Care > (page admin `/v2/pastoralcarelist`).
4. **MailChimp activé par défaut** : `src/EcclesiaCRM/Bootstrapper.php`
   (`installEcclesiaCRMSchema()`) importe désormais `Plugins/MailChimp/mysql/Install.sql`,
   active le plugin et donne le rôle `admin` à l'admin par défaut (id 1).
   Note : `isAdminEnableForPlugin` exige une ligne dans `plugin_user_role`.

5. **Sacrements datés par personne** (feature en cours de développement, Task 1 = schéma) :
   - Table `person_sacrament` : `sacrament_id` (PK auto), `sacrament_person_id`
     (FK `person_per.per_ID`, ON DELETE CASCADE), `sacrament_type` varchar(50),
     `sacrament_date` date NULL, UNIQUE (`sacrament_person_id`, `sacrament_type`).
   - Définie dans `propel/main.schema.xml` (+ CREATE TABLE dans `src/mysql/install/Install.sql`
     et migration idempotente dans `src/EcclesiaCRM/Bootstrapper.php::installEcclesiaCRMSchema()`).
   - Modèles Propel générés : `src/EcclesiaCRM/model/EcclesiaCRM/PersonSacrament(Query).php`
     (+ `Base/` et `Map/` gitignorés).
  - API (Task 2) : `src/EcclesiaCRM/APIControllers/SacramentController.php` +
    routes `src/api/routes/sacraments/sacraments.php` (groupe `/sacraments`
    inclus dans `src/api/index.php`). GET/POST `/api/sacraments/person/{id}`,
    DELETE `/api/sacraments/{id}`. IMPORTANT : le contrôleur utilise
    `Psr\Container\ContainerInterface` (comme `PastoralCareController`) et
    NON `Interop\Container\ContainerInterface` (qui déclenche un 500
    `DI\DependencyException` avec php-di 7).
  - UI (Task 4) : carte « Sacrements » ajoutée dans
    `src/v2/templates/people/personview.php` (entre Overview et carte à onglets,
    droit `isSeePrivacyDataEnabled`, édition si `$bOkToEdit`), `personEditor.php`
    (même carte, `$iPersonID`), et `personPrint.php` (lecture seule).
    JS AJAX (ajout/date via POST, suppression via DELETE, puis `location.reload()`)
    dans `src/skin/js/people/PersonView.js` et `PersonEditor.js` (ancres
    `#sacraments-list` portant `data-person-id`). Les params des trois vues
    (`argumentsPeoplePersonView/Editor/PrintArray` de
    `src/EcclesiaCRM/VIEWControllers/People/VIEWPeopleController.php`) passent
    `ormPersonSacraments` (PersonSacramentQuery::filterByPersonId()->orderByDate()).

## Règles obligatoires

- **Toujours mettre à jour la documentation** après toute modification
  fonctionnelle : `docker/README.md` (réinstallation from scratch) et/ou ce
  fichier selon le périmètre (menu, plugin, dépendance, flux d'installation).
- **Ne jamais ajouter de dépendance composer** à moins d'avoir vérifié qu'elle
  installable **et** compatible PHP 8.3 (regarder `composer i` réellement
  exécuté, pas seulement le composer.json).
- Réinstallation from zero = volume DB détruit (wizard relancé) + code source
  monté ; les changements sont dans le code source, pas uniquement en base.
- Nettoyer les fichiers temporaires `docker/tmp_*.sh` / `tmp_*.sql` créés en
  fin de tâche.
- Vérifier avec `php -l` chaque fichier PHP modifié AVANT de déclarer terminé.
- Après activation/rôle d'un plugin en base, ne pas oublier la table
  `plugin_user_role` pour que les menus soient rendus aux admins.
- Penser à lister ce qui ne se régénère pas automatiquement (paramètres SMTP,
  clés Nexmo/MailChimp restent des valeurs de conf, non codées en dur).
- **Chaque login régénère le JWT** (`User::LoginPhaseActivations()`, User.php:1166) :
  tout login curl admin (`User=admin&Password=test12!`) écrase `usr_jwt_token` /
  `usr_jwt_secret` en base et **invalide la session navigateur déjà ouverte**
  (les API `/api/*` renvoient alors 500 → warning DataTables "Ajax error").
  Après un test curl avec login, prévenir l'utilisateur de faire F5 ou se
  reconnecter. Pour vérifier une route API : un login curl suffit à récupérer le
  token courant de la base ; envoyer `Authorization: Bearer <jwt>`.