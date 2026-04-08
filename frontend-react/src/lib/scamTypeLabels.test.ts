import { describe, it, expect } from 'vitest';
import { scamTypeLabel, scamTypeColor } from './scamTypeLabels';

describe('scamTypeLabel', () => {
  it('returns mapped label for known code', () => {
    expect(scamTypeLabel('PHISHING')).toBe('Phishing');
    expect(scamTypeLabel('INVOICE_FRAUD')).toBe('Invoice Fraud');
    expect(scamTypeLabel('ADVANCE_FEE_419')).toBe('Advance Fee (419)');
  });

  it('returns humanized fallback for unknown code', () => {
    expect(scamTypeLabel('SOME_NEW_TYPE')).toBe('Some New Type');
  });
});

describe('scamTypeColor', () => {
  it('returns color classes for known code', () => {
    expect(scamTypeColor('PHISHING')).toContain('amber');
    expect(scamTypeColor('INVOICE_FRAUD')).toContain('red');
  });

  it('returns neutral color for unknown code', () => {
    expect(scamTypeColor('UNKNOWN')).toContain('surface');
  });
});
