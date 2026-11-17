import http from 'k6/http';
import { check, sleep } from 'k6';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
const courseSlug = (__ENV.COURSE_SLUG || '').trim();
const vus = Number(__ENV.VUS || 300);
const duration = __ENV.DURATION || '5m';

if (!baseUrl) {
  throw new Error('BASE_URL is required. Supply the HTTPS URL of an authorized staging environment.');
}
if (!Number.isInteger(vus) || vus < 300) {
  throw new Error('VUS must be an integer greater than or equal to 300.');
}

const paths = ['/courses'];
if (courseSlug !== '') {
  paths.push('/course/' + encodeURIComponent(courseSlug));
}

export const options = {
  scenarios: {
    concurrent_students: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '30s', target: vus },
        { duration, target: vus },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<3000'],
    checks: ['rate>0.99'],
  },
};

export default function () {
  const path = paths[__ITER % paths.length];
  const response = http.get(baseUrl + path, {
    headers: { 'User-Agent': 'AEC-LMS-300-VU-Load-Test/1.0' },
    tags: { endpoint: path },
    redirects: 5,
    timeout: '30s',
  });

  check(response, {
    'public LMS page returns HTTP 200': (r) => r.status === 200,
    'response is not empty': (r) => Boolean(r.body && r.body.length > 0),
  });

  sleep(1);
}
