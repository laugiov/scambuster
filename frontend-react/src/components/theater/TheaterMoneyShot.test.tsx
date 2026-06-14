import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TheaterMoneyShot } from './TheaterMoneyShot';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import type { TheaterIoc, TheaterMessage } from '@/hooks/useTheaterReplay';

beforeEach(() => {
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
});

function msg(idx: number, msgId: string): TheaterMessage {
  return {
    idx,
    msg_id: msgId,
    direction: 'in',
    ts_msg: '2026-06-14T00:00:00Z',
    sender: 'x@example.com',
    subject: 'Re',
    body_text: 'body',
    lang_detect: 'en',
  };
}

function ioc(indicatorId: string, type: string, msgId: string): TheaterIoc {
  return {
    indicator_id: indicatorId,
    type,
    value: 'val',
    value_norm: 'val',
    category: 'financial',
    msg_id: msgId,
    msg_idx: 1,
    revelation_context: undefined,
  };
}

function renderShot(iocs: TheaterIoc[], messages: TheaterMessage[], visibleStep: number) {
  return render(
    <MaskModeProvider>
      <TheaterMoneyShot iocs={iocs} messages={messages} visibleStep={visibleStep} />
    </MaskModeProvider>,
  );
}

describe('TheaterMoneyShot — Spec 100 S1 pinned financial banner', () => {
  const messages = [msg(0, 'm-1'), msg(1, 'm-2'), msg(2, 'm-3')];

  it('renders nothing when no financial IOC has revealed yet', () => {
    const iocs = [
      ioc('1', 'iban', 'm-3'),
      ioc('2', 'phone', 'm-1'),
    ];
    const { container } = renderShot(iocs, messages, 0);
    expect(container.querySelector('[data-testid="theater-money-shot"]')).toBeNull();
  });

  it('renders a money-shot card for each financial IOC that has revealed', () => {
    const iocs = [
      ioc('1', 'iban', 'm-1'),
      ioc('2', 'bic', 'm-3'),
    ];
    renderShot(iocs, messages, 2);
    // m-1 (idx 0) revealed at step 0 ≤ 2 ✓
    // m-3 (idx 2) revealed at step 2 ≤ 2 ✓
    expect(screen.getByTestId('money-shot-card-iban')).toBeTruthy();
    expect(screen.getByTestId('money-shot-card-bic')).toBeTruthy();
  });

  it('does not render IOCs whose parent message is not yet revealed', () => {
    const iocs = [
      ioc('1', 'iban', 'm-3'), // idx 2 — not yet visible at step 1
    ];
    renderShot(iocs, messages, 1);
    expect(screen.queryByTestId('money-shot-card-iban')).toBeNull();
  });

  it('shows the revealed-at-turn ratio in the card text', () => {
    const iocs = [ioc('1', 'iban', 'm-3')];
    renderShot(iocs, messages, 2);
    // 3 messages total, IOC parent idx=2 → turn 3 of 3 = 100%
    expect(screen.getByText(/Revealed at turn 3\/3 — 100%/i)).toBeTruthy();
  });

  it('skips non-financial IOCs even if their parent is visible', () => {
    const iocs = [
      ioc('1', 'phone', 'm-1'),
      ioc('2', 'url', 'm-2'),
      ioc('3', 'iban', 'm-3'),
    ];
    renderShot(iocs, messages, 2);
    // Only the IBAN should produce a card; phone + url stay in the catalog.
    expect(screen.queryByTestId('money-shot-card-phone')).toBeNull();
    expect(screen.queryByTestId('money-shot-card-url')).toBeNull();
    expect(screen.getByTestId('money-shot-card-iban')).toBeTruthy();
  });

  it('orders multiple IOCs by reveal turn (earliest first)', () => {
    const iocs = [
      ioc('late', 'bic', 'm-3'),    // idx 2
      ioc('early', 'iban', 'm-1'),  // idx 0
    ];
    renderShot(iocs, messages, 2);
    const cards = screen.getAllByTestId(/^money-shot-card-/);
    expect(cards[0].getAttribute('data-testid')).toBe('money-shot-card-iban');
    expect(cards[1].getAttribute('data-testid')).toBe('money-shot-card-bic');
  });
});
