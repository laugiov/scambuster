import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import { StixExport } from './StixExport';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <StixExport />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('StixExport', () => {
  it('renders STIX export page', async () => {
    renderPage();
    await waitFor(
      () => {
        expect(screen.getByText(/stix|export/i)).toBeInTheDocument();
      },
      { timeout: 3000 },
    );
  });
});
