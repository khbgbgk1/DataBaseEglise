# Design — Sacrements datés par personne (filtrables)

Date : 2026-09-20
Projet : EcclesiaCRM (fork locale, LAMP docker)

## Contexte et objectif

L'utilisateur souhaite suivre, par personne, les sacrements/événements religieux
avec une **date** :

- Baptême
- Première Communion
- Confirmation
- Mariage
- Ordination diaconale
- Ordination presbytérale
- Ordination épiscopale
- Veuvage (statut veuf/vive, daté du décès du conjoint, date optionnelle)

Exigences métier (validées en brainstorming) :

1. Saisie par "ligne ajoutable" : un sacrement n'apparaît dans une fiche que s'il
   a été saisi (champ "pour ajouter" avec un `+`, les cases vides ne s'affichent pas).
2. L'information remonte dans les **fiches personne** (miniatures/zone dédiée) et
   doit être **filtrable**.
3. Filtres dans la **liste des personnes** (`/v2/people/personlist`) et dans
   l'**annuaire** (`directoryreport.php` / `Reports/DirectoryReport.php`) :
   - par sacrement : **présence** (Oui) et **absence** (Non, filtre négatif
     "sans ce sacrement") ;
   - par **date** : plage *après le* + *avant le*, ou **année précise**.
4. Les types "Baptême (Formation)" / "Mariage (Formation)" de l'existant
   *Pastoral Care* restent **visibles et séparés** : pas de fusion, les deux
   systèmes coexistent (notes pastorales d'un côté, champs datés filtrables de
   l'autre).

## Données

Nouvelle table `person_sacrament` :

```
sacrament_id               INT UNSIGNED PK AUTO_INCREMENT
sacrament_person_id        INT          NOT NULL  (FK -> person_per per_ID, indexé)
sacrament_type             VARCHAR(50)  NOT NULL  (clé code)
sacrament_date             DATE         NULL
UNIQUE KEY uq_sacrament_person_type (sacrament_person_id, sacrament_type)
```

Type de sacrement : **code** en base (`BAPTISM`, `FIRST_COMMUNION`,
`CONFIRMATION`, `MARRIAGE`, `ORDINATION_DEACON`, `ORDINATION_PRIEST`,
`ORDINATION_BISHOP`, `WIDOWHOOD`), libellé affiché en français via un tableau
statique de correspondance (pas de table dédiée : 8 valeurs fixes, évite une
table de référence supplémentaire).

Contraintes d'intégrité : FK vers `person_per`, `ON DELETE CASCADE` ; unicité
(personne, type) ; `sacrament_date` nullable (cas veuvage sans date connue).

## Architecture / fichiers touchés

1. **Propel** :
   - nouveau `propel/sacrament.schema.xml` (table `person_sacrament`
     phpName `PersonSacrament`) ;
   - génération des modèles : `propel model:build` (cible répertoires
     `src/EcclesiaCRM/model/Base|Map` + classes). À valider en implémentation
     que la génération est isolée et sûre sans rollback git (dossier non versionné
     ; on fera un backup des fichiers modèles avant) ;
   - pas de dépendance composer ajoutée.

2. **Schéma d'installation** :
   - ajout du `CREATE TABLE` dans `src/mysql/install/Install.sql` (ordre : après
     `person_per`) ;
   - migration appliquée à la base courante (idempotente : `IF NOT EXISTS`).

3. **Couche métier/API** : `EcclesiaCRM/APIControllers/SacramentController.php` :
   - `GET /api/sacraments/person/{personId}` → liste des sacrements de la personne ;
   - `POST /api/sacraments/person/{personId}` → ajout/MAJ (personId, type, date) ;
   - `DELETE /api/sacraments/{sacramentId}` → suppression.
   - Permissions : lecture si `isSeePrivacyDataEnabled()` ; écriture si
     `isEditRecordsEnabled()` (ou `isEditSelfEnabled()` quand personId = utilisateur).

4. **Fiche personne** `src/v2/templates/people/personview.php` :
   - nouvelle carte "Sacrements" listant les lignes saisies (icône de sacrement +
     nom + date, boutons ✏️/🗑️) ;
   - bouton **"+ Ajouter"** : dropdown des types non encore saisis (masqué si les
     8 sont saisis) → invite à choisir une date (obligatoire sauf veuvage) → Save ;
   - rendu côté serveur (passer les sacrements dans `$paramsArguments`) + petit JS
     pour l'ajout/suppression en AJAX.

5. **Éditeur personne** `src/v2/templates/people/personEditor.php` :
   - mêmes lignes saisissables (ajout/édition/suppression), pour l'information qui
     "remonte" et reste modifiable.

6. **Person Print** `src/v2/templates/people/personPrint.php` : zone "Sacrements"
   en lecture seule (affichage imprimable).

7. **Liste des personnes** `VIEWPersonListController::argumentsPersonListArray`
   + template `personlist.php` :
   - panneau de filtres "Sacrements" (affiché quand le visiteur a le droit de voir
     les données privées) ;
   - par sacrement : sélecteur **Tous / Oui / Non** ;
   - dates : champ **année** + champs **après le / avant le** (plage) ;
   - application côté serveur dans la requête Propel : `filterBy` sur une sous
     requête `PersonSacramentQuery` (Oui) / `NOT EXISTS` (Non) + conditions de
     dates ; paramètres en querystring lus par le contrôleur :
     `sac_BAPTISM` (valeurs `1`=Oui, `0`=Non, absent=Tous), et pour les dates
     `sac_date_year` (année précise, prioritaire si renseignée), `sac_date_from`,
     `sac_date_to` (plage, bornes inclusives).

8. **Annuaire** `src/v2/templates/people/directoryreport.php` + rapport
   `src/Reports/DirectoryReport.php` :
   - mêmes blocs de filtres (Tous/Oui/Non + dates) dans le formulaire POST ;
   - application dans la requête SQL du rapport.

## Filtrage — précisions

- "Oui" : `EXISTS (SELECT 1 FROM person_sacrament ps WHERE ps.sacrament_person_id = p.per_ID AND ps.sacrament_type = '<X>' [AND dates…])`
- "Non" : `NOT EXISTS` (même sous-requête). Le filtre négatif signifie "n'a pas
  ce sacrement" ; combiné à une plage de dates il signifie "n'a pas ce sacrement
  (avec une date éventuellement dans la plage)" — on applique les dates dans la
  sous-requête pour rester cohérent.
- Filtre année précise : transformé en `date >= YYYY-01-01 AND date <= YYYY-12-31`.

## Cas limites / erreurs

- Type de sacrement inconnu ou vide → 400.
- Ajout d'un type déjà présent pour la personne → MAJ de la date (upsert) plutôt
  qu'erreur (l'unicité en base impose l'upsert).
- `sacrament_date` invalide → 400.
- Aucun droit d'écriture → 403, de lecture → 403.
- La carte "Sacrements" ne s'affiche pas du tout pour un visiteur sans droit vue
  données privées (pas de liste vide).
- Veuvage : date possible, mais permise vide.

## Tests / vérification

- `php -l` sur chaque fichier PHP modifié.
- Vérification SQL : `CREATE TABLE` rejouable (idempotent).
- API : créer/upsert/supprimer un sacrement via curl (login admin + JWT), vérifier
  les codes 200/400/403.
- Fiche/éditeur : la carte s'affiche, le `+` ne liste que les types non saisis,
  date requise (hors veuvage).
- Filtres liste des personnes + annuaire : jeu de données de test (2-3 personnes,
  combinaisons de sacrements/dates) ; vérifier les cas Oui / Non / période / année.
- Après tests curl avec login : l'utilisateur reconnecte ou F5 sa session
  navigateur (règle JWT documentée dans AGENTS.md).

## Documentation à mettre à jour

- `AGENTS.md` : nouvelle section "Sacrements datés" (table, endpoint, filtres,
  coexistence avec Pastoral Care).
- `docker/README.md` : création table + génération Propel pour une réinstallation
  from scratch.

## Hors périmètre (volontairement)

- Pas de fusion avec Pastoral Care (coexistence demandée).
- Pas de table de référence dynamique des sacrements (liste fixe de 8 codes).
- Pas de permissions fines par sacrement/date.
- Pas de colonne sacrement dans l'annuaire PDF (les lignes de données privées
  restent conformes aux droits).
