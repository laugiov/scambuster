import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import PersonaMatrix from './PersonaMatrix';

const BASE = '/api/v1';

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

describe('PersonaMatrix', () => {
  it('renders winner highlight only on cells with >= 3 sessions', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona-matrix`, () =>
        HttpResponse.json({
          success: true,
          data: [
            // grandmother wins PHISHING with 10 sessions @ 0.75 — qualifying
            { persona_code: 'grandma', persona_label: 'Grandma', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 10, reward_avg: 0.75 },
            // banker has higher reward (0.95) on PHISHING but only 1 session — NOT a winner
            { persona_code: 'banker', persona_label: 'Banker', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 1, reward_avg: 0.95 },
            // grandma on ROMANCE 5 sessions @ 0.40 — qualifying
            { persona_code: 'grandma', persona_label: 'Grandma', scam_type_code: 'ROMANCE', scam_type_label: 'Romance', sessions: 5, reward_avg: 0.40 },
          ],
        }),
      ),
    );

    render(<PersonaMatrix />, { wrapper: createWrapper() });

    // Wait for the matrix to render
    await waitFor(() => expect(screen.getByTestId('persona-matrix-table')).toBeInTheDocument());

    // The qualifying winner cell (Grandma × PHISHING at 0.75) must carry the
    // emerald winner styling; the cold-start cell (Banker × PHISHING at 0.95)
    // must not.
    const winnerCell = screen.getAllByText('0.75')[0];
    expect(winnerCell.className).toMatch(/emerald/i);

    const coldStartCell = screen.getAllByText('0.95')[0];
    expect(coldStartCell.className).not.toMatch(/emerald/i);
    expect(coldStartCell.className).toMatch(/italic/i);
  });

  it('shows the "not enough data" header when no cell qualifies in a column', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona-matrix`, () =>
        HttpResponse.json({
          success: true,
          data: [
            // Both cells under 3 sessions
            { persona_code: 'grandma', persona_label: 'Grandma', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 1, reward_avg: 0.5 },
            { persona_code: 'banker', persona_label: 'Banker', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 2, reward_avg: 0.8 },
          ],
        }),
      ),
    );

    render(<PersonaMatrix />, { wrapper: createWrapper() });
    await waitFor(() => expect(screen.getByTestId('persona-matrix-table')).toBeInTheDocument());

    expect(screen.getByText(/not enough data/i)).toBeInTheDocument();
  });

  it('renders the empty-state message when no rows', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona-matrix`, () =>
        HttpResponse.json({ success: true, data: [] }),
      ),
    );

    render(<PersonaMatrix />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/no active personas/i)).toBeInTheDocument();
    });
  });
});
