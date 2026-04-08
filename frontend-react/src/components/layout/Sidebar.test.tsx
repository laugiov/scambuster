import { describe, it, expect, beforeAll, afterAll, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { server } from '@/__tests__/mocks/server';
import { useAuthStore } from '@/store/authStore';
import { Sidebar } from './Sidebar';
import '../../i18n';

beforeAll(() => server.listen());
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

function renderSidebar() {
  useAuthStore.setState({ isAuthenticated: true });
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <Sidebar />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Sidebar', () => {
  it('renders navigation links', () => {
    renderSidebar();
    expect(screen.getByText(/conversations/i)).toBeInTheDocument();
  });

  it('renders ScamBuster brand', () => {
    renderSidebar();
    expect(screen.getByText('ScamBuster')).toBeInTheDocument();
  });

  it('renders logout button', () => {
    renderSidebar();
    expect(screen.getByText(/log\s*out|sign\s*out|déconnexion/i)).toBeInTheDocument();
  });
});
