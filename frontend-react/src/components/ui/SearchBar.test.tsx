import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchBar } from './SearchBar';

describe('SearchBar', () => {
  it('renders with placeholder', () => {
    render(<SearchBar value="" onChange={() => {}} placeholder="Search..." />);
    expect(screen.getByPlaceholderText('Search...')).toBeInTheDocument();
  });

  it('calls onChange when typing', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(<SearchBar value="" onChange={onChange} />);

    const input = screen.getByRole('textbox');
    await user.type(input, 'test');

    expect(onChange).toHaveBeenCalled();
  });

  it('displays the current value', () => {
    render(<SearchBar value="current search" onChange={() => {}} />);
    expect(screen.getByDisplayValue('current search')).toBeInTheDocument();
  });
});
