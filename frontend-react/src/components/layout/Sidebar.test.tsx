import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { useAuthStore } from '@/store/authStore';

afterEach(() => {
  useAuthStore.setState({ isAuthenticated: false, isLoading: false, error: null });
});

function renderSidebar(route = '/') {
  return render(
    <MemoryRouter initialEntries={[route]}>
      <Sidebar />
    </MemoryRouter>,
  );
}

describe('Sidebar', () => {
  it('renders without crashing', () => {
    renderSidebar();
  });

  it('renders ScamBuster title', () => {
    renderSidebar();
    expect(screen.getByText('ScamBuster')).toBeInTheDocument();
  });

  it('renders main navigation landmark', () => {
    renderSidebar();
    expect(screen.getByRole('navigation', { name: 'Main navigation' })).toBeInTheDocument();
  });

  it('renders nav links for top-level items', () => {
    renderSidebar();
    // These are translation keys rendered via t(); in test env they show the key
    expect(screen.getByText('Impact')).toBeInTheDocument();
    expect(screen.getByText('Conversations')).toBeInTheDocument();
    expect(screen.getByText('IOC Explorer')).toBeInTheDocument();
    expect(screen.getByText('Clusters')).toBeInTheDocument();
  });

  it('renders logout button', () => {
    renderSidebar();
    expect(screen.getByText('Logout')).toBeInTheDocument();
  });

  it('renders language switcher', () => {
    renderSidebar();
    expect(screen.getByLabelText('Switch language')).toBeInTheDocument();
  });

  it('expands nav group when clicked', async () => {
    renderSidebar();
    const user = userEvent.setup();
    // Click the personas group toggle
    // "Personas" is both the group label and a child item label ("Performance")
    // The group button text is "Personas"
    const groupButtons = screen.getAllByText('Personas');
    await user.click(groupButtons[0]);
    expect(screen.getByText('Performance')).toBeInTheDocument();
    expect(screen.getByText('Convergence')).toBeInTheDocument();
  });
});
