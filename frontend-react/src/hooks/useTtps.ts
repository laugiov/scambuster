import { useQuery } from '@tanstack/react-query';
import axios from 'axios';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import type {
  ClusterTtpMatrix,
  ClusterTtpProfile,
  ConversationTtps,
  IocTtps,
  PersonaTtpMatrix,
  StimulusTtpMatrix,
  TtpClusters,
  TtpConversations,
  TtpIocs,
  TtpPhaseTransitions,
  TtpPhaseTrend,
  TtpReviewQueue,
  TtpSequenceGrouping,
  TtpSequences,
  TtpTaxonomyResponse,
} from '@/types/ttp';

/** Server page size for the TTP → conversations pivot (backend default). */
export const TTP_CONVERSATIONS_PAGE_SIZE = 20;

/**
 * The full closed TTP taxonomy with per-entry usage counters (GET /ttps).
 * Returns every taxonomy entry — including zero-observation ones — so the
 * TTP Explorer can render honest coverage. The response object is returned
 * whole (taxonomy_version + ttps); consumers read `data.ttps`.
 */
export function useTtpTaxonomy() {
  return useQuery<TtpTaxonomyResponse>({
    queryKey: ['ttp-taxonomy'],
    queryFn: async () => {
      const { data } = await client.get<TtpTaxonomyResponse>(ENDPOINTS.ttps.list);
      return data;
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Aggregated TTP profile for a threat-actor cluster.
 * Resolves to null (not an error) when the cluster is unknown (404), so the
 * panel can degrade to an empty state instead of throwing.
 */
export function useClusterTtps(clusterId: string) {
  return useQuery<ClusterTtpProfile | null>({
    queryKey: ['cluster-ttps', clusterId],
    queryFn: async () => {
      try {
        const { data } = await client.get<ClusterTtpProfile>(ENDPOINTS.ttps.forCluster(clusterId));
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!clusterId,
  });
}

/**
 * Shared-playbook matrix across every live threat-actor cluster
 * (GET /ttps/cluster-matrix). Always enabled; the consumer degrades to an
 * empty note when there are no clusters/cells or the request fails, so this
 * section can never surface a hard error on the TTP Explorer.
 */
export function useClusterTtpMatrix() {
  return useQuery<ClusterTtpMatrix>({
    queryKey: ['cluster-ttp-matrix'],
    queryFn: async () => {
      const { data } = await client.get<ClusterTtpMatrix>(ENDPOINTS.ttps.clusterMatrix);
      return data;
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Weekly confirmed-observation counts per kill-chain phase over the last 8 ISO
 * weeks (GET /ttps/phase-trend), zero-filled and bucketed server-side on the
 * message timestamp. Resolves to null (not an error) on a 404 so the trend
 * chart can degrade to an empty state instead of throwing.
 */
export function useTtpPhaseTrend() {
  return useQuery<TtpPhaseTrend | null>({
    queryKey: ['ttp-phase-trend'],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpPhaseTrend>(ENDPOINTS.ttps.phaseTrend);
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Read-only review queue of observations awaiting analyst triage
 * (GET /ttps/review-queue), newest message first. Items carry evidence
 * offsets only — the quote is reconstructed client-side from the message
 * body on demand. Resolves to null (not an error) on a 404 so the review
 * tab can degrade to an empty state instead of throwing.
 */
export function useTtpReviewQueue() {
  return useQuery<TtpReviewQueue | null>({
    queryKey: ['ttp-review-queue'],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpReviewQueue>(ENDPOINTS.ttps.reviewQueue);
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Top TTP sequences per group (GET /ttps/sequences?group=cluster|scam_type):
 * cross-boundary bigrams with occurrence and conversation counts, filtered by
 * the server-side minimum-support threshold (echoed as min_support).
 * Resolves to null (not an error) on a 404 so the panel can degrade to an
 * empty state instead of throwing.
 */
export function useTtpSequences(group: TtpSequenceGrouping) {
  return useQuery<TtpSequences | null>({
    queryKey: ['ttp-sequences', group],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpSequences>(ENDPOINTS.ttps.sequences, {
          params: { group },
        });
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Global kill-chain phase-transition aggregate (GET /ttps/phase-transitions),
 * confirmed-only cross-boundary bigrams grouped by endpoint phases. Resolves
 * to null (not an error) on a 404 so the matrix can degrade to an empty state
 * instead of throwing.
 */
export function useTtpPhaseTransitions() {
  return useQuery<TtpPhaseTransitions | null>({
    queryKey: ['ttp-phase-transitions'],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpPhaseTransitions>(ENDPOINTS.ttps.phaseTransitions);
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Persona × TTP matrix (GET /ttps/persona-matrix), confirmed observations only,
 * with the null-persona conversations excluded from the grid and counted.
 * Resolves to null (not an error) on a 404 so the matrix can degrade to an
 * empty state instead of throwing.
 */
export function usePersonaTtpMatrix() {
  return useQuery<PersonaTtpMatrix | null>({
    queryKey: ['ttp-persona-matrix'],
    queryFn: async () => {
      try {
        const { data } = await client.get<PersonaTtpMatrix>(ENDPOINTS.ttps.personaMatrix);
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * Stimulus × TTP matrix (GET /ttps/stimulus-matrix) over the revelation-message
 * population (confirmed TTP observations on messages with an enriched, non-null
 * stimulus context). Resolves to null (not an error) on a 404 so the matrix can
 * degrade to an empty state instead of throwing.
 */
export function useStimulusTtpMatrix() {
  return useQuery<StimulusTtpMatrix | null>({
    queryKey: ['ttp-stimulus-matrix'],
    queryFn: async () => {
      try {
        const { data } = await client.get<StimulusTtpMatrix>(ENDPOINTS.ttps.stimulusMatrix);
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    staleTime: 60_000,
    refetchOnWindowFocus: false,
  });
}

/**
 * IOCs co-observed with a TTP (GET /ttps/{code}/iocs) — the TTP → IOC pivot.
 * Resolves to null on 404 (unknown TTP code) so the expand panel can degrade to
 * an empty state. Fetches only when a code is supplied: the caller mounts this
 * hook lazily (inside the row's expand panel) so no observation is needed to
 * gate it — an unmounted panel never runs the query.
 */
export function useIocsForTtp(ttpCode: string) {
  return useQuery<TtpIocs | null>({
    queryKey: ['ttp-iocs', ttpCode],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpIocs>(ENDPOINTS.ttps.iocsFor(ttpCode));
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!ttpCode,
  });
}

/**
 * Live threat-actor clusters practicing a TTP (GET /ttps/{code}/clusters),
 * widest conversation span first, capped server-side with a truncated flag.
 * Resolves to null (not an error) on 404 (unknown TTP code) so the clusters
 * tab can degrade to an empty state instead of throwing.
 */
export function useTtpClusters(ttpCode: string) {
  return useQuery<TtpClusters | null>({
    queryKey: ['ttp-clusters', ttpCode],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpClusters>(ENDPOINTS.ttps.clustersFor(ttpCode));
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!ttpCode,
  });
}

/**
 * Server-paginated conversations carrying observations of a TTP
 * (GET /ttps/{code}/conversations). The page drives the offset; the response
 * echoes total/limit/offset so the shared Pagination component can run off the
 * server total. Resolves to null (not an error) on 404 (unknown TTP code) so
 * the conversations tab can degrade to an empty state instead of throwing.
 */
export function useTtpConversations(ttpCode: string, page = 1) {
  return useQuery<TtpConversations | null>({
    queryKey: ['ttp-conversations', ttpCode, page],
    queryFn: async () => {
      try {
        const { data } = await client.get<TtpConversations>(
          ENDPOINTS.ttps.conversationsFor(ttpCode),
          {
            params: {
              limit: TTP_CONVERSATIONS_PAGE_SIZE,
              offset: (page - 1) * TTP_CONVERSATIONS_PAGE_SIZE,
            },
          },
        );
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!ttpCode,
  });
}

/**
 * TTPs co-observed with an IOC indicator (GET /iocs/{id}/ttps) — the IOC → TTP
 * pivot. Resolves to null on 404 (unknown indicator); a known indicator with no
 * co-occurring TTPs returns an empty list (not null).
 */
export function useTtpsForIoc(indicatorId: string) {
  return useQuery<IocTtps | null>({
    queryKey: ['ioc-ttps', indicatorId],
    queryFn: async () => {
      try {
        const { data } = await client.get<IocTtps>(ENDPOINTS.ttps.forIoc(indicatorId));
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!indicatorId,
  });
}

/**
 * TTP observations + per-message elicitation timeline for a conversation.
 * Resolves to null on 404 (unknown/soft-deleted conversation); a known
 * conversation with no TTPs returns empty lists (not null).
 */
export function useConversationTtps(conversationId: string) {
  return useQuery<ConversationTtps | null>({
    queryKey: ['conversation-ttps', conversationId],
    queryFn: async () => {
      try {
        const { data } = await client.get<ConversationTtps>(
          ENDPOINTS.ttps.forConversation(conversationId),
        );
        return data;
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
          return null;
        }
        throw error;
      }
    },
    enabled: !!conversationId,
  });
}
