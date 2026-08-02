import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TheaterIntelligencePanel } from './TheaterIntelligencePanel';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import type { TheaterIoc, TheaterMessage } from '@/hooks/useTheaterReplay';

function msg(idx: number): TheaterMessage {
  return {
    idx,
    msg_id: `msg-${idx}`,
    direction: 'in',
    ts_msg: '2026-06-14T00:00:00Z',
    sender: 'x@example.com',
    subject: 'Re',
    body_text: 'body',
    lang_detect: 'en',
  };
}

function ioc(indicatorId: string, type: string, category: string, msgId: string): TheaterIoc {
  return {
    indicator_id: indicatorId,
    type,
    value: 'val',
    value_norm: 'val',
    category,
    msg_id: msgId,
    msg_idx: 1,
    revelation_context: undefined,
  };
}

function renderPanel(iocs: TheaterIoc[], messages: TheaterMessage[], visibleStep: number) {
  return render(
    <MaskModeProvider>
      <TheaterIntelligencePanel iocs={iocs} messages={messages} visibleStep={visibleStep} />
    </MaskModeProvider>,
  );
}

describe('TheaterIntelligencePanel — Actionable/Context split', () => {
  const messages = [msg(1), msg(2)];

  it('headline counts only Actionable IOCs', () => {
    const iocs = [
      ioc('a-1', 'iban', 'financial', 'msg-1'),       // actionable
      ioc('a-2', 'phone', 'contact', 'msg-1'),        // actionable
      ioc('c-1', 'subject', 'other', 'msg-1'),        // context
      ioc('c-2', 'message_id', 'other', 'msg-1'),     // context
      ioc('c-3', 'dmarc_result', 'other', 'msg-1'),   // context
    ];
    renderPanel(iocs, messages, 2);
    const headline = screen.getByTestId('intelligence-headline');
    // headline starts with "2" (the two actionable) and not "5" (total)
    expect(headline.textContent?.trim().startsWith('2')).toBe(true);
    expect(headline.textContent?.includes('5')).toBe(false);
  });

  it('Context section renders collapsed by default and lists context IOCs when expanded', () => {
    const iocs = [
      ioc('a-1', 'iban', 'financial', 'msg-1'),
      ioc('c-1', 'subject', 'other', 'msg-1'),
      ioc('c-2', 'message_id', 'other', 'msg-1'),
    ];
    renderPanel(iocs, messages, 2);

    // Context toggle visible, but list hidden by default
    const toggle = screen.getByTestId('intelligence-context-toggle');
    expect(toggle).toBeTruthy();
    expect(screen.queryByTestId('intelligence-context-list')).toBeNull();

    // Click to expand
    fireEvent.click(toggle);
    expect(screen.getByTestId('intelligence-context-list')).toBeTruthy();
  });

  it('does not render Context section when no context IOCs present', () => {
    const iocs = [
      ioc('a-1', 'iban', 'financial', 'msg-1'),
      ioc('a-2', 'url', 'infrastructure', 'msg-1'),
    ];
    renderPanel(iocs, messages, 2);
    expect(screen.queryByTestId('intelligence-context')).toBeNull();
  });

  it('does not render Actionable section when only context IOCs present', () => {
    const iocs = [
      ioc('c-1', 'subject', 'other', 'msg-1'),
      ioc('c-2', 'message_id', 'other', 'msg-1'),
    ];
    renderPanel(iocs, messages, 2);
    expect(screen.queryByTestId('intelligence-actionable')).toBeNull();
    expect(screen.getByTestId('intelligence-context')).toBeTruthy();
    // Headline count = 0 (no actionable)
    expect(screen.getByTestId('intelligence-headline').textContent?.trim().startsWith('0')).toBe(true);
  });
});
