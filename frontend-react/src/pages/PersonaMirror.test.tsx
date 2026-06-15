import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import PersonaMirror from './PersonaMirror';

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

describe('PersonaMirror (spec 104 P3)', () => {
  it('renders the mirror cell when matrix has a winner AND the mirror cache has the row', async () => {
    server.use(
      // Matrix: grandma wins PHISHING with 10 sessions @ 0.75
      http.get(`${BASE}/scambaiting/persona-matrix`, () =>
        HttpResponse.json({
          success: true,
          data: [
            { persona_code: 'grandma', persona_label: 'Grandma', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 10, reward_avg: 0.75 },
          ],
        }),
      ),
      // Mirror cache: grandma × PHISHING has been generated
      http.get(`${BASE}/personas/grandma/mirrors`, () =>
        HttpResponse.json({
          success: true,
          data: {
            persona_code: 'grandma',
            mirrors: [
              {
                scam_type_code: 'PHISHING',
                scam_type_label: 'Phishing',
                hunted_victim_profile: 'Elderly people who trust authority',
                cognitive_lever: 'trust + authority',
                mirror_explanation: 'The persona mirrors a senior who defers to perceived banking authority.',
                generated_at: '2026-06-15 12:00:00',
                generated_by_model: 'gpt-4o-mini',
                prompt_version: 'v1',
              },
            ],
          },
        }),
      ),
    );

    render(<PersonaMirror />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-mirror-cell')).toBeInTheDocument());
    expect(screen.getByText(/Grandma/)).toBeInTheDocument();
    expect(screen.getByText('trust + authority')).toBeInTheDocument();
    expect(screen.getByText(/defers to perceived banking authority/)).toBeInTheDocument();
    expect(screen.getByText(/Generated 2026-06-15/i)).toBeInTheDocument();
  });

  it('shows the no-winner state when no cell qualifies for a scam type', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona-matrix`, () =>
        HttpResponse.json({
          success: true,
          data: [
            { persona_code: 'grandma', persona_label: 'Grandma', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 1, reward_avg: 0.5 },
          ],
        }),
      ),
    );

    render(<PersonaMirror />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-mirror-no-winner')).toBeInTheDocument());
  });

  it('shows generation-pending when winner exists but the mirror cache is empty', async () => {
    server.use(
      http.get(`${BASE}/scambaiting/persona-matrix`, () =>
        HttpResponse.json({
          success: true,
          data: [
            { persona_code: 'grandma', persona_label: 'Grandma', scam_type_code: 'PHISHING', scam_type_label: 'Phishing', sessions: 10, reward_avg: 0.75 },
          ],
        }),
      ),
      // Empty mirror cache for this persona
      http.get(`${BASE}/personas/grandma/mirrors`, () =>
        HttpResponse.json({
          success: true,
          data: { persona_code: 'grandma', mirrors: [] },
        }),
      ),
    );

    render(<PersonaMirror />, { wrapper: createWrapper() });

    await waitFor(() => expect(screen.getByTestId('persona-mirror-pending')).toBeInTheDocument());
  });
});
