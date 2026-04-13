import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Dashboard } from './Dashboard';

const BASE = '/api/v1';

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
  { conv_id: 'aaaa-bbbb-cccc-dddd', status: 'open', score_risk: 50, persona: 'elderly_person', scam_type: 'PHISHING', turns: 4, ts_first: '2026-03-20T10:00:00Z', ts_last: '2026-03-20T12:00:00Z' },
  { conv_id: 'eeee-ffff-0000-1111', status: 'closed', score_risk: 80, persona: 'bank_customer', scam_type: 'ROMANCE', turns: 12, ts_first: '2026-03-19T08:00:00Z', ts_last: '2026-03-19T16:00:00Z' },
];

const mockLlmCosts = {
  current_month: { total_usd: 12.34, limit_usd: 50.0, pct_used: 24.7, calls_count: 1842, total_prompt_tokens: 2450000, total_completion_tokens: 890000 },
  per_purpose: {},
  daily_trend: [],
  limit_exceeded: false,
};

const mockMetaConfig = {
  personas: [{ code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true }],
  scam_types: [{ code: 'PHISHING', label: 'Phishing', description: '', active: true }],
  ioc_types: ['email', 'domain'],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai',
  llm_model: 'gpt-4o-mini',
};

const mockPersonaPerformance = {
  persona_code: 'elderly_person',
  global_avg_reward: 0.75,
  total_sessions: 10,
  performance_by_scam_type: [],
};

const mockActivityFeed = { events: [{ event_type: 'conversation_opened', ref_id: 'aaaa-bbbb-cccc-dddd', ts: new Date().toISOString() }] };
const mockWeeklyTrends = { trends: [{ metric: 'iocs', delta_pct: 12.5 }] };
const mockIocs = [
  { obs_id: 'obs-1', ioc_id: 'ind-1', type: 'domain', value: 'evil.com', value_norm: 'evil[.]com', score: { vt: 70, urlscan: 0 }, category: 'PHISHING', ts_observed: new Date().toISOString(), confidence: 0.95, decay_factor: 0.9, effective_score: 0.85 },
];

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
    http.get(`${BASE}/communication/conversation`, () => HttpResponse.json(mockConversations)),
    http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(mockLlmCosts)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.get(`${BASE}/scambaiting/persona/:code/performance`, () => HttpResponse.json(mockPersonaPerformance)),
    http.get(`${BASE}/monitoring/analytics/activity-feed`, () => HttpResponse.json(mockActivityFeed)),
    http.get(`${BASE}/monitoring/analytics/weekly-trends`, () => HttpResponse.json(mockWeeklyTrends)),
    http.get(`${BASE}/iocs`, () => HttpResponse.json(mockIocs)),
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
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('Dashboard', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the dashboard title', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Dashboard/i)).toBeInTheDocument();
    });
  });

  it('shows loading state when stats are loading', () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockStats);
      }),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    expect(document.body.textContent).toContain('Loading');
  });

  it('shows pipeline active status', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Pipeline active|pipeline active/i)).toBeInTheDocument();
    });
  });

  it('shows error state when stats fail', async () => {
    server.use(
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json({ error: 'fail' }, { status: 500 })),
    );
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/fail|error|retry/i);
    });
  });

  it('displays IOCs extracted stat card', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('89')).toBeInTheDocument();
    });
  });

  it('displays recent activity section', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Recent Activity/i)).toBeInTheDocument();
    });
  });
});
