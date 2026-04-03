import { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { IocGraph as IocGraphData } from '@/types/api';

const TYPE_COLORS: Record<string, string> = {
  domain: '#60a5fa',   // blue
  url: '#a78bfa',      // violet
  email: '#34d399',    // green
  ipv4: '#f87171',     // red
  ipv6: '#f87171',
  phone: '#fbbf24',    // yellow
  iban: '#fb923c',     // orange
  wallet_btc: '#fb923c',
  wallet_eth: '#fb923c',
  wallet_xmr: '#fb923c',
  sha256: '#94a3b8',   // grey
  md5: '#94a3b8',
  sha1: '#94a3b8',
};

const DEFAULT_COLOR = '#64748b';

interface Props {
  data: IocGraphData;
  width?: number;
  height?: number;
}

export function IocGraph({ data, width = 700, height = 500 }: Props) {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const cx = width / 2;
  const cy = height / 2;

  const layout = useMemo(() => {
    if (data.nodes.length === 0) return { nodes: [], edges: [] };

    const centerNode = data.nodes.find((n) => n.center);
    const outerNodes = data.nodes.filter((n) => !n.center);
    const count = outerNodes.length;
    const radius = Math.min(cx, cy) - 60;

    const positioned = data.nodes.map((node) => {
      if (node.center) {
        return { ...node, x: cx, y: cy };
      }

      const idx = outerNodes.indexOf(node);
      const angle = (2 * Math.PI * idx) / count - Math.PI / 2;

      return {
        ...node,
        x: cx + radius * Math.cos(angle),
        y: cy + radius * Math.sin(angle),
      };
    });

    const nodeMap = new Map(positioned.map((n) => [n.id, n]));

    const positionedEdges = data.edges.map((edge) => {
      const source = nodeMap.get(edge.source);
      const target = nodeMap.get(edge.target);

      return {
        ...edge,
        x1: source?.x ?? cx,
        y1: source?.y ?? cy,
        x2: target?.x ?? cx,
        y2: target?.y ?? cy,
      };
    });

    return { nodes: positioned, edges: positionedEdges, centerNode };
  }, [data, cx, cy]);

  if (data.nodes.length <= 1) {
    return (
      <div className="flex items-center justify-center h-48 text-on-surface-dim text-sm">
        {t('iocDetail.noRelated')}
      </div>
    );
  }

  const maxWeight = Math.max(...data.edges.map((e) => e.weight), 1);

  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      className="w-full h-auto"
    >
      {/* Edges */}
      {layout.edges.map((edge) => {
        const opacity = 0.2 + 0.6 * (edge.weight / maxWeight);
        const strokeWidth = 1 + 2 * (edge.weight / maxWeight);

        return (
          <line
            key={`${edge.source}-${edge.target}`}
            x1={edge.x1}
            y1={edge.y1}
            x2={edge.x2}
            y2={edge.y2}
            stroke="currentColor"
            className="text-on-surface-dim"
            strokeOpacity={opacity}
            strokeWidth={strokeWidth}
          />
        );
      })}

      {/* Nodes */}
      {layout.nodes.map((node) => {
        const color = TYPE_COLORS[node.type] ?? DEFAULT_COLOR;
        const r = node.center ? 24 : 16;
        const label = node.value.length > 20 ? node.value.slice(0, 18) + '...' : node.value;

        return (
          <g
            key={node.id}
            className="cursor-pointer"
            onClick={() => { if (!node.center) navigate(`/ioc-explorer/${node.id}`); }}
            role={node.center ? undefined : 'link'}
            tabIndex={node.center ? undefined : 0}
            onKeyDown={(e) => { if (!node.center && (e.key === 'Enter')) navigate(`/ioc-explorer/${node.id}`); }}
          >
            {/* Node circle */}
            <circle
              cx={node.x}
              cy={node.y}
              r={r}
              fill={color}
              fillOpacity={node.center ? 0.9 : 0.7}
              stroke={node.center ? '#fff' : color}
              strokeWidth={node.center ? 3 : 1.5}
              className={node.center ? '' : 'hover:fill-opacity-100 transition-all'}
            />

            {/* Type label inside circle */}
            <text
              x={node.x}
              y={node.y}
              textAnchor="middle"
              dominantBaseline="central"
              className="fill-white text-[8px] font-bold uppercase pointer-events-none select-none"
            >
              {node.type.slice(0, 4)}
            </text>

            {/* Value label below */}
            <text
              x={node.x}
              y={node.y + r + 12}
              textAnchor="middle"
              className="fill-current text-on-surface-dim text-[10px] pointer-events-none select-none"
            >
              {label}
            </text>
          </g>
        );
      })}
    </svg>
  );
}
