import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useLlmCosts, useKillSwitchState, useToggleKillSwitch } from '../useLlmCosts';

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

describe('useLlmCosts', () => {
  it('fetches LLM cost report', async () => {
    // Uses the default handler from mocks/handlers.ts
    const { result } = renderHook(() => useLlmCosts(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.current_month.total_usd).toBe(12.345678);
    expect(result.current.data!.limit_exceeded).toBe(false);
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useLlmCosts(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useKillSwitchState', () => {
  it('fetches kill switch state', async () => {
    server.use(
      http.get(`${BASE}/admin/llm/killswitch`, () =>
        HttpResponse.json({ active: false }),
      ),
    );

    const { result } = renderHook(() => useKillSwitchState(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.active).toBe(false);
  });
});

describe('useToggleKillSwitch', () => {
  it('toggles kill switch via mutation', async () => {
    server.use(
      http.post(`${BASE}/admin/llm/killswitch`, () =>
        HttpResponse.json({ active: true }),
      ),
    );

    const { result } = renderHook(() => useToggleKillSwitch(), { wrapper: createWrapper() });

    await act(async () => {
      result.current.mutate(true);
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.active).toBe(true);
  });
});
