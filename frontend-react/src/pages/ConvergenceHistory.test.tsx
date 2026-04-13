import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { ConvergenceHistory } from './ConvergenceHistory';

const BASE = '/api/v1';

const mockConvergence = {
  by_scam_type: {
    PHISHING: [
      { date: '2026-03-20', dominant_persona: 'elderly_person', dominant_pct: 0.75, sessions_count: 10, converged: false },
      { date: '2026-03-19', dominant_persona: 'elderly_person', dominant_pct: 0.70, sessions_count: 8, converged: false },
    ],
    ROMANCE: [
      { date: '2026-03-20', dominant_persona: 'lonely_person', dominant_pct: 0.85, sessions_count: 12, converged: true },
    ],
  },
};

const mockMetaConfig = {
  personas: [
    { code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true },
    { code: 'lonely_person', label: 'Lonely Person', tone: 'Warm', active: true },
  ],
  scam_types: [],
  ioc_types: [],
  bandit: { strategy: 'epsilon-greedy', epsilon: 0.2, cold_start_threshold: 3, convergence_threshold: 0.6, min_sessions_for_convergence: 10, converged_epsilon: 0.05, reward_weights: {} },
  llm_provider: 'openai', llm_model: 'gpt-4o-mini',
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/convergence-history`, () => HttpResponse.json(mockConvergence)),
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

describe('ConvergenceHistory', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Convergence History/i)).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/monitoring/convergence-history`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockConvergence);
      }),
    );
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders convergence table with data', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('75.0%')).toBeInTheDocument();
    });
  });

  it('shows converged status for ROMANCE', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('CONVERGED')).toBeInTheDocument();
    });
  });

  it('shows error state when data fails', async () => {
    server.use(
      http.get(`${BASE}/monitoring/convergence-history`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail/i);
    });
  });
});
