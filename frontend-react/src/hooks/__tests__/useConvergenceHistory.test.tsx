import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useConvergenceHistory } from '../useConvergenceHistory';

const BASE = '/api/v1';

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('useConvergenceHistory', () => {
  it('fetches convergence history', async () => {
    server.use(
      http.get(`${BASE}/monitoring/convergence-history`, () =>
        HttpResponse.json({
          period_days: 30,
          by_scam_type: {
            PHISHING: [
              {
                date: '2026-03-22',
                dominant_persona: 'elderly_person',
                dominant_pct: 0.65,
                sessions_count: 15,
                converged: true,
              },
            ],
            ROMANCE: [],
          },
        }),
      ),
    );

    const { result } = renderHook(() => useConvergenceHistory(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.period_days).toBe(30);
    expect(result.current.data!.by_scam_type.PHISHING).toHaveLength(1);
    expect(result.current.data!.by_scam_type.PHISHING[0].converged).toBe(true);
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/monitoring/convergence-history`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useConvergenceHistory(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
