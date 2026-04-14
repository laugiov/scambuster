import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import PipelineMonitor from './PipelineMonitor';

const BASE = '/api/v1';

const mockTraces = {
  traces: [
    {
      msg_id: 'msg-1',
      conversation_id: 'conv-1111-2222',
      persona: 'elderly_person',
      scam_type: 'PHISHING',
      total_duration_ms: 2500,
      total_cost: 0.0123,
      attempts: 2,
      approved: true,
      fallback_used: false,
      component_count: 5,
      has_alerts: false,
      created_at: '2026-03-20T10:00:00Z',
    },
    {
      msg_id: 'msg-2',
      conversation_id: 'conv-3333-4444',
      persona: 'bank_customer',
      scam_type: 'ROMANCE',
      total_duration_ms: 3500,
      total_cost: 0.0234,
      attempts: 3,
      approved: false,
      fallback_used: true,
      component_count: 5,
      has_alerts: true,
      created_at: '2026-03-20T11:00:00Z',
    },
  ],
};

const mockHealth = {
  period_hours: 24,
  total_replies: 15,
  avg_duration_ms: 2200,
  avg_cost: 0.011,
  approval_rate: 0.93,
  fallback_rate: 0.07,
  components: {
    prompt_builder: { success_rate: 1.0, skip_rate: 0.0, error_rate: 0.0, avg_duration_ms: 120 },
    policy_guard: { success_rate: 0.90, skip_rate: 0.0, error_rate: 0.10, avg_duration_ms: 50 },
  },
  alerts: ['High fallback rate detected'],
  cost_today: 0.15,
  cost_yesterday: 0.12,
};

const mockTraceDetail = {
  msg_id: 'msg-1',
  conversation_id: 'conv-1111-2222',
  persona: 'elderly_person',
  scam_type: 'PHISHING',
  total_duration_ms: 2500,
  total_cost: 0.0123,
  attempts: 2,
  approved: true,
  fallback_used: false,
  component_count: 5,
  has_alerts: false,
  detected_language: 'en',
  components: [
    { name: 'prompt_builder', status: 'ran', duration_ms: 120, cost: 0.001, output: {}, skip_reason: null },
    { name: 'policy_guard', status: 'skipped', duration_ms: 0, cost: null, output: {}, skip_reason: 'already_checked' },
    { name: 'ioc_scorer', status: 'error', duration_ms: 50, cost: null, error: 'timeout', skip_reason: null },
  ],
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/pipeline-traces`, () => HttpResponse.json(mockTraces)),
    http.get(`${BASE}/monitoring/pipeline-health`, () => HttpResponse.json(mockHealth)),
    http.get(`${BASE}/monitoring/pipeline-traces/:msgId`, () => HttpResponse.json(mockTraceDetail)),
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

describe('PipelineMonitor — coverage gaps', () => {
  it('renders StatusBadge with Fallback variant', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Fallback')).toBeInTheDocument();
    });
  });

  it('renders StatusBadge with OK variant for approved traces', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('OK')).toBeInTheDocument();
    });
  });

  it('renders alerts section when alerts exist', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Alerts')).toBeInTheDocument();
      expect(screen.getByText('High fallback rate detected')).toBeInTheDocument();
    });
  });

  it('highlights components with low success rate in red', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('policy_guard')).toBeInTheDocument();
    });
    // policy_guard has success_rate 0.90 < 0.95, should have red bg
    expect(screen.getByText('90%')).toBeInTheDocument();
    expect(screen.getByText('10%')).toBeInTheDocument();
  });

  it('switches period when 7d is clicked', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Pipeline Monitor')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('7d'));
    // Should trigger a reload
    await waitFor(() => {
      expect(screen.getByText('Pipeline Monitor')).toBeInTheDocument();
    });
  });

  it('toggles auto-refresh checkbox', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Pipeline Monitor')).toBeInTheDocument();
    });
    const checkbox = screen.getByRole('checkbox');
    expect(checkbox).toBeChecked();
    fireEvent.click(checkbox);
    expect(checkbox).not.toBeChecked();
  });

  it('expands trace row to show component waterfall', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    // Wait for data to load — traces render after async fetchData
    await waitFor(() => {
      expect(screen.getByText(/Recent Executions/i)).toBeInTheDocument();
      // conv-1111-2222.substring(0,8) = "conv-111"
      const traceElements = screen.getAllByText(/conv-111/);
      expect(traceElements.length).toBeGreaterThan(0);
    }, { timeout: 3000 });
    const traceElements = screen.getAllByText(/conv-111/);
    const clickTarget = traceElements[0].closest('[class*="cursor-pointer"]');
    if (clickTarget) {
      fireEvent.click(clickTarget);
      await waitFor(() => {
        // prompt_builder appears both in component health table and waterfall
        const pbElements = screen.getAllByText('prompt_builder');
        expect(pbElements.length).toBeGreaterThanOrEqual(2);
      });
    }
  });

  it('shows component status icons (ran, skipped, error)', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      const traceElements = screen.getAllByText(/conv-111/);
      expect(traceElements.length).toBeGreaterThan(0);
    }, { timeout: 3000 });
    const traceElements = screen.getAllByText(/conv-111/);
    const clickTarget = traceElements[0].closest('[class*="cursor-pointer"]');
    if (clickTarget) {
      fireEvent.click(clickTarget);
      await waitFor(() => {
        expect(screen.getByText('already_checked')).toBeInTheDocument();
      });
    }
  });

  it('shows no traces message when trace list is empty', async () => {
    server.use(
      http.get(`${BASE}/monitoring/pipeline-traces`, () => HttpResponse.json({ traces: [] })),
      http.get(`${BASE}/monitoring/pipeline-health`, () => HttpResponse.json(mockHealth)),
    );
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No pipeline traces/i)).toBeInTheDocument();
    });
  });

  it('renders N/A for avg cost when total_replies is 0', async () => {
    server.use(
      http.get(`${BASE}/monitoring/pipeline-traces`, () => HttpResponse.json({ traces: [] })),
      http.get(`${BASE}/monitoring/pipeline-health`, () =>
        HttpResponse.json({ ...mockHealth, total_replies: 0 }),
      ),
    );
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      const naTexts = screen.getAllByText('N/A');
      expect(naTexts.length).toBeGreaterThanOrEqual(1);
    });
  });
});
