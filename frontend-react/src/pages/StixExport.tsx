import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
  const { data: candidates, isLoading, error, refetch } = useCampaignCandidates();
  const { data: stats } = useAutonomyStats();
  const exportMutation = useStixExport();

  const [selectedCampaign, setSelectedCampaign] = useState<string>('all');
  const [bundleName, setBundleName] = useState(`ScamBuster_Export_${new Date().toISOString().slice(0, 10)}`);
  const [selectedTypes, setSelectedTypes] = useState<Set<string>>(new Set(['ipv4', 'domain', 'sha256', 'url', 'email']));
  const [minConfidence, setMinConfidence] = useState(75);
  const [includeRelationships, setIncludeRelationships] = useState(true);

  if (isLoading) return <Loading message={t('stixExport.loading')} />;
  if (error) return <ErrorMessage message={t('stixExport.failedLoad')} onRetry={() => void refetch()} />;

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
        <h1 className="text-xl font-semibold text-on-surface">{t('stixExport.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('stixExport.subtitle')}</p>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          label={t('stixExport.exportableIocs')}
          value={stats?.iocs.total ?? 0}
        />
        <StatCard
          label={t('stixExport.campaignsAvailable')}
          value={safeCandidates.length}
        />
        <StatCard
          label={t('stixExport.lastExport')}
          value={exportMutation.data ? t('stixExport.justNow') : t('stixExport.never')}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Left: Export Configuration */}
        <div className="bg-surface-low rounded-lg p-6 space-y-5">
          <h2 className="text-base font-medium text-on-surface">{t('stixExport.exportConfiguration')}</h2>

          <FormField label={t('stixExport.bundleName')}>
            <input
              type="text"
              value={bundleName}
              onChange={(e) => setBundleName(e.target.value)}
              className="w-full bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-accent"
            />
          </FormField>

          <FormField label={t('stixExport.campaignFilter')}>
            <select
              value={selectedCampaign}
              onChange={(e) => setSelectedCampaign(e.target.value)}
              className="w-full bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface focus:outline-none focus:ring-2 focus:ring-accent"
            >
              <option value="all">{t('stixExport.allCampaigns')}</option>
              {safeCandidates.map((c) => (
                <option key={c.campaign_id} value={c.campaign_id}>
                  {c.campaign_id.slice(0, 8)} (PPV: {(c.ppv * 100).toFixed(0)}%, {c.hits_total} hits)
                </option>
              ))}
            </select>
          </FormField>

          <FormField label={t('stixExport.iocTypes')}>
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

          <FormField label={t('stixExport.minimumConfidence')}>
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

          <FormField label={t('stixExport.includeRelationships')}>
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
                {includeRelationships ? t('common.enabled') : t('common.disabled')}
              </span>
            </button>
          </FormField>

          <div className="space-y-3 pt-2">
            <button
              onClick={() => void handleExport()}
              disabled={exportMutation.isPending || safeCandidates.length === 0}
              className="w-full bg-accent-muted hover:bg-accent-hover text-on-surface font-medium rounded-lg py-3 text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
            >
              {exportMutation.isPending ? t('stixExport.generating') : t('stixExport.generateBundle')}
            </button>

            <button
              disabled
              className="w-full bg-surface-base text-on-surface-variant font-medium rounded-lg py-3 text-sm border border-surface-highest opacity-60 cursor-not-allowed"
            >
              {t('stixExport.pushToMisp')}
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
              <span className="text-sm font-medium text-on-surface ml-2">{t('stixExport.bundlePreview')}</span>
            </div>
            {exportMutation.data && (
              <span className="text-xs text-success font-medium">{t('stixExport.generated')}</span>
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
              {t('stixExport.exportFailed', { error: exportMutation.error.message })}
            </p>
          )}

          {exportMutation.data && (
            <p className="mt-3 text-xs text-success bg-success/10 rounded px-3 py-2">
              {t('stixExport.bundleSaved', { path: exportMutation.data.file_path })}
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
