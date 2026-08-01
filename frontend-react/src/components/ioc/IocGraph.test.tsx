import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { IocGraph } from './IocGraph';
import type { IocGraph as IocGraphData } from '@/types/api';

function renderGraph(data: IocGraphData, width?: number, height?: number) {
  return render(
    <MemoryRouter>
      <IocGraph data={data} width={width} height={height} />
    </MemoryRouter>,
  );
}

describe('IocGraph', () => {
  it('renders without crashing with empty data', () => {
    renderGraph({ nodes: [], edges: [] });
  });

  it('shows empty message when 0 or 1 nodes', () => {
    renderGraph({ nodes: [], edges: [] });
    expect(screen.getByText('No related IOCs found')).toBeInTheDocument();
  });

  it('shows empty message with single node', () => {
    renderGraph({
      nodes: [{ id: 'n1', type: 'domain', value: 'example.com', score: 50, center: true }],
      edges: [],
    });
    expect(screen.getByText('No related IOCs found')).toBeInTheDocument();
  });

  it('renders SVG with multiple nodes', () => {
    const data: IocGraphData = {
      nodes: [
        { id: 'n1', type: 'domain', value: 'example.com', score: 50, center: true },
        { id: 'n2', type: 'email', value: 'scam@evil.com', score: 80, center: false },
      ],
      edges: [
        { source: 'n1', target: 'n2', weight: 3, conversations: ['c1'] },
      ],
    };
    const { container } = renderGraph(data);
    const svg = container.querySelector('svg');
    expect(svg).toBeInTheDocument();
  });

  it('renders node labels truncated at 20 chars', () => {
    const data: IocGraphData = {
      nodes: [
        { id: 'n1', type: 'domain', value: 'example.com', score: 50, center: true },
        { id: 'n2', type: 'url', value: 'https://very-long-url-that-exceeds-twenty-chars.com/path', score: 30, center: false },
      ],
      edges: [
        { source: 'n1', target: 'n2', weight: 1, conversations: ['c1'] },
      ],
    };
    renderGraph(data);
    // The truncated label should be 18 chars + '...'
    expect(screen.getByText('https://very-long-...')).toBeInTheDocument();
  });

  it('renders node groups with accessible roles for non-center nodes', () => {
    const data: IocGraphData = {
      nodes: [
        { id: 'n1', type: 'domain', value: 'example.com', score: 50, center: true },
        { id: 'n2', type: 'email', value: 'test@test.com', score: 30, center: false },
      ],
      edges: [
        { source: 'n1', target: 'n2', weight: 1, conversations: ['c1'] },
      ],
    };
    renderGraph(data);
    // Non-center nodes get role="link"
    expect(screen.getByRole('link')).toBeInTheDocument();
  });

  it('renders legend with unique types', () => {
    const data: IocGraphData = {
      nodes: [
        { id: 'n1', type: 'domain', value: 'a.com', score: 50, center: true },
        { id: 'n2', type: 'email', value: 'b@c.com', score: 30, center: false },
        { id: 'n3', type: 'domain', value: 'd.com', score: 20, center: false },
      ],
      edges: [
        { source: 'n1', target: 'n2', weight: 1, conversations: ['c1'] },
        { source: 'n1', target: 'n3', weight: 2, conversations: ['c2'] },
      ],
    };
    renderGraph(data);
    expect(screen.getByText('Domain')).toBeInTheDocument();
    expect(screen.getByText('Email')).toBeInTheDocument();
  });
});
