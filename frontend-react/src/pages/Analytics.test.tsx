import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Analytics } from './Analytics';

const BASE = '/api/v1';

const mockIocTimeline = { data: [{ date: '2026-03-20', count: 10 }] };
const mockConvTimeline = { data: [{ date: '2026-03-20', opened: 2, closed: 1 }] };
const mockIocDist = { data: [{ label: 'domain', count: 50 }, { label: 'email', count: 30 }] };
const mockScamDist = { data: [{ label: 'PHISHING', count: 15 }] };
const mockCostTimeline = { data: [{ date: '2026-03-20', cost_usd: 0.5 }] };
const mockPipelineTimeline = { data: [{ date: '2026-03-20', approved: 5, fallback: 1, rejected: 0 }] };
const mockConvergence = { by_scam_type: {} };

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/analytics/ioc-timeline`, () => HttpResponse.json(mockIocTimeline)),
    http.get(`${BASE}/monitoring/analytics/conversation-timeline`, () => HttpResponse.json(mockConvTimeline)),
    http.get(`${BASE}/monitoring/analytics/ioc-distribution`, () => HttpResponse.json(mockIocDist)),
    http.get(`${BASE}/monitoring/analytics/scam-distribution`, () => HttpResponse.json(mockScamDist)),
    http.get(`${BASE}/monitoring/analytics/cost-timeline`, () => HttpResponse.json(mockCostTimeline)),
    http.get(`${BASE}/monitoring/analytics/pipeline-timeline`, () => HttpResponse.json(mockPipelineTimeline)),
    http.get(`${BASE}/monitoring/convergence-history`, () => HttpResponse.json(mockConvergence)),
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

describe('Analytics', () => {
  it('renders the analytics page with title and period selectors', async () => {
    setupHandlers();
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Analytics/i)).toBeInTheDocument();
      expect(screen.getByText('7 days')).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/ioc-timeline`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockIocTimeline);
      }),
    );
    render(<Analytics />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('shows error state when data fails', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/ioc-timeline`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
      http.get(`${BASE}/monitoring/analytics/conversation-timeline`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail|retry/i);
    });
  });

  it('renders period selector buttons', async () => {
    setupHandlers();
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('7 days')).toBeInTheDocument();
      expect(screen.getByText('30 days')).toBeInTheDocument();
      expect(screen.getByText('90 days')).toBeInTheDocument();
    });
  });

  it('renders chart sections', async () => {
    setupHandlers();
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/IOC.*Timeline|IOC Extraction/i)).toBeInTheDocument();
      expect(screen.getByText(/Conversation Volume/i)).toBeInTheDocument();
    });
  });
});
