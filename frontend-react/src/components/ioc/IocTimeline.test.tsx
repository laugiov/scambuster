import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { IocTimeline } from './IocTimeline';
import type { IocObservation } from '@/types/api';

// Recharts uses ResizeObserver which is not available in jsdom
beforeAll(() => {
  global.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
});

const makeObs = (overrides: Partial<IocObservation> = {}): IocObservation => ({
  obs_id: 'obs-1',
  msg_id: 'msg-1',
  conv_id: 'conv-1',
  conv_subject: 'Test Subject',
  conv_status: 'open',
  conv_scam_type: 'PHISHING',
  extraction_method: 'llm',
  ts_observed: '2025-03-01T12:00:00Z',
  ...overrides,
});

describe('IocTimeline', () => {
  it('renders without crashing with empty observations', () => {
    render(<IocTimeline observations={[]} />);
  });

  it('shows empty message when no observations', () => {
    render(<IocTimeline observations={[]} />);
    expect(screen.getByText('No observations found for this IOC')).toBeInTheDocument();
  });

  it('renders timeline heading with observations', () => {
    render(<IocTimeline observations={[makeObs()]} />);
    expect(screen.getByText('Observation Timeline')).toBeInTheDocument();
  });

  it('renders without crashing with multiple observations', () => {
    const observations = [
      makeObs({ obs_id: 'obs-1', ts_observed: '2025-03-01T12:00:00Z' }),
      makeObs({ obs_id: 'obs-2', ts_observed: '2025-03-05T12:00:00Z', extraction_method: 'regex' }),
      makeObs({ obs_id: 'obs-3', ts_observed: '2025-03-10T12:00:00Z', extraction_method: 'header' }),
    ];
    render(<IocTimeline observations={observations} />);
    expect(screen.getByText('Observation Timeline')).toBeInTheDocument();
  });

  it('handles single-day observations without crashing', () => {
    const observations = [
      makeObs({ obs_id: 'obs-1', ts_observed: '2025-03-01T12:00:00Z' }),
      makeObs({ obs_id: 'obs-2', ts_observed: '2025-03-01T14:00:00Z' }),
    ];
    render(<IocTimeline observations={observations} />);
    expect(screen.getByText('Observation Timeline')).toBeInTheDocument();
  });
});
