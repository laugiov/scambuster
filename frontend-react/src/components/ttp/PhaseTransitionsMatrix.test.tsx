import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { TtpPhaseTransitions } from '@/types/ttp';
import { PhaseTransitionsMatrix } from './PhaseTransitionsMatrix';
import '../../i18n';

const BASE = '/api/v1';

const populated: TtpPhaseTransitions = {
  transitions: [
    { from_phase: 'hook', to_phase: 'hook', count: 2 },
    { from_phase: 'hook', to_phase: 'trust-building', count: 6 },
    { from_phase: 'trust-building', to_phase: 'payment-request', count: 3 },
  ],
  total_pairs: 11,
};

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  };
}

describe('PhaseTransitionsMatrix', () => {
  it('renders the dense 6×6 phase grid with shaded counts and dimmed zero cells', async () => {
    server.use(http.get(`${BASE}/ttps/phase-transitions`, () => HttpResponse.json(populated)));
    render(<PhaseTransitionsMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions-table')).toBeInTheDocument();
    });

    // Every canonical phase appears twice: once as a column header, once as a
    // row header (PHASE_ORDER order).
    for (const label of ['Hook', 'Trust-building', 'Payment request', 'Escalation', 'Channel switch', 'Exit']) {
      expect(screen.getAllByText(label)).toHaveLength(2);
    }

    // Dense 6×6 grid: 36 cells, sparse payload → 3 populated, the rest '·'.
    const cells = screen.getAllByTestId('ttp-phase-transitions-cell').map((c) => c.textContent);
    expect(cells).toHaveLength(36);
    // Row hook: hook→hook = 2, hook→trust-building = 6.
    expect(cells[0]).toBe('2');
    expect(cells[1]).toBe('6');
    // Row trust-building (indexes 6-11): trust-building→payment-request = 3.
    expect(cells[8]).toBe('3');
    expect(cells.filter((c) => c === '·')).toHaveLength(33);
  });

  it('reports the total pair volume and a neutral cell tooltip', async () => {
    server.use(http.get(`${BASE}/ttps/phase-transitions`, () => HttpResponse.json(populated)));
    render(<PhaseTransitionsMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions-total')).toHaveTextContent('11 pairs in total');
    });

    const cells = screen.getAllByTestId('ttp-phase-transitions-cell');
    expect(cells[1].getAttribute('title')).toBe('Hook → Trust-building · 6 pairs');
    expect(cells[2].getAttribute('title')).toBe('Hook → Payment request · none');
  });

  it('appends an unexpected phase instead of dropping it', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () =>
        HttpResponse.json({
          transitions: [{ from_phase: 'hook', to_phase: 'some-new-phase', count: 1 }],
          total_pairs: 1,
        })),
    );
    render(<PhaseTransitionsMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions-table')).toBeInTheDocument();
    });

    // 7 phases → 49 cells, and the humanized fallback label is visible.
    expect(screen.getAllByTestId('ttp-phase-transitions-cell')).toHaveLength(49);
    expect(screen.getAllByText('Some New Phase').length).toBeGreaterThan(0);
  });

  it('shows the empty note (not an error) when nothing is observed', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () =>
        HttpResponse.json({ transitions: [], total_pairs: 0 })),
    );
    render(<PhaseTransitionsMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ttp-phase-transitions-table')).toBeNull();
  });

  it('degrades to the empty note on a 500 (no crash)', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () => HttpResponse.json({}, { status: 500 })),
    );
    render(<PhaseTransitionsMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions-empty')).toBeInTheDocument();
    });
    expect(screen.queryByTestId('ttp-phase-transitions-table')).toBeNull();
  });

  it('degrades to the empty note on a 404 (endpoint absent)', async () => {
    server.use(
      http.get(`${BASE}/ttps/phase-transitions`, () =>
        HttpResponse.json({ error: 'Not found' }, { status: 404 })),
    );
    render(<PhaseTransitionsMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('ttp-phase-transitions-empty')).toBeInTheDocument();
    });
  });
});
