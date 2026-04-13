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

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
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
  it('renders without crashing', async () => {
    setupHandlers();
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<Clusters />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Threat Actor Clusters/i)).toBeInTheDocument();
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
});
