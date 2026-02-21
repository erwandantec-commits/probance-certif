import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

const baseUrl = __ENV.BASE_URL || 'http://localhost:8080';
const userPrefix = __ENV.USER_PREFIX || 'loaduser';
const userDomain = __ENV.USER_DOMAIN || 'example.test';
const userCount = Math.max(1, Number(__ENV.USER_COUNT || 50));
const password = __ENV.PASSWORD || 'LoadTest123!';
const autoRegister = __ENV.AUTO_REGISTER === '1';
const startExam = __ENV.START_EXAM === '1';
const thinkSeconds = Math.max(0, Number(__ENV.THINK_SECONDS || 0.3));

const targetVus = Math.max(1, Number(__ENV.TARGET_VUS || 50));
const rampUp = __ENV.RAMP_UP || '1m';
const hold = __ENV.HOLD || '3m';
const rampDown = __ENV.RAMP_DOWN || '1m';

const loginFailures = new Counter('login_failures');
const dashboardFailures = new Counter('dashboard_failures');
const startFailures = new Counter('start_failures');
const examFailures = new Counter('exam_failures');
const flowFailures = new Rate('flow_failures');
const authFlowDuration = new Trend('auth_flow_duration', true);

const users = Array.from({ length: userCount }, (_, i) => {
  const n = String(i + 1).padStart(4, '0');
  return `${userPrefix}${n}@${userDomain}`;
});

export const options = {
  stages: [
    { duration: rampUp, target: targetVus },
    { duration: hold, target: targetVus },
    { duration: rampDown, target: 0 },
  ],
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1200'],
    flow_failures: ['rate<0.02'],
  },
};

function pickUser() {
  return users[(__VU - 1) % users.length];
}

function formHeaders() {
  return { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } };
}

function registerIfNeeded(email) {
  const payload = `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}&password2=${encodeURIComponent(password)}`;
  http.post(`${baseUrl}/register.php`, payload, formHeaders());
}

export function setup() {
  if (!autoRegister) {
    return;
  }
  for (const email of users) {
    registerIfNeeded(email);
  }
}

export default function () {
  const startedAt = Date.now();
  let failed = false;
  const email = pickUser();

  const loginPage = http.get(`${baseUrl}/login.php?lang=fr`);
  if (!check(loginPage, { 'GET /login.php is 200': (r) => r.status === 200 })) {
    failed = true;
    loginFailures.add(1);
  }

  const loginPayload = `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}&lang=fr`;
  const loginResp = http.post(`${baseUrl}/login.php`, loginPayload, {
    ...formHeaders(),
    redirects: 0,
  });

  const loginOk = check(loginResp, {
    'POST /login.php returns redirect': (r) => r.status === 302,
    'login redirects to dashboard': (r) => (r.headers.Location || '').indexOf('/dashboard.php') !== -1,
  });
  if (!loginOk) {
    failed = true;
    loginFailures.add(1);
  }

  const dashboard = http.get(`${baseUrl}/dashboard.php?lang=fr`);
  if (!check(dashboard, { 'GET /dashboard.php is 200': (r) => r.status === 200 })) {
    failed = true;
    dashboardFailures.add(1);
  }

  if (startExam) {
    const pkgMatch = dashboard.body.match(/name="package_id" value="(\d+)"/);
    const pkgId = pkgMatch ? pkgMatch[1] : '';
    if (!pkgId) {
      failed = true;
      startFailures.add(1);
    } else {
      const startPayload = `package_id=${encodeURIComponent(pkgId)}&session_type=TRAINING&lang=fr`;
      const startResp = http.post(`${baseUrl}/start.php`, startPayload, {
        ...formHeaders(),
        redirects: 0,
      });
      const startOk = check(startResp, {
        'POST /start.php returns redirect': (r) => r.status === 302,
        'start redirects to exam': (r) => (r.headers.Location || '').indexOf('/exam.php?sid=') !== -1,
      });
      if (!startOk) {
        failed = true;
        startFailures.add(1);
      } else {
        const location = startResp.headers.Location || '';
        const examPath = location.startsWith('http') ? location : `${baseUrl}${location}`;
        const examResp = http.get(examPath);
        if (!check(examResp, { 'GET exam page is 200': (r) => r.status === 200 })) {
          failed = true;
          examFailures.add(1);
        }
      }
    }
  }

  flowFailures.add(failed);
  authFlowDuration.add(Date.now() - startedAt);
  sleep(thinkSeconds);
}
