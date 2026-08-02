// Client-side ordering of the shared-playbook matrix rows by playbook similarity.
// Each cluster's playbook is its NORMALIZED TTP row vector — per-conversation
// shares (conversation_count / conversation_total) so a high-volume cluster does
// not dominate the geometry. Rows are then chained by greedy nearest-neighbor
// from the highest-volume cluster, so clusters running similar playbooks sit
// adjacent. Pure and deterministic: no dependency, no I/O, no mutation of inputs.

/** The minimum a cluster row needs to build its normalized playbook vector. */
export interface SimilarityCluster {
  cluster_id: string;
  /** Distinct conversations with any confirmed observation (the normalizer). */
  conversation_total: number;
}

/** One populated (cluster, TTP) cell feeding the normalized vectors. */
export interface SimilarityCell {
  cluster_id: string;
  ttp_code: string;
  /** Distinct conversations in the cluster exhibiting the TTP. */
  conversation_count: number;
}

/**
 * Cosine similarity of two sparse vectors (Map<dimension, magnitude>). For the
 * non-negative per-conversation shares used here the result lies in [0, 1].
 * Returns 0 when either vector is empty or has zero norm — an all-zero playbook
 * is similar to nothing, so it never gets pulled toward any neighbour.
 */
export function cosineSimilarity(a: Map<string, number>, b: Map<string, number>): number {
  let dot = 0;
  let normA = 0;
  let normB = 0;
  for (const [key, va] of a) {
    normA += va * va;
    const vb = b.get(key);
    if (vb !== undefined) dot += va * vb;
  }
  for (const vb of b.values()) normB += vb * vb;
  if (normA === 0 || normB === 0) return 0;
  return dot / (Math.sqrt(normA) * Math.sqrt(normB));
}

/** Deterministic total order over clusters: widest volume first, id ascending. */
function byVolumeThenId(a: SimilarityCluster, b: SimilarityCluster): number {
  if (a.conversation_total !== b.conversation_total) {
    return b.conversation_total - a.conversation_total;
  }
  return a.cluster_id < b.cluster_id ? -1 : a.cluster_id > b.cluster_id ? 1 : 0;
}

/**
 * Order cluster ids so clusters with similar normalized playbooks are adjacent.
 * Greedy nearest-neighbor chain seeded at the highest-volume cluster
 * (conversation_total DESC, cluster_id ASC tiebreak); at each step the still
 * unplaced cluster most similar to the LAST placed one is appended. Every
 * tiebreak (equal cosine, or a seed tie) falls back to the same
 * volume-then-id order, so the output is fully deterministic. Inputs are not
 * mutated.
 */
export function orderBySimilarity(clusters: SimilarityCluster[], cells: SimilarityCell[]): string[] {
  if (clusters.length <= 1) {
    return clusters.map((c) => c.cluster_id);
  }

  const totalById = new Map<string, number>();
  for (const c of clusters) totalById.set(c.cluster_id, c.conversation_total);

  // Normalized (per-conversation) playbook vector per cluster. A zero total
  // leaves the vector empty, so cosine against it is 0 (never NaN).
  const vectors = new Map<string, Map<string, number>>();
  for (const c of clusters) vectors.set(c.cluster_id, new Map());
  for (const cell of cells) {
    const vec = vectors.get(cell.cluster_id);
    if (!vec) continue;
    const total = totalById.get(cell.cluster_id) ?? 0;
    if (total > 0) vec.set(cell.ttp_code, cell.conversation_count / total);
  }

  // A single stable candidate order drives both the seed and every tiebreak.
  const byVolume = [...clusters].sort(byVolumeThenId);

  const placed = new Set<string>();
  const order: string[] = [];

  let current = byVolume[0].cluster_id;
  order.push(current);
  placed.add(current);

  while (order.length < clusters.length) {
    const currentVec = vectors.get(current);
    let best: string | null = null;
    let bestSim = -1;
    // Scan candidates in volume-then-id order and keep the FIRST with the
    // strictly-highest similarity, so equal-cosine ties resolve by that order.
    for (const cand of byVolume) {
      if (placed.has(cand.cluster_id)) continue;
      const sim = currentVec ? cosineSimilarity(currentVec, vectors.get(cand.cluster_id) as Map<string, number>) : 0;
      if (sim > bestSim) {
        bestSim = sim;
        best = cand.cluster_id;
      }
    }
    // best is always set here: the loop only runs while an unplaced cluster remains.
    current = best as string;
    order.push(current);
    placed.add(current);
  }

  return order;
}
