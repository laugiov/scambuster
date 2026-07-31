import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { useAuthStore } from '@/store/authStore';
import type { TtpClusters, TtpConversations, TtpTaxonomyResponse } from '@/types/ttp';
import { TtpDetail } from './TtpDetail';
import App from '../App';
import '../i18n';

const BASE = '/api/v1';

// Mid-day timestamps keep the formatted dates timezone-robust in CI.
const mockTaxonomy: TtpTaxonomyResponse = {
  taxonomy_version: '1.0',
  ttps: [
    {
      ttp_code: 'SB-T001',
      ttp_label: 'Cold outreach',
      phase: 'hook',
      definition: 'Unsolicited first contact.',
      examples: ['Dear friend, I found your profile...'],
      external_refs: [
        { source_name: 'mitre-attack', external_id: 'T1566', url: 'https://attack.mitre.org/techniques/T1566/' },
        { source_name: 'mitre-attack', external_id: 'T1656' },
      ],
      observation_count: 30,
      conversation_count: 5,
      first_seen: '2026-01-01T12:00:00Z',
      last_seen: '2026-03-01T12:00:00Z',
      review_count: 5,
    },
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

const mockClusters: TtpClusters = {
  items: [
    {
      cluster_id: 'cl-1',
      label: 'Cluster One',
      observation_count: 12,
      conversation_count: 4,
      first_seen: '2026-01-05T12:00:00Z',
      last_seen: '2026-02-20T12:00:00Z',
    },
  ],
  truncated: false,
};

const conversationsPage1: TtpConversations = {
  items: [
    {
      conv_id: 'conv-1',
      subject: 'Verify your account',
      scam_type_code: 'PHISHING',
      observation_count: 3,
      review_count: 0,
      last_seen: '2026-02-20T12:00:00Z',
    },
    // AS-BUILT contract: a review-only conversation appears with 0 confirmed
    // observations and its review count split out; subject may be null.
    {
      conv_id: 'conv-2',
      subject: null,
      scam_type_code: null,
      observation_count: 0,
      review_count: 2,
      last_seen: '2026-02-18T12:00:00Z',
    },
  ],
  total: 25,
  limit: 20,
  offset: 0,
};

const conversationsPage2: TtpConversations = {
  items: [
    {
      conv_id: 'conv-21',
      subject: 'Page two subject',
      scam_type_code: 'ROMANCE',
      observation_count: 1,
      review_count: 0,
      last_seen: '2026-01-10T12:00:00Z',
    },
  ],
  total: 25,
  limit: 20,
  offset: 20,
};

function taxonomyHandler() {
  server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json(mockTaxonomy)));
}

function clustersHandler() {
  server.use(http.get(`${BASE}/ttps/:code/clusters`, () => HttpResponse.json(mockClusters)));
}

/** Serves both pages and records every requested offset. */
function conversationsHandler(offsets: string[] = []) {
  server.use(
    http.get(`${BASE}/ttps/:code/conversations`, ({ request }) => {
      const offset = new URL(request.url).searchParams.get('offset') ?? '0';
      offsets.push(offset);
      return HttpResponse.json(offset === '20' ? conversationsPage2 : conversationsPage1);
    }),
  );
}

