import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import {
  useIocTimeline,
  useConversationTimeline,
  useIocDistribution,
  useScamDistribution,
  useCostTimeline,
  usePipelineTimeline,
  useActivityFeed,
  useWeeklyTrends,
} from '../useAnalytics';

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

const timeSeriesResponse = { period_days: 30, data: [{ date: '2026-03-22', count: 5 }] };
const distributionResponse = { data: [{ label: 'email', count: 10 }] };

describe('useIocTimeline', () => {
  it('fetches IOC timeline with default days', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/ioc-timeline`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('days')).toBe('30');
        return HttpResponse.json(timeSeriesResponse);
      }),
    );

    const { result } = renderHook(() => useIocTimeline(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.period_days).toBe(30);
  });

  it('fetches IOC timeline with custom days', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/ioc-timeline`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('days')).toBe('7');
        return HttpResponse.json({ period_days: 7, data: [] });
      }),
    );

    const { result } = renderHook(() => useIocTimeline(7), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });
});

describe('useConversationTimeline', () => {
  it('fetches conversation timeline', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/conversation-timeline`, () =>
        HttpResponse.json(timeSeriesResponse),
      ),
    );

    const { result } = renderHook(() => useConversationTimeline(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(1);
  });
});

describe('useIocDistribution', () => {
  it('fetches IOC distribution', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/ioc-distribution`, () =>
        HttpResponse.json(distributionResponse),
      ),
    );

    const { result } = renderHook(() => useIocDistribution(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data[0].label).toBe('email');
  });
});

describe('useScamDistribution', () => {
  it('fetches scam distribution', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/scam-distribution`, () =>
        HttpResponse.json(distributionResponse),
      ),
    );

    const { result } = renderHook(() => useScamDistribution(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });
});

describe('useCostTimeline', () => {
  it('fetches cost timeline', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/cost-timeline`, () =>
        HttpResponse.json(timeSeriesResponse),
      ),
    );

    const { result } = renderHook(() => useCostTimeline(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });
});

describe('usePipelineTimeline', () => {
  it('fetches pipeline timeline', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/pipeline-timeline`, () =>
        HttpResponse.json(timeSeriesResponse),
      ),
    );

    const { result } = renderHook(() => usePipelineTimeline(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });
});

describe('useActivityFeed', () => {
  it('fetches activity feed with default limit', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/activity-feed`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('limit')).toBe('10');
        return HttpResponse.json({ events: [{ event_type: 'ioc', ref_id: 'r1', ts: '2026-01-01T00:00:00Z' }] });
      }),
    );

    const { result } = renderHook(() => useActivityFeed(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.events).toHaveLength(1);
  });

  it('fetches activity feed with custom limit', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/activity-feed`, ({ request }) => {
        const url = new URL(request.url);
        expect(url.searchParams.get('limit')).toBe('5');
        return HttpResponse.json({ events: [] });
      }),
    );

    const { result } = renderHook(() => useActivityFeed(5), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
  });
});

describe('useWeeklyTrends', () => {
  it('fetches weekly trends', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/weekly-trends`, () =>
        HttpResponse.json({
          trends: [{ metric: 'iocs', current: 50, previous: 40, delta_pct: 25 }],
        }),
      ),
    );

    const { result } = renderHook(() => useWeeklyTrends(), { wrapper: createWrapper() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.trends[0].metric).toBe('iocs');
  });
});
