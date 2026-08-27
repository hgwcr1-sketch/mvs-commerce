import http from 'k6/http';
import { check, fail } from 'k6';

const baseUrl = (__ENV.BASE_URL || '').replace(/\/$/, '');
if (__ENV.ALLOW_P08_LOAD_TEST !== 'true' || !/(localhost|127\.0\.0\.1|staging|test)/i.test(baseUrl)) {
  fail('Carga bloqueada: use un host local/staging/test y ALLOW_P08_LOAD_TEST=true.');
}

export const options = {
  scenarios: {
    portal: { executor: 'constant-vus', vus: Number(__ENV.PORTAL_VUS || 20), duration: __ENV.DURATION || '30s', exec: 'portal' },
    pos: { executor: 'constant-vus', vus: Number(__ENV.POS_VUS || 5), duration: __ENV.DURATION || '30s', exec: 'pos' },
  },
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<1000'] },
};

export function portal() {
  const response = http.get(`${baseUrl}${__ENV.PORTAL_PATH || '/portal-clientes/1'}`, { redirects: 0 });
  check(response, { 'portal responde sin error 5xx': (r) => r.status < 500 });
}

export function pos() {
  if (!__ENV.POS_PATH || !__ENV.POS_COOKIE || !__ENV.POS_PAYLOAD) return;
  const response = http.post(`${baseUrl}${__ENV.POS_PATH}`, __ENV.POS_PAYLOAD, {
    headers: { 'Content-Type': 'application/json', Cookie: __ENV.POS_COOKIE, Accept: 'application/json' },
  });
  check(response, { 'POS responde sin error 5xx': (r) => r.status < 500 });
}
