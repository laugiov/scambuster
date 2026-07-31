import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useImpactSummary, useIocUniqueness } from '../useImpact';

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

const mockImpactSummary = {
  wasted_time: {
    total_hours: 100,
    total_conversations: 50,
    avg_hours: 2,
    max_hours: 10,
    longest_scam_type: 'ROMANCE',
    weekly_trend: [{ week: '2026-W12', hours: 15 }],
    // Scammer Replies Elicited tile fields
    scammer_replies_count: 75,
    scammer_replies_prev_count: 50,
    scammer_replies_delta_pct: 50.0,
  },
  ioc_value: {
    total_iocs: 500,
    novel_iocs: 300,
    novel_pct: 60,
    // Fresh IOCs tile fixture (period-aware window)
    fresh_iocs_count: 80,
    fresh_iocs_prev_count: 40,
    fresh_iocs_delta_pct: 100.0,
    fresh_iocs_window_days: 30,
    financial_iocs: 100,
    high_confidence_iocs: 200,
    by_type: [{ type: 'email', count: 50 }],
  },
  cost_efficiency: {
    total_cost_usd: 5.2,
    cost_per_ioc_usd: 0.01,
    cost_per_hour_wasted_usd: 0.05,
    current_month_usd: 1.5,
    previous_month_usd: 1.2,
    month_delta_pct: 25,
  },
  campaigns: {
    total: 10,
    promoted: 3,
    scam_type_count: 5,
    top_campaigns: [],
  },
  trends: null,
};

describe('useImpactSummary', () => {
  it('fetches impact summary with default period', async () => {
    server.use(
      http.get(`${BASE}/impact/summary`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('period')).toBe('all');
        return HttpResponse.json(mockImpactSummary);
      }),
    );

    const { result } = renderHook(() => useImpactSummary(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.wasted_time.total_hours).toBe(100);
    expect(result.current.data!.ioc_value.novel_pct).toBe(60);
  });

  it('fetches impact summary with custom period', async () => {
    server.use(
      http.get(`${BASE}/impact/summary`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('period')).toBe('30d');
        return HttpResponse.json(mockImpactSummary);
      }),
    );

    const { result } = renderHook(() => useImpactSummary('30d'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });

  it('handles error', async () => {
    server.use(
      http.get(`${BASE}/impact/summary`, () => HttpResponse.json({}, { status: 500 })),
    );

    const { result } = renderHook(() => useImpactSummary(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isError).toBe(true));
  });
});

describe('useIocUniqueness', () => {
  it('fetches IOC uniqueness data', async () => {
    server.use(
      http.get(`${BASE}/impact/ioc-uniqueness`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('period')).toBe('30d');
        return HttpResponse.json({
          summary: { total_iocs: 500, novel_iocs: 300, novel_pct: 60 },
          by_type: [{ type: 'email', total: 50, novel: 30, novel_pct: 60 }],
          daily_trend: [{ date: '2026-03-22', total: 10, novel: 5 }],
        });
      }),
    );

    const { result } = renderHook(() => useIocUniqueness(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.summary.novel_pct).toBe(60);
  });

  it('supports custom period', async () => {
    server.use(
      http.get(`${BASE}/impact/ioc-uniqueness`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('period')).toBe('7d');
        return HttpResponse.json({
          summary: { total_iocs: 100, novel_iocs: 50, novel_pct: 50 },
          by_type: [],
          daily_trend: [],
        });
      }),
    );

    const { result } = renderHook(() => useIocUniqueness('7d'), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });
});
