import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { Campaigns } from './Campaigns';

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

function setupHandlers() {
  server.use(
    http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({ candidates: mockCandidates })),
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

describe('Campaigns', () => {
  it('renders without crashing', async () => {
    setupHandlers();
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent?.length).toBeGreaterThan(0);
    });
  });

  it('displays the page title', async () => {
    setupHandlers();
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Campaign/i)).toBeInTheDocument();
    });
  });

  it('shows loading state', () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, async () => {
        await new Promise((r) => setTimeout(r, 5000));
        return HttpResponse.json(mockCandidates);
      }),
    );
    render(<Campaigns />, { wrapper: createWrapper() });
    expect(document.body.textContent).toMatch(/loading/i);
  });

  it('renders stat cards', async () => {
    setupHandlers();
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getAllByText('1').length).toBeGreaterThan(0); // detected campaigns
      expect(screen.getAllByText('12').length).toBeGreaterThan(0); // total hits
    });
  });

  it('renders campaign table with data', async () => {
    setupHandlers();
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/camp-aaa/)).toBeInTheDocument();
      expect(screen.getAllByText(/92\.0%?/).length).toBeGreaterThan(0);
    });
  });

  it('shows empty state when no campaigns', async () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, () => HttpResponse.json({ candidates: [] })),
    );
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/No campaigns detected|hunting pipeline/i)).toBeInTheDocument();
    });
  });

  it('shows error state when request fails', async () => {
    server.use(
      http.get(`${BASE}/campaign/candidates`, () =>
        HttpResponse.json({ error: 'fail' }, { status: 500 }),
      ),
    );
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(document.body.textContent).toMatch(/error|fail/i);
    });
  });

  it('renders the hunt button', async () => {
    setupHandlers();
    render(<Campaigns />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Run Hunt|Hunt/i })).toBeInTheDocument();
    });
  });
});
