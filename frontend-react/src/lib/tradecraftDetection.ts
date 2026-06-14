import type { TheaterMessage } from '@/hooks/useTheaterReplay';

/**
 * Spec 101 S4 — Detect tradecraft signals in message bodies.
 *
 * The first signal shipped: scammer-side email-tracker beacons. A
 * proportion of mid-to-high-volume scammers wrap their outbound emails
 * with a tracking SaaS (Mailsuite, Mailtrack, Sidekick, etc.) that
 * inserts a beacon image + a footer like
 *   `[image: Mailsuite] Email tracked with Mailsuite · Opt out
 *    <https://u.list-prefs.com/...>`
 * The presence of this footer is a real CTI signal: the operator is
 * professional enough to measure open rates and to A/B their pretexts.
 * Until now the Theater rendered the footer as ordinary body text.
 *
 * This module is a pure regex pattern-matcher. No backend round-trip,
 * no LLM, no enrichment dependency. Returns one `TradecraftSignal` per
 * matched (message, kind) pair.
 */

export type TradecraftKind =
  | 'email_tracker_mailsuite'
  | 'email_tracker_mailtrack'
  | 'email_tracker_sidekick'
  | 'email_tracker_generic';

export interface TradecraftSignal {
  msg_id: string;
  msg_idx: number;
  kind: TradecraftKind;
  /** The literal substring that matched, for the operator tooltip. */
  matched_text: string;
}

const PATTERNS: ReadonlyArray<{ kind: TradecraftKind; re: RegExp }> = [
  { kind: 'email_tracker_mailsuite', re: /\[image:\s*mailsuite\b[^\]]*\]|email\s+tracked\s+with\s+mailsuite/i },
  { kind: 'email_tracker_mailtrack', re: /\[image:\s*mailtrack\b[^\]]*\]|email\s+tracked\s+with\s+mailtrack/i },
  { kind: 'email_tracker_sidekick', re: /\[image:\s*sidekick\b[^\]]*\]|tracked\s+with\s+sidekick/i },
  // Catch-all for "[image: <something>] Email tracked with …" patterns
  // the named matchers above missed. Listed last so the named-vendor
  // signals take priority on the same body.
  { kind: 'email_tracker_generic', re: /\[image:\s*[a-z0-9.-]+\]\s+email\s+tracked\s+with\b/i },
];

/**
 * Scan every visible message body for tradecraft footprints and
 * return one signal per (msg, kind) hit. The caller is expected to
 * filter the result by `visibleStep` if it wants progressive reveal;
 * this function only walks the supplied messages.
 */
export function detectTradecraftSignals(messages: TheaterMessage[]): TradecraftSignal[] {
  const out: TradecraftSignal[] = [];

  for (const msg of messages) {
    const body = msg.body_text ?? '';
    if (body === '') continue;

    const matchedKinds = new Set<TradecraftKind>();
    for (const { kind, re } of PATTERNS) {
      // Skip catch-all if a named vendor already matched on this msg.
      if (kind === 'email_tracker_generic' && matchedKinds.size > 0) continue;
      const m = body.match(re);
      if (m === null) continue;
      matchedKinds.add(kind);
      out.push({
        msg_id: msg.msg_id,
        msg_idx: msg.idx,
        kind,
        matched_text: m[0],
      });
    }
  }

  return out;
}
