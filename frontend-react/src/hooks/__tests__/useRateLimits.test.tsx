import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useRateLimits } from '../useRateLimits';

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

describe('useRateLimits', () => {
  it('fetches rate limit stats', async () => {
    server.use(
      http.get(`${BASE}/monitoring/rate-limits`, () =>
        HttpResponse.json({
          llm_calls_limit: 1000,
          active_conversations_limit: 50,
          rate_limited_today: [{ type: 'llm', count: 3 }],
          quarantined_senders_today: 2,
        }),
      ),
    );

    const { result } = renderHook(() => useRateLimits(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.llm_calls_limit).toBe(1000);
    expect(result.current.data!.quarantined_senders_today).toBe(2);
    expect(result.current.data!.rate_limited_today).toHaveLength(1);
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/monitoring/rate-limits`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useRateLimits(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});
