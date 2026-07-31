import { describe, it, expect } from 'vitest';
import {
  STIMULUS_TYPES,
  humanizeStimulus,
  stimulusLabel,
  stimulusColor,
  isActiveStimulus,
} from './stimulusLabels';
import en from '@/i18n/locales/en.json';
import fr from '@/i18n/locales/fr.json';

// Identity translator: lets tests observe which i18n key the helper resolves.
const tKey = (key: string) => key;

describe('stimulusLabels', () => {
  it('covers exactly the 7 backend enum values', () => {
    expect([...STIMULUS_TYPES].sort()).toEqual([
      'DIRECT_REQUEST',
      'DOCUMENT_REQUEST',
      'PASSIVE',
      'PAYMENT_INITIATION',
      'TRUST_BUILDING',
      'UNKNOWN',
      'URGENCY_PRESSURE',
    ]);
  });

  it('resolves every known value to its stimulus.* i18n key', () => {
    for (const type of STIMULUS_TYPES) {
      expect(stimulusLabel(type, tKey)).toBe(`stimulus.${type}`);
    }
  });

  it('has an EN and FR locale entry for every known value', () => {
    const enStimulus = (en as Record<string, unknown>)['stimulus'] as Record<string, string>;
    const frStimulus = (fr as Record<string, unknown>)['stimulus'] as Record<string, string>;
    for (const type of STIMULUS_TYPES) {
      expect(enStimulus[type], `en.json stimulus.${type}`).toBeTypeOf('string');
      expect(frStimulus[type], `fr.json stimulus.${type}`).toBeTypeOf('string');
    }
  });

  it('keeps stimulus and causality wording neutral in BOTH locales — no causal verbs (L11)', () => {
    const EN_CAUSAL = /caus|trigger|elicit|provok|induc|led to|made the/i;
    const FR_CAUSAL = /déclench|provoqu|suscit|entraîn|caus/i;

    const enStimulus = (en as Record<string, unknown>)['stimulus'] as Record<string, string>;
    const frStimulus = (fr as Record<string, unknown>)['stimulus'] as Record<string, string>;
    for (const type of STIMULUS_TYPES) {
      expect(enStimulus[type], `en stimulus.${type}`).not.toMatch(EN_CAUSAL);
      expect(frStimulus[type], `fr stimulus.${type}`).not.toMatch(FR_CAUSAL);
    }

    const enTimeline = ((en as Record<string, unknown>)['ttp'] as { timeline: Record<string, string> }).timeline;
    const frTimeline = ((fr as Record<string, unknown>)['ttp'] as { timeline: Record<string, string> }).timeline;
    for (const key of ['precededBy', 'revelationsFollowed', 'reviewLegend'] as const) {
      expect(enTimeline[key], `en ttp.timeline.${key}`).toBeTypeOf('string');
      expect(frTimeline[key], `fr ttp.timeline.${key}`).toBeTypeOf('string');
      expect(enTimeline[key], `en ttp.timeline.${key}`).not.toMatch(EN_CAUSAL);
      expect(frTimeline[key], `fr ttp.timeline.${key}`).not.toMatch(FR_CAUSAL);
    }
  });

  it('humanizes unknown values instead of translating them', () => {
    expect(stimulusLabel('SOME_NEW_TYPE', tKey)).toBe('Some New Type');
    expect(humanizeStimulus('urgency-pressure')).toBe('Urgency Pressure');
  });

  it('returns a colour for known values and a muted fallback otherwise', () => {
    expect(stimulusColor('DIRECT_REQUEST')).toBe('bg-blue-500/20 text-blue-400');
    expect(stimulusColor('PASSIVE')).toBe('bg-slate-500/20 text-slate-300');
    expect(stimulusColor('SOME_NEW_TYPE')).toBe('bg-surface-highest text-on-surface-variant');
  });

  it('flags every value except PASSIVE/UNKNOWN (and null) as an active stimulus', () => {
    expect(isActiveStimulus('DIRECT_REQUEST')).toBe(true);
    expect(isActiveStimulus('DOCUMENT_REQUEST')).toBe(true);
    expect(isActiveStimulus('PAYMENT_INITIATION')).toBe(true);
    expect(isActiveStimulus('TRUST_BUILDING')).toBe(true);
    expect(isActiveStimulus('URGENCY_PRESSURE')).toBe(true);
    expect(isActiveStimulus('PASSIVE')).toBe(false);
    expect(isActiveStimulus('UNKNOWN')).toBe(false);
    expect(isActiveStimulus(null)).toBe(false);
    expect(isActiveStimulus(undefined)).toBe(false);
    // Unknown non-enum values count as active: the badge condition is
    // "not in (PASSIVE, UNKNOWN)", not a whitelist.
    expect(isActiveStimulus('SOME_NEW_TYPE')).toBe(true);
  });
});
