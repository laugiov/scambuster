import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import { ConversationDetail } from './ConversationDetail';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderConversationDetail() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/conversations/aaaa-bbbb-cccc-dddd']}>
        <Routes>
          <Route path="/conversations/:id" element={<ConversationDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ConversationDetail', () => {
  it('renders conversation detail page', async () => {
    renderConversationDetail();
    await waitFor(
      () => {
        // Should show conversation data (status, persona, etc.)
        expect(document.body.textContent!.length).toBeGreaterThan(20);
      },
      { timeout: 3000 },
    );
  });

  it('renders content after data loads', async () => {
    renderConversationDetail();
    await waitFor(
      () => {
        expect(document.body.textContent!.length).toBeGreaterThan(50);
      },
      { timeout: 3000 },
    );
  });
});
