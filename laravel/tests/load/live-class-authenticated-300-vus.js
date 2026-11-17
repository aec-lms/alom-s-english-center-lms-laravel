import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Counter, Gauge } from 'k6/metrics';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const liveClassId = (__ENV.LIVE_CLASS_ID || '').trim();
const emailPattern = (__ENV.STUDENT_EMAIL_PATTERN || '').trim();
const password = __ENV.STUDENT_PASSWORD || '';
const vus = Number(__ENV.VUS || 300);
const duration = __ENV.DURATION || '5m';
const capacity = Number(__ENV.CAPACITY || 300);
const authSuccess = new Rate('aec_auth_success');
const joinSuccess = new Rate('aec_join_success');
const heartbeatSuccess = new Rate('aec_heartbeat_success');
const seatOverflow = new Counter('aec_seat_overflow');
const observedActiveSeats = new Gauge('aec_observed_active_seats');

if (!baseUrl || !baseUrl.startsWith('https://')) throw new Error('BASE_URL must be HTTPS.');
if (!/^[1-9][0-9]*$/.test(liveClassId)) throw new Error('LIVE_CLASS_ID must be a positive integer.');
if (!emailPattern.includes('{vu}')) throw new Error('STUDENT_EMAIL_PATTERN must include {vu}.');
if (!password) throw new Error('STUDENT_PASSWORD is required.');
if (!Number.isInteger(vus) || vus < 300 || vus > 1000) throw new Error('VUS must be between 300 and 1000.');
if (!Number.isInteger(capacity) || capacity < 300 || capacity > 1000) throw new Error('CAPACITY must be between 300 and 1000.');

export const options = {
  scenarios: {
    authenticated_live_class_students: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '45s', target: vus },
        { duration, target: vus },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '45s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<5000'],
    aec_auth_success: ['rate==1'],
    aec_join_success: ['rate==1'],
    aec_heartbeat_success: ['rate>0.99'],
    aec_seat_overflow: ['count==0'],
  },
};

let initialized = false;
let joined = false;
let csrfToken = '';

function authenticateAndJoin() {
  const email = emailPattern.replace('{vu}', String(__VU));
  const loginPage = http.get(baseUrl + '/login', { tags: { endpoint: 'login-page' }, timeout: '30s' });
  const tokenMatch = loginPage.body && loginPage.body.match(/name="_token"\s+value="([^"]+)"/);
  if (loginPage.status !== 200 || !tokenMatch) {
    authSuccess.add(false);
    return false;
  }

  csrfToken = tokenMatch[1];
  const loginResponse = http.post(baseUrl + '/login', { _token: csrfToken, email, password }, {
    headers: { 'X-CSRF-TOKEN': csrfToken, 'User-Agent': 'AEC-LMS-Authenticated-Live-Class-Load-Test/1.0' },
    redirects: 5,
    tags: { endpoint: 'login-submit' },
    timeout: '30s',
  });
  const dashboard = http.get(baseUrl + '/dashboard', { redirects: 3, tags: { endpoint: 'student-dashboard' }, timeout: '30s' });
  const authenticated = loginResponse.status < 400 && dashboard.status === 200 && !String(dashboard.url || '').includes('/login');
  authSuccess.add(authenticated);
  if (!authenticated) return false;

  const joinResponse = http.post(baseUrl + '/live-classes/' + liveClassId + '/join', { _token: csrfToken }, {
    headers: { 'X-CSRF-TOKEN': csrfToken, 'User-Agent': 'AEC-LMS-Authenticated-Live-Class-Load-Test/1.0' },
    redirects: 3,
    tags: { endpoint: 'live-class-join' },
    timeout: '30s',
  });
  joinSuccess.add(joinResponse.status === 200);
  if (!check(joinResponse, { 'authenticated student receives a live-class seat': (r) => r.status === 200 })) return false;
  return true;
}

export default function () {
  if (!initialized) {
    initialized = true;
    joined = authenticateAndJoin();
    if (!joined) {
      sleep(2);
      return;
    }
  }
  if (!joined) {
    sleep(2);
    return;
  }

  const heartbeat = http.post(baseUrl + '/live-classes/' + liveClassId + '/heartbeat', { _token: csrfToken }, {
    headers: {
      'X-CSRF-TOKEN': csrfToken,
      Accept: 'application/json',
      'User-Agent': 'AEC-LMS-Authenticated-Live-Class-Load-Test/1.0',
    },
    tags: { endpoint: 'live-class-heartbeat' },
    timeout: '30s',
  });
  const heartbeatOk = heartbeat.status === 200;
  heartbeatSuccess.add(heartbeatOk);
  if (heartbeatOk) {
    const payload = heartbeat.json();
    const activeCount = Number(payload.active_count || 0);
    observedActiveSeats.add(activeCount);
    if (activeCount > capacity) seatOverflow.add(1);
    check(payload, { 'active seats never exceed configured capacity': (data) => Number(data.active_count || 0) <= capacity });
  }
  sleep(20);
}
