import { describe, it, expect } from 'vitest';
import { detectTradecraftSignals } from './tradecraftDetection';
import type { TheaterMessage } from '@/hooks/useTheaterReplay';

function msg(idx: number, body: string): TheaterMessage {
  return {
    idx,
    msg_id: `m-${idx}`,
    direction: 'in',
    ts_msg: '2026-06-14T00:00:00Z',
    sender: 'x@example.com',
    subject: 'Re',
    body_text: body,
    lang_detect: 'en',
  };
}

describe('detectTradecraftSignals — Spec 101 S4', () => {
  it('returns nothing on a clean conversation', () => {
    const out = detectTradecraftSignals([msg(1, 'Just a normal email body, no tracker footer.')]);
    expect(out).toEqual([]);
  });

  it('detects the Mailsuite footer pattern (image tag + tracked-with line)', () => {
    const out = detectTradecraftSignals([
      msg(1, 'Body text here.\n[image: Mailsuite] Email tracked with Mailsuite · Opt out <https://u.list-prefs.com/abc>'),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0].kind).toBe('email_tracker_mailsuite');
    expect(out[0].msg_idx).toBe(1);
  });

  it('detects Mailtrack', () => {
    const out = detectTradecraftSignals([
      msg(2, 'Email tracked with Mailtrack — by mailtrack.io'),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0].kind).toBe('email_tracker_mailtrack');
  });

  it('detects Sidekick', () => {
    const out = detectTradecraftSignals([
      msg(3, 'Sent via [image: Sidekick] — tracked with Sidekick by HubSpot'),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0].kind).toBe('email_tracker_sidekick');
  });

  it('falls back to the generic catch-all for an unknown vendor', () => {
    const out = detectTradecraftSignals([
      msg(4, '[image: Yesware] Email tracked with Yesware — see opens'),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0].kind).toBe('email_tracker_generic');
  });

  it('does not double-fire generic + vendor on the same message', () => {
    const out = detectTradecraftSignals([
      msg(5, '[image: Mailsuite] Email tracked with Mailsuite — opt out'),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0].kind).toBe('email_tracker_mailsuite');
  });

  it('returns one signal per message even when the same vendor appears in two messages', () => {
    const out = detectTradecraftSignals([
      msg(1, '[image: Mailsuite] Email tracked with Mailsuite'),
      msg(2, 'Reply body, no tracker'),
      msg(3, '[image: Mailsuite] Email tracked with Mailsuite'),
    ]);
    expect(out).toHaveLength(2);
    expect(out.map((s) => s.msg_idx)).toEqual([1, 3]);
  });

  it('is case-insensitive', () => {
    const out = detectTradecraftSignals([
      msg(1, '[IMAGE: MAILSUITE] EMAIL TRACKED WITH MAILSUITE'),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0].kind).toBe('email_tracker_mailsuite');
  });

  it('handles empty / null bodies gracefully', () => {
    const empty: TheaterMessage = { ...msg(1, ''), body_text: '' };
    const out = detectTradecraftSignals([empty]);
    expect(out).toEqual([]);
  });
});
