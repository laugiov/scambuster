import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useAutonomyStats, useScambaitingStats } from '../useStats';

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

describe('useAutonomyStats', () => {
  it('fetches autonomy stats', async () => {
    // Uses the default handler from mocks/handlers.ts
    const { result } = renderHook(() => useAutonomyStats(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.status).toBe('operational');
    expect(result.current.data!.conversations.total).toBe(15);
    expect(result.current.data!.iocs.total).toBe(89);
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useAutonomyStats(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useScambaitingStats', () => {
  it('fetches scambaiting stats', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/stats`, () =>
        HttpResponse.json([
          {
            scam_type: 'PHISHING',
            total_conversations: 20,
            avg_iocs_per_conversation: 4.5,
            avg_engagement_turns: 8,
            response_rate: 0.9,
          },
        ]),
      ),
    );

    const { result } = renderHook(() => useScambaitingStats(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toHaveLength(1);
    expect(result.current.data![0].scam_type).toBe('PHISHING');
  });
});
