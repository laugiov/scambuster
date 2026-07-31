import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PersonaDetailPanel } from './PersonaDetailPanel';
import type { PersonaSummary } from '@/types/api';

const mutateSpy = vi.fn();

// Mock the persona hooks used by the panel
vi.mock('@/hooks/usePersonas', () => ({
  usePersonaDetail: vi.fn(() => ({
    data: {
      persona_code: 'elderly_person',
      persona_label: 'Elderly Person',
      persona_tone: 'confused,trusting,slow',
      system_prompt: 'You are a confused elderly person who is easily convinced by strangers online and tends to overshare.',
      is_active: true,
      created_by: 'fixture',
      created_at: '2025-01-15T10:00:00Z',
    },
    isLoading: false,
  })),
  useUpdatePersona: vi.fn(() => ({ mutate: mutateSpy, isPending: false })),
}));

import { usePersonaDetail } from '@/hooks/usePersonas';

// jsdom does not implement scrollIntoView
beforeAll(() => {
  Element.prototype.scrollIntoView = vi.fn();
});

const performance: PersonaSummary = {
  persona_code: 'elderly_person',
  persona_label: 'Elderly Person',
  total_sessions: 10,
  global_avg_reward: 0.75,
  performance_by_scam_type: [
    {
      scam_type_code: 'PHISHING',
      sessions_count: 5,
      reward_avg: 0.8,
    },
    {
      scam_type_code: 'ROMANCE',
      sessions_count: 2,
      reward_avg: 0.6,
    },
  ],
};

describe('PersonaDetailPanel', () => {
  it('renders without crashing', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
  });

  it('displays persona label', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    expect(screen.getByText('Elderly Person')).toBeInTheDocument();
  });

  it('displays active status badge', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    expect(screen.getByText('Active')).toBeInTheDocument();
  });

  it('calls onClose when close button clicked', async () => {
    const onClose = vi.fn();
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={onClose} />,
    );
    const user = userEvent.setup();
    await user.click(screen.getByLabelText('Close'));
    expect(onClose).toHaveBeenCalledOnce();
  });

  it('displays tone tags', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    expect(screen.getByText('confused')).toBeInTheDocument();
    expect(screen.getByText('trusting')).toBeInTheDocument();
    expect(screen.getByText('slow')).toBeInTheDocument();
  });

  it('displays performance by scam type', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    expect(screen.getByText('0.80')).toBeInTheDocument();
    expect(screen.getByText('0.60')).toBeInTheDocument();
  });

  it('displays created by as System for fixtures', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    expect(screen.getByText('System')).toBeInTheDocument();
  });

  it('enters edit mode when Edit is clicked', async () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Edit' }));
    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  it('shows the bandit-bias warning only after the prompt changes (persona has stats)', async () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Edit' }));

    // No change yet → no warning, no reset checkbox
    expect(screen.queryByText(/biases the bandit/i)).not.toBeInTheDocument();

    // Change the prompt → warning + reset checkbox (checked by default)
    const textarea = screen.getByRole('textbox', { name: /System Prompt/i });
    await user.type(textarea, ' Now materially different behaviour.');
    expect(screen.getByText(/biases the bandit/i)).toBeInTheDocument();
    const resetCheckbox = screen.getByRole('checkbox');
    expect(resetCheckbox).toBeChecked();
  });

  it('does not warn when editing a persona that has no accumulated stats', async () => {
    render(
      <PersonaDetailPanel
        personaCode="elderly_person"
        performance={{ ...performance, total_sessions: 0 }}
        onClose={vi.fn()}
      />,
    );
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Edit' }));
    const textarea = screen.getByRole('textbox', { name: /System Prompt/i });
    await user.type(textarea, ' changed');
    expect(screen.queryByText(/biases the bandit/i)).not.toBeInTheDocument();
  });

  it('shows loading state', () => {
    vi.mocked(usePersonaDetail).mockReturnValueOnce({
      data: undefined,
      isLoading: true,
    } as ReturnType<typeof usePersonaDetail>);
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={performance} onClose={vi.fn()} />,
    );
    expect(screen.getByText('Loading...')).toBeInTheDocument();
  });

  it('handles null performance gracefully', () => {
    render(
      <PersonaDetailPanel personaCode="elderly_person" performance={null} onClose={vi.fn()} />,
    );
    expect(screen.getByText('Elderly Person')).toBeInTheDocument();
  });
});
