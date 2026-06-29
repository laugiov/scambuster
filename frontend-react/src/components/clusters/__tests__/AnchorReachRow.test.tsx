import { describe, it, expect, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { AnchorReachRow } from '../AnchorReachRow';

const baseAnchor = {
  indicator_id: 'ind-1',
  ioc_type: 'bank_account',
  ioc_value: '808-244517-8804',
  conv_count: 16,
  dominant_semantic_role: null,
  dominant_stimulus: null,
  avg_urgency_score: null,
};

describe('AnchorReachRow', () => {
  it('renders the reach bar at conv_count / totalConversations', () => {
    const { container } = render(
      <AnchorReachRow
        anchor={baseAnchor}
        totalConversations={39}
        isSelected={false}
        onSelect={() => {}}
        onOpenDetail={() => {}}
      />,
    );
    const fill = container.querySelector('[data-reach-pct]');
    expect(fill?.getAttribute('data-reach-pct')).toBe(String(Math.round((16 / 39) * 100)));
  });

  it('renders 100% when an anchor covers every conversation', () => {
    const { container } = render(
      <AnchorReachRow
        anchor={{ ...baseAnchor, conv_count: 3 }}
        totalConversations={3}
        isSelected={false}
        onSelect={() => {}}
        onOpenDetail={() => {}}
      />,
    );
    expect(container.querySelector('[data-reach-pct]')?.getAttribute('data-reach-pct')).toBe('100');
  });

  it('renders 0% when totalConversations is 0 (defensive)', () => {
    const { container } = render(
      <AnchorReachRow
        anchor={{ ...baseAnchor, conv_count: 5 }}
        totalConversations={0}
        isSelected={false}
        onSelect={() => {}}
        onOpenDetail={() => {}}
      />,
    );
    expect(container.querySelector('[data-reach-pct]')?.getAttribute('data-reach-pct')).toBe('0');
  });

  it('triggers onSelect when the row is clicked', () => {
    const onSelect = vi.fn();
    render(
      <AnchorReachRow
        anchor={baseAnchor}
        totalConversations={3}
        isSelected={false}
        onSelect={onSelect}
        onOpenDetail={() => {}}
      />,
    );
    fireEvent.click(screen.getByTestId('anchor-reach-row'));
    expect(onSelect).toHaveBeenCalledWith('ind-1');
  });

  it('triggers onOpenDetail when the icon button is clicked, not onSelect', () => {
    const onSelect = vi.fn();
    const onOpenDetail = vi.fn();
    render(
      <AnchorReachRow
        anchor={baseAnchor}
        totalConversations={3}
        isSelected={false}
        onSelect={onSelect}
        onOpenDetail={onOpenDetail}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /view IOC details/i }));
    expect(onOpenDetail).toHaveBeenCalledWith('ind-1');
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('renders the conv. count textually', () => {
    render(
      <AnchorReachRow
        anchor={baseAnchor}
        totalConversations={39}
        isSelected={false}
        onSelect={() => {}}
        onOpenDetail={() => {}}
      />,
    );
    expect(screen.getByText(/16 conv\./)).toBeDefined();
  });
});
