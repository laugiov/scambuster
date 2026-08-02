import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ErrorMessage } from './ErrorMessage';

describe('ErrorMessage', () => {
  it('renders without crashing', () => {
    render(<ErrorMessage message="Something failed" />);
  });

  it('displays the message', () => {
    render(<ErrorMessage message="Network error" />);
    expect(screen.getByText('Network error')).toBeInTheDocument();
  });

  it('has role="alert"', () => {
    render(<ErrorMessage message="Error" />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  it('uses default title from i18n when title not provided', () => {
    render(<ErrorMessage message="fail" />);
    // In test env, t('Error') returns the key
    expect(screen.getByText('Error')).toBeInTheDocument();
  });

  it('uses custom title when provided', () => {
    render(<ErrorMessage title="Custom Title" message="fail" />);
    expect(screen.getByText('Custom Title')).toBeInTheDocument();
    expect(screen.queryByText('Error')).not.toBeInTheDocument();
  });

  it('does not show retry button when onRetry not provided', () => {
    render(<ErrorMessage message="fail" />);
    expect(screen.queryByText('Retry')).not.toBeInTheDocument();
  });

  it('shows retry button when onRetry provided', () => {
    render(<ErrorMessage message="fail" onRetry={vi.fn()} />);
    expect(screen.getByText('Retry')).toBeInTheDocument();
  });

  it('calls onRetry when retry button clicked', async () => {
    const onRetry = vi.fn();
    render(<ErrorMessage message="fail" onRetry={onRetry} />);
    const user = userEvent.setup();
    await user.click(screen.getByText('Retry'));
    expect(onRetry).toHaveBeenCalledOnce();
  });
});
