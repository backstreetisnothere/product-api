// k6 load profile for the read-heavy hot path (cached product / list / stock reads + a trickle of writes).
//
//   k6 run -e API_KEY=sk_... -e BASE_URL=http://localhost:8080/api/v1 loadtest/read-heavy.js
//
// Seed data first:  make seed   (prints the API key of a demo merchant with 100 products)
//
// Note: the per-merchant quota (RATE_LIMIT_MERCHANT, default 6000/min = 100 rps) applies. To measure raw
// throughput raise RATE_LIMIT_MERCHANT / RATE_LIMIT_IP in the environment of the app container, or spread
// the load over several merchants by passing API_KEYS=key1,key2,...
import http from 'k6/http';
import { check } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080/api/v1';
const KEYS = (__ENV.API_KEYS || __ENV.API_KEY || '').split(',').filter(Boolean);

export const options = {
  scenarios: {
    reads: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 900),
      timeUnit: '1s',
      duration: __ENV.DURATION || '60s',
      preAllocatedVUs: 100,
      maxVUs: 400,
      exec: 'read',
    },
    writes: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.WRITE_RATE || 100),
      timeUnit: '1s',
      duration: __ENV.DURATION || '60s',
      preAllocatedVUs: 20,
      maxVUs: 100,
      exec: 'write',
    },
  },
  thresholds: {
    'http_req_failed{scenario:reads}': ['rate<0.01'],
    'http_req_duration{scenario:reads}': ['p(95)<100'],
    'http_req_duration{scenario:writes}': ['p(95)<250'],
  },
};

export function setup() {
  if (KEYS.length === 0) {
    throw new Error('Provide -e API_KEY=... (or API_KEYS=a,b,c)');
  }

  return KEYS.map((key) => {
    const headers = { 'X-API-Key': key };
    const products = http.get(`${BASE_URL}/products?per_page=50`, { headers }).json('data') || [];
    const warehouses = http.get(`${BASE_URL}/warehouses`, { headers }).json('data') || [];

    return { key, productIds: products.map((p) => p.id), warehouseId: warehouses.length ? warehouses[0].id : null };
  });
}

const pick = (list) => list[Math.floor(Math.random() * list.length)];

export function read(contexts) {
  const ctx = pick(contexts);
  const headers = { 'X-API-Key': ctx.key };
  const productId = pick(ctx.productIds);
  const roll = Math.random();

  const res = roll < 0.6
    ? http.get(`${BASE_URL}/products/${productId}`, { headers })
    : roll < 0.8
      ? http.get(`${BASE_URL}/products?per_page=20`, { headers })
      : http.get(`${BASE_URL}/products/${productId}/stock`, { headers });

  check(res, { 'status is 200': (r) => r.status === 200 });
}

export function write(contexts) {
  const ctx = pick(contexts);
  if (!ctx.warehouseId) return;

  // Every write carries its own Idempotency-Key, exactly like a well-behaved client would.
  const res = http.post(
    `${BASE_URL}/products/${pick(ctx.productIds)}/stock/${ctx.warehouseId}/adjustments`,
    JSON.stringify({ delta: 1 }),
    { headers: { 'X-API-Key': ctx.key, 'Content-Type': 'application/json', 'Idempotency-Key': uuidv4() } },
  );

  check(res, { 'status is 200': (r) => r.status === 200 });
}
