import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { TtpPhaseTrend, TtpTaxonomyResponse } from '@/types/ttp';
import { TtpExplorer } from './TtpExplorer';
import '../i18n';

const BASE = '/api/v1';

const mockTaxonomy: TtpTaxonomyResponse = {
  taxonomy_version: '1.0',
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook', definition: 'Unsolicited first contact.', examples: ['Dear friend, I found your profile...'], external_refs: [{ source_name: 'mitre-attack', external_id: 'T1566' }], observation_count: 30, conversation_count: 5, first_seen: '2026-01-01T00:00:00Z', last_seen: '2026-03-01T00:00:00Z', review_count: 5 },
    { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request', definition: 'Demands an upfront fee.', examples: [], external_refs: [], observation_count: 20, conversation_count: 10, first_seen: '2026-01-02T00:00:00Z', last_seen: '2026-03-02T00:00:00Z', review_count: 0 },
    { ttp_code: 'SB-T010', ttp_label: 'Urgency pressure', phase: 'escalation', definition: 'Creates false time pressure.', examples: [], external_refs: [], observation_count: 10, conversation_count: 8, first_seen: '2026-01-03T00:00:00Z', last_seen: '2026-03-03T00:00:00Z', review_count: 3 },
    { ttp_code: 'SB-T027', ttp_label: 'Ghosting', phase: 'exit', definition: 'Silent disappearance.', examples: [], external_refs: [], observation_count: 0, conversation_count: 0, first_seen: null, last_seen: null, review_count: 0 },
  ],
};

const mockTrend: TtpPhaseTrend = {
  weeks: [
    { week: '2026-06-08', counts: { hook: 2, 'trust-building': 0, 'payment-request': 1, escalation: 0, 'channel-switch': 0, exit: 0 } },
    { week: '2026-06-15', counts: { hook: 0, 'trust-building': 3, 'payment-request': 0, escalation: 1, 'channel-switch': 0, exit: 0 } },
  ],
};

function taxonomyHandler() {
  server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json(mockTaxonomy)));
}

function trendHandler() {
  server.use(http.get(`${BASE}/ttps/phase-trend`, () => HttpResponse.json(mockTrend)));
}

