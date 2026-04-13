import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Settings } from './Settings';

const BASE = '/api/v1';

const mockStats = {
  status: 'operational',
  conversations: { total: 15, open: 3, active: 3, closed: 10, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: { status: 'converging', best_persona: 'elderly_person', best_score: 0.82, exploration_rate: 0.15, converged_types: 1, total_types: 5 },
  kill_switch: false,
  kill_switch_active: false,
  checked_at: new Date().toISOString(),
};

const mockMetaConfig = {
  personas: [{ code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true }],
  scam_types: [],
  ioc_types: ['email', 'domain'],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai',
  llm_model: 'gpt-4o-mini',
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
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

describe('Settings', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Settings/i)).toBeInTheDocument();
    });
  });

  it('renders system status section', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/System Status/i)).toBeInTheDocument();
    });
  });

  it('shows operational pipeline status', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Operational/i)).toBeInTheDocument();
    });
  });

  it('renders counter section with stats', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('15')).toBeInTheDocument(); // total conversations
      expect(screen.getByText('42')).toBeInTheDocument(); // total messages
      expect(screen.getByText('89')).toBeInTheDocument(); // total iocs
    });
  });

  it('renders platform info section', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Platform Info/i)).toBeInTheDocument();
      expect(screen.getByText(/Symfony 7/i)).toBeInTheDocument();
      expect(screen.getByText(/PostgreSQL 15/i)).toBeInTheDocument();
    });
  });

  it('renders agents section', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Orchestrator')).toBeInTheDocument();
      expect(screen.getByText('PolicyGuard')).toBeInTheDocument();
    });
  });

  it('shows LLM provider from config', async () => {
    setupHandlers();
    render(<Settings />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/openai.*gpt-4o-mini/i)).toBeInTheDocument();
    });
  });
});
