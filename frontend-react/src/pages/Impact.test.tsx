import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { Impact } from './Impact';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';

const BASE = '/api/v1';

const mockImpactSummary = {
  wasted_time: {
    total_hours: 279.68,
    total_conversations: 33,
    weekly_trend: [
      { week: '2026-03-02', hours: 64 },
      { week: '2026-03-09', hours: 50 },
    ],
  },
  ioc_value: {
    novel_pct: 68.8,
    novel_iocs: 198,
    by_type: [
      { type: 'email', count: 500 },
      { type: 'domain', count: 520 },
    ],
  },
  cost_efficiency: {
    cost_per_ioc_usd: 0.0071,
    total_cost_usd: 2.05,
  },
  trends: null,
};

const mockIocUniqueness = {
  daily_trend: [
    { date: '2026-03-20', total: 10, novel: 7 },
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

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('Impact page', () => {
  beforeEach(() => {
    server.use(
      http.get(`${BASE}/impact/summary`, () => HttpResponse.json(mockImpactSummary)),
      http.get(`${BASE}/impact/ioc-uniqueness`, () => HttpResponse.json(mockIocUniqueness)),
      http.get(`${BASE}/clusters/stats`, () => HttpResponse.json(mockClusterStats)),
    );
  });

  it('renders stat cards', async () => {
    render(<Impact />, { wrapper: createWrapper() });

    await screen.findByText(/Impact & Intelligence/i);
    expect(screen.getByText(/Criminal Time Wasted/i)).toBeInTheDocument();
    expect(screen.getByText(/Exclusive IOCs/i)).toBeInTheDocument();
  });

  it('renders Actor Deduplication card (spec 065 / v2.14.0)', async () => {
    render(<Impact />, { wrapper: createWrapper() });

    // The Actor Dedup card shows "33 → 5" (total_conversations → total_clusters)
    await screen.findByText(/Actor Deduplication/i);
    expect(screen.getByText(/33 → 5/)).toBeInTheDocument();
  });

  it('renders IOCs by Type chart section', async () => {
    render(<Impact />, { wrapper: createWrapper() });

    await screen.findByText(/IOCs by Type/i);
  });

  it('renders weekly wasted chart section', async () => {
    render(<Impact />, { wrapper: createWrapper() });

    await screen.findByText(/Hours Wasted per Week/i);
  });
});
