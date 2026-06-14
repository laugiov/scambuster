import type { TheaterIoc } from '@/hooks/useTheaterReplay';

/**
 * Spec 100 S3 — Group near-duplicate scammer domains into "variant"
 * clusters so the Theater Intelligence panel renders them as ONE
 * primary card with a `▸ N variants` chip rather than N unrelated
 * cards.
 *
 * Real-world example caught in the BH-review screenshots:
 *   - techwardsolutions.com
 *   - techwardinfosolutions.com
 *   - http://www.techwardinfosolutions.com
 *   - www.techwardinfosolutions.com
 * → 4 cards for the same threat actor with a shared `techward`
 * registrable label. Bad CTI ergonomics ("dedup defect"). Once
 * clustered they read as a "Multi-domain operator" tradecraft
 * signal — a strength, not noise.
 *
 * Heuristic (conservative — false-positive cluster is more harmful
 * than false-negative):
 *   1. Extract the host from each domain/url IOC (defang-aware,
 *      strip scheme + www + path).
 *   2. Compute the registrable label = host minus TLD (everything
 *      before the last dot).
 *   3. Two hosts cluster if they share a substring of ≥6 chars at
 *      the START of their registrable label AND they have the same
 *      TLD. The 6-char minimum prevents `acme.com` + `acmepay.com`
 *      from clustering accidentally on `acme` if the shared label
 *      is shorter than 6 chars.
 *   4. Cluster representative = the SHORTEST host (most likely the
 *      "real" domain; longer variants tend to be typo-squats or
 *      info-padded forms).
 *
 * Non-DOMAIN/non-URL IOCs pass through unchanged.
 */

export interface DomainCluster {
  /** Cluster head IOC — shown as the primary card. */
  primary: TheaterIoc;
  /** Variants beyond the primary (may be empty for non-clustered hosts). */
  variants: TheaterIoc[];
  /** The shared label all hosts in this cluster start with. */
  sharedLabel: string;
}

function unDefang(value: string): string {
  return value
    .replace(/\[\.\]/g, '.')
    .replace(/\[\/\]/g, '/')
    .replace(/\[:\/\/\]/g, '://')
    .replace(/\[:\]/g, ':');
}

function extractHost(ioc: TheaterIoc): string | null {
  const v = unDefang((ioc.value || '').toLowerCase()).trim();

  if (v === '') return null;

  if (ioc.type === 'domain') {
    return v.startsWith('www.') ? v.slice(4) : v;
  }

  if (ioc.type === 'url') {
    // Synthesise a scheme so URL() can parse scheme-less inputs.
    const withScheme = /^[a-z][a-z0-9+\-.]*:\/\//.test(v) ? v : `https://${v}`;
    try {
      const u = new URL(withScheme);
      const host = u.hostname;
      return host.startsWith('www.') ? host.slice(4) : host;
    } catch {
      return null;
    }
  }

  return null;
}

function tld(host: string): string {
  const dot = host.lastIndexOf('.');
  return dot >= 0 ? host.slice(dot + 1) : '';
}

function registrableLabel(host: string): string {
  const dot = host.lastIndexOf('.');
  return dot >= 0 ? host.slice(0, dot) : host;
}

function longestCommonPrefix(a: string, b: string): string {
  let i = 0;
  const max = Math.min(a.length, b.length);
  while (i < max && a[i] === b[i]) i++;
  return a.slice(0, i);
}

/**
 * Group an array of IOCs (domain + url + everything else) so domain-
 * shaped IOCs that share a near-identical registrable label cluster
 * into a single DomainCluster head. Non-domain IOCs return as
 * singleton clusters (variants = []).
 */
export function clusterDomainVariants(iocs: TheaterIoc[]): DomainCluster[] {
  const clusters: DomainCluster[] = [];
  const domainLike: Array<{ ioc: TheaterIoc; host: string }> = [];

  for (const ioc of iocs) {
    const host = extractHost(ioc);
    if (host !== null) {
      domainLike.push({ ioc, host });
    } else {
      clusters.push({ primary: ioc, variants: [], sharedLabel: '' });
    }
  }

  const visited = new Set<number>();
  for (let i = 0; i < domainLike.length; i++) {
    if (visited.has(i)) continue;
    const seed = domainLike[i];
    const seedLabel = registrableLabel(seed.host);
    const seedTld = tld(seed.host);
    const peers: Array<{ ioc: TheaterIoc; host: string }> = [seed];
    visited.add(i);

    for (let j = i + 1; j < domainLike.length; j++) {
      if (visited.has(j)) continue;
      const candidate = domainLike[j];
      if (tld(candidate.host) !== seedTld) continue;
      const candidateLabel = registrableLabel(candidate.host);
      const lcp = longestCommonPrefix(seedLabel, candidateLabel);
      if (lcp.length >= 6) {
        peers.push(candidate);
        visited.add(j);
      }
    }

    // Cluster head = shortest host (most likely the canonical domain).
    peers.sort((a, b) => a.host.length - b.host.length);
    const [head, ...rest] = peers;
    const sharedLabel = rest.length > 0
      ? rest.reduce(
        (acc, p) => longestCommonPrefix(acc, registrableLabel(p.host)),
        registrableLabel(head.host),
      )
      : registrableLabel(head.host);

    clusters.push({
      primary: head.ioc,
      variants: rest.map((p) => p.ioc),
      sharedLabel,
    });
  }

  return clusters;
}
