import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { TtpClusters, TtpConversations, TtpTaxonomyResponse } from '@/types/ttp';
import { TtpDetail } from './TtpDetail';
import '../i18n';

const BASE = '/api/v1';

const mockTaxonomy: TtpTaxonomyResponse = {
  taxonomy_version: '1.0',
  ttps: [
    {
      ttp_code: 'SB-T001',
      ttp_label: 'Cold outreach',
      phase: 'hook',
      definition: 'Unsolicited first contact.',
      examples: [],
      external_refs: [],
      observation_count: 30,
      conversation_count: 5,
      first_seen: '2026-01-01T12:00:00Z',
      last_seen: '2026-03-01T12:00:00Z',
      review_count: 5,
    },
    // Demo reality: 16 taxonomy codes have zero observations — the detail
    // page must render them honestly, never as an error.
    {
      ttp_code: 'SB-T027',
      ttp_label: 'Ghosting',
      phase: 'exit',
      definition: 'Silent disappearance.',
      examples: [],
      external_refs: [],
      observation_count: 0,
      conversation_count: 0,
      first_seen: null,
      last_seen: null,
      review_count: 0,
    },
  ],
};

const emptyClusters: TtpClusters = { items: [], truncated: false };
const emptyConversations: TtpConversations = { items: [], total: 0, limit: 20, offset: 0 };

function taxonomyHandler() {
  server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json(mockTaxonomy)));
}

