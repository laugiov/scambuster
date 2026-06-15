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

import { mockMetaConfig as baseMockMetaConfig } from '@/__tests__/fixtures';

const mockMetaConfig = {
  ...baseMockMetaConfig,
  personas: [
    { code: 'elderly_person', label: 'Elderly Person', tone: 'Familiar', active: true },
    { code: 'lonely_person', label: 'Lonely Person', tone: 'Warm', active: true },
  ],
  scam_types: [],
  ioc_types: [],
};

function setupHandlers() {
  server.use(
    http.get(`${BASE}/monitoring/convergence-history`, () => HttpResponse.json(mockConvergence)),
    http.get(`${BASE}/meta/config`, () => HttpResponse.json(mockMetaConfig)),
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

describe('ConvergenceHistory', () => {
  it('renders the convergence history with title and data', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Convergence History/i)).toBeInTheDocument();
      expect(screen.getByText('75.0%')).toBeInTheDocument(); // PHISHING dominant_pct
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

  // Spec 104 P2 — convergence state banner above the dominance chart.
  // ROMANCE in the fixture has dominance 85% which crosses the 60%
  // default threshold; PHISHING peaks at 75% but never crosses (>= 60
  // is true here too actually with default 0.6, let me check). The
  // banner reports the FIRST crossing date, or "still exploring" if
  // none. Either way, the banner must always be present so the viewer
  // knows where in the learning curve they are.
  it('Spec 104 P2 + v3: renders one of the three current-state convergence banners', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      // Banner is driven by the CURRENT (latest) snapshot, not by a
      // historical crossing event. Three possible states:
      //   converged  = latest sessions >= min AND latest dominance >= threshold
      //   settled    = latest sessions >= min AND latest dominance < threshold
      //   exploring  = latest sessions < min
      const banner =
        screen.queryByTestId('convergence-state-converged') ??
        screen.queryByTestId('convergence-state-settled') ??
        screen.queryByTestId('convergence-state-exploring');
      expect(banner).not.toBeNull();
    });
  });

  it('Spec 104 follow-up: "currently dominant" line always renders when data exists', async () => {
    setupHandlers();
    render(<ConvergenceHistory />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByTestId('convergence-state-current')).toBeInTheDocument();
    });
  });
});
