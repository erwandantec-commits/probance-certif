# Guide administrateur

Ce guide explique le fonctionnement des principaux ecrans d'administration, sans entrer dans le code.

## Role de l'espace admin

L'espace admin sert a:

- suivre les sessions passees par les candidats
- consulter le detail d'un candidat
- gerer les certifications
- gerer les packs
- gerer les questions
- analyser la performance des questions
- administrer les utilisateurs

## Sessions

La page `Sessions` permet de surveiller les passages recents.

Tu peux y voir:

- le candidat
- le pack
- le type de session
- le score
- le statut
- un acces au detail

Le detail d'une session permet ensuite de:

- revoir les questions posees
- voir les reponses du candidat
- verifier les bonnes reponses
- ouvrir rapidement la fiche question
- ouvrir l'analyse de performance de la question

## Candidat

La fiche candidat centralise la vision par personne.

Elle permet notamment de consulter:

- les informations du candidat
- son historique de sessions
- ses certifications
- certains parametres admin lies a son parcours

## Certifications

La page `Certifications` donne une vue de suivi par candidat et par pack.

On y retrouve en general:

- l'etat de la certification
- la date de derniere obtention
- la date d'expiration
- l'acces au detail de la derniere session

## Utilisateurs

La page `Utilisateurs` sert a gerer les comptes applicatifs.

Elle permet de:

- consulter la liste des comptes
- modifier certaines informations utilisateur
- ajuster le role si necessaire

## Packs

La page `Packs` sert a gerer les certifications disponibles dans l'outil.

Un pack peut definir:

- son nom
- sa couleur d'affichage
- sa duree
- son seuil de reussite
- son nombre de questions
- sa logique de selection
- sa validite de certification
- le cooldown apres echec

Depuis `Modifier pack`, il est possible de:

- ajuster les parametres du pack
- revoir les questions associees
- ouvrir l'edition d'une question
- ouvrir la vue performance d'une question

## Questions

La page `Questions` est la banque de questions globale.

Elle permet de:

- rechercher des questions
- les filtrer
- les modifier
- les supprimer
- ouvrir la performance d'une question

## Import de questions

La page d'import sert a injecter ou mettre a jour des questions en masse.

Trois modes existent:

- `Reinitialisation`: cree les nouvelles questions et remplace les questions/reponses deja existantes a partir de leur ID. Seules les lignes presentes dans le fichier sont traitees. Les questions absentes du fichier ne sont pas supprimees. Les traductions existantes sont marquees a revoir, et les traductions de reponses sont supprimees quand les reponses sont recreees.
- `Mise a jour`: cree ou met a jour uniquement les questions/reponses de reference a partir de leur ID. Ce mode ne lit pas les colonnes de traduction.
- `Traductions`: met a jour uniquement les textes traduits de la langue choisie, pour des questions qui existent deja en source. Ce mode ne modifie pas la source, les bonnes reponses, les categories ou les niveaux.

En mode reinitialisation ou mise a jour, si une question existante est modifiee, ses reponses sont supprimees puis recreees.

La langue source est definie sur le programme. En modes `Reinitialisation` et `Mise a jour`, l'import force cette langue et indique la langue attendue pour le fichier. En mode `Traductions`, la liste des langues exclut la langue source du programme.

### FAQ

#### Si je reimporte une question avec le meme ID, que se passe-t-il avec les traductions?

Si la question est reimportee dans le meme programme avec le meme ID externe, l'import retrouve la meme question interne. La traduction de l'enonce reste donc liee a la question, mais elle est marquee a revoir car la source a ete modifiee.

Les reponses, elles, sont supprimees puis recreees lors d'une reinitialisation ou d'une mise a jour. Les traductions des reponses sont donc supprimees et devront etre reimportees en mode `Traductions`.

## Analyse

La page `Analyse` donne une vue agregee par question sur l'ensemble des sessions terminees ou expirees.

### Statistiques globales

Un bandeau en haut affiche les indicateurs de synthese correspondant aux filtres actifs :

- **Questions** — nombre de questions distinctes ayant recu au moins une reponse
- **Reponses** — nombre total de reponses enregistrees
- **Taux de reussite** — pourcentage global de reponses correctes
- **Taux d'echec** — pourcentage global de reponses incorrectes
- **Inchangees** — questions dont le texte est identique a celui vu en session
- **Modifiees** — questions dont le texte a change apres certaines sessions
- **Supprimees** — questions effacees de la banque mais encore presentes dans l'historique

### Filtres de portee

| Filtre | Usage |
|--------|-------|
| ID de question | retrouve une question precise par son identifiant externe |
| Type de session | limite aux sessions Examen, Entrainement, ou les deux |
| Pack | isole un pack en particulier |
| Etat de la question | filtre sur Inchangee, Modifiee ou Supprimee |
| Categorie | filtre par valeur `knowledge_required` |
| Date debut / fin | cible une plage temporelle sur la date de debut de session |

### Filtres avances sur les metriques

Pour chaque metrique, un comparateur permet d'affiner la liste :

- `=` exact
- `>=` superieur ou egal
- `<=` inferieur ou egal
- `entre` — deux valeurs encadrant une plage

Metriques disponibles : taux de reussite (%), taux d'echec (%), nombre de reponses.

Exemple d'usage : afficher uniquement les questions avec un taux d'echec >= 60 % et au moins 10 reponses.

### Tableau des questions

Le tableau est trie par la colonne choisie (clic sur l'en-tete) et pagine a 20 lignes par page.

| Colonne | Description |
|---------|-------------|
| Rang | Classement global par taux de reussite decroissant, calcule sur toutes les questions |
| ID | Identifiant externe de la question |
| Question | Texte tronque a 110 caracteres |
| Categorie | Valeur `knowledge_required` |
| Etat | Pilule : Inchangee / Modifiee / Supprimee |
| Reponses | Nombre de fois ou la question a ete soumise |
| % OK | Taux de reponses correctes |
| % KO | Taux de reponses incorrectes |
| Actions | Ouvrir l'editeur de la question · Zoom sur les sessions |

### Logique de snapshot

Les sessions enregistrent un instantane du texte de la question au moment du passage. Cela signifie :

- une question **Modifiee** conserve ses statistiques historiques — les chiffres restent valides, mais le texte affiche est le texte actuel
- une question **Supprimee** reste visible dans l'analyse tant qu'elle a au moins une reponse enregistree

### Zoom : detail des sessions d'une question

Le bouton loupe ouvre la page de detail qui liste chaque session ayant inclus la question :

- date et heure du passage
- candidat (email)
- pack et type de session
- resultat : OK (reponse correcte) ou KO (incorrecte)
- lien vers le detail complet de la session

Les filtres de type de session et de plage de dates de la page principale sont propages automatiquement.

### Cas d'usage typiques

- **Questions difficiles** — trier par `% KO` decroissant pour identifier les questions problematiques et revoir leur formulation ou leurs reponses
- **Questions peu utilisees** — trier par `Reponses` croissant pour reperer les questions rarement tirees
- **Banque obsolete** — filtrer sur `Modifiee` ou `Supprimee` pour gerer les questions qui ne correspondent plus a la source
- **Effet d'une periode** — combiner les filtres de dates et de pack pour comparer des cohortes ou evaluer l'impact d'une mise a jour
