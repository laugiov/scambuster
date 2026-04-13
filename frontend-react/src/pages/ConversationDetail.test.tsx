import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { ConversationDetail } from './ConversationDetail';

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
};

const mockMessages = [
  { message_id: 'msg-1', direction: 'in', body_text: 'Hello scammer message', subject: 'Urgent payment', ts_msg: '2026-03-20T10:00:00Z' },
  { message_id: 'msg-2', direction: 'out', body_text: 'Reply from sentinel', subject: null, ts_msg: '2026-03-20T11:00:00Z' },
];

const mockIocs = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'email', value: 'scammer@evil.com', value_norm: 'scammer@evil[.]com', score: { vt: 0, urlscan: 0 }, category: 'PHISHING', ts_observed: '2026-03-20T10:00:00Z', confidence: 0.9 },
];

const mockConversations = [
  { conv_id: CONV_ID, status: 'open', score_risk: 50, persona: 'elderly_person', scam_type: 'PHISHING', turns: 4, ts_first: '2026-03-20T10:00:00Z', ts_last: '2026-03-20T12:00:00Z' },
];

const mockMetaConfig = {
  personas: [{ code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true }],
  scam_types: [{ code: 'PHISHING', label: 'Phishing', description: '', active: true }],
  ioc_types: ['email', 'domain'],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai', llm_model: 'gpt-4o-mini',
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

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
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
  it('renders without crashing', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays conversation ID in header', async () => {
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
});
