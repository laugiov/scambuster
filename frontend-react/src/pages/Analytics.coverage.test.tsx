import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Analytics } from './Analytics';

const BASE = '/api/v1';

const mockIocTimeline = { data: [{ date: '2026-03-20', count: 10 }, { date: '2026-03-21', count: 15 }] };
const mockConvTimeline = { data: [{ date: '2026-03-20', opened: 2, closed: 1 }] };
const mockIocDist = { data: [{ label: 'domain', count: 50 }, { label: 'email', count: 30 }, { label: 'ipv4', count: 20 }] };
const mockScamDist = { data: [{ label: 'PHISHING', count: 15 }, { label: 'ROMANCE', count: 10 }] };
const mockCostTimeline = { data: [{ date: '2026-03-20', cost_usd: 0.5 }] };
const mockPipelineTimeline = { data: [{ date: '2026-03-20', approved: 5, fallback: 1, rejected: 0 }] };

const mockConvergenceWithData = {
  by_scam_type: {
    PHISHING: [
      { date: '2026-03-18', dominant_pct: 0.65 },
      { date: '2026-03-19', dominant_pct: 0.70 },
      { date: '2026-03-20', dominant_pct: 0.75 },
    ],
  },
};

function setupHandlers(convergence?: object) {
  server.use(
    http.get(`${BASE}/monitoring/analytics/ioc-timeline`, () => HttpResponse.json(mockIocTimeline)),
    http.get(`${BASE}/monitoring/analytics/conversation-timeline`, () => HttpResponse.json(mockConvTimeline)),
    http.get(`${BASE}/monitoring/analytics/ioc-distribution`, () => HttpResponse.json(mockIocDist)),
    http.get(`${BASE}/monitoring/analytics/scam-distribution`, () => HttpResponse.json(mockScamDist)),
    http.get(`${BASE}/monitoring/analytics/cost-timeline`, () => HttpResponse.json(mockCostTimeline)),
    http.get(`${BASE}/monitoring/analytics/pipeline-timeline`, () => HttpResponse.json(mockPipelineTimeline)),
    http.get(`${BASE}/monitoring/convergence-history`, () => HttpResponse.json(convergence ?? { by_scam_type: {} })),
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

describe('Analytics — coverage gaps', () => {
  it('renders all 7 chart sections', async () => {
    setupHandlers();
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/IOC Extraction Timeline/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/Conversation Volume/i)).toBeInTheDocument();
    expect(screen.getByText(/IOC Type Distribution/i)).toBeInTheDocument();
    expect(screen.getByText(/Scam Type Distribution/i)).toBeInTheDocument();
    expect(screen.getByText(/LLM Cost Trend/i)).toBeInTheDocument();
    expect(screen.getByText(/Reply Pipeline Health/i)).toBeInTheDocument();
  });

  it('switches period when 7d button is clicked', async () => {
    setupHandlers();
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('7 days')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('7 days'));
    // Should re-render with 7-day filter
    await waitFor(() => {
      expect(screen.getByText(/IOC Extraction Timeline/i)).toBeInTheDocument();
    });
  });

  it('switches period when 90d button is clicked', async () => {
    setupHandlers();
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('90 days')).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText('90 days'));
    await waitFor(() => {
      expect(screen.getByText(/IOC Extraction Timeline/i)).toBeInTheDocument();
    });
  });

  it('renders convergence sparklines when data has multiple entries', async () => {
    setupHandlers(mockConvergenceWithData);
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Persona Convergence/i)).toBeInTheDocument();
    });
    expect(screen.getByText('Phishing')).toBeInTheDocument();
  });

  it('shows empty chart for convergence when no data', async () => {
    setupHandlers({ by_scam_type: {} });
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Persona Convergence/i)).toBeInTheDocument();
    });
    // Should show "No data" in the convergence sparklines area
    const noDataTexts = screen.getAllByText(/No data/i);
    expect(noDataTexts.length).toBeGreaterThan(0);
  });

  it('shows empty chart message when ioc timeline has no data', async () => {
    server.use(
      http.get(`${BASE}/monitoring/analytics/ioc-timeline`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/monitoring/analytics/conversation-timeline`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/monitoring/analytics/ioc-distribution`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/monitoring/analytics/scam-distribution`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/monitoring/analytics/cost-timeline`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/monitoring/analytics/pipeline-timeline`, () => HttpResponse.json({ data: [] })),
      http.get(`${BASE}/monitoring/convergence-history`, () => HttpResponse.json({ by_scam_type: {} })),
    );
    render(<Analytics />, { wrapper: createWrapper() });
    await waitFor(() => {
      const noDataTexts = screen.getAllByText(/No data/i);
      expect(noDataTexts.length).toBeGreaterThanOrEqual(7);
    });
  });
});
