import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Clusters } from './Clusters';

const BASE = '/api/v1';

const mockClusters = [
  {
    cluster_id: 'cl-1',
    name: 'ScamBuster Cluster #ABCD (3 conversations)',
    status: 'active',
    conversation_count: 3,
    sophistication: 'minimal',
    primary_scam_types: ['INVOICE_FRAUD'],
    anchor_ioc_types: ['iban'],
    first_seen: '2026-02-01T00:00:00Z',
    last_seen: '2026-04-01T00:00:00Z',
  },
  {
    cluster_id: 'cl-2',
    name: 'ScamBuster Cluster #BEEF (10 conversations)',
    status: 'active',
    conversation_count: 10,
    sophistication: 'minimal',
    primary_scam_types: ['CEO_FRAUD', 'INVOICE_FRAUD'],
    anchor_ioc_types: ['phone'],
    first_seen: '2024-09-01T00:00:00Z',
    last_seen: '2024-09-30T00:00:00Z', // stale (years old)
  },
];

const mockClusterStats = {
  total_conversations: 33,
  clustered_conversations: 28,
  singleton_conversations: 5,
  total_clusters: 5,
  suspect_clusters: 0,
  taxii_noise_reduction_pct: 84.8,
  avg_cluster_size: 5.6,
  largest_cluster_size: 12,
  anchor_ioc_coverage: {},
  last_clustered_at: '2026-04-10T10:00:00Z',
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/clusters`, () => HttpResponse.json(mockClusters)),
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

describe('Clusters', () => {
  it('renders the clusters page with title and cluster data', async () => {
    setupHandlers();
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Threat Actor Clusters/i)).toBeInTheDocument();
      expect(screen.getByText('28')).toBeInTheDocument(); // clustered conversations
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/clusters`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockClusters);
      }),
    );
    render(<Clusters />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders stat cards', async () => {
    setupHandlers();
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('5').length).toBeGreaterThan(0); // total_clusters
      expect(screen.getByText('28')).toBeInTheDocument(); // clustered
    });
  });

  it('renders cluster table with data', async () => {
    setupHandlers();
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/ScamBuster Cluster #ABCD/)).toBeInTheDocument();
    });
  });

  it('shows empty state when no clusters', async () => {
    server.use(
      http.get(`${BASE}/clusters`, () => HttpResponse.json([])),
      http.get(`${BASE}/clusters/stats`, () => HttpResponse.json(mockClusterStats)),
    );
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No clusters yet/i)).toBeInTheDocument();
    });
  });

  it('shows error state when request fails', async () => {
    server.use(
      http.get(`${BASE}/clusters`, () => HttpResponse.json({ error: 'fail' }, { status: 500 })),
    );
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Failed to load clusters/i)).toBeInTheDocument();
    });
  });

  it('renders the dedup hero card before the secondary metrics', async () => {
    setupHandlers();
    const { container } = render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(container.querySelector('[data-testid="dedup-hero"]')).not.toBeNull();
    });
    const hero = container.querySelector('[data-testid="dedup-hero"]');
    const metrics = container.querySelector('[data-testid="secondary-metrics"]');
    expect(hero).not.toBeNull();
    expect(metrics).not.toBeNull();
    // Hero must appear before the secondary metrics in the DOM order.
    const pos = hero!.compareDocumentPosition(metrics!);
    expect(pos & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('renders the Sophistication column with a tone per tier (C6 re-enabled)', async () => {
    server.use(
      http.get(`${BASE}/clusters`, () => HttpResponse.json([
        { ...mockClusters[0], sophistication: 'intermediate' },
        { ...mockClusters[1], sophistication: 'advanced' },
      ])),
      http.get(`${BASE}/clusters/stats`, () => HttpResponse.json(mockClusterStats)),
    );
    const { container } = render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/ScamBuster Cluster #ABCD/)).toBeInTheDocument();
    });
    expect(screen.getByText(/Sophistication/i)).toBeDefined();
    const cells = container.querySelectorAll('[data-sophistication]');
    expect(cells.length).toBe(2);
    const tiers = Array.from(cells).map((el) => el.getAttribute('data-sophistication'));
    expect(tiers).toContain('intermediate');
    expect(tiers).toContain('advanced');
  });

  it('renders a freshness dot per cluster row', async () => {
    setupHandlers();
    const { container } = render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/ScamBuster Cluster #ABCD/)).toBeInTheDocument();
    });
    const dots = container.querySelectorAll('[data-recency]');
    expect(dots.length).toBe(2);
  });

  it('renders a financial anchor with the accent style and a phone anchor with the muted style', async () => {
    setupHandlers();
    const { container } = render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/ScamBuster Cluster #ABCD/)).toBeInTheDocument();
    });
    const finCell = container.querySelector('[data-anchor-kind="financial"]');
    const phoneCell = container.querySelector('[data-anchor-kind="phone"]');
    expect(finCell).not.toBeNull();
    expect(phoneCell).not.toBeNull();
  });

  it('renders a multi-type chip for clusters spanning multiple scam types', async () => {
    setupHandlers();
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/ScamBuster Cluster #BEEF/)).toBeInTheDocument();
    });
    expect(screen.getByText(/Multi-type · 2/)).toBeDefined();
  });
});
