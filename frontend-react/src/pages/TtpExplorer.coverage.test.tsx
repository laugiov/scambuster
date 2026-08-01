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

const populated: TtpTaxonomyResponse = {
  taxonomy_version: '1.0',
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook', definition: 'Unsolicited first contact.', examples: [], external_refs: [], observation_count: 30, conversation_count: 5, first_seen: '2026-01-01T00:00:00Z', last_seen: '2026-03-01T00:00:00Z', review_count: 5 },
    { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request', definition: 'Demands an upfront fee.', examples: [], external_refs: [], observation_count: 20, conversation_count: 10, first_seen: '2026-01-02T00:00:00Z', last_seen: '2026-03-02T00:00:00Z', review_count: 0 },
    { ttp_code: 'SB-T010', ttp_label: 'Urgency pressure', phase: 'escalation', definition: 'Creates false time pressure.', examples: [], external_refs: [], observation_count: 10, conversation_count: 8, first_seen: '2026-01-03T00:00:00Z', last_seen: '2026-03-03T00:00:00Z', review_count: 3 },
  ],
};

// Fresh-install shape: every taxonomy entry present but all counts zero.
const emptyCounts: TtpTaxonomyResponse = {
  taxonomy_version: '1.0',
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook', definition: 'Unsolicited first contact.', examples: [], external_refs: [], observation_count: 0, conversation_count: 0, first_seen: null, last_seen: null, review_count: 0 },
    { ttp_code: 'SB-T027', ttp_label: 'Ghosting', phase: 'exit', definition: 'Silent disappearance.', examples: [], external_refs: [], observation_count: 0, conversation_count: 0, first_seen: null, last_seen: null, review_count: 0 },
  ],
};

const emptyTrend: TtpPhaseTrend = { weeks: [] };

function populatedHandler() {
  server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json(populated)));
}

function emptyTrendHandler() {
  server.use(http.get(`${BASE}/ttps/phase-trend`, () => HttpResponse.json(emptyTrend)));
}

