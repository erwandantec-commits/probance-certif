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

La page `Analyse` donne une vue agregee par question.

Elle aide a reperer:

- les questions tres reussies
- les questions souvent echouees
- les questions peu utilisees
- les effets d'un filtre de dates, pack ou type de session

Le bouton de zoom ouvre ensuite le detail des sessions liees a une question donnee.
