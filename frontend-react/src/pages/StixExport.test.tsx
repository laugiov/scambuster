import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { StixExport } from './StixExport';

const BASE = '/api/v1';

const mockCandidates = [
  {
    campaign_id: 'camp-aaaa-bbbb',
    rule_id: 'rule-1111-2222',
    ppv: 0.92,
    hits_total: 12,
    lead_time_hours: 48,
    created_at: '2026-03-20T10:00:00Z',
  },
];

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
    http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({ candidates: mockCandidates })),
    http.get(`${BASE}/monitoring/autonomy`, () => HttpResponse.json(mockStats)),
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

describe('StixExport', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/STIX 2\.1 Export Center/i)).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockCandidates);
      }),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders stat cards', async () => {
    setupHandlers();
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('89')).toBeInTheDocument(); // exportable IOCs
      expect(screen.getAllByText('1').length).toBeGreaterThan(0); // campaigns available
    });
  });

  it('renders campaign list with export buttons', async () => {
    setupHandlers();
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
    });
    expect(screen.getByText(/Export STIX/i)).toBeInTheDocument();
  });

  it('renders bundle preview section', async () => {
    setupHandlers();
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/STIX Bundle Preview/i)).toBeInTheDocument();
    });
  });

  it('shows error state when request fails', async () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<StixExport />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail|retry/i);
    });
  });
});
