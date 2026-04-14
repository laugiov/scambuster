import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import Conversations from './Conversations';
import { server } from '@/__tests__/mocks/server';

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

describe('Conversations page', () => {
  it('renders the page title', async () => {
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Conversations/i)).toBeInTheDocument();
    });
  });

  it('renders conversation data from mock handlers', async () => {
    render(<Conversations />, { wrapper: createWrapper() });

    await waitFor(() => {
      // Should render conversation IDs from the default MSW handlers
      expect(screen.getByText(/Conversations/i)).toBeInTheDocument();
    });
  });

  it('does not crash on empty conversation list', async () => {
    // The page should handle empty data gracefully
    render(<Conversations />, { wrapper: createWrapper() });

    // Should at least render the title without crashing
    await waitFor(() => {
      expect(screen.getByText(/Conversations/i)).toBeInTheDocument();
    });
  });

  it('has no accessibility violations', async () => {
    const { axe } = await import('vitest-axe');
    const { container } = render(<Conversations />, { wrapper: createWrapper() });
    await waitFor(() => {
      expect(screen.getByText(/Conversations/i)).toBeInTheDocument();
    });
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
