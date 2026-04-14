import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Dashboard } from './Dashboard';
import { mockMetaConfig as baseMockMetaConfig, mockStats, mockConversations } from '@/__tests__/fixtures';

const BASE = '/api/v1';

const mockLlmCosts = {
  current_month: { total_usd: 12.34, limit_usd: 50.0, pct_used: 24.7, calls_count: 1842, total_prompt_tokens: 2450000, total_completion_tokens: 890000 },
  per_purpose: {},
  daily_trend: [],
  limit_exceeded: false,
};

const mockMetaConfig = {
  ...baseMockMetaConfig,
  personas: [{ code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true }],
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

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
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
  it('renders the dashboard with title and stat cards', async () => {
    setupHandlers();
    render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Dashboard/i)).toBeInTheDocument();
      // Stat cards should be visible with mock data values
      expect(screen.getByText('89')).toBeInTheDocument(); // total IOCs
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

  it('has no accessibility violations', async () => {
    const { axe } = await import('vitest-axe');
    setupHandlers();
    const { container } = render(<Dashboard />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Dashboard/i)).toBeInTheDocument();
    });
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
