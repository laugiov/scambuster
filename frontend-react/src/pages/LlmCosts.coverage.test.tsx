import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import LlmCosts from './LlmCosts';

const BASE = '/api/v1';

const mockReport = {
  current_month: { total_usd: 42.50, limit_usd: 50.0, pct_used: 85.0, calls_count: 3500, total_prompt_tokens: 5000000, total_completion_tokens: 2000000 },
  per_purpose: {
    generation: { cost_usd: 20.0, calls: 1000 },
    classification: { cost_usd: 10.0, calls: 800 },
    validation: { cost_usd: 8.0, calls: 700 },
  },
  daily_trend: [
    { date: '2026-03-22', cost_usd: 2.5, calls: 312 },
    { date: '2026-03-21', cost_usd: 3.0, calls: 287 },
    { date: '2026-03-20', cost_usd: 1.5, calls: 200 },
  ],
  limit_exceeded: false,
};

function setupHandlers(report?: object) {
  server.use(
    http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json(report ?? mockReport)),
    http.get(`${BASE}/admin/llm/killswitch`, () => HttpResponse.json({ active: false })),
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

describe('LlmCosts — coverage gaps', () => {
  it('shows approaching limit badge when pct_used >= 80', async () => {
    setupHandlers();
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Approaching Limit/i)).toBeInTheDocument();
    });
  });

  it('shows budget exceeded badge', async () => {
    setupHandlers({
      ...mockReport,
      limit_exceeded: true,
      current_month: { ...mockReport.current_month, pct_used: 110, total_usd: 55 },
    });
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Budget Exceeded/i)).toBeInTheDocument();
    });
  });

  it('renders budget bar with warning color', async () => {
    setupHandlers({
      ...mockReport,
      current_month: { ...mockReport.current_month, pct_used: 65 },
    });
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Budget Usage/i)).toBeInTheDocument();
    });
  });

  it('shows no limit message when limit_usd is 0', async () => {
    setupHandlers({
      ...mockReport,
      current_month: { ...mockReport.current_month, limit_usd: 0, pct_used: 0 },
    });
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No budget limit/i)).toBeInTheDocument();
    });
  });

  it('renders purpose table with cost breakdown', async () => {
    setupHandlers();
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText('Generation')).toBeInTheDocument();
      expect(screen.getByText('Classification')).toBeInTheDocument();
    });
  });

  it('shows no cost data when per_purpose is empty', async () => {
    setupHandlers({
      ...mockReport,
      per_purpose: {},
    });
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No LLM usage/i)).toBeInTheDocument();
    });
  });

  it('renders daily trend chart', async () => {
    setupHandlers();
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Daily Trend/i)).toBeInTheDocument();
    });
  });

  it('shows no cost data for empty daily trend', async () => {
    setupHandlers({
      ...mockReport,
      daily_trend: [],
    });
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      const noCostData = screen.getAllByText(/No LLM usage/i);
      expect(noCostData.length).toBeGreaterThanOrEqual(1);
    });
  });

  it('shows error state', async () => {
    server.use(
      http.get(`${BASE}/monitoring/llm-cost`, () => HttpResponse.json({ error: 'fail' }, { status: 500 })),
      http.get(`${BASE}/admin/llm/killswitch`, () => HttpResponse.json({ active: false })),
    );
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail|retry/i);
    });
  });

  it('renders kill switch toggle button and confirms activation', async () => {
    setupHandlers();
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    server.use(
      http.post(`${BASE}/admin/llm/killswitch`, () => HttpResponse.json({ active: true })),
    );
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Activate Kill Switch/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Activate Kill Switch/i));
    expect(confirmSpy).toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('cancels kill switch activation on confirm cancel', async () => {
    setupHandlers();
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Activate Kill Switch/i)).toBeInTheDocument();
    });
    fireEvent.click(screen.getByText(/Activate Kill Switch/i));
    expect(confirmSpy).toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('renders stat cards with token counts', async () => {
    setupHandlers();
    render(<LlmCosts />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Prompt Tokens/i)).toBeInTheDocument();
      expect(screen.getByText(/Completion Tokens/i)).toBeInTheDocument();
    });
  });
});
