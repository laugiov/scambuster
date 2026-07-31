import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { ClusterTtpMatrix as ClusterTtpMatrixData } from '@/types/ttp';
import { ClusterTtpMatrix } from './ClusterTtpMatrix';
import '../../i18n';

const BASE = '/api/v1';

const populated: ClusterTtpMatrixData = {
  clusters: [
    { cluster_id: 'cl-1', label: 'Acme Crew', observation_total: 12, conversation_total: 9 },
    { cluster_id: 'cl-2', label: 'Beta Ring', observation_total: 5, conversation_total: 4 },
  ],
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook' },
    { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request' },
  ],
  cells: [
    { cluster_id: 'cl-1', ttp_code: 'SB-T001', count: 8, conversation_count: 4 },
    { cluster_id: 'cl-1', ttp_code: 'SB-T017', count: 4, conversation_count: 3 },
    { cluster_id: 'cl-2', ttp_code: 'SB-T017', count: 5, conversation_count: 4 },
  ],
  truncated: true,
  total_clusters: 60,
};

// Three clusters where playbook similarity diverges from raw size order: 'big'
// and 'small' both play SB-T001, 'mid' plays SB-T017. Size order is big > mid >
// small (backend volume); similarity chains big → small (same tactic) → mid.
const sortFixture: ClusterTtpMatrixData = {
  clusters: [
    { cluster_id: 'big', label: 'Big Crew', observation_total: 30, conversation_total: 10 },
    { cluster_id: 'mid', label: 'Mid Ring', observation_total: 20, conversation_total: 8 },
    { cluster_id: 'small', label: 'Small Gang', observation_total: 10, conversation_total: 5 },
  ],
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook' },
    { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request' },
  ],
  cells: [
    { cluster_id: 'big', ttp_code: 'SB-T001', count: 30, conversation_count: 10 },
    { cluster_id: 'mid', ttp_code: 'SB-T017', count: 20, conversation_count: 8 },
    { cluster_id: 'small', ttp_code: 'SB-T001', count: 10, conversation_count: 5 },
  ],
  truncated: false,
  total_clusters: 3,
};

