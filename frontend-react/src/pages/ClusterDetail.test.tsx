import { describe, it, expect, beforeAll, afterAll, afterEach, vi } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { server } from '@/__tests__/mocks/server';
import { ClusterDetail } from './ClusterDetail';
import '../i18n';

const BASE = '/api/v1';
const CLUSTER_ID = 'aaaaaaaa-bbbb-4000-8000-000000000001';

const mockNavigate = vi.fn();
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

const mockClusterDetail = {
  cluster_id: CLUSTER_ID,
  stix_id: 'threat-actor--12345678-1234-5678-1234-567812345678',
  name: 'ScamBuster Cluster #ABCD (3 conversations)',
  status: 'active',
  conversation_count: 3,
  anchor_ioc_count: 2,
  anchor_ioc_types: ['iban', 'phone'],
  sophistication: 'minimal',
  primary_scam_types: ['INVOICE_FRAUD'],
  first_seen: '2026-02-01T00:00:00Z',
  last_seen: '2026-04-01T00:00:00Z',
  algorithm_version: '1.0',
  anchor_iocs: [
    {
      indicator_id: 'ind-iban-1',
      ioc_type: 'iban',
      ioc_value: 'DE89370400440532013000',
      ioc_value_norm: 'DE89370400440532013000',
      value_norm_hash: 'hash1',
      conv_count: 3,
      first_observed: '2026-02-01T00:00:00Z',
      last_observed: '2026-04-01T00:00:00Z',
      conv_ids: ['conv-1', 'conv-2', 'conv-3'],
      dominant_semantic_role: null,
      dominant_stimulus: null,
      avg_urgency_score: null,
    },
    {
      indicator_id: 'ind-phone-1',
      ioc_type: 'phone',
      ioc_value: '+49 151 2345 6789',
      ioc_value_norm: '+491512345678',
      value_norm_hash: 'hash2',
      conv_count: 3,
      first_observed: '2026-02-01T00:00:00Z',
      last_observed: '2026-04-01T00:00:00Z',
      conv_ids: ['conv-1', 'conv-2', 'conv-3'],
      dominant_semantic_role: null,
      dominant_stimulus: null,
      avg_urgency_score: null,
    },
  ],
  conversations: [
    { conv_id: 'conv-1', status: 'closed', score_risk: 75, ts_first: '2026-02-01T00:00:00Z', ts_last: '2026-02-05T00:00:00Z', scam_type: 'INVOICE_FRAUD', linked_at: '2026-02-05T00:00:00Z' },
    { conv_id: 'conv-2', status: 'open', score_risk: 65, ts_first: '2026-02-10T00:00:00Z', ts_last: '2026-02-15T00:00:00Z', scam_type: 'INVOICE_FRAUD', linked_at: '2026-02-15T00:00:00Z' },
    { conv_id: 'conv-3', status: 'closed', score_risk: 80, ts_first: '2026-02-20T00:00:00Z', ts_last: '2026-02-25T00:00:00Z', scam_type: 'INVOICE_FRAUD', linked_at: '2026-02-25T00:00:00Z' },
  ],
  sample_excerpts: [],
  behavioral_profile: null,
};

const detailHandler = http.get(`${BASE}/clusters/:id`, () => HttpResponse.json(mockClusterDetail));

beforeAll(() => server.listen({ onUnhandledRequest: 'bypass' }));
afterEach(() => {
  server.resetHandlers();
  mockNavigate.mockReset();
});
afterAll(() => server.close());

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={[`/clusters/${CLUSTER_ID}`]}>
          <Routes>
            <Route path="/clusters/:id" element={children} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>
    );
  };
}

describe('ClusterDetail — navigation icon (Task 1.2)', () => {
  it('renders a navigation icon button for each anchor IOC', async () => {
    server.use(detailHandler);
    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    const navButtons = screen.getAllByTitle('View IOC details');
    expect(navButtons.length).toBe(2);
  });

  it('navigates to /ioc-explorer/{id} when navigation icon is clicked', async () => {
    server.use(detailHandler);
    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    const navButtons = screen.getAllByTitle('View IOC details');
    fireEvent.click(navButtons[0]);

    expect(mockNavigate).toHaveBeenCalledWith('/ioc-explorer/ind-iban-1');
  });

  it('does not toggle filter when navigation icon is clicked (event propagation stopped)', async () => {
    server.use(detailHandler);
    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    // Initial state: 3 conversations visible
    expect(screen.getByText(/Conversations \(3\)/)).toBeDefined();

    // Click navigation icon — filter should NOT activate
    const navButtons = screen.getAllByTitle('View IOC details');
    fireEvent.click(navButtons[0]);

    // Conversation count should remain 3 (no filter applied)
    expect(screen.getByText(/Conversations \(3\)/)).toBeDefined();
  });
});

