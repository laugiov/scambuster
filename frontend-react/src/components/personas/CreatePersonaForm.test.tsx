import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CreatePersonaForm } from './CreatePersonaForm';
import type { MetaScamType } from '@/types/api';

const mutateSpy = vi.fn();

vi.mock('@/hooks/usePersonas', () => ({
  useCreatePersona: vi.fn(() => ({ mutate: mutateSpy, isPending: false })),
}));

const scamTypes: MetaScamType[] = [
  { code: 'PHISHING', label: 'Phishing', description: null, active: true },
  { code: 'ROMANCE', label: 'Romance', description: null, active: true },
];

const longPrompt =
  'You are a persona created for the CRUD test suite with clearly distinct traits and behaviour patterns that exceed the minimum.';

beforeEach(() => {
  mutateSpy.mockClear();
});

describe('CreatePersonaForm', () => {
  it('renders the form title and scam-type options', () => {
    render(<CreatePersonaForm scamTypes={scamTypes} onClose={vi.fn()} />);
    expect(screen.getByText('Add a persona')).toBeInTheDocument();
    expect(screen.getByText('Phishing')).toBeInTheDocument();
  });

  it('rejects an invalid code without calling the API', async () => {
    render(<CreatePersonaForm scamTypes={scamTypes} onClose={vi.fn()} />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(/Code/i), 'BAD-CODE');
    await user.type(screen.getByLabelText('Label'), 'Some label');
    await user.type(screen.getByLabelText(/Tone/i), 'formal');
    await user.type(screen.getByLabelText(/System Prompt/i), longPrompt);
    await user.click(screen.getByRole('button', { name: 'Create persona' }));

    expect(screen.getByText(/must be snake_case/i)).toBeInTheDocument();
    expect(mutateSpy).not.toHaveBeenCalled();
  });

  it('submits a valid persona', async () => {
    render(<CreatePersonaForm scamTypes={scamTypes} onClose={vi.fn()} />);
    const user = userEvent.setup();

    await user.type(screen.getByLabelText(/Code/i), 'logistics_dispatcher');
    await user.type(screen.getByLabelText('Label'), 'Logistics dispatcher');
    await user.type(screen.getByLabelText(/Tone/i), 'formal, busy');
    await user.type(screen.getByLabelText(/System Prompt/i), longPrompt);
    await user.click(screen.getByLabelText('Phishing'));
    await user.click(screen.getByRole('button', { name: 'Create persona' }));

    expect(mutateSpy).toHaveBeenCalledTimes(1);
    const body = mutateSpy.mock.calls[0][0];
    expect(body.persona_code).toBe('logistics_dispatcher');
    expect(body.scam_type_codes).toEqual(['PHISHING']);
  });
});
