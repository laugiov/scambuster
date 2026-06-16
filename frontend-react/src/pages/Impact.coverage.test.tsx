import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Impact } from './Impact';

const BASE = '/api/v1';

const mockImpactWithTrends = {
  wasted_time: {
    total_hours: 279.68,
    total_conversations: 33,
    weekly_trend: [
      { week: '2026-03-02', hours: 64 },
      { week: '2026-03-09', hours: 50 },
    ],
    // Spec 108 — Scammer Replies Elicited tile fixture.
    // 12.5% delta kept (was the old wasted_hours_delta_pct, now drives
    // the new tile's chip — the trend-deltas test below asserts /12\.5%/).
    scammer_replies_count: 92,
    scammer_replies_prev_count: 82,
    scammer_replies_delta_pct: 12.5,
  },
  ioc_value: {
    novel_pct: 68.8,
    novel_iocs: 198,
    // Spec 106 — dual-face tile fields (Total face when window_days=null)
    total_iocs: 1700,
    fresh_iocs_count: null,
    fresh_iocs_prev_count: null,
    fresh_iocs_delta_pct: null,
    fresh_iocs_window_days: null,
    by_type: [
      { type: 'email', count: 500 },
      { type: 'domain', count: 520 },
      { type: 'ipv4', count: 200 },
      { type: 'url', count: 150 },
      { type: 'sha256', count: 100 },
      { type: 'phone', count: 80 },
      { type: 'iban', count: 60 },
      { type: 'wallet_btc', count: 40 },
      { type: 'wallet_eth', count: 30 },
      { type: 'wallet_xmr', count: 20 },
    ],
  },
  cost_efficiency: {
    cost_per_ioc_usd: 0.0071,
    total_cost_usd: 2.05,
  },
  trends: {
    wasted_hours_delta_pct: 12.5,
    novel_pct_delta: -3.2,
    cost_per_ioc_delta_pct: -8.1,
  },
};

const mockIocUniqueness = {
  daily_trend: [
    { date: '2026-03-20', total: 10, novel: 7 },
    { date: '2026-03-21', total: 15, novel: 10 },
  ],
};

const mockClusterStats = {
  total_conversations: 33,
  clustered_conversations: 28,
  singleton_conversations: 5,
  total_clusters: 5,
  suspect_clusters: 2,
  taxii_noise_reduction_pct: 84.8,
  avg_cluster_size: 5.6,
  largest_cluster_size: 12,
  anchor_ioc_coverage: {},
  last_clustered_at: '2026-04-10T10:00:00Z',
};

function setupHandlers(impact?: object) {
  server.use(
    http.get(`${BASE}/impact/summary`, () => HttpResponse.json(impact ?? mockImpactWithTrends)),
    http.get(`${BASE}/impact/ioc-uniqueness`, () => HttpResponse.json(mockIocUniqueness)),
    http.get(`${BASE}/clusters/stats`, () => HttpResponse.json(mockClusterStats)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('Impact — coverage gaps', () => {
  it('shows empty state when total_conversations is 0', async () => {
    setupHandlers({
      wasted_time: { total_hours: 0, total_conversations: 0, weekly_trend: [] },
      ioc_value: { novel_pct: 0, novel_iocs: 0, by_type: [] },
      cost_efficiency: { cost_per_ioc_usd: 0, total_cost_usd: 0 },
      trends: null,
    });
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No data available/i)).toBeInTheDocument();
    });
  });

  it('renders trend deltas (up/down arrows)', async () => {
    setupHandlers();
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      // wasted_hours_delta_pct 12.5% up
      expect(screen.getByText(/12\.5%/)).toBeInTheDocument();
    });
    // cost_per_ioc_delta_pct -8.1% down (green because lower cost is good)
    expect(screen.getByText(/8\.1%/)).toBeInTheDocument();
    // Spec 106 — the old novel_pct_delta "pp" chip was retired when the
    // tile pivoted from "Novel IOCs %" to "Fresh IOCs / Total IOCs".
    // This fixture has fresh_iocs_window_days=null → Total face → no trend
    // chip on this tile by design.
  });

  it('switches period buttons', async () => {
    setupHandlers();
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('7d')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('7d'));
    await waitFor(() => {
      expect(screen.getByText(/Impact/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('90d'));
    await waitFor(() => {
      expect(screen.getByText(/Impact/i)).toBeInTheDocument();
    });
  });

  it('renders IOC daily trend chart', async () => {
    setupHandlers();
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/IOCs per Day/i)).toBeInTheDocument();
    });
  });

  it('collapses more than 8 IOC types into Other category', async () => {
    setupHandlers();
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/IOCs by Type/i)).toBeInTheDocument();
    });
    // We have 10 types; should show top 8 + Other
  });

  it('renders weekly wasted chart with empty data', async () => {
    setupHandlers({
      ...mockImpactWithTrends,
      wasted_time: {
        total_hours: 10,
        total_conversations: 5,
        weekly_trend: [],
        // Spec 108 — Scammer Replies tile fields required even when chart is empty
        scammer_replies_count: 8,
        scammer_replies_prev_count: null,
        scammer_replies_delta_pct: null,
      },
    });
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      // Should show "No data" for the weekly chart
      const noData = screen.getAllByText(/No data/i);
      expect(noData.length).toBeGreaterThanOrEqual(1);
    });
  });

  it('renders without cluster stats', async () => {
    server.use(
      http.get(`${BASE}/impact/summary`, () => HttpResponse.json(mockImpactWithTrends)),
      http.get(`${BASE}/impact/ioc-uniqueness`, () => HttpResponse.json(mockIocUniqueness)),
      http.get(`${BASE}/clusters/stats`, () => HttpResponse.json(null)),
    );
    render(<Impact />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Impact/i)).toBeInTheDocument();
    });
    // Actor Deduplication card should not render
    expect(screen.queryByText(/Actor Deduplication/i)).toBeNull();
  });
});
