import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { CampaignExcerptsPanel } from '../CampaignExcerptsPanel';

function wrap(ui: React.ReactNode) {
  return <MemoryRouter>{ui}</MemoryRouter>;
}

const baseExcerpts = [
  { text: 'first excerpt', occurrence_count: 1, source_conv_id: 'aaaaaaaa-1' },
  { text: 'second excerpt', occurrence_count: 1, source_conv_id: 'bbbbbbbb-2' },
];

describe('CampaignExcerptsPanel', () => {
  it('renders nothing when there are no excerpts', () => {
    const { container } = render(wrap(<CampaignExcerptsPanel excerpts={[]} templatedExcerptCount={0} />));
    expect(container.querySelector('[data-testid="campaign-excerpts"]')).toBeNull();
  });

  it('renders the variants and their source conv links', () => {
    render(wrap(<CampaignExcerptsPanel excerpts={baseExcerpts} templatedExcerptCount={0} />));
    expect(screen.getByText(/first excerpt/)).toBeDefined();
    expect(screen.getByText(/second excerpt/)).toBeDefined();
    expect(screen.getByText('aaaaaaaa')).toBeDefined();
    expect(screen.getByText('bbbbbbbb')).toBeDefined();
  });

  it('does NOT render the Templated badge when templated_excerpt_count <= 1', () => {
    const { container } = render(
      wrap(<CampaignExcerptsPanel excerpts={baseExcerpts} templatedExcerptCount={1} />),
    );
    expect(screen.queryByText(/Templated/)).toBeNull();
    expect(container.querySelector('[data-templated="true"]')).toBeNull();
  });

  it('renders the Templated badge with the count when templated_excerpt_count > 1', () => {
    const { container } = render(
      wrap(<CampaignExcerptsPanel excerpts={baseExcerpts} templatedExcerptCount={58} />),
    );
    expect(screen.getByText(/Templated · 58 IOCs/)).toBeDefined();
    expect(container.querySelector('[data-templated="true"]')).not.toBeNull();
  });

  it('renders occurrence bars on each row when templated', () => {
    const templated = [
      { text: 'A', occurrence_count: 15, source_conv_id: 'a' },
      { text: 'B', occurrence_count: 9, source_conv_id: 'b' },
    ];
    const { container } = render(
      wrap(<CampaignExcerptsPanel excerpts={templated} templatedExcerptCount={58} />),
    );
    expect(container.querySelectorAll('[data-occurrence-bar]').length).toBe(2);
  });

  it('caption mentions total variant count when greater than rendered top 5', () => {
    const six = Array.from({ length: 6 }, (_, i) => ({
      text: `t${i}`,
      occurrence_count: 1,
      source_conv_id: `s${i}`,
    }));
    render(wrap(<CampaignExcerptsPanel excerpts={six} templatedExcerptCount={0} totalVariantCount={42} />));
    expect(screen.getByText(/Top 5 of 42 variants/)).toBeDefined();
  });

  it('caption falls back to "{N} unique excerpts" when totalVariantCount is missing', () => {
    render(wrap(<CampaignExcerptsPanel excerpts={baseExcerpts} templatedExcerptCount={0} />));
    expect(screen.getByText(/2 unique excerpts/)).toBeDefined();
  });

  it('renders the occurrence count chip on rows with > 1 occurrence', () => {
    const rep = [{ text: 'A', occurrence_count: 7, source_conv_id: 's' }];
    render(wrap(<CampaignExcerptsPanel excerpts={rep} templatedExcerptCount={5} />));
    expect(screen.getByText('×7')).toBeDefined();
  });
});
