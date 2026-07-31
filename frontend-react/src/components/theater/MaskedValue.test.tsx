import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import { MaskedValue } from './MaskedValue';

describe('MaskedValue — default-masked invariant', () => {
  it('CRITICAL: in default state (no toggle), DOM must NOT contain the raw sensitive value', () => {
    const RAW_BIC = 'HDFCINBBDEL';
    render(
      <MaskModeProvider>
        <MaskedValue value={RAW_BIC} type="bic" />
      </MaskModeProvider>,
    );
    const html = document.body.innerHTML;
    expect(html).not.toContain(RAW_BIC);
    expect(screen.getByTestId('masked-value').textContent).toMatch(/\*/);
  });

  it('CRITICAL: phone is masked by default and raw is NOT in DOM', () => {
    const RAW = '+919821686885';
    render(
      <MaskModeProvider>
        <MaskedValue value={RAW} type="phone" />
      </MaskModeProvider>,
    );
    expect(document.body.innerHTML).not.toContain(RAW);
  });

  it('non-sensitive type renders raw value (no masking)', () => {
    render(
      <MaskModeProvider>
        <MaskedValue value="https://example.com/path" type="url" />
      </MaskModeProvider>,
    );
    expect(screen.getByTestId('masked-value').textContent).toBe('https://example.com/path');
  });

  it('outside a provider, defaults to masked (defensive)', () => {
    render(<MaskedValue value="HDFCINBBDEL" type="bic" />);
    expect(screen.getByTestId('masked-value').textContent).toMatch(/\*/);
  });
});