function emptyMatrixHandler() {
  server.use(
    http.get(`${BASE}/ttps/cluster-matrix`, () =>
      HttpResponse.json({ clusters: [], ttps: [], cells: [], truncated: false, total_clusters: 0 })),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

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

describe('TtpExplorer — coverage gaps', () => {
  it('shows the loading state', () => {
    server.use(
      http.get(`${BASE}/ttps`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(populated);
      }),
    );
    render(<TtpExplorer />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows the error state with a retry affordance', async () => {
    server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json({}, { status: 500 })));
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail|retry/i);
    });
  });

  it('renders the zero-observation taxonomy honestly (rows kept, zero backlog)', async () => {
    server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json(emptyCounts)));
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Cold outreach')).toBeInTheDocument();
    });
    // Rows still present, never an error.
    expect(screen.getByText('Ghosting')).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/failed to load/i);
    // Review backlog counter reads zero.
    expect(screen.getByText(/0 awaiting review/i)).toBeInTheDocument();
  });

  it('analytics tab degrades to informative notes when everything is zero', async () => {
    server.use(http.get(`${BASE}/ttps`, () => HttpResponse.json(emptyCounts)));
    emptyTrendHandler();
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=analytics') });

    await waitFor(() => {
      expect(screen.getByText(/No observations recorded yet/i)).toBeInTheDocument();
    });
    // The trend card renders its own empty note, never an error.
    expect(screen.getByTestId('ttp-phase-trend')).toBeInTheDocument();
    await waitFor(() => {
      expect(screen.getByText(/No confirmed observations in the last 8 weeks/i)).toBeInTheDocument();
    });
    expect(document.body.textContent).not.toMatch(/failed to load/i);
  });

  it('trend card shows a failure note when the phase-trend endpoint errors', async () => {
    populatedHandler();
    server.use(http.get(`${BASE}/ttps/phase-trend`, () => HttpResponse.json({}, { status: 500 })));
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=analytics') });

    // The distribution chart still renders from the taxonomy payload…
    await waitFor(() => {
      expect(screen.getByText(/Observations by kill-chain phase/i)).toBeInTheDocument();
    });
    // …while the trend card degrades to its own failure note.
    await waitFor(() => {
      expect(screen.getByText(/Failed to load the phase trend/i)).toBeInTheDocument();
    });
  });

  it('splits the playbooks matrix, sequences and phase-transitions into sub-tabs', async () => {
    populatedHandler();
    emptyMatrixHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-tab-playbooks'));
    // Default sub-view is the matrix; the other two panels are not mounted yet
    // (self-fetching panels fetch lazily, only when their sub-tab is active).
    await waitFor(() => {
      expect(screen.getByText(/Shared-playbook matrix/i)).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=playbooks');
    });
    expect(screen.queryByTestId('ttp-sequences')).toBeNull();
    expect(screen.queryByTestId('ttp-phase-transitions')).toBeNull();

    // Sequences sub-tab mounts the sequences panel; the matrix unmounts. The
    // deep behavioural suites live in SequencesPanel.test.tsx and
    // PhaseTransitionsMatrix.test.tsx — here we prove the sub-tab shell.
    fireEvent.click(screen.getByTestId('ttp-subtab-sequences'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-sequences')).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=playbooks&view=sequences');
    });
    expect(screen.queryByTestId('cluster-ttp-matrix')).toBeNull();
    await waitFor(() => expect(screen.getByTestId('ttp-sequences-empty')).toBeInTheDocument());

    // Phase-transitions sub-tab mounts the transitions matrix.
    fireEvent.click(screen.getByTestId('ttp-subtab-phases'));
    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions')).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=playbooks&view=phases');
    });
    expect(screen.queryByTestId('ttp-sequences')).toBeNull();
    await waitFor(() => expect(screen.getByTestId('ttp-phase-transitions-empty')).toBeInTheDocument());
  });

  it('navigates to the detail page via keyboard (Enter) on a row', async () => {
    populatedHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.keyDown(screen.getByText('Cold outreach').closest('tr')!, { key: 'Enter' });
    await waitFor(() => {
      expect(screen.getByTestId('ttp-detail-probe')).toBeInTheDocument();
      expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps/SB-T001');
    });
  });

  it('toggles sort direction when the same header is clicked twice', async () => {
    populatedHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    // Default observation_count DESC.
    expect(rowCodes()).toEqual(['SB-T001', 'SB-T017', 'SB-T010']);
    // Click Observations header → ascending.
    fireEvent.click(screen.getByText('Observations'));
    await waitFor(() => {
      expect(rowCodes()).toEqual(['SB-T010', 'SB-T017', 'SB-T001']);
    });
  });

  it('sorts by the review column', async () => {
    populatedHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Review'));
    await waitFor(() => {
      // review_count DESC → T001(5), T010(3), T017(0)
      expect(rowCodes()).toEqual(['SB-T001', 'SB-T010', 'SB-T017']);
    });
  });

  it('sorts by the last-seen column', async () => {
    populatedHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.click(screen.getByText('Last Seen'));
    await waitFor(() => {
      // last_seen DESC → T010(03-03), T017(03-02), T001(03-01)
      expect(rowCodes()).toEqual(['SB-T010', 'SB-T017', 'SB-T001']);
    });
  });

  it('resets the phase filter with the All chip', async () => {
    populatedHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    const escalationChip = screen.getAllByRole('button').find((b) => b.textContent === 'Escalation');
    fireEvent.click(escalationChip!);
    await waitFor(() => {
      expect(screen.getByText('Urgency pressure')).toBeInTheDocument();
      expect(screen.queryByText('Cold outreach')).toBeNull();
    });

    const allChip = screen.getAllByRole('button').find((b) => b.textContent === 'All');
    fireEvent.click(allChip!);
    await waitFor(() => {
      expect(screen.getByText('Cold outreach')).toBeInTheDocument();
    });
  });

  it('shows the no-match empty state when a search excludes every row', async () => {
    populatedHandler();
    render(<TtpExplorer />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Cold outreach')).toBeInTheDocument());

    fireEvent.change(screen.getByLabelText('Search TTPs'), { target: { value: 'zzzznope' } });
    await waitFor(() => {
      expect(screen.getByText(/No TTPs match/i)).toBeInTheDocument();
    });
  });

  it('renders a populated review queue through the page shell at ?tab=review', async () => {
    populatedHandler();
    server.use(http.get(`${BASE}/ttps/review-queue`, () => HttpResponse.json({
      items: [{
        obs_id: 'obs-1',
        ttp_code: 'SB-T001',
        ttp_label: 'Cold outreach',
        phase: 'hook',
        confidence: 0.4,
        conv_id: 'conv-review-1',
        msg_id: 'msg-1',
        ts_msg: '2026-07-01T00:00:00Z',
        evidence_start: null,
        evidence_end: null,
        extraction_model: 'mistral-large',
      }],
      total: 1,
    })));
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=review') });

    // The deep behavioural suite lives in ReviewQueueTable.test.tsx; this
    // pass proves the queue mounts through the tab shell.
    await waitFor(() => {
      expect(screen.getAllByTestId('ttp-review-row')).toHaveLength(1);
    });
    expect(screen.getByTestId('ttp-review-mask-toggle')).toBeInTheDocument();
    // Singular plural form renders end-to-end.
    expect(screen.getByText('1 observation awaiting review')).toBeInTheDocument();
  });

  it('mounts the persona and stimulus matrices in their analytics sub-tabs', async () => {
    populatedHandler();
    emptyTrendHandler();
    server.use(
      http.get(`${BASE}/ttps/persona-matrix`, () => HttpResponse.json({
        personas: [{ code: 'elderly', label: 'Elderly Person', conversation_total: 8 }],
        ttps: [{ code: 'SB-T001', label: 'Cold outreach', phase: 'hook' }],
        cells: [{ persona_code: 'elderly', ttp_code: 'SB-T001', observation_count: 5, conversation_count: 4 }],
        truncated: false,
        total_personas: 1,
        null_persona_conversations: 0,
      })),
      http.get(`${BASE}/ttps/stimulus-matrix`, () => HttpResponse.json({
        stimuli: ['URGENCY_PRESSURE'],
        ttps: [{ code: 'SB-T001', label: 'Cold outreach', phase: 'hook' }],
        cells: [{ stimulus_type: 'URGENCY_PRESSURE', ttp_code: 'SB-T001', message_count: 3, conversation_count: 2 }],
        population_messages: 12,
      })),
    );
    render(<TtpExplorer />, { wrapper: createWrapper('/ttps?tab=analytics') });

    // Default sub-view is activity — the matrices fetch lazily and are not
    // mounted yet. The deep behavioural suites live in PersonaTtpMatrix.test.tsx
    // and StimulusTtpMatrix.test.tsx; this pass proves both mount through the
    // sub-tab shell.
    await waitFor(() => expect(screen.getByText(/Observations by kill-chain phase/i)).toBeInTheDocument());
    expect(screen.queryByTestId('persona-ttp-matrix')).toBeNull();
    expect(screen.queryByTestId('stimulus-ttp-matrix')).toBeNull();

    // Persona sub-tab.
    fireEvent.click(screen.getByTestId('ttp-subtab-persona'));
    await waitFor(() => {
      expect(screen.getByTestId('persona-ttp-matrix-table')).toBeInTheDocument();
      expect(screen.getByText('Elderly Person')).toBeInTheDocument();
    });
    expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=analytics&view=persona');
    expect(screen.queryByTestId('ttp-phase-trend')).toBeNull();

    // Stimulus sub-tab.
    fireEvent.click(screen.getByTestId('ttp-subtab-stimulus'));
    await waitFor(() => {
      expect(screen.getByTestId('stimulus-ttp-matrix-table')).toBeInTheDocument();
    });
    expect(screen.getByTestId('stimulus-ttp-matrix-population')).toHaveTextContent('12');
    expect(screen.getByTestId('location-probe')).toHaveTextContent('/ttps?tab=analytics&view=stimulus');
    expect(document.body.textContent).not.toMatch(/failed to load/i);
  });
});
