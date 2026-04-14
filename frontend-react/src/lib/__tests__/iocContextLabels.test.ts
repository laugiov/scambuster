import { describe, it, expect } from 'vitest';
import {
  ROLE_COLORS,
  STIMULUS_COLORS,
  normalizeContextKey,
  humanizeContext,
  urgencyColorClass,
  urgencyTextClass,
} from '../iocContextLabels';

describe('ROLE_COLORS', () => {
  it('has entries for all expected roles', () => {
    expect(ROLE_COLORS.PAYMENT_DESTINATION).toBeDefined();
    expect(ROLE_COLORS.PAYMENT_REDIRECT_URL).toBeDefined();
    expect(ROLE_COLORS.MONEY_MULE_ACCOUNT).toBeDefined();
    expect(ROLE_COLORS.PHISHING_CREDENTIAL_URL).toBeDefined();
    expect(ROLE_COLORS.MALWARE_DOWNLOAD_URL).toBeDefined();
    expect(ROLE_COLORS.CONTACT_CHANNEL).toBeDefined();
    expect(ROLE_COLORS.INFRASTRUCTURE_DOMAIN).toBeDefined();
    expect(ROLE_COLORS.UNKNOWN).toBeDefined();
  });

  it('payment roles use error color', () => {
    expect(ROLE_COLORS.PAYMENT_DESTINATION).toContain('error');
    expect(ROLE_COLORS.MONEY_MULE_ACCOUNT).toContain('error');
  });
});

describe('STIMULUS_COLORS', () => {
  it('has entries for all expected stimuli', () => {
    expect(STIMULUS_COLORS.URGENCY_PRESSURE).toBeDefined();
    expect(STIMULUS_COLORS.AUTHORITY).toBeDefined();
    expect(STIMULUS_COLORS.RECIPROCITY).toBeDefined();
    expect(STIMULUS_COLORS.FINANCIAL_INCENTIVE).toBeDefined();
    expect(STIMULUS_COLORS.SCARCITY).toBeDefined();
    expect(STIMULUS_COLORS.SOCIAL_PROOF).toBeDefined();
    expect(STIMULUS_COLORS.PASSIVE).toBeDefined();
  });
});

describe('normalizeContextKey', () => {
  it('converts lowercase to uppercase', () => {
    expect(normalizeContextKey('payment_destination')).toBe('PAYMENT_DESTINATION');
  });

  it('replaces hyphens with underscores', () => {
    expect(normalizeContextKey('urgency-pressure')).toBe('URGENCY_PRESSURE');
  });

  it('replaces spaces with underscores', () => {
    expect(normalizeContextKey('urgency pressure')).toBe('URGENCY_PRESSURE');
  });

  it('returns UNKNOWN for null', () => {
    expect(normalizeContextKey(null)).toBe('UNKNOWN');
  });

  it('returns UNKNOWN for undefined', () => {
    expect(normalizeContextKey(undefined)).toBe('UNKNOWN');
  });

  it('returns UNKNOWN for empty string', () => {
    expect(normalizeContextKey('')).toBe('UNKNOWN');
  });
});

describe('humanizeContext', () => {
  it('converts snake_case to Title Case', () => {
    expect(humanizeContext('urgency_pressure')).toBe('Urgency Pressure');
  });

  it('converts kebab-case to Title Case', () => {
    expect(humanizeContext('urgency-pressure')).toBe('Urgency Pressure');
  });

  it('handles single word', () => {
    expect(humanizeContext('authority')).toBe('Authority');
  });

  it('handles UPPERCASE input', () => {
    expect(humanizeContext('PAYMENT_DESTINATION')).toBe('Payment Destination');
  });

  it('returns Unknown for null', () => {
    expect(humanizeContext(null)).toBe('Unknown');
  });

  it('returns Unknown for undefined', () => {
    expect(humanizeContext(undefined)).toBe('Unknown');
  });

  it('returns Unknown for empty string', () => {
    expect(humanizeContext('')).toBe('Unknown');
  });
});

describe('urgencyColorClass', () => {
  it('returns error for high urgency (>= 0.75)', () => {
    expect(urgencyColorClass(0.75)).toBe('bg-error');
    expect(urgencyColorClass(0.9)).toBe('bg-error');
    expect(urgencyColorClass(1.0)).toBe('bg-error');
  });

  it('returns warning for medium urgency (0.50-0.74)', () => {
    expect(urgencyColorClass(0.50)).toBe('bg-warning');
    expect(urgencyColorClass(0.74)).toBe('bg-warning');
  });

  it('returns success for low urgency (< 0.50)', () => {
    expect(urgencyColorClass(0.0)).toBe('bg-success');
    expect(urgencyColorClass(0.49)).toBe('bg-success');
  });
});

describe('urgencyTextClass', () => {
  it('returns text-error for high urgency (>= 0.75)', () => {
    expect(urgencyTextClass(0.75)).toBe('text-error');
    expect(urgencyTextClass(1.0)).toBe('text-error');
  });

  it('returns text-warning for medium urgency (0.50-0.74)', () => {
    expect(urgencyTextClass(0.50)).toBe('text-warning');
    expect(urgencyTextClass(0.74)).toBe('text-warning');
  });

  it('returns text-success for low urgency (< 0.50)', () => {
    expect(urgencyTextClass(0.0)).toBe('text-success');
    expect(urgencyTextClass(0.49)).toBe('text-success');
  });
});
