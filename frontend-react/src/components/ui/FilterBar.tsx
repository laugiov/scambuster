import { useTranslation } from 'react-i18next';

interface FilterOption {
  value: string;
  label: string;
}

interface FilterBarProps {
  statusFilter: string;
  scamTypeFilter: string;
  onStatusChange: (value: string) => void;
  onScamTypeChange: (value: string) => void;
  statusOptions: FilterOption[];
  scamTypeOptions: FilterOption[];
  onClear: () => void;
  hasActiveFilters: boolean;
}

export function FilterBar({
  statusFilter,
  scamTypeFilter,
  onStatusChange,
  onScamTypeChange,
  statusOptions,
  scamTypeOptions,
  onClear,
  hasActiveFilters,
}: FilterBarProps) {
  const { t } = useTranslation();

  return (
    <div className="flex items-center gap-3 flex-wrap">
      <SelectChip
        label={t('conversations.filterStatus')}
        value={statusFilter}
        options={statusOptions}
        onChange={onStatusChange}
      />
      <SelectChip
        label={t('conversations.filterScamType')}
        value={scamTypeFilter}
        options={scamTypeOptions}
        onChange={onScamTypeChange}
      />
      {hasActiveFilters && (
        <button
          type="button"
          onClick={onClear}
          className="text-xs text-on-surface-dim hover:text-accent transition-colors cursor-pointer"
        >
          {t('conversations.clearFilters')}
        </button>
      )}
    </div>
  );
}

function SelectChip({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: FilterOption[];
  onChange: (value: string) => void;
}) {
  const isActive = value !== '';

  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className={`text-xs px-3 py-1.5 rounded-lg border cursor-pointer transition-colors appearance-none bg-[length:16px_16px] bg-[right_8px_center] bg-no-repeat bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2216%22%20height%3D%2216%22%20fill%3D%22%239ca3af%22%20viewBox%3D%220%200%2016%2016%22%3E%3Cpath%20d%3D%22M4.646%206.646a.5.5%200%200%201%20.708%200L8%209.293l2.646-2.647a.5.5%200%200%201%20.708.708l-3%203a.5.5%200%200%201-.708%200l-3-3a.5.5%200%200%201%200-.708z%22%2F%3E%3C%2Fsvg%3E')] pr-7 ${
        isActive
          ? 'border-accent bg-accent-muted/10 text-accent'
          : 'border-surface-highest bg-surface-low text-on-surface-variant'
      }`}
      style={{ colorScheme: 'dark' }}
    >
      <option value="" className="bg-neutral-800 text-neutral-200">{label}</option>
      {options.map((opt) => (
        <option key={opt.value} value={opt.value} className="bg-neutral-800 text-neutral-200">
          {opt.label}
        </option>
      ))}
    </select>
  );
}
