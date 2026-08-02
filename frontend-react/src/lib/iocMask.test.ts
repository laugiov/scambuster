import { describe, it, expect } from 'vitest';
import { displayValue, isSensitiveType, maskValue } from './iocMask';

describe('iocMask — masking rules', () => {
  describe('isSensitiveType', () => {
    it('flags financial + phone as sensitive', () => {
      ['phone', 'iban', 'bic', 'bank_account', 'wallet_btc', 'wallet', 'credit_card'].forEach((t) => {
        expect(isSensitiveType(t)).toBe(true);
      });
    });

    it('does NOT flag infrastructure / contact (other than phone) as sensitive', () => {
      ['url', 'domain', 'ipv4', 'sha256', 'email', 'telegram_username'].forEach((t) => {
        expect(isSensitiveType(t)).toBe(false);
      });
    });
  });

  describe('maskValue rules per length bucket', () => {
    it('phone: first 3 + ***** + last 3', () => {
      expect(maskValue('+919821686885', 'phone')).toBe('+91*****885');
      expect(maskValue('+1234567', 'phone')).toBe('+12*****567');
    });

    it('phone short fallback to ***', () => {
      expect(maskValue('+1234', 'phone')).toBe('***');
    });

    it('non-phone length < 6: ***', () => {
      expect(maskValue('ab', 'bic')).toBe('***');
      expect(maskValue('abcde', 'iban')).toBe('***');
    });

    it('non-phone 6-11: first 3 + ***', () => {
      expect(maskValue('HDFCIN', 'bic')).toBe('HDF***');
      expect(maskValue('HDFCINBBDE', 'bic')).toBe('HDF***');
    });

    it('non-phone ≥ 12: first 6 + *** + last 3', () => {
      expect(maskValue('HDFCINBBDELXYZ', 'bic')).toBe('HDFCIN***XYZ');
      expect(maskValue('FR7630006000011234567890189', 'iban')).toBe('FR7630***189');
    });
  });

  describe('displayValue end-to-end', () => {
    it('does not mask non-sensitive types regardless of mask state', () => {
      expect(displayValue('https://example.com', 'url', true)).toBe('https://example.com');
      expect(displayValue('user@example.com', 'email', true)).toBe('user@example.com');
    });

    it('masks sensitive types when masked=true (length 11 = bucket "first 3 + ***")', () => {
      expect(displayValue('HDFCINBBDEL', 'bic', true)).toBe('HDF***');
    });

    it('shows raw when masked=false', () => {
      expect(displayValue('HDFCINBBDEL', 'bic', false)).toBe('HDFCINBBDEL');
    });
  });
});