function allHandlers() {
  taxonomyHandler();
  clustersHandler();
  conversationsHandler();
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

/** Exposes the router URL so tests can assert back/link navigation targets. */
function LocationProbe() {
  const location = useLocation();
  return <div data-testid="location-probe">{`${location.pathname}${location.search}`}</div>;
}

function createWrapper(initialEntry = '/ttps/SB-T001') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[initialEntry]}>
          <Routes>
            {/* Probe route: the back button must land on the Explorer. */}
            <Route path="/ttps" element={<><div data-testid="ttp-explorer-probe" /><LocationProbe /></>} />
            <Route path="/ttps/:code" element={<>{children}<LocationProbe /></>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('TtpDetail', () => {
  it('renders the overview tab by default with taxonomy metadata and counters', async () => {
    allHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Cold outreach')).toBeInTheDocument();
    });

    // Header: code badge + phase badge.
    expect(screen.getByText('SB-T001')).toBeInTheDocument();
    expect(screen.getByText('Hook')).toBeInTheDocument();

    // Counters: observations 30, conversations 5, awaiting review 5.
    expect(screen.getByText('30')).toBeInTheDocument();
    expect(screen.getAllByText('5')).toHaveLength(2);

    // First/last seen from message timestamps.
    expect(screen.getByText('Jan 1, 2026')).toBeInTheDocument();
    expect(screen.getByText('Mar 1, 2026')).toBeInTheDocument();

    // Definition + taxonomy example formulations.
    expect(screen.getByText('Unsolicited first contact.')).toBeInTheDocument();
    expect(screen.getByTestId('ttp-detail-examples')).toBeInTheDocument();
    expect(screen.getByText(/Dear friend, I found your profile/)).toBeInTheDocument();

    // External refs: the ATT&CK entry with a URL is an anchor…
    const refLink = screen.getByTestId('ttp-detail-ref-link');
    expect(refLink).toHaveTextContent('mitre-attack T1566');
    expect(refLink.getAttribute('href')).toBe('https://attack.mitre.org/techniques/T1566/');
    // …and the URL-less one renders as plain text.
    expect(screen.getByTestId('ttp-detail-ref')).toHaveTextContent('mitre-attack T1656');

    // All four tabs are present.
    expect(screen.getByTestId('ttp-detail-tab-overview')).toBeInTheDocument();
    expect(screen.getByTestId('ttp-detail-tab-iocs')).toBeInTheDocument();
    expect(screen.getByTestId('ttp-detail-tab-clusters')).toBeInTheDocument();
    expect(screen.getByTestId('ttp-detail-tab-conversations')).toBeInTheDocument();
  });

  it('shows cheap count badges on the clusters and conversations tabs', async () => {
    allHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    await waitFor(() => {
      expect(screen.getByTestId('ttp-detail-tab-clusters-badge').textContent).toBe('1');
      expect(screen.getByTestId('ttp-detail-tab-conversations-badge').textContent).toBe('25');
    });
  });

  it('navigates back to the Explorer via the back button', async () => {
    allHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-back'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-explorer-probe')).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps');
    });
  });

  it('lazily fetches co-occurring IOCs only when the tab is opened and deep-links each IOC', async () => {
    allHandlers();
    const requestedCodes: string[] = [];
    server.use(
      http.get(`${BASE}/ttps/:code/iocs`, ({ params }) => {
        requestedCodes.push(String(params.code));
        return HttpResponse.json({
          ttp_code: String(params.code),
          iocs: [
            { indicator_id: 'ind-9', type: 'iban', value_norm: 'de00 1111 2222', co_occurrence_count: 3, conversation_count: 2 },
          ],
        });
      }),
    );

    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    // Lazy: the panel is not mounted and the pivot endpoint is untouched
    // before the tab is opened (conditional mount, no effect).
    expect(screen.queryByTestId('ttp-iocs-section')).toBeNull();
    expect(requestedCodes).toEqual([]);

    fireEvent.click(screen.getByTestId('ttp-detail-tab-iocs'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-iocs-section')).toBeInTheDocument();
    });
    expect(requestedCodes).toContain('SB-T001');

    // The IOC value renders and deep-links to /ioc-explorer/{indicator_id}.
    await waitFor(() => {
      expect(screen.getByText('de00 1111 2222')).toBeInTheDocument();
    });
    expect(screen.getByTestId('ttp-ioc-link').getAttribute('href')).toBe('/ioc-explorer/ind-9');
  });

  it('shows an empty note on the IOC tab when the TTP has no co-occurring IOCs', async () => {
    allHandlers();
    server.use(
      http.get(`${BASE}/ttps/:code/iocs`, ({ params }) =>
        HttpResponse.json({ ttp_code: String(params.code), iocs: [] })),
    );

    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-iocs'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-iocs-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ttp-ioc-link')).toBeNull();
  });

  it('renders the clusters tab with cluster links and counters', async () => {
    allHandlers();
    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-clusters'));
    await waitFor(() => {
      expect(screen.getByText('Cluster One')).toBeInTheDocument();
    });

    expect(screen.getByTestId('ttp-cluster-link').getAttribute('href')).toBe('/clusters/cl-1');
    const row = screen.getByTestId('ttp-cluster-row');
    expect(row).toHaveTextContent('4'); // conversation_count
    expect(row).toHaveTextContent('12'); // observation_count
    expect(row).toHaveTextContent('Jan 5, 2026');
    expect(row).toHaveTextContent('Feb 20, 2026');
    // Under the cap: no truncated note.
    expect(screen.queryByTestId('ttp-clusters-truncated')).toBeNull();
  });

  it('renders server-paginated conversations, including the review-only row with 0 confirmed observations', async () => {
    taxonomyHandler();
    clustersHandler();
    const offsets: string[] = [];
    conversationsHandler(offsets);

    render(<TtpDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-detail-tab-conversations'));
    await waitFor(() => {
      expect(screen.getByText('Verify your account')).toBeInTheDocument();
    });

    // Row links target the conversation detail route.
    const links = screen.getAllByTestId('ttp-conversation-link');
    expect(links[0].getAttribute('href')).toBe('/conversations/conv-1');
    expect(links[1].getAttribute('href')).toBe('/conversations/conv-2');
    // Null subject renders '--' (still a link).
    expect(links[1]).toHaveTextContent('--');

    // Scam type label from the shared helper.
    expect(screen.getByText('Phishing')).toBeInTheDocument();

    // AS-BUILT: the review-only conversation shows 0 confirmed / 2 review.
    // Per-cell exact matches — a substring check on the whole row would pass
    // vacuously via the date cell ("Feb 18, 2026" contains both 0 and 2).
    const reviewOnlyRow = links[1].closest('tr')!;
    const cells = within(reviewOnlyRow).getAllByRole('cell');
    expect(cells[2]).toHaveTextContent(/^0$/);
    expect(cells[3]).toHaveTextContent(/^2$/);

    // Server-driven pagination: 25 total / 20 per page → 2 pages.
    expect(screen.getByText('1 / 2')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));

    await waitFor(() => {
      expect(screen.getByText('Page two subject')).toBeInTheDocument();
    });
    // Page 2 asked the server for offset=20 (never client-side slicing).
    expect(offsets).toEqual(['0', '20']);
  });

  it('degrades to an ErrorMessage for a code missing from the taxonomy', async () => {
    allHandlers();
    render(<TtpDetail />, { wrapper: createWrapper('/ttps/SB-T999') });

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });
    expect(screen.getByText('TTP code not found in the taxonomy')).toBeInTheDocument();
  });
});

describe('TtpDetail — sidebar active state through the real app routes', () => {
  afterEach(() => {
    useAuthStore.setState({ isAuthenticated: false, isLoading: false, error: null });
    window.history.pushState({}, '', '/');
  });

  it('keeps the TTP Explorer sidebar entry active on /ttps/:code', async () => {
    allHandlers();
    useAuthStore.setState({ isAuthenticated: true });
    window.history.pushState({}, '', '/ttps/SB-T001');

    render(<App />);

    // The real route table serves the detail page (no probe routes here)…
    expect(await screen.findByTestId('ttp-detail-back', {}, { timeout: 3000 })).toBeInTheDocument();
    // …and the sidebar TTPs NavLink stays active via partial matching.
    const navLink = screen.getByRole('link', { name: 'TTP Explorer' });
    expect(navLink).toHaveAttribute('aria-current', 'page');
  });
});