function emptyPivotHandlers() {
  server.use(
    http.get(`${BASE}/ttps/:code/clusters`, () => HttpResponse.json(emptyClusters)),
    http.get(`${BASE}/ttps/:code/conversations`, () => HttpResponse.json(emptyConversations)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper(initialEntry = '/ttps/SB-T001') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[initialEntry]}>
          <Routes>
            <Route path="/ttps" element={<div data-testid="ttp-explorer-probe" />} />
            <Route path="/ttps/:code" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('TtpDetail — coverage gaps', () => {
  it('shows the loading state while the taxonomy is fetching', () => {
    server.use(
      http.get(`${BASE}/ttps`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockTaxonomy);
      }),
    );
    emptyPivotHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows the error state with a retry affordance when the taxonomy fails', async () => {
    server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json({}, { status: 500 })));
    emptyPivotHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });
    expect(document.body.textContent).toMatch(/fail|retry/i);
  });

  it('renders an honest zero-observation overview (counters at 0, dates as --), never an error', async () => {
    taxonomyHandler();
    emptyPivotHandlers();
    render(<TtpDetail />, { wrapper: createWrapper('/ttps/SB-T027') });

    await waitFor(() => {
      expect(screen.getByText('Ghosting')).toBeInTheDocument();
    });

    // Honest zeros: observations, conversations and review all read 0.
    expect(screen.getAllByText('0')).toHaveLength(3);
    // First/last seen degrade to '--'.
    expect(screen.getAllByText('--')).toHaveLength(2);
    // The explicit never-observed note is shown; no error surface anywhere.
    expect(screen.getByTestId('ttp-detail-zero-note')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
    // Taxonomy metadata still renders (definition), empty sections are hidden.
    expect(screen.getByText('Silent disappearance.')).toBeInTheDocument();
    expect(screen.queryByTestId('ttp-detail-examples')).toBeNull();
    expect(screen.queryByTestId('ttp-detail-refs')).toBeNull();
  });

  it('clusters tab degrades to the empty state on a 404 (null resolve)', async () => {
    taxonomyHandler();
    server.use(
      http.get(`${BASE}/ttps/:code/clusters`, () =>
        HttpResponse.json({ error: 'TTP not found' }, { status: 404 })),
      http.get(`${BASE}/ttps/:code/conversations`, () => HttpResponse.json(emptyConversations)),
    );
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-clusters'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-clusters-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ttp-cluster-row')).toBeNull();
  });

  it('clusters tab shows the truncated note when the server cap bites', async () => {
    taxonomyHandler();
    server.use(
      http.get(`${BASE}/ttps/:code/clusters`, () =>
        HttpResponse.json({
          items: [
            { cluster_id: 'cl-1', label: 'Cluster One', observation_count: 12, conversation_count: 4, first_seen: '2026-01-05T12:00:00Z', last_seen: '2026-02-20T12:00:00Z' },
          ],
          truncated: true,
        })),
      http.get(`${BASE}/ttps/:code/conversations`, () => HttpResponse.json(emptyConversations)),
    );
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-clusters'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-clusters-truncated')).toBeInTheDocument();
    });
    expect(screen.getByTestId('ttp-clusters-truncated')).toHaveTextContent(/top 1 clusters/i);
  });

  it('clusters tab surfaces a failure note when the endpoint errors', async () => {
    taxonomyHandler();
    server.use(
      http.get(`${BASE}/ttps/:code/clusters`, () => HttpResponse.json({}, { status: 500 })),
      http.get(`${BASE}/ttps/:code/conversations`, () => HttpResponse.json(emptyConversations)),
    );
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-clusters'));
    await waitFor(() => {
      expect(screen.getByText(/Failed to load the clusters/i)).toBeInTheDocument();
    });
  });

  it('conversations tab renders the designed empty state when no conversation carries the TTP', async () => {
    taxonomyHandler();
    emptyPivotHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-conversations'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-conversations-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ttp-conversation-row')).toBeNull();
  });

  it('conversations tab degrades to the empty state on a 404 (null resolve)', async () => {
    taxonomyHandler();
    server.use(
      http.get(`${BASE}/ttps/:code/clusters`, () => HttpResponse.json(emptyClusters)),
      http.get(`${BASE}/ttps/:code/conversations`, () =>
        HttpResponse.json({ error: 'TTP not found' }, { status: 404 })),
    );
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-conversations'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-conversations-empty')).toBeInTheDocument();
    });
  });

  it('conversations tab surfaces a failure note when the endpoint errors', async () => {
    taxonomyHandler();
    server.use(
      http.get(`${BASE}/ttps/:code/clusters`, () => HttpResponse.json(emptyClusters)),
      http.get(`${BASE}/ttps/:code/conversations`, () => HttpResponse.json({}, { status: 500 })),
    );
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-conversations'));
    await waitFor(() => {
      expect(screen.getByText(/Failed to load the conversations/i)).toBeInTheDocument();
    });
  });

  it('keeps the pager reachable on an empty page and Previous repopulates the rows', async () => {
    taxonomyHandler();
    server.use(
      http.get(`${BASE}/ttps/:code/clusters`, () => HttpResponse.json(emptyClusters)),
      // The server still reports total 25, but page 2 comes back empty —
      // data shrank mid-session. The user must never be stranded there.
      http.get(`${BASE}/ttps/:code/conversations`, ({ request }) => {
        const offset = Number(new URL(request.url).searchParams.get('offset') ?? '0');
        if (offset >= 20) {
          return HttpResponse.json({ items: [], total: 25, limit: 20, offset });
        }
        return HttpResponse.json({
          items: [{
            conv_id: 'conv-1',
            subject: 'Page-one row',
            scam_type_code: null,
            observation_count: 2,
            review_count: 0,
            last_seen: '2026-03-01T00:00:00Z',
          }],
          total: 25,
          limit: 20,
          offset: 0,
        });
      }),
    );
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-conversations'));
    await waitFor(() => expect(screen.getByText('Page-one row')).toBeInTheDocument());

    fireEvent.click(screen.getByLabelText('Next page'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-conversations-empty')).toBeInTheDocument();
    });
    // The pager renders under the empty state and Previous goes back to a
    // populated page 1.
    fireEvent.click(screen.getByLabelText('Previous page'));
    await waitFor(() => expect(screen.getByText('Page-one row')).toBeInTheDocument());
  });
});
