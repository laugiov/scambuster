import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Login } from './Login';
import { server } from '@/__tests__/mocks/server';
import { http, HttpResponse } from 'msw';
import { useAuthStore } from '@/store/authStore';

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
afterEach(() => {
  server.resetHandlers();
  useAuthStore.setState({ isAuthenticated: false, isLoading: false, error: null });
});
afterAll(() => server.close());

describe('Login page', () => {
  it('renders form fields', () => {
    render(<Login />, { wrapper: createWrapper() });

    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('submits with valid credentials', async () => {
    const user = userEvent.setup();

    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ access_token: 'tok', refresh_token: 'ref', expires_in: 900 }),
      ),
    );

    render(<Login />, { wrapper: createWrapper() });

    await user.type(screen.getByLabelText(/email/i), 'test@example.com');
    await user.type(screen.getByLabelText(/password/i), 'password123');
    await user.click(screen.getByRole('button'));

    // After successful login, the store should be authenticated
    // (navigation happens via useNavigate which is mocked by MemoryRouter)
    await vi.waitFor(() => {
      expect(useAuthStore.getState().isAuthenticated).toBe(true);
    });
  });

  it('displays error on invalid credentials', async () => {
    const user = userEvent.setup();

    server.use(
      http.post(`${BASE}/auth/login`, () =>
        HttpResponse.json({ message: 'invalid credentials' }, { status: 401 }),
      ),
    );

    render(<Login />, { wrapper: createWrapper() });

    await user.type(screen.getByLabelText(/email/i), 'bad@example.com');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button'));

    await vi.waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });
  });

  it('shows loading state during submission', async () => {
    const user = userEvent.setup();

    // Delay the response to observe loading state
    server.use(
      http.post(`${BASE}/auth/login`, async () => {
        await new Promise((r) => setTimeout(r, 200));
        return HttpResponse.json({ access_token: 'tok', refresh_token: 'ref', expires_in: 900 });
      }),
    );

    render(<Login />, { wrapper: createWrapper() });

    await user.type(screen.getByLabelText(/email/i), 'test@example.com');
    await user.type(screen.getByLabelText(/password/i), 'password123');
    await user.click(screen.getByRole('button'));

    // Button should be disabled during loading
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('has no accessibility violations', async () => {
    const { axe } = await import('vitest-axe');
    const { container } = render(<Login />, { wrapper: createWrapper() });
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
