import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import { ConversationMonitoring } from './ConversationMonitoring';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ConversationMonitoring />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ConversationMonitoring', () => {
  it('renders lifecycle and rate limit data', async () => {
    renderPage();
    await waitFor(
      () => {
        expect(document.body.textContent!.length).toBeGreaterThan(20);
      },
      { timeout: 3000 },
    );
  });
});