function emptyReviewQueueHandler() {
  server.use(http.get(`${BASE}/ttps/review-queue`, () => HttpResponse.json({ items: [], total: 0 })));
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

/** Exposes the router URL so tests can assert deep-links are honored, never rewritten. */
function LocationProbe() {
  const location = useLocation();
  return <div data-testid="location-probe">{`${location.pathname}${location.search}`}</div>;
}

function createWrapper(initialEntry = '/ttps') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[initialEntry]}>
          <Routes>
            <Route path="/ttps" element={<>{children}<LocationProbe /></>} />
            {/* Probe route: row clicks must land on the per-TTP detail page. */}
            <Route path="/ttps/:code" element={<><div data-testid="ttp-detail-probe" /><LocationProbe /></>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

function rowCodes(): string[] {
  return screen.getAllByTestId('ttp-row').map((tr) => tr.querySelector('td')!.textContent!.trim());
}

describe('TtpExplorer', () => {
  it('renders the taxonomy list including zero-observation rows', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Cold outreach')).toBeInTheDocument();
    });
    // Zero-observation entry is still shown for honest coverage.
    expect(screen.getByText('Ghosting')).toBeInTheDocument();
    expect(screen.getByText('Payment demand')).toBeInTheDocument();
    expect(screen.getByText('Urgency pressure')).toBeInTheDocument();
  });

  it('search narrows the list by label/code/definition', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText('Search TTPs'), { target: { value: 'Ghosting' } });
    await waitFor(() => {
      expect(screen.getByText('Ghosting')).toBeInTheDocument();
      expect(screen.queryByText('Cold outreach')).toBeNull();
    });
  });

  it('sorts by a numeric column when its header is clicked', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    // Default sort is observation_count DESC → T001(30), T017(20), T010(10), T027(0)
    expect(rowCodes()).toEqual(['SB-T001', 'SB-T017', 'SB-T010', 'SB-T027']);

    // Sort by Conversations DESC → T017(10), T010(8), T001(5), T027(0)
    fireEvent.click(screen.getByText('Conversations'));
    await waitFor(() => {
      expect(rowCodes()).toEqual(['SB-T017', 'SB-T010', 'SB-T001', 'SB-T027']);
    });
  });

  it('review filter narrows to rows with review observations', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Payment demand')).toBeInTheDocument());

    fireEvent.click(screen.getByLabelText(/Has review observations/i));
    await waitFor(() => {
      // Only T001 (review 5) and T010 (review 3) remain.
      expect(screen.getByText('Cold outreach')).toBeInTheDocument();
      expect(screen.getByText('Urgency pressure')).toBeInTheDocument();
      expect(screen.queryByText('Payment demand')).toBeNull();
      expect(screen.queryByText('Ghosting')).toBeNull();
    });
  });

  it('shows the total review-backlog counter', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      // 5 + 3 = 8 awaiting review across the taxonomy.
      expect(screen.getByText(/8 awaiting review/i)).toBeInTheDocument();
    });
  });

  it('phase chips filter the table by kill-chain phase', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    const hookChip = screen.getAllByRole('button').find((b) => b.textContent === 'Hook');
    fireEvent.click(hookChip!);
    await waitFor(() => {
      expect(screen.getByText('Cold outreach')).toBeInTheDocument();
      expect(screen.queryByText('Payment demand')).toBeNull();
    });
  });

  it('navigates to the TTP detail page when a row is clicked', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Cold outreach').closest('tr')!);
    await waitFor(() => {
      expect(screen.getByTestId('ttp-detail-probe')).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps/SB-T001');
    });
  });

  it('deep-links ?tab=review to the review queue', async () => {
    taxonomyHandler();
    emptyReviewQueueHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=review') });

    // An empty queue renders the designed "queue clear" state; the taxonomy
    // table is not rendered.
    await waitFor(() => {
      expect(screen.getByTestId('ttp-review-empty')).toBeInTheDocument();
    });
    expect(screen.queryAllByTestId('ttp-row')).toHaveLength(0);
  });

  it('falls back to the taxonomy on an invalid ?tab= without rewriting the URL', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=zzz') });

    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());
    // The URL keeps the invalid value: derived-in-render fallback, no normalization.
    expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=zzz');
  });

  it('updates the URL when a tab is clicked and renders that tab', async () => {
    taxonomyHandler();
    trendHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-tab-analytics'));
    await waitFor(() => {
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=analytics');
      expect(screen.getByText(/Observations by kill-chain phase/i)).toBeInTheDocument();
      expect(screen.getByTestId('ttp-phase-trend')).toBeInTheDocument();
    });
  });

  it('renders the analytics tab from a ?tab=analytics deep-link', async () => {
    taxonomyHandler();
    trendHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=analytics') });

    await waitFor(() => {
      expect(screen.getByText(/Observations by kill-chain phase/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/60 observations/i)).toBeInTheDocument();
    expect(screen.getByTestId('ttp-phase-trend')).toBeInTheDocument();
    expect(screen.getByText(/Phase evolution/i)).toBeInTheDocument();
  });

  it('navigates to the review tab via the backlog pill', async () => {
    taxonomyHandler();
    emptyReviewQueueHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByText(/8 awaiting review/i));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-review-empty')).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=review');
    });
  });

  it('shows the review backlog count as a badge on the review tab button', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    // Exact match on the badge element itself — a substring check on the whole
    // button would still pass for a wrong count like 18 or 80.
    expect(screen.getByTestId('ttp-tab-review-badge').textContent).toBe('8');
  });

  // --- In-tab sub-tabs (?view=, scoped to ?tab=) --------------------------

  it('deep-links ?tab=playbooks&view=sequences to the sequences sub-view only', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=playbooks&view=sequences') });

    await waitFor(() => expect(screen.getByTestId('ttp-sequences')).toBeInTheDocument());
    // Only the active sub-view renders; the default matrix sub-view is not mounted.
    expect(screen.queryByTestId('cluster-ttp-matrix')).toBeNull();
    expect(screen.getByTestId('ttp-subtab-sequences')).toHaveAttribute('aria-current', 'true');
  });

  it('deep-links ?tab=analytics&view=stimulus to the stimulus matrix only', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=analytics&view=stimulus') });

    await waitFor(() => expect(screen.getByTestId('stimulus-ttp-matrix')).toBeInTheDocument());
    // The activity sub-view (phase chart + trend) is not mounted.
    expect(screen.queryByTestId('ttp-phase-trend')).toBeNull();
    expect(screen.queryByText(/Observations by kill-chain phase/i)).toBeNull();
    expect(screen.getByTestId('ttp-subtab-stimulus')).toHaveAttribute('aria-current', 'true');
  });

  it('falls back to the default sub-view on an invalid ?view= without rewriting the URL', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=playbooks&view=zzz') });

    // Playbooks default sub-view is the matrix.
    await waitFor(() => expect(screen.getByTestId('cluster-ttp-matrix')).toBeInTheDocument());
    expect(screen.getByTestId('ttp-subtab-matrix')).toHaveAttribute('aria-current', 'true');
    // The invalid value is preserved: derived-in-render fallback, no normalization.
    expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=playbooks&view=zzz');
  });

  it('updates ?view= when a sub-tab is clicked, keeping ?tab=', async () => {
    taxonomyHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=playbooks') });

    await waitFor(() => expect(screen.getByTestId('cluster-ttp-matrix')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('ttp-subtab-phases'));
    await waitFor(() => {
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=playbooks&view=phases');
      expect(screen.getByTestId('ttp-phase-transitions')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('cluster-ttp-matrix')).toBeNull();
  });

  it('clears a stale ?view= when a different main tab is selected', async () => {
    taxonomyHandler();
    trendHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=playbooks&view=sequences') });

    await waitFor(() => expect(screen.getByTestId('ttp-sequences')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('ttp-tab-analytics'));
    await waitFor(() => {
      // The new tab opens on its own default sub-view (activity), no stale view=.
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=analytics');
      expect(screen.getByText(/Observations by kill-chain phase/i)).toBeInTheDocument();
    });
    expect(screen.getByTestId('location-probe').textContent).not.toMatch(/view=/);
  });

  it('defaults to the matrix (playbooks) and activity (analytics) sub-views', async () => {
    taxonomyHandler();
    trendHandler();
    const { unmount } = render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=playbooks') });
    await waitFor(() => {
      expect(screen.getByTestId('cluster-ttp-matrix')).toBeInTheDocument();
    });
    expect(screen.getByTestId('ttp-subtab-matrix')).toHaveAttribute('aria-current', 'true');
    unmount();

    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=analytics') });
    await waitFor(() => {
      expect(screen.getByText(/Observations by kill-chain phase/i)).toBeInTheDocument();
    });
    expect(screen.getByTestId('ttp-subtab-activity')).toHaveAttribute('aria-current', 'true');
  });
});
