import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { ConversationDetail } from './ConversationDetail';
import { mockMetaConfig as baseMockMetaConfig, mockConversations as baseConversations } from '@/__tests__/fixtures';

const BASE = '/api/v1';
const CONV_ID = 'aaaa-bbbb-cccc-dddd';

const mockConvDetail = {
  conv_id: CONV_ID,
  status: 'open',
  score_risk: 50,
  persona: 'elderly_person',
  scam_type: 'INVOICE_FRAUD',
  ts_first: '2026-03-20T10:00:00Z',
  ts_last: '2026-03-20T12:00:00Z',
  account_label: 'Delta Holdings',
  account_email: 'admin@delta-holdings.example',
};

// msg-1 subject "Urgent" (6 code points) + "\n\n" (2) → body starts at combined
// offset 8. "pay" sits at body index 7, so its combined offsets are [15, 18).
const mockMessages = [
  { message_id: 'msg-1', direction: 'in', body_text: 'Please pay the invoice now', subject: 'Urgent', ts_msg: '2026-03-20T10:00:00Z' },
  { message_id: 'msg-2', direction: 'out', body_text: 'Sure, how do I proceed?', subject: null, ts_msg: '2026-03-20T11:00:00Z' },
  { message_id: 'msg-3', direction: 'in', body_text: 'Trust me, I am a lawyer.', subject: null, ts_msg: '2026-03-20T11:30:00Z' },
];

const mockTtps = {
  conv_id: CONV_ID,
  observations: [
    { msg_id: 'msg-1', ts_msg: '2026-03-20T10:00:00Z', ttp_code: 'SB-T017', ttp_label: 'Payment demand', phase: 'payment-request', confidence: 0.8, status: 'confirmed', evidence_start: 15, evidence_end: 18 },
    { msg_id: 'msg-1', ts_msg: '2026-03-20T10:00:00Z', ttp_code: 'SB-T099', ttp_label: 'Small talk', phase: 'hook', confidence: 0.4, status: 'review', evidence_start: null, evidence_end: null },
    { msg_id: 'msg-3', ts_msg: '2026-03-20T11:30:00Z', ttp_code: 'SB-T010', ttp_label: 'False authority', phase: 'trust-building', confidence: 0.7, status: 'confirmed', evidence_start: null, evidence_end: null },
  ],
  timeline: [
    {
      msg_id: 'msg-1',
      direction: 'in',
      ts_msg: '2026-03-20T10:00:00Z',
      subject: 'Urgent',
      ttps: [
        { ttp_code: 'SB-T017', phase: 'payment-request', confidence: 0.8, status: 'confirmed', evidence_start: 15, evidence_end: 18 },
        { ttp_code: 'SB-T099', phase: 'hook', confidence: 0.4, status: 'review', evidence_start: null, evidence_end: null },
      ],
      // First contact: stimulus_msg_id is null (unsolicited) — no linkage chip.
      iocs_revealed: [{ type: 'iban', value_norm: 'DE00SECRETIBAN', indicator_id: 'ind-1', stimulus_msg_id: null }],
      stimulus_type: null,
    },
    {
      msg_id: 'msg-2',
      direction: 'out',
      ts_msg: '2026-03-20T11:00:00Z',
      subject: null,
      ttps: [],
      iocs_revealed: [],
      stimulus_type: 'PAYMENT_INITIATION',
    },
    {
      msg_id: 'msg-3',
      direction: 'in',
      ts_msg: '2026-03-20T11:30:00Z',
      subject: null,
      ttps: [
        { ttp_code: 'SB-T010', phase: 'trust-building', confidence: 0.7, status: 'confirmed', evidence_start: null, evidence_end: null },
      ],
      iocs_revealed: [{ type: 'phone', value_norm: '+33612345678', indicator_id: 'ind-2', stimulus_msg_id: 'msg-2' }],
      stimulus_type: null,
    },
  ],
};

const mockIocs = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'iban', value: 'DE00SECRETIBAN', value_norm: 'DE00SECRETIBAN', score: { vt: 0, urlscan: 0 }, category: 'INVOICE_FRAUD', ts_observed: '2026-03-20T10:00:00Z', confidence: 0.9 },
];

const mockConversations = [baseConversations[0]];

