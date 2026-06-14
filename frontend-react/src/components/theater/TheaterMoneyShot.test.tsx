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
  // Spec 101 S1 — fixtures use 1-based idx, matching the backend
  // contract in TheaterAssemblyService::serializeMessages.
  const messages = [msg(1, 'm-1'), msg(2, 'm-2'), msg(3, 'm-3')];

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
    // visibleStep=3 reveals all 3 messages → both IOCs visible.
    renderShot(iocs, messages, 3);
    expect(screen.getByTestId('money-shot-card-iban')).toBeTruthy();
    expect(screen.getByTestId('money-shot-card-bic')).toBeTruthy();
  });

  it('does not render IOCs whose parent message is not yet revealed', () => {
    const iocs = [
      ioc('1', 'iban', 'm-3'), // idx 3 — not visible at step 1
    ];
    renderShot(iocs, messages, 1);
    expect(screen.queryByTestId('money-shot-card-iban')).toBeNull();
  });

  it('shows the revealed-at-turn ratio in the card text', () => {
    const iocs = [ioc('1', 'iban', 'm-3')];
    renderShot(iocs, messages, 3);
    // 3 messages total, IOC parent idx=3 → turn 3 of 3 = 100%
    expect(screen.getByText(/Revealed at turn 3\/3 — 100%/i)).toBeTruthy();
  });

  it('skips non-financial IOCs even if their parent is visible', () => {
    const iocs = [
      ioc('1', 'phone', 'm-1'),
      ioc('2', 'url', 'm-2'),
      ioc('3', 'iban', 'm-3'),
    ];
    renderShot(iocs, messages, 3);
    // Only the IBAN should produce a card; phone + url stay in the catalog.
    expect(screen.queryByTestId('money-shot-card-phone')).toBeNull();
    expect(screen.queryByTestId('money-shot-card-url')).toBeNull();
    expect(screen.getByTestId('money-shot-card-iban')).toBeTruthy();
  });

  it('orders multiple IOCs by reveal turn (earliest first)', () => {
    const iocs = [
      ioc('late', 'bic', 'm-3'),    // idx 3
      ioc('early', 'iban', 'm-1'),  // idx 1
    ];
    renderShot(iocs, messages, 3);
    const cards = screen.getAllByTestId(/^money-shot-card-/);
    expect(cards[0].getAttribute('data-testid')).toBe('money-shot-card-iban');
    expect(cards[1].getAttribute('data-testid')).toBe('money-shot-card-bic');
  });

  // Spec 101 S1 — regression: the ratio must reflect the actual
  // message position in the conversation. Backend serialises `idx`
  // as 1-based (1..N); the frontend must not add another +1 on top.
  it('Spec 101 S1: computes ratio correctly when financial reveals before the last message', () => {
    // 9-message conversation, financial IOC on message at position 8/9.
    // Expected: turn 8/9 = 89%, NOT 9/9 = 100% (the pre-S1 bug).
    const nineMessages: TheaterMessage[] = Array.from({ length: 9 }, (_, i) => ({
      idx: i + 1, // 1-based, matching backend serialisation
      msg_id: `m-${i + 1}`,
      direction: 'in',
      ts_msg: '2026-06-14T00:00:00Z',
      sender: 'x@example.com',
      subject: 'Re',
      body_text: 'body',
      lang_detect: 'en',
    }));

    const iocs = [
      {
        indicator_id: 'fin-1',
        type: 'bank_account',
        value: '259711650852',
        value_norm: '259711650852',
        category: 'financial',
        msg_id: 'm-8', // 8th message of 9 → 89%
        msg_idx: 8,
        revelation_context: undefined,
      } as TheaterIoc,
    ];

    renderShot(iocs, nineMessages, 9);
    expect(screen.getByText(/Revealed at turn 8\/9 — 89%/i)).toBeTruthy();
    // And NOT the buggy form
    expect(screen.queryByText(/9\/9 — 100%/i)).toBeNull();
  });
});
