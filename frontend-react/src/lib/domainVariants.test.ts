import { describe, it, expect } from 'vitest';
import { clusterDomainVariants } from './domainVariants';
import type { TheaterIoc } from '@/hooks/useTheaterReplay';

function ioc(id: string, type: string, value: string): TheaterIoc {
  return {
    indicator_id: id,
    type,
    value,
    value_norm: value,
    category: 'infrastructure',
    msg_id: 'm-1',
    msg_idx: 0,
    revelation_context: undefined,
  };
}

describe('clusterDomainVariants — Spec 100 S3', () => {
  it('returns one singleton cluster per IOC when none share a ≥6-char prefix', () => {
    const iocs = [
      ioc('1', 'domain', 'acme.example'),
      ioc('2', 'domain', 'beta.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(2);
    expect(clusters[0].variants).toHaveLength(0);
    expect(clusters[1].variants).toHaveLength(0);
  });

  it('clusters two domains that share a ≥6-char prefix and the same TLD', () => {
    const iocs = [
      ioc('1', 'domain', 'techwardinfosolutions.example'),
      ioc('2', 'domain', 'techwardsolutions.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(1);
    // Cluster head is the SHORTEST host
    expect(clusters[0].primary.value).toBe('techwardsolutions.example');
    expect(clusters[0].variants.map((v) => v.value)).toEqual(['techwardinfosolutions.example']);
    expect(clusters[0].sharedLabel).toBe('techward');
  });

  it('clusters domain + url forms of the same host', () => {
    const iocs = [
      ioc('1', 'domain', 'techwardinfosolutions.example'),
      ioc('2', 'url', 'http://www.techwardinfosolutions.example/portfolio'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].variants).toHaveLength(1);
  });

  it('does not cluster across different TLDs even with a shared label', () => {
    const iocs = [
      ioc('1', 'domain', 'techward-solutions.com'),
      ioc('2', 'domain', 'techward-solutions.net'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(2);
  });

  it('does not cluster when shared prefix is < 6 chars', () => {
    const iocs = [
      ioc('1', 'domain', 'acme.example'),
      ioc('2', 'domain', 'acmecorp.example'), // shared "acme" is only 4 chars
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(2);
  });

  it('strips www. prefix when comparing', () => {
    const iocs = [
      ioc('1', 'domain', 'techwardinfosolutions.example'),
      ioc('2', 'domain', 'www.techwardinfosolutions.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].variants).toHaveLength(1);
  });

  it('un-defangs value before clustering', () => {
    const iocs = [
      ioc('1', 'domain', 'techwardinfosolutions[.]example'),
      ioc('2', 'domain', 'techwardsolutions.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].variants).toHaveLength(1);
  });

  it('passes non-domain / non-url IOCs through as singletons', () => {
    const iocs = [
      ioc('1', 'phone', '+15555550111'),
      ioc('2', 'iban', 'DE89370400440532013000'),
      ioc('3', 'domain', 'acme-corp.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(3);
    clusters.forEach((c) => expect(c.variants).toHaveLength(0));
  });

  it('handles 3+ variants and uses the shortest as the head', () => {
    const iocs = [
      ioc('1', 'domain', 'techwardinfosolutions.example'),
      ioc('2', 'domain', 'techwardsolutionscorp.example'),
      ioc('3', 'domain', 'techward.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(1);
    expect(clusters[0].primary.value).toBe('techward.example');
    expect(clusters[0].variants).toHaveLength(2);
  });

  it('handles malformed URLs gracefully', () => {
    const iocs = [
      ioc('1', 'url', 'not-actually-a-url-at-all-just-text'),
      ioc('2', 'domain', 'acme.example'),
    ];
    const clusters = clusterDomainVariants(iocs);
    expect(clusters).toHaveLength(2);
  });
});
