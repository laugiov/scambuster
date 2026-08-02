import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchBar } from './SearchBar';

describe('SearchBar', () => {
  it('renders without crashing', () => {
    render(<SearchBar value="" onChange={vi.fn()} />);
  });

  it('renders input with value', () => {
    render(<SearchBar value="test query" onChange={vi.fn()} />);
    const input = screen.getByRole('textbox');
    expect(input).toHaveValue('test query');
  });

  it('uses default placeholder', () => {
    render(<SearchBar value="" onChange={vi.fn()} />);
    expect(screen.getByPlaceholderText('Search...')).toBeInTheDocument();
  });

  it('uses custom placeholder', () => {
    render(<SearchBar value="" onChange={vi.fn()} placeholder="Find IOCs..." />);
    expect(screen.getByPlaceholderText('Find IOCs...')).toBeInTheDocument();
  });

  it('uses default aria-label', () => {
    render(<SearchBar value="" onChange={vi.fn()} />);
    expect(screen.getByLabelText('Search')).toBeInTheDocument();
  });

  it('uses custom aria-label', () => {
    render(<SearchBar value="" onChange={vi.fn()} ariaLabel="Search conversations" />);
    expect(screen.getByLabelText('Search conversations')).toBeInTheDocument();
  });

  it('calls onChange when user types', async () => {
    const onChange = vi.fn();
    render(<SearchBar value="" onChange={onChange} />);
    const user = userEvent.setup();
    await user.type(screen.getByRole('textbox'), 'a');
    expect(onChange).toHaveBeenCalledWith('a');
  });
});
