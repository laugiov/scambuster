import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SubTabNav } from './SubTabNav';

const TABS = [
  { id: 'matrix', label: 'Matrix' },
  { id: 'sequences', label: 'Sequences' },
  { id: 'phases', label: 'Phase transitions' },
];

describe('SubTabNav', () => {
  it('renders one button per tab with its label and a stable testid', () => {
    render(<SubTabNav tabs={TABS} active="matrix" onSelect={() => {}} />);

    expect(screen.getByTestId('ttp-subtab-matrix')).toHaveTextContent('Matrix');
    expect(screen.getByTestId('ttp-subtab-sequences')).toHaveTextContent('Sequences');
    expect(screen.getByTestId('ttp-subtab-phases')).toHaveTextContent('Phase transitions');
  });

  it('marks only the active tab as current', () => {
    render(<SubTabNav tabs={TABS} active="sequences" onSelect={() => {}} />);

    expect(screen.getByTestId('ttp-subtab-sequences')).toHaveAttribute('aria-current', 'true');
    expect(screen.getByTestId('ttp-subtab-matrix')).not.toHaveAttribute('aria-current');
    expect(screen.getByTestId('ttp-subtab-phases')).not.toHaveAttribute('aria-current');
  });

  it('fires onSelect with the tab id when a tab is clicked', () => {
    const onSelect = vi.fn();
    render(<SubTabNav tabs={TABS} active="matrix" onSelect={onSelect} />);

    fireEvent.click(screen.getByTestId('ttp-subtab-phases'));
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect).toHaveBeenCalledWith('phases');
  });
});
