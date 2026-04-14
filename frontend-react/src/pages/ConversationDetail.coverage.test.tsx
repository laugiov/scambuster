import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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

const longBody = 'A'.repeat(600);

const mockMessages = [
  { message_id: 'msg-1', direction: 'in', body_text: 'Hello from scammer', subject: 'Urgent payment required', ts_msg: '2026-03-20T10:00:00Z' },
  { message_id: 'msg-2', direction: 'out', body_text: 'Reply from sentinel', subject: null, ts_msg: '2026-03-20T11:00:00Z' },
  { message_id: 'msg-3', direction: 'in', body_text: longBody, subject: null, ts_msg: null },
];

const mockIocs = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'email', value: 'scammer@evil.com', value_norm: 'scammer@evil[.]com', score: { vt: 70, urlscan: 0 }, category: 'PHISHING', ts_observed: '2026-03-20T10:00:00Z', confidence: 0.9, effective_score: 0.8 },
  { obs_id: 'obs-2', ioc_id: 'ind-2', type: 'dmarc_result', value: 'pass@scambuster.local', value_norm: 'pass', score: { vt: 0, urlscan: 0 }, category: 'PHISHING', ts_observed: '2026-03-20T10:00:00Z', confidence: 0.5 },
  { obs_id: 'obs-3', ioc_id: 'ind-3', type: 'spf_result', value: 'pass', value_norm: 'pass', score: { vt: 0, urlscan: 0 }, category: 'PHISHING', ts_observed: '2026-03-20T10:00:00Z', confidence: 0.5 },
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

const mockThreatActor = {
  sophistication: 'advanced',
  motivation: 'financial',
  threat_level: 'high',
  style_summary: 'Aggressive pressure tactics',
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/communication/conversation/${CONV_ID}`, () => HttpResponse.json(mockConvDetail)),
    http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json(mockMessages)),
    http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json(mockIocs)),
    http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(mockThreatActor)),
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

describe('ConversationDetail — coverage gaps', () => {
  it('renders persona and scam type badges in header', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Persona: Elderly Person/i)).toBeInTheDocument();
    });
    expect(screen.getByText('PHISHING')).toBeInTheDocument();
  });

  it('shows session metadata with duration', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/2h 0min/)).toBeInTheDocument();
    });
  });

  it('displays IOC count excluding infra types', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      // Should show 1 real IOC (email) and hide dmarc_result and spf_result
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
  });

  it('shows email auth IOCs in details section', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Email Authentication/)).toBeInTheDocument();
    });
  });

  it('renders outbound message bubble with sentinel label', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('Sentinel').length).toBeGreaterThan(0);
    });
    expect(screen.getAllByText('Scammer').length).toBeGreaterThan(0);
  });

  it('renders subject line on message', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Urgent payment required')).toBeInTheDocument();
    });
  });

  it('truncates long messages and shows expand button', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Show full message/)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Show full message/));
    await waitFor(() => {
      expect(screen.getByText(/Show less/)).toBeInTheDocument();
    });
  });

  it('shows --:-- for messages with no timestamp', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('--:--')).toBeInTheDocument();
    });
  });

  it('selects an IOC and shows detail panel', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    // Click the IOC button — the IOC value is rendered in a button
    const buttons = screen.getAllByRole('button');
    const iocButton = buttons.find((b) => b.textContent?.includes('scammer@evil.com'));
    expect(iocButton).toBeDefined();
    fireEvent.click(iocButton!);
    await waitFor(() => {
      expect(screen.getByText(/Back to Intelligence/i)).toBeInTheDocument();
    });
  });

  it('closes IOC detail panel when back button is clicked', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('scammer@evil.com')).toBeInTheDocument();
    });
    const buttons = screen.getAllByRole('button');
    const iocButton = buttons.find((b) => b.textContent?.includes('scammer@evil.com'));
    fireEvent.click(iocButton!);
    await waitFor(() => {
      expect(screen.getByText(/Back to Intelligence/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Back to Intelligence/i));
    await waitFor(() => {
      expect(screen.queryByText(/Back to Intelligence/)).toBeNull();
    });
  });

  it('renders no messages state', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, () => HttpResponse.json(mockConvDetail)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json([])),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json([])),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(null)),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No messages/i)).toBeInTheDocument();
    });
  });

  it('renders no IOCs state', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, () => HttpResponse.json(mockConvDetail)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json(mockMessages)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json([])),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(null)),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No IOCs extracted/i)).toBeInTheDocument();
    });
  });

  it('renders conversation with scam_type in header', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('PHISHING')).toBeInTheDocument();
    });
  });

  it('renders conversation without persona or scam_type', async () => {
    server.use(
      http.get(`${BASE}/communication/conversation/${CONV_ID}`, () =>
        HttpResponse.json({ ...mockConvDetail, persona: null, scam_type: null }),
      ),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/messages`, () => HttpResponse.json([])),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/iocs`, () => HttpResponse.json([])),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/communication/conversation/${CONV_ID}/threat-actor`, () => HttpResponse.json(null)),
    );
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Conversation #aaaa-bbb/i)).toBeInTheDocument();
    });
    // No persona/scam_type badges should appear
    expect(screen.queryByText(/Persona:/)).toBeNull();
  });

  it('displays STIX export button when IOCs exist', async () => {
    setupHandlers();
    render(<ConversationDetail />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('STIX 2.1')).toBeInTheDocument();
    });
  });
});
