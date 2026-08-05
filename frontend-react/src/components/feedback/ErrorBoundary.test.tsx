import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ErrorBoundary } from './ErrorBoundary';

function ThrowingComponent({ shouldThrow }: { shouldThrow: boolean }) {
  if (shouldThrow) throw new Error('Test error');
  return <div>Content rendered</div>;
}

describe('ErrorBoundary', () => {
  it('renders children when no error', () => {
    render(
      <ErrorBoundary>
        <ThrowingComponent shouldThrow={false} />
      </ErrorBoundary>,
    );
    expect(screen.getByText('Content rendered')).toBeInTheDocument();
  });

  it('shows fallback UI when child throws', () => {
    // Suppress console.error for expected error
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

    render(
      <ErrorBoundary>
        <ThrowingComponent shouldThrow={true} />
      </ErrorBoundary>,
    );

    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText('Try Again')).toBeInTheDocument();

    spy.mockRestore();
  });

  it('recovers when retry is clicked', async () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    let shouldThrow = true;

    function DynamicChild() {
      if (shouldThrow) throw new Error('Temporary error');
      return <div>Recovered</div>;
    }

    const { rerender } = render(
      <ErrorBoundary>
        <DynamicChild />
      </ErrorBoundary>,
    );

    expect(screen.getByText('Something went wrong')).toBeInTheDocument();

    shouldThrow = false;
    const user = userEvent.setup();
    await user.click(screen.getByText('Try Again'));

    rerender(
      <ErrorBoundary>
        <DynamicChild />
      </ErrorBoundary>,
    );

    expect(screen.getByText('Recovered')).toBeInTheDocument();

    spy.mockRestore();
  });
});
