import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { setTokens, clearTokens } from '../client';
import client from '../client';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => {
  server.resetHandlers();
  clearTokens();
});
afterAll(() => server.close());

describe('response interceptor — 401 refresh flow', () => {
  it('redirects to /login when 401 with no refresh token', async () => {
    // No tokens set, so refreshToken is null
    const originalHref = window.location.href;
    // Mock window.location
    const hrefSetter = vi.fn();
    Object.defineProperty(window, 'location', {
      value: { ...window.location, href: originalHref, pathname: '/dashboard' },
      writable: true,
    });
    Object.defineProperty(window.location, 'href', {
      set: hrefSetter,
      get: () => originalHref,
    });

    server.use(
      http.get(`${BASE}/test-401-no-refresh`, () =>
        HttpResponse.json({ error: 'unauthorized' }, { status: 401 }),
      ),
    );

    await expect(client.get('/test-401-no-refresh')).rejects.toThrow();
    expect(hrefSetter).toHaveBeenCalledWith('/login');
  });

  it('refreshes token on 401 and retries original request', async () => {
    setTokens('expired-token', 'valid-refresh-token');

    let callCount = 0;
    server.use(
      http.get(`${BASE}/test-refresh-retry`, ({ request }) => {
        callCount++;
        const auth = request.headers.get('Authorization');
        if (auth === 'Bearer expired-token') {
          return HttpResponse.json({ error: 'unauthorized' }, { status: 401 });
        }
        return HttpResponse.json({ success: true, auth });
      }),
      http.post(`${BASE}/auth/refresh`, () =>
        HttpResponse.json({
          access_token: 'new-access-token',
          refresh_token: 'new-refresh-token',
          expires_in: 900,
        }),
      ),
    );

    const { data } = await client.get('/test-refresh-retry');
    expect(data.success).toBe(true);
    expect(data.auth).toBe('Bearer new-access-token');
    expect(callCount).toBe(2); // First call 401, second with new token
  });

  it('does not refresh on login route 401', async () => {
    setTokens('some-token', 'some-refresh');
    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ error: 'invalid credentials' }, { status: 401 }),
      ),
    );

    await expect(client.post('/auth/login', { email: 'bad@x.com', password: 'wrong' })).rejects.toThrow();
  });

  it('does not refresh on refresh route 401', async () => {
    setTokens('some-token', 'some-refresh');
    server.use(
      http.post(`${BASE}/auth/refresh`, () =>
        HttpResponse.json({ error: 'invalid refresh' }, { status: 401 }),
      ),
    );

    await expect(client.post('/auth/refresh', { refresh_token: 'bad' })).rejects.toThrow();
  });

  it('passes through non-401 errors', async () => {
    setTokens('valid-token', 'valid-refresh');
    server.use(
      http.get(`${BASE}/test-500`, () =>
        HttpResponse.json({ error: 'server error' }, { status: 500 }),
      ),
    );

    await expect(client.get('/test-500')).rejects.toThrow();
  });

  it('queues requests while refresh is in progress', async () => {
    setTokens('expired-token', 'valid-refresh');

    let refreshCallCount = 0;
    server.use(
      http.get(`${BASE}/test-queued-1`, ({ request }) => {
        const auth = request.headers.get('Authorization');
        if (auth === 'Bearer expired-token') {
          return HttpResponse.json({}, { status: 401 });
        }
        return HttpResponse.json({ endpoint: 1, auth });
      }),
      http.get(`${BASE}/test-queued-2`, ({ request }) => {
        const auth = request.headers.get('Authorization');
        if (auth === 'Bearer expired-token') {
          return HttpResponse.json({}, { status: 401 });
        }
        return HttpResponse.json({ endpoint: 2, auth });
      }),
      http.post(`${BASE}/auth/refresh`, async () => {
        refreshCallCount++;
        // Slight delay to simulate real refresh
        await new Promise((r) => setTimeout(r, 50));
        return HttpResponse.json({
          access_token: 'fresh-token',
          refresh_token: 'fresh-refresh',
          expires_in: 900,
        });
      }),
    );

    const [r1, r2] = await Promise.all([
      client.get('/test-queued-1'),
      client.get('/test-queued-2'),
    ]);

    expect(r1.data.auth).toBe('Bearer fresh-token');
    expect(r2.data.auth).toBe('Bearer fresh-token');
    // Only one refresh call should have been made
    expect(refreshCallCount).toBe(1);
  });
});
