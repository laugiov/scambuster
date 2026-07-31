import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { TtpSequences } from '@/types/ttp';
import { SequencesPanel } from './SequencesPanel';
import '../../i18n';

const BASE = '/api/v1';

const clusterSequences: TtpSequences = {
  groups: [
    {
      key: 'cl-1',
      label: 'Acme Crew',
      sequences: [
        { sequence: ['SB-T001', 'SB-T017'], count: 4, conversation_count: 3 },
        { sequence: ['SB-T017', 'SB-T003'], count: 2, conversation_count: 2 },
      ],
    },
    {
      key: 'cl-2',
      label: 'Beta Ring',
      sequences: [{ sequence: ['SB-T001', 'SB-T003'], count: 2, conversation_count: 2 }],
    },
  ],
  min_support: 2,
  truncated: false,
};

const scamTypeSequences: TtpSequences = {
  groups: [
    {
      key: 'ADVANCE_FEE',
      label: 'Advance fee',
      sequences: [{ sequence: ['SB-T003', 'SB-T017'], count: 5, conversation_count: 4 }],
    },
  ],
  min_support: 2,
  truncated: true,
};

const taxonomy = {
  taxonomy_version: '1.0',
  ttps: [
    { ttp_code: 'SB-T001', ttp_label: 'Cold outreach', phase: 'hook', definition: 'Unsolicited first contact.', examples: [], external_refs: [], observation_count: 4, conversation_count: 3, first_seen: null, last_seen: null, review_count: 0 },
    { ttp_code: 'SB-T003', ttp_label: 'Authority claim', phase: 'hook', definition: 'Fake official identity.', examples: [], external_refs: [], observation_count: 2, conversation_count: 2, first_seen: null, last_seen: null, review_count: 0 },
    { ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'escalation', definition: 'Demands an upfront fee.', examples: [], external_refs: [], observation_count: 6, conversation_count: 4, first_seen: null, last_seen: null, review_count: 0 },
  ],
};

/** Serve both groupings from one handler, branching on ?group=. */
function sequencesHandler() {
  server.use(
    http.get(`${BASE}/ttps`, () => HttpResponse.json(taxonomy)),
    http.get(`${BASE}/ttps/sequences`, ({ request }) => {
      const group = new URL(request.url).searchParams.get('group');
      return HttpResponse.json(group === 'scam_type' ? scamTypeSequences : clusterSequences);
    }),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('SequencesPanel', () => {
  it('renders per-group ordered chips "A → B (×N)" in server order', async () => {
    sequencesHandler();
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getAllByTestId('ttp-sequences-group')).toHaveLength(2);
    });

    // Group labels in server order.
    expect(screen.getByText('Acme Crew')).toBeInTheDocument();
    expect(screen.getByText('Beta Ring')).toBeInTheDocument();

    // Chips keep the server order and the house vocabulary, surfacing both the
    // occurrence count (×N) and the conversation support (N conv) so an
    // analyst is never misled by a pair confined to one conversation.
    const chips = screen.getAllByTestId('ttp-sequence-chip').map((c) => c.textContent?.replace(/\s+/g, ' ').trim());
    expect(chips).toEqual([
      'SB-T001 → SB-T017(×4 · 3 conv)',
      'SB-T017 → SB-T003(×2 · 2 conv)',
      'SB-T001 → SB-T003(×2 · 2 conv)',
    ]);
  });

  it('resolves TTP labels from the taxonomy cache into the chip tooltip', async () => {
    sequencesHandler();
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getAllByTestId('ttp-sequence-chip').length).toBeGreaterThan(0);
    });

    await waitFor(() => {
      const first = screen.getAllByTestId('ttp-sequence-chip')[0];
      expect(first.getAttribute('title')).toBe('Cold outreach → Payment demand · seen in 3 conversations');
    });
  });

  it('states the minimum-support hiding rule honestly', async () => {
    sequencesHandler();
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-sequences-min-support')).toHaveTextContent(
        'Pairs seen in fewer than 2 conversations are hidden.',
      );
    });
  });

  it('toggles to scam-type grouping and surfaces the truncation note', async () => {
    sequencesHandler();
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByText('Acme Crew')).toBeInTheDocument());

    fireEvent.click(screen.getByTestId('ttp-sequences-group-scam_type'));

    await waitFor(() => {
      expect(screen.getByText('Advance fee')).toBeInTheDocument();
    });
    expect(screen.queryByText('Acme Crew')).toBeNull();
    const chips = screen.getAllByTestId('ttp-sequence-chip').map((c) => c.textContent?.replace(/\s+/g, ' ').trim());
    expect(chips).toEqual(['SB-T003 → SB-T017(×5 · 4 conv)']);
    // The scam-type payload is truncated → the cap is never silent.
    expect(screen.getByTestId('ttp-sequences-truncated')).toBeInTheDocument();
  });

  it('shows the empty state when no pair clears the threshold (note kept)', async () => {
    server.use(
      http.get(`${BASE}/ttps`, () => HttpResponse.json(taxonomy)),
      http.get(`${BASE}/ttps/sequences`, () =>
        HttpResponse.json({ groups: [], min_support: 2, truncated: false })),
    );
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-sequences-empty')).toBeInTheDocument();
    });
    // The hiding rule stays visible so the empty state is honest.
    expect(screen.getByTestId('ttp-sequences-min-support')).toBeInTheDocument();
  });

  it('degrades to the empty state on a 404 (no support note without data)', async () => {
    server.use(
      http.get(`${BASE}/ttps`, () => HttpResponse.json(taxonomy)),
      http.get(`${BASE}/ttps/sequences`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 })),
    );
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-sequences-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ttp-sequences-min-support')).toBeNull();
  });

  it('degrades to the empty state on a 500 (no crash)', async () => {
    server.use(
      http.get(`${BASE}/ttps`, () => HttpResponse.json(taxonomy)),
      http.get(`${BASE}/ttps/sequences`, () => HttpResponse.json({}, { status: 500 })),
    );
    render(<SequencesPanel />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-sequences-empty')).toBeInTheDocument();
    });
  });
});
