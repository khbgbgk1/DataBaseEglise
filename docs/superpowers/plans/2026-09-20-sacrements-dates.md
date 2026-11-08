# Sacrements datés par personne (filtrables) — Plan d'implémentation

> **Pour les travailleurs agentiques :** SOUS-SKILL REQUISE : superpowers:subagent-driven-development (recommandé) ou superpowers:executing-plans pour implémenter ce plan tâche par tâche. Étapes au format case à cocher (`- [ ]`).

**But :** Ajouter un suivi par personne de 8 sacrements/événements avec une date, saisis uniquement via un bouton "+" (les vides n'apparaissent pas), visibles dans les fiches/éditeur/impression, et filtrables (présence/absence + plage ou année) dans la liste des personnes et l'annuaire.

**Architecture :** Nouvelle table `person_sacrament` (lignes datées par personne, unique (personne,type)), modèle Propel généré depuis `main.schema.xml` ; un contrôleur d'API REST (`SacramentController`) pour le CRUD ; affichage serveur dans `personview.php`/`personEditor.php`/`personPrint.php` avec petit JS AJAX ; filtres appliqués **côté serveur** (sous-requête `EXISTS`/`NOT EXISTS` + plages de dates) dans `VIEWPersonListController` et `Reports/DirectoryReport.php`.

**Tech stack :** PHP 8.3, Slim 4, Propel 2 (modèles ORM), jQuery/DataTables/bootstrap-datepicker côté client, MariaDB 10.6 (base `docker`, root/tiger).

**Spec :** `docs/superpowers/specs/2026-09-20-sacrements-dates-design.md`

## Contraintes globales

- Le code vit en WSL ; l'écriture directe via `\\wsl.localhost\...` échoue — écrire un script `docker/tmp_*.sh` et l'exécuter via `wsl -d Ubuntu -u root -e bash -lc "bash /home/khbgbgk/ecclesiacrm/docker/tmp_*.sh"`. Pour injecter du PHP/SQL avec backslashes, heredoc **python3** avec `r"""..."""` dans le script bash.
- **Pas de nouvelle dépendance composer.**
- `php -l` sur chaque fichier PHP modifié AVANT de déclarer terminé.
- `docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SQL"` pour la base.
- Nettoyer TOUS les fichiers `docker/tmp_*.sh` créés en fin de tâche.
- Chaque connexion curl `User=admin&Password=test12!` régénère le JWT en base et invalide la session navigateur ouverte → prévenir l'utilisateur de faire F5. Pour les API `/api/*`, lire le token courant via login curl puis `Authorization: Bearer <jwt>` (ou `usr_jwt_token` de `user_usr`).
- Mettre à jour la documentation (`AGENTS.md` + `docker/README.md`) après les changements fonctionnels.
- Pas de repo git fiable ici (dossier avec `safe.directory` configuré en root) — les commits ne sont PAS exigés ; ils restent optionnels et jamais automatiques.
- Les types de sacrements sont des **codes fixes** : `BAPTISM`, `FIRST_COMMUNION`, `CONFIRMATION`, `MARRIAGE`, `ORDINATION_DEACON`, `ORDINATION_PRIEST`, `ORDINATION_BISHOP`, `WIDOWHOOD`.

## Points de revue (Review Focus)

1. **Personne n'ayant AUCUN sacrement saisi** : le filtre "Non" sur un sacrement doit la ramener ; le filtre "Oui" ne doit jamais la ramener. Affecté à la Task 6 (tests liste) et Task 7 (annuaire).
2. **Date invalide ou année à 4 chiffres** : `sac_date_year=1999` → 200 ; `sac_date_year=19` ou texte → ignoré sans 500. Task 6.
3. **Valeurs de filtre "Tous"** (ni Oui ni Non) : aucun `EXISTS`/`NOT EXISTS` ajouté → la liste complète reste correcte (permutation des autres filtres). Task 6.
4. **Sacrement déjà saisi** : le bouton "+" ne le propose plus ; un upsert via l'API met à jour la date au lieu de dupliquer (UNIQUE). Task 4 (UI) + Task 5 (API).
5. **Veuvage sans date** : `sacrament_date = NULL` autorisé uniquement pour `WIDOWHOOD`, sinon 400. Task 5.
6. **Visiteur sans droit vue données privées** : la carte Sacrements et les filtres de la liste ne doivent pas s'afficher (pas de fuite). Tasks 4 + 6.
7. **Ancien navigateur / session JWT invalidée** par les tests curl admin : les pages v2 restent OK (cookie session), seules les API `/api/*` 500 → après les vérifs curl, toujours logger la note "F5/reconnect" pour l'utilisateur. Tasks 5-8.

---
## Task 1 : Schéma Propel + SQL d'installation

**Files:**
- Modify: `propel/main.schema.xml` (ajouter `<table name="person_sacrament">`, après la table `pastoral_care`, ~ligne 1360)
- Modify: `src/mysql/install/Install.sql` (CREATE TABLE, après `person_per`)
- Modify: `src/EcclesiaCRM/Bootstrapper.php` (migration idempotente pour base existante)

**Interfaces:**
- Consumes: — (démarrage)
- Produces: table `person_sacrament` avec colonnes `sacrament_id`, `sacrament_person_id`, `sacrament_type`, `sacrament_date`, model Propel généré.

- [ ] **Étape 1 : Écrire le squelette SQL (script tmp)**

Créer `docker/tmp_sac_schema.sh` :

```bash
#!/bin/bash
set -e
cd /home/khbgbgk/ecclesiacrm
echo "preparing (no-op): person_sacrament migration"
```

- [ ] **Étape 2 : Ajouter la table dans `main.schema.xml`**

Insérer APRÈS la fermeture de `<table name="pastoral_care" ...>...</table>` (trouver la ligne de fin vers 1360) :

```xml
  <table name="person_sacrament" idMethod="native" phpName="PersonSacrament" description="Sacraments dated per person">
    <column name="sacrament_id" phpName="Id" type="SMALLINT" size="9" sqlType="mediumint(9) unsigned" primaryKey="true" autoIncrement="true" required="true"/>
    <column name="sacrament_person_id" phpName="PersonId" type="SMALLINT" size="9" sqlType="mediumint(9) unsigned" required="true"/>
    <column name="sacrament_type" phpName="Type" type="VARCHAR" size="50" required="true"/>
    <column name="sacrament_date" phpName="Date" type="DATE"/>
    <index name="uq_sacrament_person_type">
      <index-column name="sacrament_person_id"/>
      <index-column name="sacrament_type"/>
    </index>
    <unique name="uq_sacrament_person_type">
      <unique-column name="sacrament_person_id"/>
      <unique-column name="sacrament_type"/>
    </unique>
    <foreign-key foreignTable="person_per" onDelete="CASCADE">
      <reference local="sacrament_person_id" foreign="per_ID"/>
    </foreign-key>
    <vendor type="mysql">
      <parameter name="Engine" value="InnoDB"/>
    </vendor>
  </table>
```

- [ ] **Étape 3 : Ajouter le CREATE TABLE dans `src/mysql/install/Install.sql`**

Immédiatement après le bloc `person_per` (~ligne 844) :

```sql
-- Table structure for table `person_sacrament`
CREATE TABLE IF NOT EXISTS person_sacrament (
    `sacrament_id` mediumint(9) unsigned NOT NULL AUTO_INCREMENT,
    `sacrament_person_id` mediumint(9) unsigned NOT NULL,
    `sacrament_type` varchar(50) NOT NULL,
    `sacrament_date` date DEFAULT NULL,
    PRIMARY KEY(sacrament_id),
    UNIQUE KEY uq_sacrament_person_type (sacrament_person_id, sacrament_type),
    CONSTRAINT fk_sacrament_person_id
      FOREIGN KEY (sacrament_person_id)
      REFERENCES person_per(per_ID)
      ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci;
```

- [ ] **Étape 4 : Migration idempotente dans `Bootstrapper.php`**

Dans `src/EcclesiaCRM/Bootstrapper.php`, dans `installEcclesiaCRMSchema()` (section où les tables sont créées — vérifier le pattern : il y a un `UPDATE`/`INSERT` après le `Install.sql` import, cf. changements MailChimp) ajouter après le bloc d'import :

```php
        // person_sacrament : sacrements datés par personne (fork)
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS person_sacrament (
                `sacrament_id` mediumint(9) unsigned NOT NULL AUTO_INCREMENT,
                `sacrament_person_id` mediumint(9) unsigned NOT NULL,
                `sacrament_type` varchar(50) NOT NULL,
                `sacrament_date` date DEFAULT NULL,
                PRIMARY KEY(sacrament_id),
                UNIQUE KEY uq_sacrament_person_type (sacrament_person_id, sacrament_type),
                CONSTRAINT fk_sacrament_person_id FOREIGN KEY (sacrament_person_id)
                  REFERENCES person_per(per_ID) ON DELETE CASCADE
            ) ENGINE=InnoDB CHARACTER SET utf8 COLLATE utf8_unicode_ci'
        );
```

Attention : repérer la variable de connexion réellement utilisée dans `Bootstrapper.php` (souvent `$connection` issu de `Propel::getConnection()`).

- [ ] **Étape 5 : Générer le modèle Propel**

Depuis la racine du repo (volume host), lancer la génération. Propel tourne via le binaire vendor :
```
npm run orm-gen
```
(commande vérifiée dans package.json : `src/vendor/propel/propel/bin/propel --config-dir=propel model:build ...`).

Vérifier la présence :
- `src/EcclesiaCRM/model/EcclesiaCRM/PersonSacrament.php`
- `src/EcclesiaCRM/model/EcclesiaCRM/PersonSacramentQuery.php`
- `src/EcclesiaCRM/model/EcclesiaCRM/Base/PersonSacrament.php`
- `src/EcclesiaCRM/model/EcclesiaCRM/Map/PersonSacramentTableMap.php`

⚠️ Si `npm` n'est pas dispo en WSL, exécuter directement dans le container :
```
docker exec lamp_ecclesiacrm-php82 bash -lc "cd /var/www/html && ../propel/???"
```
NE PAS lancer — d'abord vérifier la commande executeuse : tester `docker exec lamp_ecclesiacrm-php82 bash -lc "ls /var/www/html/../../propel 2>/dev/null || echo absent"` ; si `propel/` n'est pas monté, copier le dossier `propel/` dans `/var/www/html/propel` via `docker cp`, lancer `php src/vendor/propel/propel/bin/propel --config-dir=propel model:build` dans `/var/www/html`, puis retirer la copie. (Constater le chemin réel à l'exécution.)

- [ ] **Étape 6 : Vérifier**

```bash
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/EcclesiaCRM/Bootstrapper.php
docker exec lamp_ecclesiacrm-mariadb106 mariadb -uroot -ptiger -D docker -e "SHOW CREATE TABLE person_sacrament;"
```
Attendu : PHP OK, table créée avec la contrainte FK.

- [ ] **Étape 7 : Commit (optionnel)**

```bash
git add propel/main.schema.xml src/mysql/install/Install.sql src/EcclesiaCRM/Bootstrapper.php src/EcclesiaCRM/model/EcclesiaCRM/PersonSacrament.php src/EcclesiaCRM/model/EcclesiaCRM/PersonSacramentQuery.php
git commit -m "feat: add dated sacraments per person (schema + propel model)"
```
Note : `Base/` et `Map/` générés sont dans `.gitignore` (lignes 55-56) — ne pas les ajouter.

---
## Task 2 : Contrôleur API SacramentController + routes

**Files:**
- Create: `src/EcclesiaCRM/APIControllers/SacramentController.php`
- Create: `src/api/routes/sacraments/sacraments.php`
- Modify: `src/api/index.php` (require route group)
- Test: scripts curl temporaires

**Interfaces:**
- Consumes: table `person_sacrament` (Task 1)
- Produces: routes `GET /api/sacraments/person/{id}`, `POST /api/sacraments/person/{id}`, `DELETE /api/sacraments/{id}` — utilisées par Tasks 4 et 5.

- [ ] **Étape 1 : Écrire le contrôleur**

Créer `src/EcclesiaCRM/APIControllers/SacramentController.php` (modèle : `PastoralCareController`, `SystemCustomFieldController`) :

```php
<?php

namespace EcclesiaCRM\APIControllers;

use Interop\Container\ContainerInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

use EcclesiaCRM\SessionUser;
use EcclesiaCRM\PersonSacrament;
use EcclesiaCRM\PersonSacramentQuery;

class SacramentController
{
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public static function getSacramentLabels(): array
    {
        return [
            return \EcclesiaCRM\dto\SacramentTypes::getAll();
    }

    public function getPersonSacraments(ServerRequest $request, Response $response, array $args): Response
    {
        if (!SessionUser::getUser()->isSeePrivacyDataEnabled()) {
            return $response->withStatus(403);
        }
        $personId = (int)$args['personId'];
        $sacraments = PersonSacramentQuery::create()
            ->filterByPersonId($personId)
            ->orderByDate()
            ->find();

        $labels = self::getSacramentLabels();
        $out = [];
        foreach ($sacraments as $sac) {
            $out[] = [
                'id'     => $sac->getId(),
                'type'   => $sac->getType(),
                'label'  => $labels[$sac->getType()] ?? $sac->getType(),
                'date'   => $sac->getDate() ? $sac->getDate()->format('Y-m-d') : null
            ];
        }
        return $response->withJson($out);
    }

    public function savePersonSacrament(ServerRequest $request, Response $response, array $args): Response
    {
        $user = SessionUser::getUser();
        $personId = (int)$args['personId'];
        $canEdit = $user->isEditRecordsEnabled()
            || ($user->isEditSelfEnabled() && $personId == $user->getPersonId());
        if (!$canEdit) {
            return $response->withStatus(403);
        }

        $input = (object)$request->getParsedBody();
        $type = (string)($input->type ?? '');
        $date = (string)($input->date ?? '');

        $labels = self::getSacramentLabels();
        if (!isset($labels[$type])) {
            return $response->withJson(['status' => 'error', 'message' => _('Unknown sacrament type')], 400);
        }

        $dateObj = null;
        if ($date !== '') {
            $canonical = \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($date);
            if ($canonical === false) {
                return $response->withJson(['status' => 'error', 'message' => _('Not a valid date')], 400);
            }
            $dateObj = new \DateTime($canonical);
        } elseif ($type !== 'WIDOWHOOD') {
            return $response->withJson(['status' => 'error', 'message' => _('A date is required for this sacrament')], 400);
        }

        $sac = PersonSacramentQuery::create()
            ->filterByPersonId($personId)
            ->findOneByType($type);
        if ($sac === null) {
            $sac = new PersonSacrament();
            $sac->setPersonId($personId);
            $sac->setType($type);
        }
        $sac->setDate($dateObj);
        $sac->save();

        return $response->withJson(['status' => 'success', 'id' => $sac->getId()]);
    }

    public function deletePersonSacrament(ServerRequest $request, Response $response, array $args): Response
    {
        $user = SessionUser::getUser();
        $sac = PersonSacramentQuery::create()->findOneById((int)$args['sacramentId']);
        if ($sac === null) {
            return $response->withStatus(404);
        }
        $personId = $sac->getPersonId();
        $canEdit = $user->isEditRecordsEnabled()
            || ($user->isEditSelfEnabled() && $personId == $user->getPersonId());
        if (!$canEdit) {
            return $response->withStatus(403);
        }
        $sac->delete();
        return $response->withJson(['status' => 'success']);
    }
}
```

- [ ] **Étape 2 : Écriture des routes API**

Créer `src/api/routes/sacraments/sacraments.php` :

```php
<?php

use Slim\Routing\RouteCollectorProxy;

use EcclesiaCRM\APIControllers\SacramentController;

$app->group('/sacraments', function (RouteCollectorProxy $group) {
    $group->get('/person/{personId:[0-9]+}', SacramentController::class . ':getPersonSacraments' );
    $group->post('/person/{personId:[0-9]+}', SacramentController::class . ':savePersonSacrament' );
    $group->delete('/{sacramentId:[0-9]+}', SacramentController::class . ':deletePersonSacrament' );
});
```

- [ ] **Étape 3 : Enregistrer le groupe dans `src/api/index.php`**

Trouver le bloc des `require_once __DIR__.'/routes/...'` (vers ligne 90-158) et ajouter près de pastoralcare :

```php
require_once __DIR__ . '/routes/sacraments/sacraments.php';
```

- [ ] **Étape 4 : Vérifier la syntaxe**

```bash
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/EcclesiaCRM/APIControllers/SacramentController.php
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/api/routes/sacraments/sacraments.php
```

- [ ] **Étape 5 : Test fonctionnel (curl + JWT)**

Script `docker/tmp_sac_api.sh` (login admin, récupère token, teste CRUD) :

```bash
#!/bin/bash
cd /tmp
rm -f cj.txt
curl -s -c cj.txt http://localhost/session/login -o /dev/null
curl -s -b cj.txt -c cj.txt -d 'User=admin&Password=test12!' http://localhost/session/login -o /dev/null
JWT=$(docker exec lamp_ecclesiacrm-mariadb106 mariadb -N -uroot -ptiger -D docker -e "SELECT usr_jwt_token FROM user_usr WHERE usr_per_id=1 LIMIT 1")
P=1  # person 1 = admin
echo "GET empty:"
curl -s -H "Authorization: Bearer $JWT" http://localhost/api/sacraments/person/$P
echo
echo "POST baptism:"
curl -s -X POST -H "Authorization: Bearer $JWT" -H 'Content-Type: application/json' -d '{"type":"BAPTISM","date":"1980-06-12"}' http://localhost/api/sacraments/person/$P
echo
echo "POST bad type:"
curl -s -X POST -H "Authorization: Bearer $JWT" -H 'Content-Type: application/json' -d '{"type":"NOPE","date":"1980-06-12"}' http://localhost/api/sacraments/person/$P
echo
echo "POST bad date:"
curl -s -X POST -H "Authorization: Bearer $JWT" -H 'Content-Type: application/json' -d '{"type":"BAPTISM","date":"notadate"}' http://localhost/api/sacraments/person/$P
echo
echo "GET after:"
curl -s -H "Authorization: Bearer $JWT" http://localhost/api/sacraments/person/$P
echo
rm -f cj.txt
```

Exécuter ; attendre : `[]`, puis le POST 200 success, les 2 erreurs JSON 400 (message), et GET retournant la ligne BAPTISM/1980-06-12. Puis Nettoyer la ligne de test (DELETE via la DB ou garder — vérifier que l'API DELETE fonctionne en récupérant l'id).

- [ ] **Étape 6 : Nettoyage + note utilisateur**

Supprimer `docker/tmp_sac_api.sh`. Logger : « tests curl admin effectués → F5/reconnect du navigateur » (cf. contraintes globales).

---
## Task 3 : Constantes/listes partagées (dto)

**Files:**
- Create: `src/EcclesiaCRM/dto/SacramentTypes.php`
- Modify: `src/api/index.php` (autoload : rien à faire si PSR-4 OK — vérifier `composer dump-autoload` si nécessaire)

**Interfaces:**
- Consumes: —
- Produces: `EcclesiaCRM\dto\SacramentTypes::getAll()` retournant `[code => label]` (ordre canonique) — utilisé par Tasks 4, 5, 6, 7.

- [ ] **Étape 1 : Créer le dto**

```php
<?php

namespace EcclesiaCRM\dto;

class SacramentTypes
{
    public static function getAll(): array
    {
        return [
            'BAPTISM'             => 'Baptême',
            'FIRST_COMMUNION'     => 'Première Communion',
            'CONFIRMATION'        => 'Confirmation',
            'MARRIAGE'            => 'Mariage',
            'ORDINATION_DEACON'   => 'Ordination diaconale',
            'ORDINATION_PRIEST'   => 'Ordination presbytérale',
            'ORDINATION_BISHOP'   => 'Ordination épiscopale',
            'WIDOWHOOD'           => 'Veuf/Veuve'
        ];
    }

    public static function getCodes(): array
    {
        return array_keys(self::getAll());
    }
}
```

- [ ] **Étape 2 : Réutiliser dans SacramentController (cohérence)**

Remplacer le tableau local `getSacramentLabels()` de la Task 2 par un appel à `SacramentTypes::getAll()` (garder la méthode mais la déléguer, ou supprimer et utiliser directement). **Décision :** Task 2 conserve sa méthode statique mais son contenu devient `return SacramentTypes::getAll();` pour avoir une source unique.

- [ ] **Étape 3 : Vérifier autoload**

```bash
docker exec lamp_ecclesiacrm-php82 bash -lc "php -r 'require \"/var/www/html/src/EcclesiaCRM/loadDatabase.php\"; var_export(EcclesiaCRM\\dto\\SacramentTypes::getAll());'"
```
⚠️ adapter le bootstrap (probablement pas `loadDatabase.php`). Alternative sûre : vérifier que le fichier est bien autoloadé via `composer dump-autoload` puis `php -r "var_dump(class_exists('EcclesiaCRM\\dto\\SacramentTypes'));"` dans `/var/www/html` après un bootstrap minimal. Si échec, lancer `docker exec lamp_ecclesiacrm-php82 composer dump-autoload`.

- [ ] **Étape 4 : Nettoyage**

Supprimer le script tmp utilisé.

---
## Task 4 : Fiche personne — carte "Sacrements"

**Files:**
- Modify: `src/EcclesiaCRM/VIEWControllers/People/VIEWPeopleController.php` (passer `ormPersonSacraments` dans `argumentsPeoplePersonViewArray`)
- Modify: `src/v2/templates/people/personview.php` (carte entre ligne 665 et 674)
- Modify: `src/skin/js/people/PersonView.js` (gestion AJAX "+"/delete)
- Modify: `src/v2/templates/people/personEditor.php` (zone sacrements dans l'éditeur)
- Modify: `src/v2/templates/people/personPrint.php` (affichage imprimable)

**Interfaces:**
- Consumes: `GET|POST /api/sacraments/person/{id}`, `DELETE /api/sacraments/{id}` (Task 2), `SacramentTypes::getAll()` (Task 3)
- Produces: carte visible dans la fiche personne avec bouton "+" limité aux types non saisis.

- [ ] **Étape 1 : Passer les sacrements dans les params de la fiche**

Dans `VIEWPeopleController::argumentsPeoplePersonViewArray` (bloc `$paramsArguments` ligne ~708), ajouter avant le `return` :

```php
        $ormPersonSacraments = \EcclesiaCRM\PersonSacramentQuery::create()
            ->filterByPersonId($iPersonID)
            ->orderByDate()
            ->find();

        // ... dans le tableau $paramsArguments :
        'ormPersonSacraments' => $ormPersonSacraments,
```

- [ ] **Étape 2 : Carte sacrements dans `personview.php`**

Insérer entre la fin de la carte "Overview" (ligne ~665) et le début de la carte à onglets (ligne ~674), dans `col-md-9` :

```php
<?php if (SessionUser::getUser()->isSeePrivacyDataEnabled()) {
    $allSacTypes = \EcclesiaCRM\dto\SacramentTypes::getAll();
    $usedTypes   = [];
?>
<div class="card card-outline card-secondary shadow-sm mb-3">
    <div class="card-header">
        <h3 class="card-title mb-0"><i class="fas fa-asterisk text-secondary mr-2"></i><?= _('Sacraments') ?></h3>
    </div>
    <div class="card-body">
        <ul class="list-group list-group-flush" id="sacraments-list" data-person-id="<?= $PersonInfos['iPersonID'] ?>">
        <?php
        foreach ($ormPersonSacraments as $sac) {
            $usedTypes[$sac->getType()] = true;
            $d = $sac->getDate() ? OutputUtils::change_date_for_place_holder($sac->getDate()->format('Y-m-d')) : _('(no date)');
            ?>
            <li class="list-group-item d-flex justify-content-between align-items-center"
                data-sacrament-id="<?= $sac->getId() ?>">
                <span><i class="fas fa-tag text-secondary mr-2"></i><?= $allSacTypes[$sac->getType()] ?? $sac->getType() ?>
                    — <span class="text-muted"><?= $d ?></span></span>
                <?php if ($bOkToEdit) { ?>
                <span>
                    <a class="btn btn-sm btn-outline-danger remove-sacrament" data-sacrament-id="<?= $sac->getId() ?>" title="<?= _('Delete') ?>"><i class="fas fa-trash-alt"></i></a>
                </span>
                <?php } ?>
            </li>
            <?php
        }
        ?>
        </ul>
        <?php if ($bOkToEdit) {
            $available = array_diff_key($allSacTypes, $usedTypes);
            if (!empty($available)) { ?>
        <div class="mt-3 d-flex align-items-center">
            <div class="btn-group">
                <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-plus mr-1"></i><?= _('Add sacrament') ?>
                </button>
                <div class="dropdown-menu add-sacrament-dropdown">
                <?php foreach ($available as $code => $label) { ?>
                    <a class="dropdown-item add-sacrament" href="#" data-type="<?= $code ?>" data-type-id-sak="<?= $code ?>"><?= $label ?></a>
                <?php } ?>
                </div>
            </div>
        </div>
        <div id="sacrament-date-form" class="mt-2 d-none">
            <div class="input-group input-group-sm" style="max-width:280px">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-calendar"></i></span></div>
                <input type="text" class="form-control date-picker" id="sacrament-date-input" placeholder="<?= SystemConfig::getValue('sDatePickerPlaceHolder') ?>">
            </div>
            <button class="btn btn-sm btn-success mt-2" id="sacrament-date-save"><i class="fas fa-check mr-1"></i><?= _('Save') ?></button>
        </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
<?php } ?>
```

- [ ] **Étape 3 : JS AJAX dans `PersonView.js`**

Ajouter en fin de `$(function(){...})` (une fois, dans le même fichier) :

```js
  // --- Sacraments (fork) ---
  $('#sacraments-list').on('click', '.remove-sacrament', function () {
    var id = $(this).data('sacrament-id');
    $.ajax({
      url: window.CRM.root + '/api/sacraments/' + id,
      method: 'DELETE',
      headers: { Authorization: 'Bearer ' + window.CRM.jwtToken }
    }).done(function () { location.reload(); });
  });

  var sacramentTypeToAdd = null;
  $('.add-sacrament').on('click', function (e) {
    e.preventDefault();
    sacramentTypeToAdd = $(this).data('type');
    $('#sacrament-date-form').removeClass('d-none');
  });
  $('#sacrament-date-save').on('click', function () {
    var dateVal = $('#sacrament-date-input').val() || '';
    var personId = $('#sacraments-list').data('person-id');
    $.ajax({
      url: window.CRM.root + '/api/sacraments/person/' + personId,
      method: 'POST',
      headers: { Authorization: 'Bearer ' + window.CRM.jwtToken },
      contentType: 'application/json',
      data: JSON.stringify({ type: sacramentTypeToAdd, date: dateVal })
    }).done(function () { location.reload(); })
      .fail(function (x) { showActionFeedback(x.responseJSON ? x.responseJSON.message : i18next.t('Error')); });
  });
```

NB: `window.CRM.currentPersonId` n'existe PAS dans ce codebase. Le personId est remonte dans la vue via `$PersonInfos['iPersonID']` ; la carte porte `data-person-id="<?= $PersonInfos['iPersonID'] ?>"` et le JS lit `$('#sacraments-list').data('person-id')`. Ne surtout pas utiliser `$('#PersonID')` (inexistant).

- [ ] **Étape 4 : Éditeur personne (`personEditor.php`)**

L'éditeur est rendu par `VIEWPeopleController::personEditor` (`VIEWPeopleController.php:243`) qui passe `argumentsPeoplePersonEditorArray($iPersonID, $iFamilyID)` (ligne 280, variable `iPersonID` ligne 288). Le template inclut `skin/js/people/PersonEditor.js` (line 1960), PAS `PersonView.js` — donc le bloc JS sacrements doit être ajouté dans `PersonEditor.js` (même code que Task 4 Étape 3, avec les ancres `#sacraments-list`, `.add-sacrament`, `#sacrament-date-save` et `data-person-id`).

Ajouter dans `argumentsPeoplePersonEditorArray` :
```php
        // Sacraments (fork)
        $ormPersonSacraments = \EcclesiaCRM\PersonSacramentQuery::create()
            ->filterByPersonId($iPersonID)
            ->orderByDate()
            ->find();
```
et dans le `return [ ... ]` :
```php
        'ormPersonSacraments' => $ormPersonSacraments,
```
Dans `personEditor.php`, insérer la même carte Sacrements que `personview.php` (Task 4 Étape 2), en remplaçant `$PersonInfos['iPersonID']` par `$iPersonID` et en re-utilisant `$bOkToEdit` s'il est défini dans ce template (sinon `SessionUser::getUser()->isEditRecordsEnabled() || SessionUser::getUser()->isEditSelfEnabled()`). Le POST de sauvegarde (lignes 371-378 et 636-641) ne touche pas aux sacrements : ils sont gérés par l'API AJAX, PAS par le formulaire POST de l'éditeur.

- [ ] **Étape 5 : Person print**

Dans `personPrint.php`, ajouter une section (respectant `isSeePrivacyDataEnabled()`) qui affiche la liste des sacrements (nom + date) en lecture seule. Le personId est dispo dans `argumentsPeoplePersonPrintArray($iPersonID)` (`VIEWPeopleController.php:1358`) : y ajouter la requete `PersonSacramentQuery` et passer `ormPersonSacraments` comme variable de template (meme methode serveur que Task 4 Etape 1).

- [ ] **Étape 6 : Vérifier**

```bash
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/EcclesiaCRM/VIEWControllers/People/VIEWPeopleController.php
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/v2/templates/people/personview.php
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/v2/templates/people/personEditor.php
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/v2/templates/people/personPrint.php
```
Page : login curl puis `curl -b cj.txt http://localhost/v2/people/person/view/1` → la carte Sacrements apparaît (avec les sacrements de test de la Task 2 si présents), le bouton "+" ne liste pas BAPTISM déjà saisi. Compléter par un test navigateur (l'utilisateur validera).

- [ ] **Étape 7 : Nettoyage scripts tmp + note utilisateur**

---
## Task 5 : Filtres — liste des personnes (côté serveur)

**Files:**
- Modify: `src/EcclesiaCRM/VIEWControllers/People/VIEWPersonListController.php`
- Modify: `src/v2/templates/people/personlist.php` (panneau filtres sacrements)
- Modify: `src/skin/js/people/PersonList.js` (soumission/rechargement)

**Interfaces:**
- Consumes: `SacramentTypes::getAll()` (Task 3), table `person_sacrament`
- Produces: querystring `sac_BAPTISM=1|0|absent`, `sac_date_year`, `sac_date_from`, `sac_date_to` interprétée dans la requête `PersonQuery`.

- [ ] **Étape 1 : Étendre le contrôleur**

Dans `renderPersonList`, lire les params :

```php
        $params = $request->getQueryParams();
        return $renderer->render($response, 'personlist.php', $this->argumentsPersonListArray($sMode, $params));
```

Dans `argumentsPersonListArray($sMode='Active', $params=[])`, après la construction de `$persons`, appliquer la logique de filtre sacrement. Extraire un helper privé `applySacramentFilters(PersonQuery $q, array $params): PersonQuery` :

```php
    private function applySacramentFilters(PersonQuery $q, array $paramsQ): PersonQuery
    {
        $codes = \EcclesiaCRM\dto\SacramentTypes::getCodes();

        $year = isset($paramsQ['sac_date_year']) ? trim($paramsQ['sac_date_year']) : '';
        $from = isset($paramsQ['sac_date_from']) ? trim($paramsQ['sac_date_from']) : '';
        $to   = isset($paramsQ['sac_date_to'])   ? trim($paramsQ['sac_date_to'])   : '';

        $dateConds = [];
        if (preg_match('/^\d{4}$/', $year)) {
            $dateConds[] = "(sacrament_date >= '$year-01-01' AND sacrament_date <= '$year-12-31')";
        }
        if ($from !== '' && \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($from) !== false) {
            $dateConds[] = "(sacrament_date >= '" . \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($from) . "')";
        }
        if ($to !== '' && \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($to) !== false) {
            $dateConds[] = "(sacrament_date <= '" . \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($to) . "')";
        }
        $dateSql = empty($dateConds) ? '' : (' AND ' . implode(' AND ', $dateConds));

        $clauses = [];
        $params  = [];
        foreach ($codes as $code) {
            $key = 'sac_' . $code;
            if (!isset($paramsQ[$key])) {
                continue;
            }
            $val = $paramsQ[$key];
            if ($val === '1') {
                $clauses[] = "(EXISTS (SELECT 1 FROM person_sacrament WHERE sacrament_person_id = person_per.per_ID AND sacrament_type = ?$dateSql))";
                $params[]  = $code;
            } elseif ($val === '0') {
                $clauses[] = "(NOT EXISTS (SELECT 1 FROM person_sacrament WHERE sacrament_person_id = person_per.per_ID AND sacrament_type = ?$dateSql))";
                $params[]  = $code;
            }
        }
        if (!empty($clauses)) {
            $q = $q->where(implode(' AND ', $clauses), $params, \PDO::PARAM_STR);
        }
        return $q;
    }
```

⚠️ Avec Propel `->where()` il faut référencer la table parente correcte (`person_per.per_ID`). Vérifier le comportement Propel exact (`where()` dans une query utilisée avec `find()`). S'assurer aussi que `->where` avec les 3 modes fonctionne (les valeurs par défaut quand pas de filtre).

- [ ] **Étape 2 : Panneau filtres dans `personlist.php`**

Ajouter une carte "Sacrements" au-dessus du tableau (après la barre Add person, ligne ~40), formulare GET sur la page courante, masquée sans `isSeePrivacyDataEnabled()` :

```php
<?php if (SessionUser::getUser()->isSeePrivacyDataEnabled()) { ?>
<div class="card card-warning card-outline mb-3">
    <div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-asterisk text-warning mr-2"></i><?= _('Sacrament filters') ?></h3></div>
    <div class="card-body">
        <form method="GET" action="<?= $sRootPath ?>/v2/personlist/<?= strtolower($sMode) ?>">
            <?php foreach (\EcclesiaCRM\dto\SacramentTypes::getAll() as $code => $label) { ?>
            <div class="row align-items-center mb-2">
                <div class="col-md-3 font-weight-bold"><?= $label ?></div>
                <div class="col-md-3">
                    <select name="sac_<?= $code ?>" class="form-control form-control-sm">
                        <option value=""><?= _('All') ?></option>
                        <option value="1"<?= (isset($_GET['sac_' . $code]) && $_GET['sac_'.$code] === '1') ? ' selected' : '' ?>><?= _('Has it') ?></option>
                        <option value="0"<?= (isset($_GET['sac_' . $code]) && $_GET['sac_'.$code] === '0') ? ' selected' : '' ?>><?= _('Without it') ?></option>
                    </select>
                </div>
            </div>
            <?php } ?>
            <hr>
            <div class="row align-items-center mb-2">
                <div class="col-md-3 font-weight-bold"><?= _('Year') ?></div>
                <div class="col-md-3"><input type="text" class="form-control form-control-sm" name="sac_date_year" value="<?= htmlentities($_GET['sac_date_year'] ?? '') ?>" placeholder="2020"></div>
            </div>
            <div class="row align-items-center mb-2">
                <div class="col-md-3 font-weight-bold"><?= _('After') ?></div>
                <div class="col-md-3"><input type="text" class="form-control form-control-sm date-picker" name="sac_date_from" value="<?= htmlentities($_GET['sac_date_from'] ?? '') ?>"></div>
            </div>
            <div class="row align-items-center mb-2">
                <div class="col-md-3 font-weight-bold"><?= _('Before') ?></div>
                <div class="col-md-3"><input type="text" class="form-control form-control-sm date-picker" name="sac_date_to" value="<?= htmlentities($_GET['sac_date_to'] ?? '') ?>"></div>
            </div>
            <button type="submit" class="btn btn-warning btn-sm mt-2"><i class="fas fa-filter mr-1"></i><?= _('Apply filters') ?></button>
            <a href="<?= $sRootPath ?>/v2/personlist/<?= strtolower($sMode) ?>" class="btn btn-outline-secondary btn-sm mt-2"><?=  _("Reset") ?></a>
        </form>
    </div>
</div>
<?php } ?>
```


- [ ] **Étape 3 : Réutiliser dans le template — URL du form**

Le formulaire GET (Étape 2) poste vers `<?= $sRootPath ?>/v2/personlist/<?= strtolower($sMode) ?>`
et le lien *Reset* vers la même page sans querystring, pour conserver le mode
(`active`/`inactive`/`gdrp`). `$sMode` est déjà injecté dans le template (le
contrôleur le normalise : `strtolower('Active')` => `'active'`). Ne PAS utiliser
`/v2/personlistActive` (aucune route ne correspond).

- [ ] **Étape 4 : PersonList.js**

Vérifier que `PersonList.js` n'écrase pas le form (s'il bind des handlers DataTable seulement, pas de conflit). Ajouter si besoin l'init des `.date-picker` (le code global AdminLTE le fait probablement — vérifier au runtime).

- [ ] **Étape 5 : Vérifier**

Créer 2-3 personnes de test + sacrements variés en base (script tmp), puis :
```bash
# active list, no filter
curl -s -b cj.txt "http://localhost/v2/personlist" | grep -c '<tr'
# filter HasIt baptism
curl -s -b cj.txt "http://localhost/v2/personlist?sac_BAPTISM=1" | grep -c '<tr'
# filter WithoutIt baptism
curl -s -b cj.txt "http://localhost/v2/personlist?sac_BAPTISM=0" | grep -c '<tr'
# year filter
curl -s -b cj.txt "http://localhost/v2/personlist?sac_BAPTISM=1&sac_date_year=1980" | grep -c '<tr'
```
Attendu : cohérence (Oui + Non = total ; année réduit). Nettoyer ensuite les données de test.

- [ ] **Étape 6 : Commit optionnel + nettoyage + note utilisateur**

---
## Task 6 : Filtres — annuaire

**Files:**
- Modify: `src/v2/templates/people/directoryreport.php` (blocs filtres sacrements)
- Modify: `src/Reports/DirectoryReport.php` (ajout clauses `$sWhereExt`)

**Interfaces:**
- Consumes: `SacramentTypes::getAll()` (Task 3)
- Produces: POST `sac_BAPTISM` (1/0/absent) + `sac_date_year|from|to` appliqués dans le rapport.

- [ ] **Étape 1 : Formulaire dans `directoryreport.php`**

Ajouter une carte de filtres "Sacrements" dans le formulaire POST (pattern des cartes existantes, vers ligne 236-271) :

```php
<div class="border rounded p-3 mb-3">
    <h6 class="font-weight-bold mb-3"><i class="fas fa-asterisk text-info mr-1"></i><?= _('Sacrament filters') ?></h6>
    <div class="row">
        <?php foreach (\EcclesiaCRM\dto\SacramentTypes::getAll() as $code => $label) { ?>
        <div class="col-md-4 mb-2">
            <label class="font-weight-bold"><?= $label ?></label>
            <select name="sac_<?= $code ?>" class="form-control form-control-sm">
                <option value=""><?= _('All') ?></option>
                <option value="1"><?= _('Has it') ?></option>
                <option value="0"><?= _('Without it') ?></option>
            </select>
        </div>
        <?php } ?>
    </div>
    <div class="row">
        <div class="col-md-3 mb-2"><label><?= _('Year') ?></label><input type="text" class="form-control form-control-sm" name="sac_date_year" placeholder="2020"></div>
        <div class="col-md-3 mb-2"><label><?= _('After') ?></label><input type="text" class="form-control form-control-sm date-picker" name="sac_date_from"></div>
        <div class="col-md-3 mb-2"><label><?= _('Before') ?></label><input type="text" class="form-control form-control-sm date-picker" name="sac_date_to"></div>
    </div>
</div>
```

- [ ] **Étape 2 : Application dans `Reports/DirectoryReport.php`**

Juste avant la construction de `$sSQL` (ligne ~125), ajouter :

```php
        // Sacrament filters (fork)
        $sacYear = isset($_POST['sac_date_year']) ? trim($_POST['sac_date_year']) : '';
        $sacFrom = isset($_POST['sac_date_from']) ? trim($_POST['sac_date_from']) : '';
        $sacTo   = isset($_POST['sac_date_to'])   ? trim($_POST['sac_date_to'])   : '';

        $sacDateConds = [];
        if (preg_match('/^\d{4}$/', $sacYear)) {
            $sacDateConds[] = "(sacrament_date >= '$sacYear-01-01' AND sacrament_date <= '$sacYear-12-31')";
        }
        if ($sacFrom !== '' && ($df = \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($sacFrom)) !== false) {
            $sacDateConds[] = "(sacrament_date >= '$df')";
        }
        if ($sacTo !== '' && ($dt = \EcclesiaCRM\Utils\InputUtils::parseAndValidateDate($sacTo)) !== false) {
            $sacDateConds[] = "(sacrament_date <= '$dt')";
        }
        $sacDateSql = empty($sacDateConds) ? '' : (' AND ' . implode(' AND ', $sacDateConds));

        foreach (\EcclesiaCRM\dto\SacramentTypes::getCodes() as $code) {
            if (isset($_POST['sac_' . $code]) && $_POST['sac_' . $code] === '1') {
                $sWhereExt .= " AND EXISTS (SELECT 1 FROM person_sacrament WHERE sacrament_person_id = per_ID AND sacrament_type = '$code'$sacDateSql)";
            } elseif (isset($_POST['sac_' . $code]) && $_POST['sac_' . $code] === '0') {
                $sWhereExt .= " AND NOT EXISTS (SELECT 1 FROM person_sacrament WHERE sacrament_person_id = per_ID AND sacrament_type = '$code'$sacDateSql)";
            }
        }
```
⚠️ Le rapport combine 3 SELECT UNION : vérifier que `per_ID` (sans préfixe) est accessible dans tous (la table est `person_per` aliasée implicite `person_per` dans `$sGroupTable`) — utiliser `person_per.per_ID` explicite si besoin.

- [ ] **Étape 3 : Vérifier**

```bash
docker exec lamp_ecclesiacrm-php82 php -l /var/www/html/src/Reports/DirectoryReport.php
```
Test navigateur : page annuaire avec filtre HasIt baptism → le PDF produit n'inclut que la personne test. (La génération PDF est lente ; valider au moins l'absence d'erreur SQL en POSTant le form.)

- [ ] **Étape 4 : Commit optionnel + nettoyage**

---
## Task 7 : Documentation + vérification finale

**Files:**
- Modify: `AGENTS.md` (section "Sacrements datés")
- Modify: `docker/README.md` (table + orm-gen pour reinstallation)

- [ ] **Étape 1 : AGENTS.md**

Ajouter à la liste "Modifications validées à conserver" un point 5 :

```markdown
5. **Sacrements datés par personne** (table `person_sacrament`, modèle Propel
   `PersonSacrament`) : 8 types fixes (`SacramentTypes` dto), saisis depuis la
   fiche personne (carte "Sacrements", bouton "+" pour les types non saisis),
   API `GET|POST /api/sacraments/person/{id}` + `DELETE /api/sacraments/{id}`.
   Filtres (présence/absence + année/plage) dans `/v2/personlist` et l'annuaire
   (`DirectoryReport`, clauses `$sWhereExt` `EXISTS`/`NOT EXISTS`). Bordures de
   dates côté serveur. Pas de fusion avec Pastoral Care (coexistence assumée).
   Dialecte : `propel/main.schema.xml` + `npm run orm-gen` pour régénérer les modèles.
```

- [ ] **Étape 2 : docker/README.md**

Ajouter au flux "reinstallation from scratch" que la nouvelle table et la génération Propel (via `npm run orm-gen`) doivent être refaites ; documenter la table et les endpoints.

- [ ] **Étape 3 : Vérification finale globale**

```bash
# 1. php -l sur tous les fichiers modifiés
find /home/khbgbgk/ecclesiacrm/src -name '*.php' -newer /home/khbgbgk/ecclesiacrm/docs/superpowers/specs/2026-09-20-sacrements-dates-design.md -exec docker exec lamp_ecclesiacrm-php82 php -l {} \;
# 2. dashboard + listes répondent
curl -s -o /dev/null -w '%{http_code}\n' -b cj.txt http://localhost/v2/dashboard
curl -s -o /dev/null -w '%{http_code}\n' -b cj.txt http://localhost/v2/personlist
curl -s -o /dev/null -w '%{http_code}\n' -b cj.txt http://localhost/v2/people/directoryreport
curl -s -o /dev/null -w '%{http_code}\n' -b cj.txt http://localhost/v2/people/person/view/1
# 3. API sacraments respond
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $JWT" http://localhost/api/sacraments/person/1
```
Attendus : tous 200 (le filemtime newermt n'inclut pas les fichiers créés dans les tâches précédentes — utiliser un marqueur : `touch /tmp/marker` au début et `-newer /tmp/marker`).

- [ ] **Étape 4 : Nettoyage TOTAL des scripts tmp**

Supprimer tous `docker/tmp_*.sh` et `/tmp/*.html`. Vérifier :
```bash
ls /home/khbgbgk/ecclesiacrm/docker/tmp_* 2>/dev/null || echo "no tmp scripts left"
```

- [ ] **Étape 5 : Note utilisateur finale**

Récapituler : refaire F5/reconnect (JWT), là où saisir, là où filtrer ; rappeler que les tests curl ont invalidié les sessions ouvertes.

---
## Notes transverse

- **`bOkToEdit`** dans personview : variable existante du template (ligne 84) — vérifier sa définition dans `argumentsPeoplePersonViewArray` (elle existe dans le diff fourni ligne 708-789) ; sinon utiliser `SessionUser::getUser()->isEditRecordsEnabled() || isEditSelfEnabled()`.
- **`$sMode` dans `personlist.php`** : existant, sert au form GET de la Task 5.
- **Le formulaire GET de la Task 5 et la DataTable** : la DataTable client se réinitialise au rechargement (form submit = navigation GET), pas besoin de `draw()`.
- **`window.CRM.jwtToken`** : exposé sur les pages v2 (Header-function.php). Vérifié aujourd'hui.
