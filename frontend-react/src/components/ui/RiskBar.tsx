export function RiskBar({ score }: { score: number }) {
  const color =
    score >= 70 ? 'bg-red-500' : score >= 40 ? 'bg-amber-500' : 'bg-emerald-500';
  const textColor =
    score >= 70 ? 'text-red-400' : score >= 40 ? 'text-amber-400' : 'text-emerald-400';

  return (
    <div className="flex items-center gap-2">
      <span className={`font-mono text-xs font-medium ${textColor}`}>{score}</span>
      <div className="w-16 h-1 bg-surface-highest rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full ${color}`}
          style={{ width: `${Math.min(score, 100)}%` }}
        />
      </div>
    </div>
  );
}
