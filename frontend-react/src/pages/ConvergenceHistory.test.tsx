import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import { ConvergenceHistory } from './ConvergenceHistory';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ConvergenceHistory />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ConvergenceHistory', () => {
  it('renders convergence history page', async () => {
    renderPage();
    await waitFor(
      () => {
        expect(screen.getByText(/convergence|history/i)).toBeInTheDocument();
      },
      { timeout: 3000 },
    );
  });
});
