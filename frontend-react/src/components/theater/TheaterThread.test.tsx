import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { TheaterThread } from './TheaterThread';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import type { TheaterMessage, TheaterIoc, TheaterTtp } from '@/hooks/useTheaterReplay';

function msg(
  idx: number,
  msgId: string,
  direction: 'in' | 'out',
  bodyText: string,
  subject: string | null = null,
): TheaterMessage {
  return {
    idx,
    msg_id: msgId,
    direction,
    ts_msg: '2026-06-14T00:00:00Z',
    sender: 'scammer@example.com',
    subject,
    body_text: bodyText,
    lang_detect: 'en',
  };
}

/**
 * Build a confirmed TheaterTtp. If `evidenceSubstr` is given, its OFFSETS are
 * computed against `subject + "\n\n" + body` exactly as the backend stores them
 * (ASCII fixtures ⇒ UTF-16 indexOf == code-point index).
 */
function ttp(
  m: TheaterMessage,
  code: string,
  label: string,
  phase: string,
  evidenceSubstr: string | null = null,
): TheaterTtp {
  let start: number | null = null;
  let end: number | null = null;
  if (evidenceSubstr !== null) {
    const combined = (m.subject ?? '') + '\n\n' + m.body_text;
    start = combined.indexOf(evidenceSubstr);
    end = start >= 0 ? start + evidenceSubstr.length : null;
    if (start < 0) start = null;
  }

  return {
    msg_id: m.msg_id,
    ttp_code: code,
    ttp_label: label,
    phase,
    confidence: 0.9,
    status: 'confirmed',
    evidence_start: start,
    evidence_end: end,
  };
}

function ioc(msgId: string, value: string, valueNorm: string): TheaterIoc {
  return {
    msg_id: msgId,
    obs_id: `obs-${value}`,
    indicator_id: `ind-${value}`,
    type: 'phone',
    value,
    value_norm: valueNorm,
    category: 'contact',
    ts_observed: '2026-06-14T00:00:00Z',
    revelation_context: null,
  };
}

function renderThread(
  messages: TheaterMessage[],
  ttps: TheaterTtp[],
  visibleStep: number,
  iocs: TheaterIoc[] = [],
) {
  return render(
    <MaskModeProvider>
      <TheaterThread
        messages={messages}
        visibleStep={visibleStep}
        iocsByMsg={iocs}
        ttpsByMsg={ttps}
        typingDirection={null}
      />
    </MaskModeProvider>,
  );
}

describe('TheaterThread — TTP chips + evidence highlight', () => {
  it('renders a phase-coloured chip per confirmed TTP on a revealed inbound message', () => {
    const m = msg(1, 'm-1', 'in', 'hello there');
    const { getAllByTestId, getByText } = renderThread([m], [ttp(m, 'SB-T001', 'Urgency', 'hook')], 1);

    expect(getAllByTestId('theater-ttp-chip')).toHaveLength(1);
    expect(getByText('Urgency')).toBeTruthy();
  });

  it('shows no chips on outbound messages', () => {
    const m = msg(1, 'm-1', 'out', 'our reply');
    const { queryByTestId } = renderThread([m], [ttp(m, 'SB-T001', 'Urgency', 'hook')], 1);

    expect(queryByTestId('theater-ttp-chip')).toBeNull();
  });

  it('shows no chips for a message that has not been revealed yet', () => {
    const m1 = msg(1, 'm-1', 'in', 'first');
    const m2 = msg(2, 'm-2', 'in', 'second');
    // Only m-1 revealed; the TTP is on the not-yet-revealed m-2.
    const { queryByTestId } = renderThread([m1, m2], [ttp(m2, 'SB-T001', 'Urgency', 'hook')], 1);

    expect(queryByTestId('theater-ttp-chip')).toBeNull();
  });

  it('highlights the verbatim evidence span and renders the FULL body (no truncation)', () => {
    // A body well over the 600-char truncation cap, with the evidence near the end.
    const filler = 'x'.repeat(700);
    const body = `${filler} then act fast now`;
    const m = msg(1, 'm-1', 'in', body, '');
    const { getByTestId, container } = renderThread([m], [ttp(m, 'SB-T003', 'Pressure', 'escalation', 'act fast')], 1);

    expect(getByTestId('theater-ttp-evidence').textContent).toBe('act fast');
    // Full body present: the filler tail survives and there is no ellipsis.
    expect(container.textContent).toContain(filler);
    expect(container.textContent).not.toContain('…');
  });

  it('masks an IOC value that sits inside a highlighted segment (masked by default)', () => {
    const body = 'please wire to acct now';
    const m = msg(1, 'm-1', 'in', body, '');
    const iocs = [ioc('m-1', 'acct', 'acct')];
    const { getByTestId, container } = renderThread([m], [ttp(m, 'SB-T010', 'Payment', 'payment-request', 'to acct now')], 1, iocs);

    // The evidence mark exists, but the IOC value inside it is redacted.
    expect(getByTestId('theater-ttp-evidence')).toBeTruthy();
    expect(container.textContent).not.toContain('acct');
    expect(container.textContent).toContain('[•••]');
  });

  it('does not leak an IOC value bisected by an evidence boundary (snap guard)', () => {
    // Evidence ends mid-phone-number: without the outward snap, the value would
    // split across a highlighted + a non-highlighted segment and dodge masking.
    const body = 'call +91-7906757261 urgently';
    const m = msg(1, 'm-1', 'in', body, '');
    const iocs = [ioc('m-1', '+91-7906757261', '+917906757261')];
    const { container } = renderThread([m], [ttp(m, 'SB-T017', 'Channel', 'channel-switch', 'call +91-790')], 1, iocs);

    // Neither the whole value nor the bisected fragment may appear in clear.
    expect(container.textContent).not.toContain('7906757261');
    expect(container.textContent).not.toContain('+91-790');
    expect(container.textContent).toContain('[•••]');
  });

  it('does not leak a MULTI-WORD IOC value bisected by an evidence boundary', () => {
    // A postal-address IOC keeps internal spaces after normalization, so a
    // whitespace snap alone would land a segment edge inside it. The evidence
    // ends mid-address; expansion over the IOC value must still fully redact it.
    const address = 'plot no 1 and 2 mamram towers new delhi';
    const body = `please send funds to ${address} urgently`;
    const m = msg(1, 'm-1', 'in', body, '');
    const iocs = [ioc('m-1', address, address)];
    const { container } = renderThread([m], [ttp(m, 'SB-T010', 'Payment', 'payment-request', 'to plot no 1 and 2 mamram')], 1, iocs);

    expect(container.textContent).not.toContain('mamram');
    expect(container.textContent).not.toContain('new delhi');
    expect(container.textContent).toContain('[•••]');
  });

  it('renders chip + evidence highlight even when the conversation has no IOCs', () => {
    const body = 'act now please';
    const m = msg(1, 'm-1', 'in', body, '');
    const { getByTestId } = renderThread([m], [ttp(m, 'SB-T001', 'Urgency', 'hook', 'act now')], 1, []);

    expect(getByTestId('theater-ttp-chip')).toBeTruthy();
    expect(getByTestId('theater-ttp-evidence').textContent).toBe('act now');
  });
});
