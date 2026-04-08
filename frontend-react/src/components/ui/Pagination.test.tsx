import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Pagination } from './Pagination';
import '../../i18n';

describe('Pagination', () => {
  it('renders page info', () => {
    render(<Pagination page={1} totalPages={5} onPageChange={() => {}} />);
    expect(screen.getByText(/1/)).toBeInTheDocument();
  });

  it('calls onPageChange when clicking next', async () => {
    const onPageChange = vi.fn();
    const user = userEvent.setup();
    render(<Pagination page={1} totalPages={5} onPageChange={onPageChange} />);

    const nextButton = screen.getByRole('button', { name: /next|suivant|→|›/i });
    await user.click(nextButton);

    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('disables previous button on first page', () => {
    render(<Pagination page={1} totalPages={5} onPageChange={() => {}} />);
    const prevButtons = screen.getAllByRole('button');
    expect(prevButtons[0]).toBeDisabled();
  });
});
