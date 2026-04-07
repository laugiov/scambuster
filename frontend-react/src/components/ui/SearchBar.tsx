interface SearchBarProps {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  ariaLabel?: string;
}

export function SearchBar({
  value,
  onChange,
  placeholder = 'Search...',
  ariaLabel = 'Search',
}: SearchBarProps) {
  return (
    <div className="relative max-w-md flex-1">
      <svg
        className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-on-surface-dim"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        strokeWidth={1.5}
        aria-hidden="true"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
        />
      </svg>
      <input
        type="text"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full bg-surface-low pl-10 pr-4 py-2.5 rounded-lg text-sm text-on-surface placeholder-on-surface-dim focus:outline-none focus:ring-2 focus:ring-accent"
        aria-label={ariaLabel}
      />
    </div>
  );
}
