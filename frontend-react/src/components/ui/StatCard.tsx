interface StatCardProps {
  label: string;
  value: string | number;
  subtitle?: React.ReactNode;
  subtitleColor?: string;
}

export function StatCard({ label, value, subtitle, subtitleColor }: StatCardProps) {
  return (
    <div className="bg-surface-low rounded-lg p-5">
      <p className="text-xs text-on-surface-dim uppercase tracking-widest mb-2">{label}</p>
      <p className="text-3xl font-light text-on-surface">{value}</p>
      {subtitle && (
        <p className={`text-xs mt-1 ${subtitleColor ?? 'text-on-surface-dim'}`}>{subtitle}</p>
      )}
    </div>
  );
}
