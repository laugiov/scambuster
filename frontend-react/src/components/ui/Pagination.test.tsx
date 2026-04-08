import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Pagination } from './Pagination';
import '../../i18n';

describe('Pagination', () => {
  it('renders page info when items exceed page size', () => {
    render(<Pagination page={1} pageSize={10} totalItems={50} onPageChange={() => {}} />);
    expect(screen.getByText('1 / 5')).toBeInTheDocument();
  });

  it('calls onPageChange when clicking next', async () => {
    const onPageChange = vi.fn();
    const user = userEvent.setup();
    render(<Pagination page={1} pageSize={10} totalItems={50} onPageChange={onPageChange} />);

    const nextButton = screen.getByLabelText('Next page');
    await user.click(nextButton);

    expect(onPageChange).toHaveBeenCalledWith(2);
  });

  it('disables previous button on first page', () => {
    render(<Pagination page={1} pageSize={10} totalItems={50} onPageChange={() => {}} />);
    const buttons = screen.getAllByRole('button');
    expect(buttons[0]).toBeDisabled();
  });

  it('returns null when items fit in one page', () => {
    const { container } = render(
      <Pagination page={1} pageSize={10} totalItems={5} onPageChange={() => {}} />,
    );
    expect(container.innerHTML).toBe('');
  });
});
