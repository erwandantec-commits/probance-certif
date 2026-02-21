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

