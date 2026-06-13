import { useMaskMode } from '@/hooks/useMaskMode';
import { displayValue, isSensitiveType } from '@/lib/iocMask';

/**
 * Spec 097 — Renders an IOC value through the centralized mask state.
 * MUST be the ONLY way the Theater renders sensitive IOC values to
 * guarantee that the default-masked invariant is never bypassed.
 */
export function MaskedValue({ value, type, className }: { value: string; type: string; className?: string }) {
  const { masked } = useMaskMode();
  const shown = displayValue(value, type, masked);
  const sensitive = isSensitiveType(type);
  return (
    <span className={className} data-testid="masked-value" data-sensitive={sensitive ? 'true' : 'false'}>
      {shown}
    </span>
  );
}
