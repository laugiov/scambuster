import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FilterBar } from './FilterBar';

const statusOptions = [
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
];

const scamTypeOptions = [
  { value: 'PHISHING', label: 'Phishing' },
  { value: 'ROMANCE', label: 'Romance' },
];

const defaultProps = {
  statusFilter: '',
  scamTypeFilter: '',
  onStatusChange: vi.fn(),
  onScamTypeChange: vi.fn(),
  statusOptions,
  scamTypeOptions,
  onClear: vi.fn(),
  hasActiveFilters: false,
};

describe('FilterBar', () => {
  it('renders without crashing', () => {
    render(<FilterBar {...defaultProps} />);
  });

  it('renders two select elements', () => {
    render(<FilterBar {...defaultProps} />);
    const selects = screen.getAllByRole('combobox');
    expect(selects).toHaveLength(2);
  });

  it('renders status options', () => {
    render(<FilterBar {...defaultProps} />);
    expect(screen.getByText('Open')).toBeInTheDocument();
    expect(screen.getByText('Closed')).toBeInTheDocument();
  });

  it('renders scam type options', () => {
    render(<FilterBar {...defaultProps} />);
    expect(screen.getByText('Phishing')).toBeInTheDocument();
    expect(screen.getByText('Romance')).toBeInTheDocument();
  });

  it('does not show clear button when no active filters', () => {
    render(<FilterBar {...defaultProps} hasActiveFilters={false} />);
    expect(screen.queryByText('Clear filters')).not.toBeInTheDocument();
  });

  it('shows clear button when filters are active', () => {
    render(<FilterBar {...defaultProps} hasActiveFilters={true} />);
    expect(screen.getByText('Clear filters')).toBeInTheDocument();
  });

  it('calls onClear when clear button is clicked', async () => {
    const onClear = vi.fn();
    render(<FilterBar {...defaultProps} hasActiveFilters={true} onClear={onClear} />);
    const user = userEvent.setup();
    await user.click(screen.getByText('Clear filters'));
    expect(onClear).toHaveBeenCalledOnce();
  });

  it('calls onStatusChange when status select changes', async () => {
    const onStatusChange = vi.fn();
    render(<FilterBar {...defaultProps} onStatusChange={onStatusChange} />);
    const user = userEvent.setup();
    const selects = screen.getAllByRole('combobox');
    await user.selectOptions(selects[0], 'open');
    expect(onStatusChange).toHaveBeenCalledWith('open');
  });

  it('calls onScamTypeChange when scam type select changes', async () => {
    const onScamTypeChange = vi.fn();
    render(<FilterBar {...defaultProps} onScamTypeChange={onScamTypeChange} />);
    const user = userEvent.setup();
    const selects = screen.getAllByRole('combobox');
    await user.selectOptions(selects[1], 'PHISHING');
    expect(onScamTypeChange).toHaveBeenCalledWith('PHISHING');
  });
});