describe('ClusterDetail — Campaign Excerpts (Task 1.3 + audit fixes)', () => {
  it('renders Campaign Excerpts section when excerpts are present', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      sample_excerpts: [
        { text: 'Wire transfer demanded urgently to avoid penalties', occurrence_count: 1, source_conv_id: 'conv-1' },
        { text: 'Please send Bitcoin to the address below', occurrence_count: 1, source_conv_id: 'conv-2' },
      ],
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Campaign Excerpts/i)).toBeDefined();
    });

    expect(screen.getByText(/Wire transfer demanded urgently/)).toBeDefined();
    expect(screen.getByText(/Please send Bitcoin/)).toBeDefined();
  });

  it('hides Campaign Excerpts section when no excerpts', async () => {
    server.use(detailHandler);
    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    expect(screen.queryByText(/Campaign Excerpts/i)).toBeNull();
  });

  it('renders at most 5 excerpts even if more provided', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      sample_excerpts: [
        { text: 'Excerpt 1', occurrence_count: 1, source_conv_id: 'conv-1' },
        { text: 'Excerpt 2', occurrence_count: 1, source_conv_id: 'conv-2' },
        { text: 'Excerpt 3', occurrence_count: 1, source_conv_id: 'conv-3' },
        { text: 'Excerpt 4', occurrence_count: 1, source_conv_id: 'conv-4' },
        { text: 'Excerpt 5', occurrence_count: 1, source_conv_id: 'conv-5' },
        { text: 'Excerpt 6', occurrence_count: 1, source_conv_id: 'conv-6' },
        { text: 'Excerpt 7', occurrence_count: 1, source_conv_id: 'conv-7' },
      ],
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Campaign Excerpts/i)).toBeDefined();
    });

    expect(screen.getByText(/Excerpt 5/)).toBeDefined();
    expect(screen.queryByText(/Excerpt 6/)).toBeNull();
    expect(screen.queryByText(/Excerpt 7/)).toBeNull();
  });

  it('displays occurrence count badge when excerpt is repeated', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      sample_excerpts: [
        { text: 'Repeated template excerpt', occurrence_count: 3, source_conv_id: 'conv-1' },
      ],
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Campaign Excerpts/i)).toBeDefined();
    });

    expect(screen.getByText('×3')).toBeDefined();
  });

  it('hides occurrence count badge when excerpt is unique', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      sample_excerpts: [
        { text: 'Unique excerpt', occurrence_count: 1, source_conv_id: 'conv-1' },
      ],
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Unique excerpt/)).toBeDefined();
    });

    expect(screen.queryByText('×1')).toBeNull();
  });

  it('renders source conversation link for each excerpt', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      sample_excerpts: [
        { text: 'Test excerpt', occurrence_count: 1, source_conv_id: 'abcdef1234567890' },
      ],
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Test excerpt/)).toBeDefined();
    });

    const link = screen.getByText('abcdef12');
    expect(link).toBeDefined();
    expect(link.getAttribute('href')).toBe('/conversations/abcdef1234567890');
  });
});

describe('ClusterDetail — Threat Profile section (Sprint 2)', () => {
  it('renders Threat Profile section when behavioral_profile present', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      behavioral_profile: {
        dominant_stimulus: 'urgency-pressure',
        dominant_stimulus_count: 8,
        avg_urgency_score: 0.76,
        dominant_revelation_turn: 1,
        hesitation_count: 0,
        language_switch_count: 0,
        templated_excerpt_count: 3,
        total_enriched_iocs: 10,
      },
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Threat Profile/i)).toBeDefined();
    });

    expect(screen.getByText(/Urgency Pressure/i)).toBeDefined();
    expect(screen.getByText(/76%/)).toBeDefined();
    expect(screen.getByText(/Turn 1/)).toBeDefined();
  });

  it('hides Threat Profile when no enriched IOCs', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      behavioral_profile: null,
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    expect(screen.queryByText(/Threat Profile/i)).toBeNull();
  });

  it('shows templated excerpt count when > 0', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      behavioral_profile: {
        dominant_stimulus: 'urgency-pressure',
        dominant_stimulus_count: 8,
        avg_urgency_score: 0.76,
        dominant_revelation_turn: 1,
        hesitation_count: 0,
        language_switch_count: 0,
        templated_excerpt_count: 3,
        total_enriched_iocs: 10,
      },
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/Threat Profile/i)).toBeDefined();
    });

    expect(screen.getByText(/3 IOCs share identical/i)).toBeDefined();
  });
});

describe('ClusterDetail — anchor IOC behavioral pills (Sprint 2)', () => {
  it('displays semantic role pill when present', async () => {
    server.use(http.get(`${BASE}/clusters/:id`, () => HttpResponse.json({
      ...mockClusterDetail,
      anchor_iocs: [
        {
          ...mockClusterDetail.anchor_iocs[0],
          dominant_semantic_role: 'Payment Destination',
          dominant_stimulus: 'urgency-pressure',
          avg_urgency_score: 0.78,
        },
        mockClusterDetail.anchor_iocs[1],
      ],
    })));

    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    expect(screen.getByText('Payment Destination')).toBeDefined();
    expect(screen.getByText(/78%/)).toBeDefined();
  });

  it('hides pills when no behavioral data on anchor', async () => {
    server.use(detailHandler);
    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    expect(screen.queryByText('Payment Destination')).toBeNull();
  });
});

describe('ClusterDetail — anchor IOC row click (existing filter behavior)', () => {
  it('toggles filter when row body is clicked', async () => {
    server.use(detailHandler);
    render(<ClusterDetail />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('DE89370400440532013000')).toBeDefined();
    });

    // Click the IOC value text (not the icon)
    const ibanText = screen.getByText('DE89370400440532013000');
    fireEvent.click(ibanText);

    // Filter applied — should show "3 / 3" indicator if all 3 share the IOC
    // (count stays the same here since all 3 conv_ids match)
    await waitFor(() => {
      const filterIndicator = screen.queryByText(/Filtered by/);
      expect(filterIndicator).not.toBeNull();
    });
  });
});
