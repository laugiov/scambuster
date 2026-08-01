import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { TheaterKillChainStrip } from './TheaterKillChainStrip';
import { PHASE_ORDER } from '@/lib/ttpLabels';
import type { TheaterMessage, TheaterTtp } from '@/hooks/useTheaterReplay';

function msg(idx: number, msgId: string): TheaterMessage {
  return {
    idx,
    msg_id: msgId,
    direction: 'in',
    ts_msg: '2026-06-14T00:00:00Z',
    sender: 'x@example.com',
    subject: null,
    body_text: 'body',
    lang_detect: 'en',
  };
}

function ttp(msgId: string, phase: string): TheaterTtp {
  return {
    msg_id: msgId,
    ttp_code: `SB-${phase}`,
    ttp_label: phase,
    phase,
    confidence: 0.9,
    status: 'confirmed',
    evidence_start: null,
    evidence_end: null,
  };
}

function renderStrip(ttps: TheaterTtp[], messages: TheaterMessage[], visibleStep: number) {
  return render(<TheaterKillChainStrip ttpsByMsg={ttps} messages={messages} visibleStep={visibleStep} />);
}

function phaseOrderInDom(container: HTMLElement): string[] {
  return Array.from(container.querySelectorAll('[data-testid^="killchain-phase-"]')).map((el) =>
    (el.getAttribute('data-testid') ?? '').replace('killchain-phase-', ''),
  );
}

describe('TheaterKillChainStrip', () => {
  const messages = [msg(1, 'm-1'), msg(2, 'm-2')];
  const ttps = [ttp('m-1', 'hook'), ttp('m-2', 'payment-request')];

  it('renders all six phases in canonical PHASE_ORDER', () => {
    const { container } = renderStrip(ttps, messages, 2);
    expect(phaseOrderInDom(container)).toEqual([...PHASE_ORDER]);
  });

  it('marks a phase reached only once its TTP parent message is revealed', () => {
    // visibleStep = 1 → m-1 (idx 1) revealed, m-2 (idx 2) not.
    const { getByTestId } = renderStrip(ttps, messages, 1);
    expect(getByTestId('killchain-phase-hook').getAttribute('data-reached')).toBe('true');
    expect(getByTestId('killchain-phase-payment-request').getAttribute('data-reached')).toBe('false');
  });

  it('fills a later phase once its message is revealed too', () => {
    const { getByTestId } = renderStrip(ttps, messages, 2);
    expect(getByTestId('killchain-phase-hook').getAttribute('data-reached')).toBe('true');
    expect(getByTestId('killchain-phase-payment-request').getAttribute('data-reached')).toBe('true');
  });

  it('marks nothing reached at visibleStep 0 but still renders the ribbon', () => {
    const { getByTestId, container } = renderStrip(ttps, messages, 0);
    expect(getByTestId('theater-killchain')).toBeTruthy();
    const nodes = container.querySelectorAll('[data-testid^="killchain-phase-"]');
    expect(nodes.length).toBe(PHASE_ORDER.length);
    for (const el of nodes) {
      expect(el.getAttribute('data-reached')).toBe('false');
    }
  });

  it('renders nothing when the conversation has no confirmed TTPs', () => {
    const { queryByTestId } = renderStrip([], messages, 2);
    expect(queryByTestId('theater-killchain')).toBeNull();
  });
});
