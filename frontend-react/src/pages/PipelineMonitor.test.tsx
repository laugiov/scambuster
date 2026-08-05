import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
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
      conversation_id: 'conv-1',
      persona: 'elderly_person',
      scam_type: 'PHISHING',
      total_duration_ms: 2500,
      total_cost: 0.0123,
      attempts: 1,
      approved: true,
      fallback_used: false,
      component_count: 5,
      has_alerts: false,
      created_at: '2026-03-20T10:00:00Z',
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
    policy_guard: { success_rate: 0.95, skip_rate: 0.0, error_rate: 0.05, avg_duration_ms: 50 },
  },
  alerts: [],
  cost_today: 0.15,
  cost_yesterday: 0.12,
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/pipeline-traces`, () => HttpResponse.json(mockTraces)),
    http.get(`${BASE}/monitoring/pipeline-health`, () => HttpResponse.json(mockHealth)),
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

describe('PipelineMonitor', () => {
  it('renders the pipeline monitor with title and stat cards', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Pipeline Monitor/i)).toBeInTheDocument();
      expect(screen.getByText('15')).toBeInTheDocument(); // total_replies
    });
  });

  it('shows loading state initially', () => {
    server.use(
      http.get(`${BASE}/monitoring/pipeline-traces`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockTraces);
      }),
      http.get(`${BASE}/monitoring/pipeline-health`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockHealth);
      }),
    );
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders stat cards with health data', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('15')).toBeInTheDocument(); // total_replies
      expect(screen.getByText(/93%/)).toBeInTheDocument(); // approval rate
    });
  });

  it('renders recent executions section', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Recent Executions/i)).toBeInTheDocument();
    });
  });

  it('renders component health table', async () => {
    setupHandlers();
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Component Health/i)).toBeInTheDocument();
      expect(screen.getByText('prompt_builder')).toBeInTheDocument();
    });
  });

  it('shows error state when request fails', async () => {
    server.use(
      http.get(`${BASE}/monitoring/pipeline-traces`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
      http.get(`${BASE}/monitoring/pipeline-health`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<PipelineMonitor />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/failed|error/i);
    });
  });
});
