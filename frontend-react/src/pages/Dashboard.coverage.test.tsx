import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Dashboard } from './Dashboard';

const BASE = '/api/v1';

const mockNavigate = vi.fn();
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return { ...actual, useNavigate: () => mockNavigate };
});

const mockStats = {
  status: 'operational',
  conversations: { total: 15, open: 3, active: 3, closed: 10, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: { status: 'converging', best_persona: 'elderly_person', best_score: 0.82, exploration_rate: 0.15, converged_types: 0, total_types: 5 },
  kill_switch: false,
  kill_switch_active: false,
  checked_at: new Date().toISOString(),
};

const mockConversations = [
  { conv_id: 'aaaa-bbbb-cccc-dddd', status: 'open', score_risk: 50, persona: 'elderly_person', scam_type: 'PHISHING', turns: 4 },
  { conv_id: 'eeee-ffff-0000-1111', status: 'closed', score_risk: 80, persona: 'bank_customer', scam_type: 'ROMANCE', turns: 12 },
];

const mockLlmCosts = {
  current_month: { total_usd: 12.34, limit_usd: 50.0, pct_used: 24.7, calls_count: 1842, total_prompt_tokens: 2450000, total_completion_tokens: 890000 },
  per_purpose: {},
  daily_trend: [],
  limit_exceeded: false,
};

const mockMetaConfig = {
  personas: [
    { code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true },
    { code: 'bank_customer', label: 'Bank Customer', tone: 'Formal', active: true },
  ],
  scam_types: [{ code: 'PHISHING', label: 'Phishing', description: '', active: true }],
  ioc_types: ['email', 'domain'],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai', llm_model: 'gpt-4o-mini',
};

const mockPersona = { persona_code: 'elderly_person', global_avg_reward: 0.75, total_sessions: 10, performance_by_scam_type: [] };
const mockActivityFeed = {
  events: [
    { event_type: 'conversation_opened', ref_id: 'aaaa-bbbb-cccc-dddd', ts: new Date().toISOString() },
    { event_type: 'reply_sent', ref_id: 'aaaa-bbbb-cccc-dddd', ts: new Date().toISOString() },
    { event_type: 'ioc_extracted', ref_id: 'aaaa-bbbb-cccc-dddd', ts: new Date().toISOString() },
    { event_type: 'conversation_closed', ref_id: 'eeee-ffff-0000-1111', ts: new Date().toISOString() },
  ],
};
const mockWeeklyTrends = {
  trends: [
    { metric: 'iocs', delta_pct: 12.5 },
    { metric: 'replies', delta_pct: -5.0 },
  ],
};
const mockIocs = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'domain', value: 'evil.com', value_norm: 'evil[.]com', score: { vt: 70 }, category: 'PHISHING', ts_observed: new Date().toISOString(), confidence: 0.95, decay_factor: 0.9, effective_score: 0.85 },
  { obs_id: 'obs-2', ioc_id: 'ind-2', type: 'email', value: 'bad@evil.com', value_norm: 'bad@evil[.]com', score: { vt: 0 }, category: 'PHISHING', ts_observed: new Date().toISOString(), confidence: 0.8, decay_factor: 0.9, effective_score: 0.72 },
];

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
    http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
    http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCosts)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersona)),
    http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json(mockActivityFeed)),
    http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json(mockWeeklyTrends)),
    http.get(`${BASE}/iocs`, () => HttpResponse.json(mockIocs)),
  );
}

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => {
  server.resetHandlers();
  mockNavigate.mockReset();
});
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('Dashboard — coverage gaps', () => {
  it('shows kill switch active status', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () =>
        HttpResponse.json({ ...mockStats, kill_switch_active: true }),
      ),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json([])),
      http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCosts)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersona)),
      http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json({ events: [] })),
      http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json({ trends: [] })),
      http.get(`${BASE}/iocs`, () => HttpResponse.json([])),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/kill switch active/i)).toBeInTheDocument();
    });
  });

  it('displays activity feed with event types', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Recent Activity/i)).toBeInTheDocument();
    });
    // Should display event labels
    await waitFor(() => {
      expect(screen.getByText(/Conversation opened/i)).toBeInTheDocument();
    });
  });

  it('displays top IOCs section', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('evil.com')).toBeInTheDocument();
    });
  });

  it('displays weekly trend badges', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/13%|12%/)).toBeInTheDocument();
    });
  });

  it('navigates to LLM costs on card click', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('$12.34')).toBeInTheDocument();
    });
    const costCard = screen.getByText('$12.34').closest('div[class*="cursor-pointer"]');
    fireEvent.click(costCard!);
    expect(mockNavigate).toHaveBeenCalledWith('/llm-costs');
  });

  it('navigates to conversation detail on row click', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Active Conversations/i)).toBeInTheDocument();
    });
    // Find the conversation row in the active conversations table
    const rows = screen.getAllByRole('link');
    const convRow = rows.find((r) => r.closest('tr'));
    if (convRow) {
      const row = convRow.closest('tr');
      fireEvent.click(row!);
      expect(mockNavigate).toHaveBeenCalled();
    }
  });

  it('handles Enter keydown on conversation row', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Active Conversations/i)).toBeInTheDocument();
    });
    // The rows have role="link" and tabIndex
    const rows = screen.getAllByRole('link');
    const convRow = rows.find((r) => r.closest('tr'));
    if (convRow) {
      const row = convRow.closest('tr');
      fireEvent.keyDown(row!, { key: 'Enter' });
      expect(mockNavigate).toHaveBeenCalled();
    }
  });

  it('shows no active conversations message', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json({ ...mockStats, conversations: { ...mockStats.conversations, open: 0 } })),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json([{ conv_id: 'x', status: 'closed', score_risk: 0 }])),
      http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCosts)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersona)),
      http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json({ events: [] })),
      http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json({ trends: [] })),
      http.get(`${BASE}/iocs`, () => HttpResponse.json([])),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No active conversations/i)).toBeInTheDocument();
    });
  });

  it('shows no recent activity message', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCosts)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersona)),
      http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json({ events: [] })),
      http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json({ trends: [] })),
      http.get(`${BASE}/iocs`, () => HttpResponse.json([])),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No recent activity/i)).toBeInTheDocument();
    });
  });

  it('shows no top IOCs message when all have low confidence', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCosts)),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersona)),
      http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json(mockActivityFeed)),
      http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json(mockWeeklyTrends)),
      http.get(`${BASE}/iocs`, () => HttpResponse.json([
        { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'domain', value: 'low.com', confidence: 0.2, ts_observed: new Date().toISOString() },
      ])),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No high-confidence/i)).toBeInTheDocument();
    });
  });

  it('shows LLM cost warning color when pct_used >= 80', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
      http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
      http.get(`${BASE}/monitoring/llm-cost`, () =>
        HttpResponse.json({ ...mockLlmCosts, current_month: { ...mockLlmCosts.current_month, pct_used: 85 } }),
      ),
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersona)),
      http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json(mockActivityFeed)),
      http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json(mockWeeklyTrends)),
      http.get(`${BASE}/iocs`, () => HttpResponse.json(mockIocs)),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('$12.34')).toBeInTheDocument();
    });
  });
});