// Minimal taxonomy payload so the header hover text can pick up definitions.
const taxonomy = {
  taxonomy_version: '1.0',
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook', definition: 'Unsolicited first-contact lure.', examples: [], external_refs: [], observation_count: 0, conversation_count: 0, first_seen: null, last_seen: null, review_count: 0 },
    { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request', definition: 'Direct request to send funds.', examples: [], external_refs: [], observation_count: 0, conversation_count: 0, first_seen: null, last_seen: null, review_count: 0 },
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

function mockMatrix(data: ClusterTtpMatrixData) {
  server.use(
    http.get(`${BASE}/ttps/cluster-matrix`, () => HttpResponse.json(data)),
    http.get(`${BASE}/ttps`, () => HttpResponse.json(taxonomy)),
  );
}

describe('ClusterTtpMatrix', () => {
  it('renders cluster rows, TTP columns and populated cells (raw counts by default)', async () => {
    mockMatrix(populated);
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('cluster-ttp-matrix-table')).toBeInTheDocument();
    });

    // Cluster labels (rows) and TTP codes (column headers).
    expect(screen.getByText('Acme Crew')).toBeInTheDocument();
    expect(screen.getByText('Beta Ring')).toBeInTheDocument();
    expect(screen.getByText('SB-T001')).toBeInTheDocument();
    expect(screen.getByText('SB-T017')).toBeInTheDocument();

    // Sparse grid, row-major: cl-1 [8, 4], cl-2 [gap, 5]. The gap cell is a dim
    // placeholder ("·"), the three populated cells carry their raw counts.
    const cells = screen.getAllByTestId('cluster-ttp-matrix-cell').map((c) => c.textContent);
    expect(cells).toEqual(['8', '4', '·', '5']);
  });

  it('shows the abbreviated label + code in each header, with the definition on hover', async () => {
    mockMatrix(populated);
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('cluster-ttp-matrix-table')).toBeInTheDocument());

    // The label is the readable header text (kept alongside the mono code).
    const header = screen.getByText('Cold outreach').closest('th')!;
    expect(header).toBeTruthy();
    expect(header).toHaveTextContent('SB-T001');
    // Full code + definition surface on hover (definition joined from the taxonomy).
    await waitFor(() =>
      expect(header.getAttribute('title')).toContain('Unsolicited first-contact lure.'),
    );
    expect(header.getAttribute('title')).toContain('SB-T001');
  });

  it('toggles cells between raw counts and per-conversation shares, reshading them', async () => {
    const user = userEvent.setup();
    mockMatrix(populated);
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('cluster-ttp-matrix-table')).toBeInTheDocument());

    const rawFirst = screen.getAllByTestId('cluster-ttp-matrix-cell')[0];
    expect(rawFirst).toHaveTextContent('8');
    const rawStyle = rawFirst.getAttribute('style') ?? '';
    expect(rawStyle).toContain('rgba');

    await user.click(screen.getByTestId('cluster-ttp-matrix-norm-share'));

    // Cells now show the conversation share: cl-1 [4/9=44%, 3/9=33%], cl-2 [·, 4/4=100%].
    const shareCells = screen.getAllByTestId('cluster-ttp-matrix-cell').map((c) => c.textContent);
    expect(shareCells).toEqual(['44%', '33%', '·', '100%']);

    // The first cell reshaded: raw shaded by max-count (8/8=1), share by 4/9.
    const shareStyle = screen.getAllByTestId('cluster-ttp-matrix-cell')[0].getAttribute('style') ?? '';
    expect(shareStyle).toContain('rgba');
    expect(shareStyle).not.toEqual(rawStyle);

    // The normalizer note reflects the active mode.
    expect(screen.getByTestId('cluster-ttp-matrix-normalizer')).toHaveTextContent(/per-conversation view/i);
  });

  it('reorders rows by playbook similarity when the similarity sort is chosen', async () => {
    const user = userEvent.setup();
    mockMatrix(sortFixture);
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('cluster-ttp-matrix-table')).toBeInTheDocument());

    const labelOrder = () =>
      screen.getAllByTestId('cluster-ttp-matrix-row').map((r) => r.querySelector('td')!.textContent);

    // Default (size): backend widest-first order.
    expect(labelOrder()).toEqual([
      expect.stringContaining('Big Crew'),
      expect.stringContaining('Mid Ring'),
      expect.stringContaining('Small Gang'),
    ]);

    await user.click(screen.getByTestId('cluster-ttp-matrix-sort-similarity'));

    // Similarity: big → small (shared SB-T001) → mid (SB-T017), deterministic.
    expect(labelOrder()).toEqual([
      expect.stringContaining('Big Crew'),
      expect.stringContaining('Small Gang'),
      expect.stringContaining('Mid Ring'),
    ]);
  });

  it('surfaces the truncation cap as an explicit note', async () => {
    mockMatrix(populated);
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('cluster-ttp-matrix-truncated')).toBeInTheDocument();
    });
    // "Showing top 2 of 60 clusters ..."
    expect(screen.getByText(/top 2 of 60/i)).toBeInTheDocument();
  });

  it('shows an empty note (not an error) when there are no clusters', async () => {
    server.use(
      http.get(`${BASE}/ttps/cluster-matrix`, () =>
        HttpResponse.json({ clusters: [], ttps: [], cells: [], truncated: false, total_clusters: 0 })),
    );
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('cluster-ttp-matrix-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('cluster-ttp-matrix-table')).toBeNull();
  });

  it('degrades to an empty note (not an error) on a 500', async () => {
    server.use(http.get(`${BASE}/ttps/cluster-matrix`, () => HttpResponse.json({}, { status: 500 })));
    render(<ClusterTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('cluster-ttp-matrix-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('cluster-ttp-matrix-table')).toBeNull();
  });
});
