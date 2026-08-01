import { describe, it, expect } from 'vitest';
import {
  cosineSimilarity,
  orderBySimilarity,
  type SimilarityCell,
  type SimilarityCluster,
} from './playbookSimilarity';

describe('cosineSimilarity', () => {
  it('is 1 for identical vectors', () => {
    const v = new Map([['a', 3], ['b', 4]]);
    expect(cosineSimilarity(v, new Map(v))).toBeCloseTo(1, 10);
  });

  it('is 0 for orthogonal vectors (no shared dimension)', () => {
    expect(cosineSimilarity(new Map([['a', 1]]), new Map([['b', 1]]))).toBe(0);
  });

  it('computes the known partial-overlap value', () => {
    // {a:1} vs {a:1,b:1}: dot 1, |a|=1, |b|=√2 → 1/√2.
    expect(cosineSimilarity(new Map([['a', 1]]), new Map([['a', 1], ['b', 1]]))).toBeCloseTo(
      1 / Math.SQRT2,
      10,
    );
  });

  it('is 0 (never NaN) when either vector is empty or all-zero', () => {
    expect(cosineSimilarity(new Map(), new Map([['a', 1]]))).toBe(0);
    expect(cosineSimilarity(new Map([['a', 0]]), new Map([['a', 1]]))).toBe(0);
  });

  it('is symmetric', () => {
    const a = new Map([['x', 2], ['y', 1]]);
    const b = new Map([['x', 1], ['y', 3]]);
    expect(cosineSimilarity(a, b)).toBeCloseTo(cosineSimilarity(b, a), 12);
  });
});

describe('orderBySimilarity', () => {
  it('returns [] for no clusters and the single id for one', () => {
    expect(orderBySimilarity([], [])).toEqual([]);
    expect(
      orderBySimilarity([{ cluster_id: 'solo', conversation_total: 3 }], []),
    ).toEqual(['solo']);
  });

  it('chains similar playbooks adjacent, seeded from the highest-volume cluster', () => {
    // A and C share the same tactic (T1); B plays a different one (T2). Seeded at
    // the widest cluster (A, 10 convs), the chain must place C (identical vector)
    // before the dissimilar B.
    const clusters: SimilarityCluster[] = [
      { cluster_id: 'A', conversation_total: 10 },
      { cluster_id: 'B', conversation_total: 5 },
      { cluster_id: 'C', conversation_total: 8 },
    ];
    const cells: SimilarityCell[] = [
      { cluster_id: 'A', ttp_code: 'T1', conversation_count: 10 },
      { cluster_id: 'B', ttp_code: 'T2', conversation_count: 5 },
      { cluster_id: 'C', ttp_code: 'T1', conversation_count: 8 },
    ];
    expect(orderBySimilarity(clusters, cells)).toEqual(['A', 'C', 'B']);
  });

  it('is deterministic under ties (equal vectors resolve by volume then id)', () => {
    // A and C are identical playbooks with equal volume: the seed and the tie
    // both fall back to id-ascending, so A leads and C follows; B trails.
    const clusters: SimilarityCluster[] = [
      { cluster_id: 'C', conversation_total: 10 },
      { cluster_id: 'A', conversation_total: 10 },
      { cluster_id: 'B', conversation_total: 4 },
    ];
    const cells: SimilarityCell[] = [
      { cluster_id: 'A', ttp_code: 'T1', conversation_count: 10 },
      { cluster_id: 'C', ttp_code: 'T1', conversation_count: 10 },
      { cluster_id: 'B', ttp_code: 'T9', conversation_count: 4 },
    ];
    const order = orderBySimilarity(clusters, cells);
    expect(order).toEqual(['A', 'C', 'B']);
    // Same inputs → same output (no hidden ordering dependence).
    expect(orderBySimilarity([...clusters].reverse(), [...cells].reverse())).toEqual(order);
  });

  it('normalizes per conversation so a chatty cluster does not dominate', () => {
    // Big and Small run the same tactic mix (T1 twice as often as T2). Despite
    // Big's larger raw counts, their normalized vectors are identical, so they
    // sit adjacent and Other (pure T2) trails.
    const clusters: SimilarityCluster[] = [
      { cluster_id: 'Big', conversation_total: 100 },
      { cluster_id: 'Small', conversation_total: 10 },
      { cluster_id: 'Other', conversation_total: 20 },
    ];
    const cells: SimilarityCell[] = [
      { cluster_id: 'Big', ttp_code: 'T1', conversation_count: 80 },
      { cluster_id: 'Big', ttp_code: 'T2', conversation_count: 40 },
      { cluster_id: 'Small', ttp_code: 'T1', conversation_count: 8 },
      { cluster_id: 'Small', ttp_code: 'T2', conversation_count: 4 },
      { cluster_id: 'Other', ttp_code: 'T2', conversation_count: 20 },
    ];
    expect(orderBySimilarity(clusters, cells)).toEqual(['Big', 'Small', 'Other']);
  });

  it('places clusters with no cells last (zero vector is similar to nothing)', () => {
    const clusters: SimilarityCluster[] = [
      { cluster_id: 'A', conversation_total: 10 },
      { cluster_id: 'B', conversation_total: 6 },
      { cluster_id: 'Empty', conversation_total: 3 },
    ];
    const cells: SimilarityCell[] = [
      { cluster_id: 'A', ttp_code: 'T1', conversation_count: 10 },
      { cluster_id: 'B', ttp_code: 'T1', conversation_count: 6 },
    ];
    // A seeds, B (cosine 1) follows, Empty (cosine 0 with everything) trails.
    expect(orderBySimilarity(clusters, cells)).toEqual(['A', 'B', 'Empty']);
  });

  it('returns a permutation of every input cluster id', () => {
    const clusters: SimilarityCluster[] = [
      { cluster_id: 'A', conversation_total: 5 },
      { cluster_id: 'B', conversation_total: 5 },
      { cluster_id: 'C', conversation_total: 5 },
      { cluster_id: 'D', conversation_total: 5 },
    ];
    const cells: SimilarityCell[] = [
      { cluster_id: 'A', ttp_code: 'T1', conversation_count: 5 },
      { cluster_id: 'B', ttp_code: 'T2', conversation_count: 5 },
      { cluster_id: 'C', ttp_code: 'T1', conversation_count: 5 },
      { cluster_id: 'D', ttp_code: 'T2', conversation_count: 5 },
    ];
    expect([...orderBySimilarity(clusters, cells)].sort()).toEqual(['A', 'B', 'C', 'D']);
  });
});
