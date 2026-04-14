import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Personas } from './Personas';
import { mockMetaConfig as baseMockMetaConfig } from '@/__tests__/fixtures';

const BASE = '/api/v1';

const mockMetaConfig = {
  ...baseMockMetaConfig,
  scam_types: [],
  ioc_types: [],
};

const mockPersonaPerformance = (code: string) => ({
  persona_code: code,
  global_avg_reward: code === 'elderly_person' ? 0.75 : 0.60,
  total_sessions: code === 'elderly_person' ? 15 : 8,
  performance_by_scam_type: [],
});

const mockStats = {
  status: 'operational',
  conversations: { total: 15, active: 3, closed: 10, abandoned: 2 },
  messages: { total: 42, inbound: 20, outbound: 22 },
  iocs: { total: 89, unique_types: 6 },
  convergence: { status: 'converging', best_persona: 'elderly_person', best_score: 0.82, exploration_rate: 0.15 },
  kill_switch: false,
  checked_at: new Date().toISOString(),
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
    http.get(`${BASE}/scambaiting/persona/:code/performance`, ({ params }) =>
      HttpResponse.json(mockPersonaPerformance(params.code as string)),
    ),
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
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

describe('Personas', () => {
  it('renders the personas page with title and bandit strategy', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Personas/i)).toBeInTheDocument();
      expect(screen.getAllByText(/epsilon-greedy/i).length).toBeGreaterThan(0);
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/meta/config`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockMetaConfig);
      }),
    );
    render(<Personas />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders stat cards', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('0.20')).toBeInTheDocument(); // exploration rate
    });
  });

  it('renders performance matrix with persona names', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Performance Matrix/i)).toBeInTheDocument();
    });
  });

  it('renders bandit strategy settings', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText(/epsilon-greedy/i).length).toBeGreaterThan(0);
    });
  });
});
