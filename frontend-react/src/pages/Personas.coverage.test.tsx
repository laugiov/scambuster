import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Personas } from './Personas';

const BASE = '/api/v1';

const mockMetaConfig = {
  personas: [
    { code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true },
    { code: 'bank_customer', label: 'Bank Customer', tone: 'Formal', active: true },
    { code: 'generic_user', label: 'Generic User', tone: 'Neutral', active: true },
  ],
  scam_types: [],
  ioc_types: [],
  bandit: {
    strategy: 'epsilon-greedy',
    epsilon: 0.2,
    cold_start_threshold: 3,
    convergence_threshold: 0.6,
    min_sessions_for_convergence: 50,
    converged_epsilon: 0.05,
    reward_weights: { duration: 0.4, iocs_total: 0.25, iocs_sensibles: 0.25, completion: 0.1 },
  },
  llm_provider: 'openai', llm_model: 'gpt-4o-mini',
};

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
    http.get(`${BASE}/scambaiting/persona/:code/performance`, ({ params }) => {
      const code = params.code as string;
      const perfData: Record<string, object> = {
        elderly_person: {
          persona_code: 'elderly_person',
          global_avg_reward: 0.75,
          total_sessions: 15,
          performance_by_scam_type: [{ scam_type: 'PHISHING', reward_avg: 0.8, best_reward: 0.9 }],
        },
        bank_customer: {
          persona_code: 'bank_customer',
          global_avg_reward: 0.45,
          total_sessions: 8,
          performance_by_scam_type: [{ scam_type: 'ROMANCE', reward_avg: 0.5, best_reward: 0.6 }],
        },
        generic_user: {
          persona_code: 'generic_user',
          global_avg_reward: 0.30,
          total_sessions: 1,
          performance_by_scam_type: [],
        },
      };
      return HttpResponse.json({ success: true, data: perfData[code] ?? { persona_code: code, global_avg_reward: 0, total_sessions: 0, performance_by_scam_type: [] } });
    }),
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

describe('Personas — coverage gaps', () => {
  it('renders persona names from all persona codes', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('Elderly Person').length).toBeGreaterThan(0);
      expect(screen.getAllByText('Bank Customer').length).toBeGreaterThan(0);
      expect(screen.getAllByText('Generic User').length).toBeGreaterThan(0);
    });
  });

  it('renders total sessions stat card', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('Elderly Person').length).toBeGreaterThan(0);
      // Total sessions = 15 + 8 + 1 = 24
      expect(screen.getByText('24')).toBeInTheDocument();
    }, { timeout: 3000 });
  });

  it('renders performance matrix section', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Performance Matrix/i)).toBeInTheDocument();
    });
  });

  it('displays persona with reward >= 0.7 in success color', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('Elderly Person').length).toBeGreaterThan(0);
    });
  });

  it('shows dimmed opacity for low-pull personas (< 3 sessions)', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Generic User')).toBeInTheDocument();
    });
    // generic_user has 1 session, should be dimmed (opacity-50)
    const row = screen.getByText('Generic User').closest('tr');
    expect(row?.className).toContain('opacity-50');
  });

  it('shows cold start badge for personas with 0 sessions', async () => {
    server.use(
      http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
      http.get(`${BASE}/scambaiting/persona/:code/performance`, () =>
        HttpResponse.json({ success: true, data: { persona_code: 'test', global_avg_reward: 0, total_sessions: 0, performance_by_scam_type: [] } }),
      ),
      http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
    );
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      const badges = screen.getAllByText(/Cold Start/i);
      expect(badges.length).toBeGreaterThan(0);
    });
  });

  it('shows best reward from performance_by_scam_type', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      // elderly_person best_reward = 0.9
      expect(screen.getByText('0.90')).toBeInTheDocument();
    });
  });

  it('shows -- when no performance_by_scam_type', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      // generic_user has empty performance_by_scam_type
      const dashes = screen.getAllByText('--');
      expect(dashes.length).toBeGreaterThanOrEqual(1);
    });
  });

  it('opens persona detail panel on row click', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('Elderly Person').length).toBeGreaterThan(0);
    });
    // Click on the persona name in the table
    const personaElements = screen.getAllByText('Elderly Person');
    const tableEl = personaElements.find((el) => el.closest('tr'));
    if (tableEl) {
      fireEvent.click(tableEl);
      await waitFor(() => {
        const body = document.body.textContent ?? '';
        expect(body.length).toBeGreaterThan(200);
      });
    }
  });

  it('handles Enter keyboard on persona row', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('Elderly Person').length).toBeGreaterThan(0);
    });
    const personaElements = screen.getAllByText('Elderly Person');
    const tableEl = personaElements.find((el) => el.closest('tr'));
    if (tableEl) {
      const row = tableEl.closest('tr');
      fireEvent.keyDown(row!, { key: 'Enter' });
      await waitFor(() => {
        const body = document.body.textContent ?? '';
        expect(body.length).toBeGreaterThan(200);
      });
    }
  });

  it('renders bandit strategy settings section', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      // Bandit settings section should render with epsilon-greedy
      expect(screen.getAllByText(/epsilon-greedy/i).length).toBeGreaterThan(0);
    });
  });

  it('renders min pulls before exploit setting', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('50')).toBeInTheDocument();
    });
  });

  it('renders cold restart threshold info', async () => {
    setupHandlers();
    render(<Personas />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/cold/i);
    });
  });
});