const mockMetaConfig = {
  ...baseMockMetaConfig,
  personas: [{ code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true }],
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/communication/conversation/${CONV_ID}`, () => HttpResponse.json(mockConvDetail)),
    http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json(mockMessages)),
    http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json(mockIocs)),
    http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(null)),
    http.get(`${BASE}/conversations/${CONV_ID}/ttps`, () => HttpResponse.json(mockTtps)),
  );
}

beforeAll(() => {
  server.listen({ onUnhandledRequest: 'warn' });
  // jsdom does not implement scrollIntoView
  Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => {
  server.resetHandlers();
  vi.mocked(Element.prototype.scrollIntoView).mockClear();
});
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[`/conversations/${CONV_ID}`]}>
          <Routes>
            <Route path="/conversations/:id" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('ConversationDetail — TTP elicitation timeline', () => {
  it('shows TTP badges with human labels on an inbound message', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Payment demand')).toBeInTheDocument());
    expect(screen.getByText('Small talk')).toBeInTheDocument();
  });

  it('keeps the legacy ttp-badge testid on the shared chip', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getAllByTestId('ttp-badge').length).toBe(3));
  });

  it('renders a review-status TTP as a dashed chip with a thread legend', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Small talk')).toBeInTheDocument());
    const reviewChip = screen.getByText('Small talk');
    expect(reviewChip.className).toContain('border-dashed');
    expect(reviewChip.className).toContain('opacity-70');
    // Confirmed chips stay solid.
    expect(screen.getByText('Payment demand').className).not.toContain('border-dashed');
    expect(screen.getByTestId('ttp-review-legend')).toBeInTheDocument();
  });

  it('hides the review legend when every observation is confirmed', async () => {
    setupHandlers();
    const confirmedOnly = {
      ...mockTtps,
      observations: mockTtps.observations.filter((o) => o.status !== 'review'),
      timeline: mockTtps.timeline.map((entry) => ({
        ...entry,
        ttps: entry.ttps.filter((ttp) => ttp.status !== 'review'),
      })),
    };
    server.use(http.get(`${BASE}/conversations/${CONV_ID}/ttps`, () => HttpResponse.json(confirmedOnly)));
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByText('Payment demand')).toBeInTheDocument());
    expect(screen.queryByTestId('ttp-review-legend')).toBeNull();
  });

  it('reconstructs the evidence highlight from offsets (code-point slice of the body)', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('ttp-evidence')).toBeInTheDocument());
    expect(screen.getByTestId('ttp-evidence').textContent).toBe('pay');
  });

  it('shows the revealed IOCs of a message', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getAllByTestId('ttp-ioc').length).toBeGreaterThan(0));
    const ioc = screen.getAllByTestId('ttp-ioc')[0];
    expect(ioc.textContent).toContain('DE00SECRETIBAN');
    expect(ioc.textContent?.toLowerCase()).toContain('iban');
  });

  it('shows the stimulus as a styled chip with a translated label on an outbound message', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('stimulus-chip')).toBeInTheDocument());
    const chip = screen.getByTestId('stimulus-chip');
    expect(chip.textContent).toBe('Payment initiation');
    expect(chip.className).toContain('bg-red-500/20');
    // Neutral prefix wording, no raw enum value on screen.
    expect(screen.getByText('Stimulus')).toBeInTheDocument();
    expect(screen.queryByText('PAYMENT_INITIATION')).toBeNull();
  });

  it('humanizes an unknown stimulus value instead of crashing', async () => {
    setupHandlers();
    const withUnknownStimulus = {
      ...mockTtps,
      timeline: mockTtps.timeline.map((entry) =>
        entry.msg_id === 'msg-2' ? { ...entry, stimulus_type: 'SOME_NEW_TYPE' } : entry,
      ),
    };
    server.use(http.get(`${BASE}/conversations/${CONV_ID}/ttps`, () => HttpResponse.json(withUnknownStimulus)));
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('stimulus-chip')).toBeInTheDocument());
    expect(screen.getByTestId('stimulus-chip').textContent).toBe('Some New Type');
  });

  it('renders a null-offset TTP as a badge without a highlight and without crashing', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    // msg-3's only TTP has null offsets → the badge shows, the message body is
    // rendered intact, and no highlight is produced for it.
    await waitFor(() => expect(screen.getByText('False authority')).toBeInTheDocument());
    expect(screen.getByText('Trust me, I am a lawyer.')).toBeInTheDocument();
    // Exactly one highlight exists in the whole thread (from msg-1's SB-T017).
    expect(screen.getAllByTestId('ttp-evidence')).toHaveLength(1);
  });
});

describe('ConversationDetail — stimulus ↔ revelation causality links', () => {
  it('renders a preceded-by chip only on revelations carrying a stimulus_msg_id', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    // msg-3's phone revelation points at msg-2; msg-1's iban is first contact
    // (null stimulus_msg_id) and gets no chip — exactly one chip on the thread,
    // anchored on msg-3 (an inverted null-filter would hang it on msg-1).
    await waitFor(() => expect(screen.getAllByTestId('preceded-by-chip')).toHaveLength(1));
    expect(screen.getByTestId('preceded-by-chip').textContent).toContain('Preceded by');
    expect(document.getElementById('msg-msg-3')).toContainElement(screen.getByTestId('preceded-by-chip'));
  });

  it('scrolls to and flashes the referenced outbound bubble on click', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('preceded-by-chip')).toBeInTheDocument());

    const target = document.getElementById('msg-msg-2');
    expect(target).not.toBeNull();

    const scrollSpy = vi.mocked(Element.prototype.scrollIntoView);
    fireEvent.click(screen.getByTestId('preceded-by-chip'));
    expect(scrollSpy).toHaveBeenCalledTimes(1);
    expect(scrollSpy).toHaveBeenCalledWith({ behavior: 'smooth', block: 'center' });
    // The scroll must land on the referenced outbound bubble, not just anywhere.
    expect(scrollSpy.mock.contexts[0]).toBe(target);
    expect(target!.classList.contains('ring-2')).toBe(true);
    expect(target!.classList.contains('ring-accent')).toBe(true);
  });

  it('clears the flash ring after the window and debounces rapid re-clicks', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('preceded-by-chip')).toBeInTheDocument());
    const target = document.getElementById('msg-msg-2')!;

    vi.useFakeTimers();
    try {
      fireEvent.click(screen.getByTestId('preceded-by-chip'));
      vi.advanceTimersByTime(1000);
      // Second click inside the window: the first timer must NOT strip the
      // ring at its original 1600ms mark — the flash window restarts.
      fireEvent.click(screen.getByTestId('preceded-by-chip'));
      vi.advanceTimersByTime(1000); // t=2000 after first click, 1000 after second
      expect(target.classList.contains('ring-2')).toBe(true);
      expect(target.classList.contains('ring-accent')).toBe(true);
      vi.advanceTimersByTime(600); // full window elapsed since the second click
      expect(target.classList.contains('ring-2')).toBe(false);
      expect(target.classList.contains('ring-accent')).toBe(false);
    } finally {
      vi.useRealTimers();
    }
  });

  it('marks the referenced outbound message with a revelations-followed chip', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getAllByTestId('revelations-followed-chip')).toHaveLength(1));
    // The marker sits inside the referenced outbound bubble.
    expect(document.getElementById('msg-msg-2')).toContainElement(screen.getByTestId('revelations-followed-chip'));
  });

  it('marks a referenced outbound even without a stimulus_type of its own', async () => {
    // Being a stimulus reference must open the annotations area on its own:
    // msg-2 has NO stimulus_type, no TTPs, no revealed IOCs — only msg-3's
    // revelation pointing at it.
    setupHandlers();
    const noOwnStimulus = {
      ...mockTtps,
      timeline: mockTtps.timeline.map((entry) =>
        entry.msg_id === 'msg-2' ? { ...entry, stimulus_type: null } : entry,
      ),
    };
    server.use(http.get(`${BASE}/conversations/${CONV_ID}/ttps`, () => HttpResponse.json(noOwnStimulus)));
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('revelations-followed-chip')).toBeInTheDocument());
    expect(document.getElementById('msg-msg-2')).toContainElement(screen.getByTestId('revelations-followed-chip'));
    expect(screen.queryByTestId('stimulus-chip')).toBeNull();
  });

  it('renders no linkage chips at all when no revelation carries a stimulus_msg_id', async () => {
    setupHandlers();
    const unlinked = {
      ...mockTtps,
      timeline: mockTtps.timeline.map((entry) => ({
        ...entry,
        iocs_revealed: entry.iocs_revealed.map((ioc) => ({ ...ioc, stimulus_msg_id: null })),
      })),
    };
    server.use(http.get(`${BASE}/conversations/${CONV_ID}/ttps`, () => HttpResponse.json(unlinked)));
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getAllByTestId('ttp-ioc').length).toBeGreaterThan(0));
    expect(screen.queryByTestId('preceded-by-chip')).toBeNull();
    expect(screen.queryByTestId('revelations-followed-chip')).toBeNull();
  });
});
