import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Theater } from './Theater';

const BASE = '/api/v1';
const CONV_ID = 'cc111111-2222-3333-4444-555555555555';

function fixture() {
  return {
    meta: {
      conv_id: CONV_ID,
      scam_type: 'INVOICE_FRAUD',
      persona: 'tech_newbie',
      persona_label: 'Tech newbie (small business owner)',
      status: 'open',
      score_risk: 0.5,
      score_engagement: 0.6,
      from_address: 'scammer@example.com',
      to_address: 'admin@example.com',
      truncated: false,
      iocs_count: 0,
      enrichment_coverage_pct: 100,
    },
    messages: [
      {
        idx: 0,
        msg_id: '11111111-1111-1111-1111-111111111111',
        direction: 'in',
        ts_msg: '2026-06-10T10:00:00Z',
        sender: 'scammer@example.com',
        subject: 'Invoice',
        body_text: 'Hello, this is message 1.',
        lang_detect: 'en',
      },
      {
        idx: 1,
        msg_id: '22222222-2222-2222-2222-222222222222',
        direction: 'out',
        ts_msg: '2026-06-10T10:30:00Z',
        sender: 'admin@example.com',
        subject: 'Re: Invoice',
        body_text: 'This is message 2.',
        lang_detect: 'en',
      },
      {
        idx: 2,
        msg_id: '33333333-3333-3333-3333-333333333333',
        direction: 'in',
        ts_msg: '2026-06-10T11:00:00Z',
        sender: 'scammer@example.com',
        subject: 'Re: Re: Invoice',
        body_text: 'And this is message 3.',
        lang_detect: 'en',
      },
    ],
    iocs_by_msg: [],
    human_factor: {
      deterministic: {
        engagement_hours: 1.0,
        first_financial_turn: null,
        first_financial_ratio: null,
        total_turns: 3,
        scammer_response_time_hours_median: null,
        cascade_events: [],
        language_switch_count: 0,
        persona_pressure_profile: {
          persona_code: 'tech_newbie',
          financial_obtained: 0,
          iocs_obtained: 0,
        },
      },
      exploratory_llm_signals: {
        enrichment_confidence_avg: 0,
        iocs_under_active_stimulus: 0,
        avg_urgency_at_reveal: null,
        hesitation_count: 0,
        enrichment_coverage: 0,
      },
    },
  };
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderTheater() {
  server.use(
    http.get(`${BASE}/communication/conversation/${CONV_ID}/theater`, () => HttpResponse.json(fixture())),
  );

  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/conversations/${CONV_ID}/theater`]}>
        <Routes>
          <Route path="/conversations/:id/theater" element={children} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  );
  return render(<Theater />, { wrapper: Wrapper });
}

describe('Theater page — keyboard navigation (Spec 097 follow-up)', () => {
  it('ArrowRight advances by one step and pauses', async () => {
    renderTheater();
    await waitFor(() => expect(screen.getByTestId('play-pause')).toBeTruthy());

    // Initial: 0 / 3
    expect(screen.getByText('0/3')).toBeTruthy();

    fireEvent.keyDown(window, { key: 'ArrowRight' });
    await waitFor(() => expect(screen.getByText('1/3')).toBeTruthy());
  });

  it('ArrowLeft steps back', async () => {
    renderTheater();
    await waitFor(() => expect(screen.getByTestId('play-pause')).toBeTruthy());

    fireEvent.keyDown(window, { key: 'ArrowRight' });
    fireEvent.keyDown(window, { key: 'ArrowRight' });
    await waitFor(() => expect(screen.getByText('2/3')).toBeTruthy());

    fireEvent.keyDown(window, { key: 'ArrowLeft' });
    await waitFor(() => expect(screen.getByText('1/3')).toBeTruthy());
  });

  it('Home jumps to 0, End jumps to total', async () => {
    renderTheater();
    await waitFor(() => expect(screen.getByTestId('play-pause')).toBeTruthy());

    fireEvent.keyDown(window, { key: 'End' });
    await waitFor(() => expect(screen.getByText('3/3')).toBeTruthy());

    fireEvent.keyDown(window, { key: 'Home' });
    await waitFor(() => expect(screen.getByText('0/3')).toBeTruthy());
  });

  it('renders the keyboard hint chip', async () => {
    renderTheater();
    await waitFor(() => expect(screen.getByTestId('keyboard-hint')).toBeTruthy());
  });

  it('collapses LLM sub-block when all signals are zero (Spec 099 S5)', async () => {
    // Base fixture already has all zeros for exploratory_llm_signals.
    renderTheater();
    await waitFor(() => expect(screen.getByTestId('theater-psychology-llm-empty')).toBeTruthy());
    expect(screen.queryByTestId('theater-psychology-llm')).toBeNull();
  });

  it('renders full LLM sub-block when at least one signal is non-zero (Spec 099 S5)', async () => {
    const fix = fixture();
    fix.human_factor.exploratory_llm_signals.iocs_under_active_stimulus = 3;
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}/theater`, () => HttpResponse.json(fix)),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[`/conversations/${CONV_ID}/theater`]}>
          <Routes>
            <Route path="/conversations/:id/theater" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
    render(<Theater />, { wrapper: Wrapper });

    await waitFor(() => expect(screen.getByTestId('theater-psychology-llm')).toBeTruthy());
    expect(screen.queryByTestId('theater-psychology-llm-empty')).toBeNull();
  });

  it('renders teaser at step 0 idle, hidden once playback advances (Spec 099 S4)', async () => {
    renderTheater();
    await waitFor(() => expect(screen.getByTestId('theater-teaser')).toBeTruthy());
    fireEvent.keyDown(window, { key: 'End' });
    await waitFor(() => expect(screen.queryByTestId('theater-teaser')).toBeNull());
  });

  it('hides first-financial spoiler until visibleStep reaches turn (Spec 099 S4)', async () => {
    // Configure fixture with first_financial_turn=2 to test the reveal.
    const fix = fixture();
    fix.human_factor.deterministic.first_financial_turn = 2;
    fix.human_factor.deterministic.first_financial_ratio = 0.67;
    fix.human_factor.deterministic.total_turns = 3;
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}/theater`, () => HttpResponse.json(fix)),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[`/conversations/${CONV_ID}/theater`]}>
          <Routes>
            <Route path="/conversations/:id/theater" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
    render(<Theater />, { wrapper: Wrapper });

    // step 0 → spoiler placeholder
    await waitFor(() => expect(screen.getByText(/reveals as you play/i)).toBeTruthy());

    // arrow-right twice → reach turn 2 → spoiler revealed
    fireEvent.keyDown(window, { key: 'ArrowRight' });
    fireEvent.keyDown(window, { key: 'ArrowRight' });
    await waitFor(() => expect(screen.queryByText(/reveals as you play/i)).toBeNull());
  });

  it('toggles screen-share mode via S key + masks IOC values in bodies (Spec 099 S7)', async () => {
    const fix = fixture();
    fix.messages[0].body_text = 'Send wire to DE89370400440532013000 today';
    fix.iocs_by_msg = [
      {
        indicator_id: 'iban-1',
        type: 'iban',
        value: 'DE89370400440532013000',
        value_norm: 'DE89370400440532013000',
        category: 'financial',
        msg_id: fix.messages[0].msg_id,
        msg_idx: 0,
        ts_observed: '2026-06-14T00:00:00Z',
        revelation_context: null,
      },
    ];
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}/theater`, () => HttpResponse.json(fix)),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[`/conversations/${CONV_ID}/theater`]}>
          <Routes>
            <Route path="/conversations/:id/theater" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
    render(<Theater />, { wrapper: Wrapper });
    await waitFor(() => expect(screen.getByTestId('play-pause')).toBeTruthy());
    // Reveal first message
    fireEvent.keyDown(window, { key: 'ArrowRight' });
    await waitFor(() => expect(screen.getByText(/DE89370400440532013000/)).toBeTruthy());

    // No banner before S press
    expect(screen.queryByTestId('screen-share-banner')).toBeNull();

    // Press S → banner appears + IBAN replaced by placeholder
    fireEvent.keyDown(window, { key: 'S' });
    await waitFor(() => expect(screen.getByTestId('screen-share-banner')).toBeTruthy());
    expect(screen.queryByText(/DE89370400440532013000/)).toBeNull();
  });

  it('renders chapter markers on the progress bar (Spec 099 S3)', async () => {
    // The base fixture has no IOCs and no cascades, so we need a richer
    // fixture for this case. Re-define handlers inline with a financial
    // IOC + a phone IOC + a cascade event.
    const richFixture = fixture();
    richFixture.iocs_by_msg = [
      {
        indicator_id: 'ind-1',
        type: 'phone',
        value: '+15555550111',
        value_norm: '+15555550111',
        category: 'contact',
        msg_id: '22222222-2222-2222-2222-222222222222',
        msg_idx: 1,
        ts_observed: '2026-06-10T10:30:00Z',
        revelation_context: null,
      },
      {
        indicator_id: 'ind-2',
        type: 'iban',
        value: 'DE89370400440532013000',
        value_norm: 'DE89370400440532013000',
        category: 'financial',
        msg_id: '33333333-3333-3333-3333-333333333333',
        msg_idx: 2,
        ts_observed: '2026-06-10T11:00:00Z',
        revelation_context: null,
      },
    ];
    richFixture.human_factor.deterministic.cascade_events = [
      {
        trigger_msg_id: '33333333-3333-3333-3333-333333333333',
        turn: 3,
        yielded_types: ['iban', 'bic'],
      },
    ];
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}/theater`, () => HttpResponse.json(richFixture)),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const Wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>
        <MemoryRouter initialEntries={[`/conversations/${CONV_ID}/theater`]}>
          <Routes>
            <Route path="/conversations/:id/theater" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
    render(<Theater />, { wrapper: Wrapper });

    await waitFor(() => expect(screen.getByTestId('chapter-first_phone')).toBeTruthy());
    expect(screen.getByTestId('chapter-first_financial')).toBeTruthy();
    expect(screen.getByTestId('chapter-cascade')).toBeTruthy();
  });
});
