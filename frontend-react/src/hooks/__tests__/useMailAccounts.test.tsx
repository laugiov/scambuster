import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { http, HttpResponse } from 'msw';
import { server } from '@/__tests__/mocks/server';
import { useMailAccounts } from '../useMailAccounts';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('useMailAccounts', () => {
  it('fetches and returns the list of mail accounts', async () => {
    const accounts = [
      { account_id: 'acc-1', label: 'Delta Holdings', email: 'admin@delta-holdings.example' },
      { account_id: 'acc-2', label: 'Gamma Partners', email: 'admin@gamma-partners.example' },
    ];
    server.use(
      http.get(`${BASE}/communication/mail-accounts`, () => HttpResponse.json(accounts)),
    );

    const { result } = renderHook(() => useMailAccounts(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toEqual(accounts);
  });

  it('handles empty response', async () => {
    server.use(
      http.get(`${BASE}/communication/mail-accounts`, () => HttpResponse.json([])),
    );

    const { result } = renderHook(() => useMailAccounts(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toEqual([]);
  });

  it('returns nullable label and email correctly', async () => {
    server.use(
      http.get(`${BASE}/communication/mail-accounts`, () =>
        HttpResponse.json([{ account_id: 'acc-legacy', label: null, email: null }]),
      ),
    );

    const { result } = renderHook(() => useMailAccounts(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toHaveLength(1);
    expect(result.current.data![0].label).toBeNull();
    expect(result.current.data![0].email).toBeNull();
  });

  it('surfaces API errors', async () => {
    server.use(
      http.get(`${BASE}/communication/mail-accounts`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useMailAccounts(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
