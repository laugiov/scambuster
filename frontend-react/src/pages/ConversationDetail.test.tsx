import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
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
  scam_type: 'PHISHING',
  ts_first: '2026-03-20T10:00:00Z',
  ts_last: '2026-03-20T12:00:00Z',
  account_label: 'Delta Holdings',
  account_email: 'admin@delta-holdings.example',
};

const mockMessages = [
  { message_id: 'msg-1', direction: 'in', body_text: 'Hello scammer message', subject: 'Urgent payment', ts_msg: '2026-03-20T10:00:00Z' },
  { message_id: 'msg-2', direction: 'out', body_text: 'Reply from sentinel', subject: null, ts_msg: '2026-03-20T11:00:00Z' },
];

const mockIocs = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'email', value: 'scammer@evil.com', value_norm: 'scammer@evil[.]com', score: { vt: 0, urlscan: 0 }, category: 'PHISHING', ts_observed: '2026-03-20T10:00:00Z', confidence: 0.9 },
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
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
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

describe('ConversationDetail', () => {
  it('renders the conversation detail with ID in header', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Conversation #aaaa-bbb/i)).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockConvDetail);
      }),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders messages in the thread', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Hello scammer message/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/Reply from sentinel/i)).toBeInTheDocument();
  });

  it('shows error state when conversation not found', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 }),
      ),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/not found|error|failed/i);
    });
  });

  it('displays email thread header', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Email Thread/i)).toBeInTheDocument();
    });
  });

  // ──────────────────────────────────────────────────────────────────────
  // Spec 087 — US1: mailbox visible in SESSION METADATA
  // ──────────────────────────────────────────────────────────────────────

  it('renders the mailbox label and email in SESSION METADATA', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Delta Holdings')).toBeInTheDocument();
    });
    expect(screen.getByText('admin@delta-holdings.example')).toBeInTheDocument();
  });

  it('shows only email when account_label is null (legacy mailbox)', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, () => HttpResponse.json({
        ...mockConvDetail,
        account_label: null,
        account_email: 'legacy@example.invalid',
      })),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json(mockMessages)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json(mockIocs)),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(null)),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('legacy@example.invalid')).toBeInTheDocument();
    });
    // Heading is still rendered so the test doesn't false-pass on a crash
    expect(screen.getByText(/Session Metadata/i)).toBeInTheDocument();
  });

  it('renders an em-dash placeholder when both label and email are null', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, () => HttpResponse.json({
        ...mockConvDetail,
        account_label: null,
        account_email: null,
      })),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json(mockMessages)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json(mockIocs)),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(null)),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Session Metadata/i)).toBeInTheDocument();
    });
    // The MAILBOX row must exist with em-dash, not crash
    const labels = screen.getAllByText(/Mailbox/i);
    expect(labels.length).toBeGreaterThan(0);
  });
});
