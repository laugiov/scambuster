import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { setTokens, clearTokens, hasTokens, login, logout } from '../client';
import client from '../client';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => {
  server.resetHandlers();
  clearTokens();
});
afterAll(() => server.close());

describe('token management', () => {
  it('hasTokens returns false initially', () => {
    expect(hasTokens()).toBe(false);
  });

  it('setTokens makes hasTokens return true', () => {
    setTokens('access', 'refresh');
    expect(hasTokens()).toBe(true);
  });

  it('clearTokens makes hasTokens return false', () => {
    setTokens('access', 'refresh');
    clearTokens();
    expect(hasTokens()).toBe(false);
  });
});

describe('login', () => {
  it('posts credentials and sets tokens', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({
          access_token: 'new-access',
          refresh_token: 'new-refresh',
          expires_in: 900,
        }),
      ),
    );

    const result = await login({ email: 'test@example.com', password: 'pass' });
    expect(result.access_token).toBe('new-access');
    expect(result.refresh_token).toBe('new-refresh');
    expect(hasTokens()).toBe(true);
  });

  it('throws on invalid credentials', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ message: 'invalid' }, { status: 401 }),
      ),
    );

    await expect(login({ email: 'bad@example.com', password: 'wrong' })).rejects.toThrow();
    expect(hasTokens()).toBe(false);
  });
});

describe('logout', () => {
  it('clears tokens even if API call fails', async () => {
    setTokens('access', 'refresh');
    server.use(
      http.post(`${BASE}/auth/logout`, () => HttpResponse.json({}, { status: 500 })),
    );

    // logout() catches errors internally via finally block, but the
    // response interceptor may still reject. We just verify tokens are cleared.
    try {
      await logout();
    } catch {
      // expected — interceptor may reject on 500
    }
    expect(hasTokens()).toBe(false);
  });

  it('clears tokens on success', async () => {
    setTokens('access', 'refresh');
    server.use(
      http.post(`${BASE}/auth/logout`, () => HttpResponse.json({})),
    );

    await logout();
    expect(hasTokens()).toBe(false);
  });
});

describe('request interceptor', () => {
  it('attaches Authorization header when token is set', async () => {
    setTokens('my-jwt-token', 'refresh');
    server.use(
      http.get(`${BASE}/test-auth`, ({ request }) => {
        const authHeader = request.headers.get('Authorization');
        return HttpResponse.json({ auth: authHeader });
      }),
    );

    const { data } = await client.get('/test-auth');
    expect(data.auth).toBe('Bearer my-jwt-token');
  });

  it('does not attach Authorization header when no token', async () => {
    server.use(
      http.get(`${BASE}/test-no-auth`, ({ request }) => {
        const authHeader = request.headers.get('Authorization');
        return HttpResponse.json({ auth: authHeader });
      }),
    );

    const { data } = await client.get('/test-no-auth');
    expect(data.auth).toBeNull();
  });
});

describe('client defaults', () => {
  it('has JSON content type', () => {
    expect(client.defaults.headers['Content-Type']).toBe('application/json');
  });

  it('has 15s timeout', () => {
    expect(client.defaults.timeout).toBe(15000);
  });
});
