# Load test quick start

This project now includes a `k6` scenario for:
- login
- dashboard load
- optional session start and exam page load

Script: `load/k6-auth-dashboard.js`

## 1) Prerequisites

- Docker app stack running (`docker compose up -d`)
- k6 installed locally

## 2) Baseline run (read-heavy)

This run checks auth + dashboard only.

```powershell
k6 run `
  -e BASE_URL=http://localhost:8080 `
  -e TARGET_VUS=50 `
  -e USER_COUNT=100 `
  -e USER_PREFIX=loaduser `
  -e USER_DOMAIN=example.test `
  -e PASSWORD=LoadTest123! `
  -e AUTO_REGISTER=1 `
  -e START_EXAM=0 `
  load/k6-auth-dashboard.js
```

## 3) Write-heavy run (creates sessions)

This adds `POST /start.php` and a first exam page load.

```powershell
k6 run `
  -e BASE_URL=http://localhost:8080 `
  -e TARGET_VUS=50 `
  -e USER_COUNT=100 `
  -e USER_PREFIX=loaduser `
  -e USER_DOMAIN=example.test `
  -e PASSWORD=LoadTest123! `
  -e AUTO_REGISTER=1 `
  -e START_EXAM=1 `
  load/k6-auth-dashboard.js
```

## 4) Ramp strategy

Increase load by steps:
1. `TARGET_VUS=20`
2. `TARGET_VUS=50`
3. `TARGET_VUS=100`
4. `TARGET_VUS=150`

Keep each run long enough (`HOLD=5m`) to stabilize:

```powershell
k6 run `
  -e BASE_URL=http://localhost:8080 `
  -e TARGET_VUS=100 `
  -e RAMP_UP=2m `
  -e HOLD=5m `
  -e RAMP_DOWN=2m `
  -e USER_COUNT=200 `
  -e AUTO_REGISTER=1 `
  -e START_EXAM=1 `
  load/k6-auth-dashboard.js
```

## 5) What to track

- `http_req_failed`
- `http_req_duration` p95
- `flow_failures`
- response time trends as `TARGET_VUS` increases

Stop increasing when one of these happens:
- `http_req_failed` > 1%
- p95 > 1.2s for sustained period
- app errors or DB saturation

---

# Guide layout UI (certif-app)

Ce guide sert de base commune pour garder une interface coherente entre:
- espace candidat (`dashboard.php`, `exam.php`, `result.php`)
- espace admin (`admin/*.php`)

## 1) Structure standard d'une page

1. Header de page
- logo + titre
- langue / actions globales (logout, navigation)

2. Bloc de contexte
- titre clair (`h1`)
- sous-titre court (objectif de la page)

3. Zone d'actions
- bouton primaire visible immediatement
- actions secondaires en `ghost`

4. Contenu principal
- cartes pour resumes et actions
- tableaux pour donnees volumineuses
- formulaires regroupes par logique metier

## 2) Regles par zone produit

### Espace candidat
- Priorite aux certifications et actions "Demarrer/Reprendre".
- Afficher statut + validite + `jours restants` de maniere lisible.
- Conserver les actions critiques au-dessus de la ligne de flottaison.

### Espace admin
- Priorite aux filtres puis aux resultats.
- Toujours afficher un retour explicite apres action (`succes/erreur`).
- Sur listes longues: filtres en haut, tableau, pagination en bas.

## 3) Grille et responsive

- Container principal centre (max ~1200px).
- Espacements bases sur pas de `8px`.
- Mobile-first:
  - filtres empiles
  - actions en pleine largeur si necessaire
  - colonnes secondaires masquees si la lisibilite chute

## 4) Composants UI a homogeniser

- Boutons: `primary`, `ghost`, `danger`.
- Badges/pills: statuts (`ACTIVE`, `TERMINATED`, `EXPIRED`, etc.).
- Inputs: labels au-dessus, aide contextuelle via bulle `i` si besoin.
- Tableaux: en-tetes stables, actions dans une colonne dediee.

## 5) Terminologie produit (FR)

- Utiliser `Pack` (et non `Package`) dans l'UI.
- Utiliser `Espace Admin` cote candidat.
- Garder `jours restants` (eviter les abreviations ambigues).

## 6) Accessibilite minimale

- Focus clavier visible partout.
- Contraste suffisant sur badges et boutons.
- Libelles explicites pour chaque champ.
- Messages d'erreur comprehensibles et localises.

## 7) Checklist avant merge UI

- Le layout reste lisible a 320px.
- Les actions principales sont visibles sans scroll excessif.
- Les statuts et feedback utilisateur sont explicites.
- Les pages candidat/admin gardent la meme logique visuelle.
