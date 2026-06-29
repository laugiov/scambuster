import { scamTypeLabel } from './scamTypeLabels';

interface AnchorIoc {
  ioc_type: string;
  ioc_value: string;
  conv_count: number;
}

interface BehavioralProfileInput {
  dominant_revelation_turn?: number | null;
  dominant_stimulus?: string | null;
  templated_excerpt_count?: number | null;
  total_excerpt_variant_count?: number | null;
  avg_urgency_score?: number | null;
}

export interface VerdictInputs {
  conversation_count: number;
  primary_scam_types: string[];
  anchor_iocs: AnchorIoc[];
  behavioral_profile?: BehavioralProfileInput | null;
}

const FINANCIAL_TYPES = new Set(['bank_account', 'iban', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'credit_card']);

const ANCHOR_NOUN: Record<string, string> = {
  bank_account: 'bank account',
  iban: 'IBAN',
  wallet_btc: 'BTC wallet',
  wallet_eth: 'ETH wallet',
  wallet_xmr: 'XMR wallet',
  credit_card: 'card',
  phone: 'phone number',
  email: 'email address',
  domain: 'domain',
};

/**
 * Mask the trailing-digits portion of a sensitive identifier so the verdict
 * reads forensically (****8804) without dumping the full value into a banner.
 * Non-numeric or short values are returned as-is.
 */
export function maskAnchor(type: string, value: string): string {
  if (!value) return '?';
  if (type === 'phone') {
    const last4 = value.replace(/\D/g, '').slice(-4);
    return last4.length === 4 ? `****${last4}` : value;
  }
  if (FINANCIAL_TYPES.has(type)) {
    const digits = value.replace(/[^a-zA-Z0-9]/g, '');
    if (digits.length <= 4) return value;
    return `****${digits.slice(-4)}`;
  }
  return value;
}

function anchorNoun(type: string, plural = false): string {
  const base = ANCHOR_NOUN[type] ?? type.replace(/_/g, ' ');
  if (!plural) return base;
  return /s$/.test(base) ? base : `${base}s`;
}

function dominantScamLabel(types: string[]): string {
  if (types.length === 0) return 'mixed';
  if (types.length === 1) return scamTypeLabel(types[0]).toLowerCase();
  return 'mixed';
}

function isTemplated(prof?: BehavioralProfileInput | null): boolean {
  if (!prof) return false;
  const count = prof.templated_excerpt_count ?? 0;
  return count >= 5;
}

function pickDominantAnchor(anchors: AnchorIoc[]): AnchorIoc | null {
  if (anchors.length === 0) return null;
  return [...anchors].sort((a, b) => b.conv_count - a.conv_count)[0];
}

/**
 * Build a 1-2 sentence plain-English verdict for the top of a cluster detail
 * page. Pure function of the existing API response — no new fields needed.
 *
 * The verdict is the "read me first" line so a SOC analyst grasps the cluster
 * in 5 seconds. It deliberately compresses the most-decisional facts:
 *   - what kind of operation (templated vs one-off)
 *   - what the shared infrastructure is (mule account vs disposable phone)
 *   - how many victims share it
 *
 * Secondary sentence (when meaningful) names the operator's tactic in one
 * fragment: turn of first IOC reveal + urgency floor.
 */
export function buildClusterVerdict(c: VerdictInputs): string {
  const convs = Math.max(1, c.conversation_count);
  const scam = dominantScamLabel(c.primary_scam_types);
  const isMulti = c.primary_scam_types.length > 1;
  const anchor = pickDominantAnchor(c.anchor_iocs);
  const templated = isTemplated(c.behavioral_profile);
  const variants = c.behavioral_profile?.total_excerpt_variant_count ?? null;

  let primary: string;

  if (convs === 1) {
    if (anchor) {
      primary = `Single-conversation ${scam} scam pointing at ${anchorNoun(anchor.ioc_type)} ${maskAnchor(anchor.ioc_type, anchor.ioc_value)}.`;
    } else {
      primary = `Single-conversation ${scam} scam, no shared anchor IOC yet.`;
    }
  } else if (isMulti) {
    const types = c.primary_scam_types.length;
    if (anchor && FINANCIAL_TYPES.has(anchor.ioc_type)) {
      primary = `Multi-type cluster (${types} scam categories) on a shared ${anchorNoun(anchor.ioc_type)} (${maskAnchor(anchor.ioc_type, anchor.ioc_value)}), ${convs} conversations.`;
    } else {
      primary = `Multi-type cluster (${types} scam categories) across ${convs} conversations on a shared ${anchor ? anchorNoun(anchor.ioc_type) : 'IOC'}.`;
    }
  } else if (templated) {
    if (variants && variants > 1) {
      primary = `Templated ${scam} operation: ${convs} conversations across ${variants} script variants.`;
    } else {
      primary = `Templated ${scam} operation across ${convs} conversations.`;
    }
  } else if (anchor && FINANCIAL_TYPES.has(anchor.ioc_type)) {
    primary = `${capitalize(scam)} cluster on a shared ${anchorNoun(anchor.ioc_type)} (${maskAnchor(anchor.ioc_type, anchor.ioc_value)}), ${convs} conversations.`;
  } else if (anchor) {
    primary = `${capitalize(scam)} cluster spanning ${convs} conversations on a shared ${anchorNoun(anchor.ioc_type)}.`;
  } else {
    primary = `${capitalize(scam)} cluster of ${convs} conversations.`;
  }

  const turn = c.behavioral_profile?.dominant_revelation_turn ?? null;
  const stim = c.behavioral_profile?.dominant_stimulus ?? null;

  let secondary = '';
  if (turn !== null && turn <= 2) {
    const stimFrag = stim ? `, ${stim.toLowerCase().replace(/_/g, ' ')} tactic` : '';
    secondary = ` IOCs revealed on turn ${turn}${stimFrag}.`;
  } else if (stim) {
    secondary = ` Primary tactic: ${stim.toLowerCase().replace(/_/g, ' ')}.`;
  }

  return (primary + secondary).trim();
}

function capitalize(s: string): string {
  if (!s) return s;
  return s.charAt(0).toUpperCase() + s.slice(1);
}
