import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import type { PersonaTtpMatrix as PersonaTtpMatrixData } from '@/types/ttp';
import { PersonaTtpMatrix } from './PersonaTtpMatrix';
import '../../i18n';

const BASE = '/api/v1';

const populated: PersonaTtpMatrixData = {
  personas: [
    { code: 'elderly', label: 'Elderly Person', conversation_total: 10 },
    // Below the 3-conversation floor → provisional, never highlighted.
    { code: 'thin', label: 'Thin Persona', conversation_total: 2 },
  ],
  ttps: [
    { code: 'SB-T001', label: 'Cold outreach', phase: 'hook' },
    { code: 'SB-T017', label: 'Payment demand', phase: 'payment-request' },
  ],
  cells: [
    { persona_code: 'elderly', ttp_code: 'SB-T001', observation_count: 8, conversation_count: 6 },
    { persona_code: 'elderly', ttp_code: 'SB-T017', observation_count: 4, conversation_count: 3 },
    { persona_code: 'thin', ttp_code: 'SB-T001', observation_count: 2, conversation_count: 2 },
  ],
  truncated: true,
  total_personas: 40,
  null_persona_conversations: 4,
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

describe('PersonaTtpMatrix', () => {
  it('renders persona rows, TTP columns and the fair per-conversation cells', async () => {
    server.use(http.get(`${BASE}/ttps/persona-matrix`, () => HttpResponse.json(populated)));
    render(<PersonaTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByTestId('persona-ttp-matrix-table')).toBeInTheDocument();
    });

    expect(screen.getByText('Elderly Person')).toBeInTheDocument();
    expect(screen.getByText('Thin Persona')).toBeInTheDocument();
    expect(screen.getByText('SB-T001')).toBeInTheDocument();
    expect(screen.getByText('SB-T017')).toBeInTheDocument();

    // Cells default to conversation_count, row-major: elderly [6, 3], thin [2, ·].
    const cells = screen.getAllByTestId('persona-ttp-matrix-cell').map((c) => c.textContent);
    expect(cells).toEqual(['6', '3', '2', '·']);
  });

  it('dims provisional rows below the threshold and never shades their cells', async () => {
    server.use(http.get(`${BASE}/ttps/persona-matrix`, () => HttpResponse.json(populated)));
    render(<PersonaTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-ttp-matrix-table')).toBeInTheDocument());

    const rows = screen.getAllByTestId('persona-ttp-matrix-row');
    const byProvisional = Object.fromEntries(rows.map((r) => [r.getAttribute('data-provisional'), r]));
    expect(byProvisional['false']).toBeTruthy(); // elderly (headline)
    expect(byProvisional['true']).toBeTruthy(); // thin (provisional)

    // Headline cell (elderly / SB-T001 = 6) is shaded; the provisional cell
    // (thin / SB-T001 = 2) carries the count but never the highlight.
    const cells = screen.getAllByTestId('persona-ttp-matrix-cell');
    const headline = cells.find((c) => c.textContent === '6')!;
    const provisional = cells.find((c) => c.textContent === '2')!;
    expect(headline.getAttribute('style') ?? '').toContain('rgba');
    expect(provisional.getAttribute('style') ?? '').not.toContain('rgba');
  });

  it('states the normalizer, the threshold rule and the null-persona exclusion', async () => {
    server.use(http.get(`${BASE}/ttps/persona-matrix`, () => HttpResponse.json(populated)));
    render(<PersonaTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-ttp-matrix-normalizer')).toBeInTheDocument());
    expect(screen.getByTestId('persona-ttp-matrix-threshold')).toHaveTextContent('fewer than 3');
    expect(screen.getByTestId('persona-ttp-matrix-null-note')).toHaveTextContent(
      '4 conversations excluded from the grid (no persona assigned).',
    );
    // The cap is never silent.
    expect(screen.getByTestId('persona-ttp-matrix-truncated')).toHaveTextContent(/top 2 of 40/i);
  });

  it('shows an empty note (not an error) when there are no personas', async () => {
    server.use(
      http.get(`${BASE}/ttps/persona-matrix`, () =>
        HttpResponse.json({ personas: [], ttps: [], cells: [], truncated: false, total_personas: 0, null_persona_conversations: 0 })),
    );
    render(<PersonaTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-ttp-matrix-empty')).toBeInTheDocument());
    expect(screen.queryByTestId('persona-ttp-matrix-table')).toBeNull();
  });

  it('degrades to an empty note (not an error) on a 500', async () => {
    server.use(http.get(`${BASE}/ttps/persona-matrix`, () => HttpResponse.json({}, { status: 500 })));
    render(<PersonaTtpMatrix />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-ttp-matrix-empty')).toBeInTheDocument());
    expect(screen.queryByTestId('persona-ttp-matrix-table')).toBeNull();
  });
});
