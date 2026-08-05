import { describe, it, expect, beforeAll, afterAll, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { Ioc, Message } from '@/types/api';
import type { TtpReviewQueueItem } from '@/types/ttp';
import { ReviewQueueTable } from './ReviewQueueTable';
import '../../i18n';

const BASE = '/api/v1';

// ---- Evidence fixture --------------------------------------------------
// Offsets follow the extractor convention: code-point positions into
// `subject + "\n\n" + body_text`. They are COMPUTED from the fixture (never
// hand-counted) so the fixture can change without desyncing the ranges.
const SUBJECT = 'Urgent business proposal';
const IOC_EMAIL = 'john.doe@scam.example';
const PERSONA_EMAIL = 'mildred.honey@bait.example';
// > 120 code points of preamble so the ±120 context window provably crops:
// EARLYMARKER must never render, while the persona address (placed just
// before the evidence) falls inside the window. The filler deliberately
// carries an astral emoji + accented chars: any UTF-16 slice regression in
// the window math shifts the mark and fails these tests.
const FILLER = 'relance 🚀 café '.repeat(20);
const EVIDENCE = `send the processing fee via Western Union to ${IOC_EMAIL}`;
const MASKED_EVIDENCE = 'send the processing fee via Western Union to [•••]';
const BODY = `EARLYMARKER ${FILLER}As <${PERSONA_EMAIL}> requested, please ${EVIDENCE} before Friday, thank you.`;

const BASE_TEXT = `${SUBJECT}\n\n${BODY}`;
const EV_START = Array.from(BASE_TEXT.slice(0, BASE_TEXT.indexOf(EVIDENCE))).length;
const EV_END = EV_START + Array.from(EVIDENCE).length;
// Truncated variant: the cut lands MID-EMAIL (the server caps evidence at a
// fixed byte budget, so mid-token ends are real).
const CUT_EVIDENCE = 'send the processing fee via Western Union to john.doe@sc';
const EV_CUT_END = EV_START + Array.from(CUT_EVIDENCE).length;

const CONV_A = 'aaaa1111-2222-3333-4444-555566667777';
const CONV_B = 'bbbb1111-2222-3333-4444-555566667777';
const MSG_A = 'msg-aaaa';

function queueItem(overrides: Partial<TtpReviewQueueItem> = {}): TtpReviewQueueItem {
  return {
    obs_id: 'obs-1',
    ttp_code: 'SB-T017',
    ttp_label: 'Payment demand',
    phase: 'payment-request',
    confidence: 0.55,
    conv_id: CONV_A,
    msg_id: MSG_A,
    ts_msg: '2026-07-20T10:00:00Z',
    evidence_start: EV_START,
    evidence_end: EV_END,
    extraction_model: 'mistral-large',
    ...overrides,
  };
}

const offsetItem = queueItem();
const paraphrasedItem = queueItem({
  obs_id: 'obs-2',
  ttp_code: 'SB-T001',
  ttp_label: 'Cold outreach',
  phase: 'hook',
  confidence: 0.9,
  conv_id: CONV_B,
  msg_id: 'msg-bbbb',
  ts_msg: '2026-07-10T10:00:00Z',
  evidence_start: null,
  evidence_end: null,
  extraction_model: 'gpt-judge',
});

const messagesA: Message[] = [
  { message_id: 'msg-other', direction: 'out', subject: 'Re: hello', body_text: 'our reply', ts_msg: '2026-07-19T09:00:00Z' },
  { message_id: MSG_A, direction: 'in', subject: SUBJECT, body_text: BODY, ts_msg: '2026-07-20T10:00:00Z' },
];

const iocsA: Ioc[] = [
  {
    obs_id: 'iocobs-1',
    ioc_id: 'ioc-1',
    type: 'email',
    value: IOC_EMAIL,
    value_norm: IOC_EMAIL,
    category: 'contact',
    ts_observed: '2026-07-20T10:00:00Z',
  },
];

let messagesFetches = 0;
let iocsFetches = 0;
let detailFetches = 0;

function queueHandler(items: TtpReviewQueueItem[], total = items.length) {
  server.use(http.get(`${BASE}/ttps/review-queue`, () => HttpResponse.json({ items, total })));
}

function conversationHandlers() {
  server.use(
    http.get(`${BASE}/communication/conversation/:id/messages`, ({ params }) => {
      messagesFetches += 1;
      return HttpResponse.json(params.id === CONV_A ? messagesA : []);
    }),
    http.get(`${BASE}/communication/conversation/:id/iocs`, ({ params }) => {
      iocsFetches += 1;
      return HttpResponse.json(params.id === CONV_A ? iocsA : []);
    }),
    // Conversation detail: the deterministic account_email source (no cache
    // pre-seeding — this IS the fresh-session deep-link path).
    http.get(`${BASE}/communication/conversation/:id`, ({ params }) => {
      detailFetches += 1;
      return HttpResponse.json({
        conv_id: params.id,
        status: 'closed',
        score_risk: 80,
        account_email: params.id === CONV_A ? PERSONA_EMAIL : null,
      });
    }),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
beforeEach(() => { messagesFetches = 0; iocsFetches = 0; detailFetches = 0; });
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  }
  return { Wrapper, qc };
}

function rowTexts(): string[] {
  return screen.getAllByTestId('ttp-review-row').map((tr) => tr.textContent ?? '');
}

async function expandFirstRow() {
  await waitFor(() => expect(screen.getAllByTestId('ttp-review-row').length).toBeGreaterThan(0));
  fireEvent.click(screen.getAllByTestId('ttp-review-row')[0]);
}

describe('ReviewQueueTable', () => {
  it('renders the queue newest message first with chip, confidence, short conv id and provenance', async () => {
    queueHandler([offsetItem, paraphrasedItem]);
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await waitFor(() => expect(screen.getAllByTestId('ttp-review-row')).toHaveLength(2));
    const rows = rowTexts();
    // Served order = ts_msg DESC: obs-1 (07-20) before obs-2 (07-10).
    expect(rows[0]).toContain('Payment demand');
    expect(rows[0]).toContain('55%');
    expect(rows[0]).toContain(CONV_A.slice(0, 8));
    expect(rows[0]).toContain('mistral-large');
    expect(rows[1]).toContain('Cold outreach');
    // Queue chips carry the review variant (dashed + dimmed).
    expect(screen.getAllByTestId('ttp-chip')[0].className).toContain('border-dashed');
    // Conversation link targets the detail page.
    expect(screen.getAllByTestId('ttp-review-conversation-link')[0]).toHaveAttribute(
      'href',
      `/conversations/${CONV_A}`,
    );
  });

  it('toggles sorting when a header is clicked', async () => {
    queueHandler([offsetItem, paraphrasedItem]);
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });
    await waitFor(() => expect(screen.getAllByTestId('ttp-review-row')).toHaveLength(2));

    // Confidence DESC → obs-2 (0.9) first.
    fireEvent.click(screen.getByText('Confidence'));
    await waitFor(() => {
      expect(rowTexts()[0]).toContain('Cold outreach');
    });
    // Second click flips to ASC → obs-1 (0.55) first.
    fireEvent.click(screen.getByText('Confidence'));
    await waitFor(() => {
      expect(rowTexts()[0]).toContain('Payment demand');
    });
  });

  it('expand fires BOTH lazy fetches and renders the offset-reconstructed highlight', async () => {
    queueHandler([offsetItem]);
    conversationHandlers();
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();

    const mark = await screen.findByTestId('ttp-review-evidence');
    expect(messagesFetches).toBe(1);
    expect(iocsFetches).toBe(1);
    expect(detailFetches).toBe(1);
    // The <mark> carries EXACTLY the evidence span (IOC value masked by
    // default) — no neighbouring window text bleeds into the highlight.
    expect(mark.textContent).toBe(MASKED_EVIDENCE);
    // ±120 code-point window: text far before the evidence is cropped out.
    expect(document.body.textContent).not.toContain('EARLYMARKER');
    // Nearby context inside the window still renders.
    expect(document.body.textContent).toContain('before Friday');
  });

  it('masks a known IOC value inside the evidence as [•••] BY DEFAULT', async () => {
    queueHandler([offsetItem]);
    conversationHandlers();
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();
    await screen.findByTestId('ttp-review-evidence');

    // If the mask value set were empty, maskPiiInBody would return the raw
    // text unchanged and this surface would silently leak — assert the leak
    // cannot happen.
    expect(document.body.textContent).not.toContain(IOC_EMAIL);
    expect(document.body.textContent).toContain('[•••]');
  });

  it('reveals the raw IOC value after the mask toggle', async () => {
    queueHandler([offsetItem]);
    conversationHandlers();
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();
    await screen.findByTestId('ttp-review-evidence');

    fireEvent.click(screen.getByTestId('ttp-review-mask-toggle'));
    await waitFor(() => {
      expect(document.body.textContent).toContain(IOC_EMAIL);
    });
    // Revealed mark is EXACTLY the raw evidence span.
    expect(screen.getByTestId('ttp-review-evidence').textContent).toBe(EVIDENCE);
  });

  it('masks a value straddling the highlight boundary (evidence cut mid-token)', async () => {
    queueHandler([queueItem({ obs_id: 'obs-cut', evidence_end: EV_CUT_END })]);
    conversationHandlers();
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();
    const mark = await screen.findByTestId('ttp-review-evidence');

    // The raw offsets end inside the email; the highlight snaps outward to
    // the token boundary so the whole value stays maskable — without the
    // snap, both fragments would dodge the whole-value regex and leak.
    expect(document.body.textContent).not.toContain(IOC_EMAIL);
    expect(mark.textContent).toBe(MASKED_EVIDENCE);
  });

  it('masks the conversation account_email on a fresh session (no cache, detail fetched)', async () => {
    queueHandler([offsetItem]);
    conversationHandlers();
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();
    await screen.findByTestId('ttp-review-evidence');

    // Nothing was pre-cached: the panel's own conversation-detail fetch is
    // the only account_email source, and the persona address sitting inside
    // the rendered context window must still come out masked…
    expect(detailFetches).toBe(1);
    expect(document.body.textContent).not.toContain(PERSONA_EMAIL);
    // …which the unmask toggle proves (it renders once revealed).
    fireEvent.click(screen.getByTestId('ttp-review-mask-toggle'));
    await waitFor(() => {
      expect(document.body.textContent).toContain(PERSONA_EMAIL);
    });
  });

  it('renders the paraphrased state for NULL offsets without any network call', async () => {
    queueHandler([paraphrasedItem]);
    conversationHandlers();
    const { Wrapper, qc } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();

    expect(await screen.findByTestId('ttp-review-paraphrased')).toBeInTheDocument();
    // Deterministic no-fetch probes: a query instance exists in the cache
    // the instant its hook mounts, so absence proves the evidence panel
    // (and its three fetches) never mounted — no interceptor timing races.
    expect(qc.getQueryCache().find({ queryKey: ['conversation-messages', CONV_B] })).toBeUndefined();
    expect(qc.getQueryCache().find({ queryKey: ['conversation-iocs', CONV_B] })).toBeUndefined();
    expect(qc.getQueryCache().find({ queryKey: ['conversation', CONV_B] })).toBeUndefined();
    expect(messagesFetches).toBe(0);
    expect(iocsFetches).toBe(0);
    expect(detailFetches).toBe(0);
  });

  it('renders the row-level error state when the evidence fetch is denied (403)', async () => {
    queueHandler([offsetItem]);
    conversationHandlers();
    server.use(
      http.get(`${BASE}/communication/conversation/:id/messages`, () =>
        HttpResponse.json({ error: 'forbidden' }, { status: 403 })),
    );
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();

    expect(await screen.findByTestId('ttp-review-error')).toBeInTheDocument();
    // Access denial is an ERROR state, never the not-found state.
    expect(screen.queryByTestId('ttp-review-not-found')).toBeNull();
  });

  it('renders the not-found state when the anchored message is missing from the thread', async () => {
    queueHandler([queueItem({ obs_id: 'obs-missing', msg_id: 'msg-vanished' })]);
    conversationHandlers();
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await expandFirstRow();

    expect(await screen.findByTestId('ttp-review-not-found')).toBeInTheDocument();
    expect(screen.queryByTestId('ttp-review-error')).toBeNull();
  });

  it('shows the designed empty state when the queue is clear', async () => {
    queueHandler([], 0);
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    const empty = await screen.findByTestId('ttp-review-empty');
    expect(empty.textContent).toMatch(/queue is clear/i);
  });

  it('shows the error state with retry when the queue fetch fails', async () => {
    server.use(http.get(`${BASE}/ttps/review-queue`, () => HttpResponse.json({}, { status: 500 })));
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await waitFor(() => {
      expect(document.body.textContent).toMatch(/failed to load the review queue/i);
    });
  });

  it('paginates client-side at 15 rows and surfaces the server cap honestly', async () => {
    const many = Array.from({ length: 16 }, (_, i) =>
      queueItem({
        obs_id: `obs-${String(i).padStart(2, '0')}`,
        evidence_start: null,
        evidence_end: null,
        ts_msg: `2026-07-${String(28 - i).padStart(2, '0')}T10:00:00Z`,
      }));
    queueHandler(many, 600); // endpoint capped: total > items.length
    const { Wrapper } = createWrapper();
    render(<ReviewQueueTable />, { wrapper: Wrapper });

    await waitFor(() => expect(screen.getAllByTestId('ttp-review-row')).toHaveLength(15));
    expect(screen.getByTestId('ttp-review-cap-note')).toBeInTheDocument();
    expect(screen.getByText(/600 observations awaiting review/i)).toBeInTheDocument();

    fireEvent.click(screen.getAllByLabelText('Next page')[0]);
    await waitFor(() => expect(screen.getAllByTestId('ttp-review-row')).toHaveLength(1));
  });
});
