import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import { Analytics } from './Analytics';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderAnalytics() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <Analytics />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Analytics', () => {
  it('renders analytics page after loading', async () => {
    renderAnalytics();
    await waitFor(
      () => {
        expect(document.body.textContent!.length).toBeGreaterThan(20);
      },
      { timeout: 3000 },
    );
  });

  it('renders content after data loads', async () => {
    renderAnalytics();
    await waitFor(
      () => {
        expect(document.body.textContent!.length).toBeGreaterThan(50);
      },
      { timeout: 3000 },
    );
  });
});
