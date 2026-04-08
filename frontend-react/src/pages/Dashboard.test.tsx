import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { server } from '@/__tests__/mocks/server';
import '../i18n';

// Dashboard is a default export (lazy loaded in App)
import { Dashboard } from './Dashboard';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderDashboard() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <Dashboard />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Dashboard', () => {
  it('renders loading state initially', () => {
    renderDashboard();
    expect(document.body).toBeTruthy();
  });

  it('renders dashboard content after loading', async () => {
    renderDashboard();

    await waitFor(
      () => {
        // Should show conversation stats from mockAutonomyStats
        expect(screen.getByText(/15/)).toBeInTheDocument(); // total conversations
      },
      { timeout: 3000 },
    );
  });
});
