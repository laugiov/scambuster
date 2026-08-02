import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import LlmCosts from './LlmCosts';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';

const BASE = '/api/v1';

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={qc}>
        <MemoryRouter>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };
}

beforeAll(() => server.listen({ onUnhandledRequest: 'warn' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('LlmCosts page', () => {
  beforeEach(() => {
    // Default: kill switch inactive
    server.use(
      http.get(`${BASE}/admin/llm/killswitch`, () =>
        HttpResponse.json({ active: false }),
      ),
    );
  });

  it('renders the budget bar and stat cards', async () => {
    render(<LlmCosts />, { wrapper: createWrapper() });

    await screen.findByText(/LLM Cost Monitor/i);
    expect(screen.getByText(/Monthly Cost/i)).toBeInTheDocument();
    expect(screen.getByText(/API Calls/i)).toBeInTheDocument();
  });

  it('shows within budget badge when pct_used < 80', async () => {
    render(<LlmCosts />, { wrapper: createWrapper() });

    await screen.findByText(/Within Budget/i);
  });

  it('renders the kill switch toggle button', async () => {
    render(<LlmCosts />, { wrapper: createWrapper() });

    // The button text depends on the kill switch state (inactive = "Activate")
    await screen.findByText(/kill switch/i);
  });

  it('shows kill switch banner when active', async () => {
    server.use(
      http.get(`${BASE}/admin/llm/killswitch`, () =>
        HttpResponse.json({ active: true }),
      ),
    );

    render(<LlmCosts />, { wrapper: createWrapper() });

    await screen.findByText(/ACTIF|ACTIVE/i);
  });

  it('renders daily trend section', async () => {
    render(<LlmCosts />, { wrapper: createWrapper() });

    await screen.findByText(/Daily Trend/i);
  });
});
