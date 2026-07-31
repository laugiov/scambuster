import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { ClusterTtpPanel } from './ClusterTtpPanel';

const BASE = '/api/v1';
const CLUSTER_ID = 'cl-1';

const populatedProfile = {
  cluster_id: CLUSTER_ID,
  ttps: [
    {
      ttp_code: 'SB-T017',
      ttp_label: 'Payment demand',
      phase: 'payment-request',
      observation_count: 5,
      conversation_count: 3,
      avg_confidence: 0.84,
      first_seen: '2026-01-01T00:00:00Z',
      last_seen: '2026-03-01T00:00:00Z',
    },
    {
      ttp_code: 'SB-T001',
      ttp_label: 'Cold outreach',
      phase: 'hook',
      observation_count: 2,
      conversation_count: 2,
      avg_confidence: 0.9,
      first_seen: '2026-01-01T00:00:00Z',
      last_seen: '2026-02-01T00:00:00Z',
    },
  ],
  top_sequences: [
    { sequence: ['SB-T001', 'SB-T017'], count: 3 },
  ],
};

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('ClusterTtpPanel', () => {
  it('renders nothing while loading', () => {
    server.use(
      http.get(`${BASE}/clusters/${CLUSTER_ID}/ttps`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(populatedProfile);
      }),
    );
    const { container } = render(<ClusterTtpPanel clusterId={CLUSTER_ID} />, { wrapper: createWrapper() });
    expect(container.firstChild).toBeNull();
  });

  it('renders the empty state when the cluster is unknown (404 → absent)', async () => {
    server.use(
      http.get(`${BASE}/clusters/${CLUSTER_ID}/ttps`, () =>
        HttpResponse.json({ error: 'Cluster not found' }, { status: 404 })),
    );
    render(<ClusterTtpPanel clusterId={CLUSTER_ID} />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('cluster-ttp-empty')).toBeTruthy());
    expect(screen.queryByTestId('cluster-ttp')).toBeNull();
  });

  it('renders the empty state when the cluster has no observations', async () => {
    server.use(
      http.get(`${BASE}/clusters/${CLUSTER_ID}/ttps`, () =>
        HttpResponse.json({ cluster_id: CLUSTER_ID, ttps: [], top_sequences: [] })),
    );
    render(<ClusterTtpPanel clusterId={CLUSTER_ID} />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('cluster-ttp-empty')).toBeTruthy());
  });

  it('degrades to the empty state on a server error (no crash)', async () => {
    server.use(
      http.get(`${BASE}/clusters/${CLUSTER_ID}/ttps`, () =>
        HttpResponse.json({ error: 'boom' }, { status: 500 })),
    );
    render(<ClusterTtpPanel clusterId={CLUSTER_ID} />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('cluster-ttp-empty')).toBeTruthy());
  });

  it('renders the TTP rows and the top sequences when populated', async () => {
    server.use(
      http.get(`${BASE}/clusters/${CLUSTER_ID}/ttps`, () => HttpResponse.json(populatedProfile)),
    );
    render(<ClusterTtpPanel clusterId={CLUSTER_ID} />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('cluster-ttp')).toBeTruthy());
    expect(screen.getAllByTestId('cluster-ttp-row')).toHaveLength(2);
    expect(screen.getByText('Payment demand')).toBeTruthy();
    expect(screen.getByText('SB-T017')).toBeTruthy();

    // The phase badge uses the human phase label.
    expect(screen.getByText('Payment request')).toBeTruthy();

    // Sequence rendered as "A → B (×N)".
    const sequence = screen.getByTestId('cluster-ttp-sequence');
    expect(sequence.textContent).toContain('SB-T001 → SB-T017');
    expect(sequence.textContent).toContain('×3');
  });
});
