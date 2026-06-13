import { describe, it, expect } from 'vitest';
import { classifyIoc } from './iocCategory';

describe('classifyIoc / Spec 097 S3', () => {
  it('classifies financial types', () => {
    ['iban', 'bic', 'swift', 'bank_account', 'wallet_btc', 'wallet_eth', 'wallet', 'credit_card'].forEach((t) => {
      expect(classifyIoc(t)).toBe('financial');
    });
  });

  it('classifies contact types', () => {
    ['phone', 'email', 'telegram_username', 'skype_id', 'whatsapp'].forEach((t) => {
      expect(classifyIoc(t)).toBe('contact');
    });
  });

  it('classifies infrastructure types', () => {
    ['url', 'domain', 'ipv4', 'sha256', 'tracking_number'].forEach((t) => {
      expect(classifyIoc(t)).toBe('infrastructure');
    });
  });

  it('falls back to "other" for unknown types (explicit default bucket)', () => {
    expect(classifyIoc('quantum_dna_signature')).toBe('other');
    expect(classifyIoc('')).toBe('other');
    expect(classifyIoc('   ')).toBe('other');
  });

  it('is case-insensitive and trims', () => {
    expect(classifyIoc('BIC')).toBe('financial');
    expect(classifyIoc('  iban  ')).toBe('financial');
    expect(classifyIoc('Phone')).toBe('contact');
  });
});
