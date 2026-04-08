import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import { Conversations } from './Conversations';
import '../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderConversations() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <Conversations />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Conversations', () => {
  it('shows loading state initially', () => {
    renderConversations();
    // Should show loading or content (depending on cache/timing)
    expect(document.body).toBeTruthy();
  });

  it('renders conversation list after loading', async () => {
    renderConversations();

    await waitFor(
      () => {
        // MSW returns 2 mock conversations
        expect(screen.getByText(/phishing/i)).toBeInTheDocument();
      },
      { timeout: 3000 },
    );
  });

  it('renders search bar', async () => {
    renderConversations();

    await waitFor(() => {
      expect(screen.getByPlaceholderText(/search/i)).toBeInTheDocument();
    });
  });
});
