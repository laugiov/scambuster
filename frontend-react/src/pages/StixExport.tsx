import { useState } from 'react';
import { useCampaignCandidates, useStixExport } from '@/hooks/useStix';
import { useAutonomyStats } from '@/hooks/useStats';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

const IOC_TYPE_OPTIONS = [
  { key: 'ipv4', label: 'IPv4' },
  { key: 'domain', label: 'Domain' },
  { key: 'sha256', label: 'SHA256' },
  { key: 'url', label: 'URL' },
  { key: 'email', label: 'Email' },
] as const;

export function StixExport() {
  const { data: candidates, isLoading, error, refetch } = useCampaignCandidates();
  const { data: stats } = useAutonomyStats();
  const exportMutation = useStixExport();

  const [selectedCampaign, setSelectedCampaign] = useState<string>('all');
  const [bundleName, setBundleName] = useState(`ScamBuster_Export_${new Date().toISOString().slice(0, 10)}`);
  const [selectedTypes, setSelectedTypes] = useState<Set<string>>(new Set(['ipv4', 'domain', 'sha256', 'url', 'email']));
  const [minConfidence, setMinConfidence] = useState(75);
  const [includeRelationships, setIncludeRelationships] = useState(true);

  if (isLoading) return <Loading message="Loading export configuration..." />;
  if (error) return <ErrorMessage message="Failed to load campaign data" onRetry={() => void refetch()} />;

  const safeCandidates = candidates ?? [];

  function toggleType(key: string) {
    setSelectedTypes((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  async function handleExport() {
    if (selectedCampaign === 'all' && safeCandidates.length === 0) return;
    const campaignId = selectedCampaign === 'all'
      ? safeCandidates[0]?.campaign_id ?? ''
      : selectedCampaign;
    if (!campaignId) return;
    await exportMutation.mutateAsync(campaignId);
  }

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">STIX 2.1 Export Center</h1>
        <p className="text-xs text-on-surface-dim mt-1">Generate and download Threat Intelligence bundles</p>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          label="Exportable IOCs"
          value={stats?.iocs.total ?? 0}
        />
        <StatCard
          label="Campaigns Available"
          value={safeCandidates.length}
        />
        <StatCard
          label="Last Export"
          value={exportMutation.data ? 'Just now' : 'Never'}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Left: Export Configuration */}
        <div className="bg-surface-low rounded-lg p-6 space-y-5">
          <h2 className="text-base font-medium text-on-surface">Export Configuration</h2>

          <FormField label="Bundle Name">
            <input
              type="text"
              value={bundleName}
              onChange={(e) => setBundleName(e.target.value)}
              className="w-full bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-accent"
            />
          </FormField>

          <FormField label="Campaign Filter">
            <select
              value={selectedCampaign}
              onChange={(e) => setSelectedCampaign(e.target.value)}
              className="w-full bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-accent"
            >
              <option value="all">All Campaigns</option>
              {safeCandidates.map((c) => (
                <option key={c.campaign_id} value={c.campaign_id}>
                  {c.campaign_id.slice(0, 8)} (PPV: {(c.ppv * 100).toFixed(0)}%, {c.hits_total} hits)
                </option>
              ))}
            </select>
          </FormField>

          <FormField label="IOC Types">
            <div className="flex flex-wrap gap-2">
              {IOC_TYPE_OPTIONS.map((opt) => (
                <button
                  key={opt.key}
                  onClick={() => toggleType(opt.key)}
                  className={`px-3 py-1.5 text-xs rounded transition-colors cursor-pointer ${
                    selectedTypes.has(opt.key)
                      ? 'bg-accent-muted text-on-surface font-medium'
                      : 'bg-surface-base text-on-surface-variant hover:bg-surface-high'
                  }`}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </FormField>

          <FormField label="Minimum Confidence">
            <div className="flex items-center gap-3">
              <input
                type="range"
                min={0}
                max={100}
                value={minConfidence}
                onChange={(e) => setMinConfidence(Number(e.target.value))}
                className="flex-1 accent-accent-muted"
                aria-label="Minimum confidence threshold"
              />
              <span className="text-sm font-mono text-on-surface w-10 text-right">{minConfidence}%</span>
            </div>
          </FormField>

          <FormField label="Include Relationships">
            <button
              onClick={() => setIncludeRelationships(!includeRelationships)}
              className="flex items-center gap-2 cursor-pointer"
              role="switch"
              aria-checked={includeRelationships}
            >
              <span className={`w-10 h-5 rounded-full relative transition-colors ${
                includeRelationships ? 'bg-accent-muted' : 'bg-surface-highest'
              }`}>
                <span className={`absolute top-0.5 w-4 h-4 bg-on-surface rounded-full transition-transform ${
                  includeRelationships ? 'translate-x-5' : 'translate-x-0.5'
                }`} />
              </span>
              <span className="text-sm text-on-surface-variant">
                {includeRelationships ? 'Enabled' : 'Disabled'}
              </span>
            </button>
          </FormField>

          <div className="space-y-3 pt-2">
            <button
              onClick={() => void handleExport()}
              disabled={exportMutation.isPending || safeCandidates.length === 0}
              className="w-full bg-accent-muted hover:bg-accent-hover text-on-surface font-medium rounded-lg py-3 text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
            >
              {exportMutation.isPending ? 'Generating...' : 'Generate STIX Bundle'}
            </button>

            <button
              disabled
              className="w-full bg-surface-base text-on-surface-variant font-medium rounded-lg py-3 text-sm border border-surface-highest opacity-60 cursor-not-allowed"
            >
              Push to MISP
            </button>
          </div>
        </div>

        {/* Right: Bundle Preview */}
        <div className="bg-surface-low rounded-lg p-6 flex flex-col">
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full bg-error" />
              <span className="w-2.5 h-2.5 rounded-full bg-warning" />
              <span className="w-2.5 h-2.5 rounded-full bg-success" />
              <span className="text-sm font-medium text-on-surface ml-2">STIX Bundle Preview</span>
            </div>
            {exportMutation.data && (
              <span className="text-xs text-success font-medium">Generated</span>
            )}
          </div>

          <pre className="flex-1 min-h-[300px] p-4 bg-surface-base rounded-lg font-mono text-xs text-accent/70 overflow-auto">
{exportMutation.data ? (
  JSON.stringify({
    type: 'bundle',
    id: exportMutation.data.bundle_id,
    spec_version: '2.1',
    name: bundleName,
    exported_at: new Date().toISOString(),
    config: {
      campaign: selectedCampaign === 'all' ? 'all' : selectedCampaign.slice(0, 8),
      ioc_types: [...selectedTypes],
      min_confidence: minConfidence,
      include_relationships: includeRelationships,
    },
  }, null, 2)
) : (
  JSON.stringify({
    type: 'bundle',
    id: 'bundle--...',
    spec_version: '2.1',
    objects: [
      {
        type: 'indicator',
        id: 'indicator--...',
        name: 'Example IOC',
        pattern: "[ipv4-addr:value = '192.168.44.202']",
        confidence: 92,
        labels: ['malicious-activity'],
      },
    ],
  }, null, 2)
)}
          </pre>

          {exportMutation.error && (
            <p className="mt-3 text-sm text-error bg-error/10 rounded px-3 py-2" role="alert">
              Export failed: {exportMutation.error.message}
            </p>
          )}

          {exportMutation.data && (
            <p className="mt-3 text-xs text-success bg-success/10 rounded px-3 py-2">
              Bundle saved: {exportMutation.data.file_path}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

function FormField({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-2">{label}</span>
      {children}
    </div>
  );
}

export default StixExport;
