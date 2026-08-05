import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AbuseReportPanel } from './AbuseReportPanel';
import type { ClusterAbuseReport } from '@/types/threatActor';

const { mockUse } = vi.hoisted(() => ({ mockUse: vi.fn() }));

vi.mock('@/hooks/useClusterAbuseReport', () => ({
  useClusterAbuseReport: (id: string, enabled: boolean) => mockUse(id, enabled),
}));

const report: ClusterAbuseReport = {
  report_type: 'threat-actor-abuse-report',
  generated_from: 'ScamBuster honeypot (first-party observation)',
  actor: { cluster_id: 'c-1', stix_id: 'threat-actor--c-1', name: 'Cluster Zeta', sophistication: 'advanced', first_seen: null, last_seen: null },
  scam_types: ['INVOICE_FRAUD'],
  evidence: { conversation_count: 4, inbound_message_count: 42, actionable_indicator_count: 1, criminal_time_wasted_sec: 9000 },
  temporal: null,
  psychological_profile: { dominant_lever: 'Urgency' },
  actionable_indicators: [
    { type: 'iban', value: 'DE00', recommended_recipient: 'Issuing bank', conv_count: 3, first_observed: null, last_observed: null },
  ],
  narrative: 'Observed across 4 honeypot conversations.',
  text: 'THREAT-ACTOR ABUSE / TAKEDOWN REPORT\n...\nDISCLAIMER: first-party.',
  disclaimer: 'first-party.',
};

describe('AbuseReportPanel', () => {
  beforeEach(() => mockUse.mockReset());

  it('shows a loading state while the report is auto-assembling', () => {
    mockUse.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    render(<AbuseReportPanel clusterId="c-1" />);

    expect(screen.getByText(/Assembling report/i)).toBeTruthy();
    expect(screen.queryByTestId('abuse-report-text')).toBeNull();
  });

  it('renders the report text and a download button when data is present (auto-loaded)', () => {
    mockUse.mockReturnValue({ data: report, isLoading: false, isError: false });
    render(<AbuseReportPanel clusterId="c-1" />);

    expect(screen.getByTestId('abuse-report-text').textContent).toContain('ABUSE / TAKEDOWN REPORT');
    expect(screen.getByTestId('abuse-report-download')).toBeTruthy();
  });

  it('shows the criminal-time-wasted chip in human units', () => {
    mockUse.mockReturnValue({ data: report, isLoading: false, isError: false });
    render(<AbuseReportPanel clusterId="c-1" />);

    // 9000s → 2.5h.
    expect(screen.getByText(/2\.5h wasted/i)).toBeTruthy();
  });

  it('hides the time-wasted chip when nothing was elicited', () => {
    mockUse.mockReturnValue({
      data: { ...report, evidence: { ...report.evidence, criminal_time_wasted_sec: 0 } },
      isLoading: false,
      isError: false,
    });
    render(<AbuseReportPanel clusterId="c-1" />);

    expect(screen.queryByText(/wasted/i)).toBeNull();
  });

  it('shows an error state when the report fails', () => {
    mockUse.mockReturnValue({ data: undefined, isLoading: false, isError: true });
    render(<AbuseReportPanel clusterId="c-1" />);

    expect(screen.getByText(/Could not generate/i)).toBeTruthy();
  });
});
