import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import InjectionMonitoring from './InjectionMonitoring';

const BASE = '/api/v1';

const mockStats = {
  period_days: 7,
  total_inbound: 100,
  analyzed: 95,
  coverage_pct: 95,
  high_risk: 2,
  medium_risk: 5,
  low_risk: 88,
  recent_alerts: [
    {
      msg_id: 'msg-1',
      conv_id: 'conv-1',
      ts_msg: '2026-03-20T10:00:00Z',
      risk_score: 75,
      risk_level: 'high',
      patterns: ['prompt_injection', 'role_override'],
    },
  ],
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/injection`, () => HttpResponse.json(mockStats)),
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

describe('InjectionMonitoring', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Injection Monitor/i)).toBeInTheDocument();
    });
  });

  it('shows loading state initially', () => {
    server.use(
      http.get(`${BASE}/monitoring/injection`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockStats);
      }),
    );
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows stat cards with correct values', async () => {
    setupHandlers();
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('95')).toBeInTheDocument(); // analyzed
      expect(screen.getByText('2')).toBeInTheDocument(); // high_risk
      expect(screen.getByText('5')).toBeInTheDocument(); // medium_risk
    });
  });

  it('renders recent alerts', async () => {
    setupHandlers();
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Recent Alerts/i)).toBeInTheDocument();
      expect(screen.getByText(/prompt_injection/i)).toBeInTheDocument();
    });
  });

  it('shows no-threat message when no alerts and data exists', async () => {
    server.use(
      http.get(`${BASE}/monitoring/injection`, () => HttpResponse.json({
        ...mockStats,
        high_risk: 0,
        medium_risk: 0,
        recent_alerts: [],
      })),
    );
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No injection threats detected/i)).toBeInTheDocument();
    });
  });

  it('shows error state when request fails', async () => {
    server.use(
      http.get(`${BASE}/monitoring/injection`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<InjectionMonitoring />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/failed|error/i);
    });
  });
});
