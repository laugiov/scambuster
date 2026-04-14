import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Pagination } from './Pagination';

describe('Pagination', () => {
  it('renders nothing when totalItems <= pageSize', () => {
    const { container } = render(
      <Pagination page={1} pageSize={20} totalItems={10} onPageChange={vi.fn()} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders when totalItems > pageSize', () => {
    render(<Pagination page={1} pageSize={10} totalItems={25} onPageChange={vi.fn()} />);
    expect(screen.getByText('1 / 3')).toBeInTheDocument();
  });

  it('shows correct range text', () => {
    render(<Pagination page={2} pageSize={10} totalItems={25} onPageChange={vi.fn()} />);
    expect(screen.getByText('11-20 / 25')).toBeInTheDocument();
  });

  it('shows correct range on last page', () => {
    render(<Pagination page={3} pageSize={10} totalItems={25} onPageChange={vi.fn()} />);
    expect(screen.getByText('21-25 / 25')).toBeInTheDocument();
  });

  it('disables previous button on first page', () => {
    render(<Pagination page={1} pageSize={10} totalItems={30} onPageChange={vi.fn()} />);
    const buttons = screen.getAllByRole('button');
    expect(buttons[0]).toBeDisabled();
  });

  it('disables next button on last page', () => {
    render(<Pagination page={3} pageSize={10} totalItems={30} onPageChange={vi.fn()} />);
    const buttons = screen.getAllByRole('button');
    expect(buttons[1]).toBeDisabled();
  });

  it('calls onPageChange with page-1 when previous clicked', async () => {
    const onPageChange = vi.fn();
    render(<Pagination page={2} pageSize={10} totalItems={30} onPageChange={onPageChange} />);
    const user = userEvent.setup();
    const buttons = screen.getAllByRole('button');
    await user.click(buttons[0]);
    expect(onPageChange).toHaveBeenCalledWith(1);
  });

  it('calls onPageChange with page+1 when next clicked', async () => {
    const onPageChange = vi.fn();
    render(<Pagination page={1} pageSize={10} totalItems={30} onPageChange={onPageChange} />);
    const user = userEvent.setup();
    const buttons = screen.getAllByRole('button');
    await user.click(buttons[1]);
    expect(onPageChange).toHaveBeenCalledWith(2);
  });
});
