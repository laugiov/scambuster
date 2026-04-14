import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LanguageSwitcher } from './LanguageSwitcher';
import i18n from '@/i18n';

describe('LanguageSwitcher', () => {
  afterEach(async () => {
    await i18n.changeLanguage('en');
  });

  it('renders compact variant by default', () => {
    render(<LanguageSwitcher />);
    const button = screen.getByRole('button', { name: 'Switch language' });
    expect(button).toBeInTheDocument();
  });

  it('shows current language code in compact mode', () => {
    render(<LanguageSwitcher />);
    expect(screen.getByText('EN')).toBeInTheDocument();
  });

  it('toggles language on click in compact mode', async () => {
    render(<LanguageSwitcher />);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Switch language' }));
    expect(i18n.language).toBe('fr');
  });

  it('renders full variant with both language buttons', () => {
    render(<LanguageSwitcher variant="full" />);
    expect(screen.getByText('English')).toBeInTheDocument();
    expect(screen.getByText('Francais')).toBeInTheDocument();
  });

  it('switches to French when Francais button clicked in full mode', async () => {
    render(<LanguageSwitcher variant="full" />);
    const user = userEvent.setup();
    await user.click(screen.getByText('Francais'));
    expect(i18n.language).toBe('fr');
  });
});
