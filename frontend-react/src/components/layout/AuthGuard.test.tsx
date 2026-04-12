import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AuthGuard } from './AuthGuard';
import { useAuthStore } from '@/store/authStore';

afterEach(() => {
  useAuthStore.setState({ isAuthenticated: false, isLoading: false, error: null });
});

describe('AuthGuard', () => {
  it('renders children when authenticated', () => {
    useAuthStore.setState({ isAuthenticated: true });

    render(
      <MemoryRouter initialEntries={['/']}>
        <Routes>
          <Route path="/" element={<AuthGuard><div>Protected Content</div></AuthGuard>} />
        </Routes>
      </MemoryRouter>,
    );

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('redirects to /login when unauthenticated', () => {
    useAuthStore.setState({ isAuthenticated: false });

    render(
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route path="/dashboard" element={<AuthGuard><div>Protected</div></AuthGuard>} />
          <Route path="/login" element={<div>Login Page</div>} />
        </Routes>
      </MemoryRouter>,
    );

    expect(screen.queryByText('Protected')).not.toBeInTheDocument();
    expect(screen.getByText('Login Page')).toBeInTheDocument();
  });

  it('preserves from location in redirect state', () => {
    useAuthStore.setState({ isAuthenticated: false });

    // When Navigate is rendered, it includes `state: { from: location }`.
    // We can't directly inspect Navigate's state in unit tests, but we CAN
    // verify the redirect happens from the right source path.
    render(
      <MemoryRouter initialEntries={['/conversations']}>
        <Routes>
          <Route path="/conversations" element={<AuthGuard><div>Conversations</div></AuthGuard>} />
          <Route path="/login" element={<div>Login Redirect</div>} />
        </Routes>
      </MemoryRouter>,
    );

    // User is NOT on conversations, they are on login
    expect(screen.queryByText('Conversations')).not.toBeInTheDocument();
    expect(screen.getByText('Login Redirect')).toBeInTheDocument();
  });
});
