import { renderHook, act } from '@testing-library/react';
import { useAuthStore } from './authStore';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => {
  server.resetHandlers();
  // Reset the store between tests
  useAuthStore.setState({ isAuthenticated: false, isLoading: false, error: null });
});
afterAll(() => server.close());

describe('authStore', () => {
  it('initial state is unauthenticated', () => {
    const { result } = renderHook(() => useAuthStore());
    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.isLoading).toBe(false);
    expect(result.current.error).toBeNull();
  });

  it('login success sets isAuthenticated true', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ access_token: 'tok', refresh_token: 'ref', expires_in: 900 }),
      ),
    );

    const { result } = renderHook(() => useAuthStore());

    await act(async () => {
      await result.current.login({ email: 'test@example.com', password: 'pass' });
    });

    expect(result.current.isAuthenticated).toBe(true);
    expect(result.current.isLoading).toBe(false);
    expect(result.current.error).toBeNull();
  });

  it('login failure sets error and authenticated false', async () => {
    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ message: 'invalid credentials' }, { status: 401 }),
      ),
    );

    const { result } = renderHook(() => useAuthStore());

    await act(async () => {
      try {
        await result.current.login({ email: 'bad@example.com', password: 'wrong' });
      } catch {
        // expected
      }
    });

    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.error).toBeTruthy();
  });

  it('logout clears tokens and state', async () => {
    // First login
    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ access_token: 'tok', refresh_token: 'ref', expires_in: 900 }),
      ),
      http.post(`${BASE}/auth/logout`, () => HttpResponse.json({})),
    );

    const { result } = renderHook(() => useAuthStore());

    await act(async () => {
      await result.current.login({ email: 'test@example.com', password: 'pass' });
    });
    expect(result.current.isAuthenticated).toBe(true);

    await act(async () => {
      await result.current.logout();
    });

    expect(result.current.isAuthenticated).toBe(false);
    expect(result.current.error).toBeNull();
  });
});
