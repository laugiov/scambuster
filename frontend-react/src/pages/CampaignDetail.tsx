import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useCampaignCandidates } from '@/hooks/useStix';
import { useCampaignMessages, useCampaignProfile, usePromoteRule } from '@/hooks/useCampaigns';
import { useStixExport } from '@/hooks/useStix';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import type { CampaignMessage } from '@/hooks/useCampaigns';

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
}

function MessagesTable({ messages, t }: { messages: CampaignMessage[]; t: (k: string) => string }) {
  if (messages.length === 0) {
    return <p className="text-on-surface-dim text-sm py-4 text-center">{t('campaignDetail.noMessages')}</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-outline-variant text-left text-xs text-on-surface-dim uppercase tracking-widest">
            <th className="pb-2 font-medium">{t('campaignDetail.subject')}</th>
            <th className="pb-2 font-medium">{t('campaignDetail.from')}</th>
            <th className="pb-2 font-medium">{t('campaignDetail.date')}</th>
            <th className="pb-2 font-medium">{t('campaignDetail.preview')}</th>
          </tr>
        </thead>
        <tbody>
          {messages.map((msg) => (
            <tr key={msg.msg_id} className="border-b border-outline-variant/50">
              <td className="py-2 text-on-surface font-medium">{msg.subject ?? '--'}</td>
              <td className="py-2 text-on-surface-variant">{msg.from ?? '--'}</td>
              <td className="py-2 text-on-surface-dim text-xs">{formatDate(msg.received_at)}</td>
              <td className="py-2 text-on-surface-variant text-xs max-w-xs truncate">{msg.body_preview}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function CampaignDetail() {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const campaignId = id ?? '';

  const { data: candidates, isLoading: loadingCandidates } = useCampaignCandidates();
  const { data: messages, isLoading: loadingMessages } = useCampaignMessages(campaignId);
  const profileMutation = useCampaignProfile(campaignId);
  const promoteMutation = usePromoteRule();
  const stixMutation = useStixExport();
  const [showConfirm, setShowConfirm] = useState(false);

  if (loadingCandidates) return <Loading message={t('campaigns.loading')} />;

  // Find the campaign in candidates
  const campaign = candidates?.find((c) => c.campaign_id === campaignId);

  if (!campaign) {
    return (
      <div className="space-y-4">
        <Link to="/campaigns" className="text-accent hover:underline text-sm">&larr; {t('campaignDetail.backToCampaigns')}</Link>
        <ErrorMessage message={t('campaignDetail.notFound')} />
      </div>
    );
  }

  const ppvPct = (campaign.ppv * 100).toFixed(1);
  const ppvColor = campaign.ppv >= 0.85 ? 'text-success' : campaign.ppv >= 0.5 ? 'text-warning' : 'text-error';
  const isPromotable = campaign.ppv >= 0.85 && campaign.hits_total >= 5;

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link to="/campaigns" className="text-accent hover:underline text-sm">&larr; {t('campaignDetail.backToCampaigns')}</Link>
          <h1 className="text-xl font-semibold text-on-surface">
            {t('campaignDetail.title')} <span className="font-mono text-accent">#{campaignId.slice(0, 8)}</span>
          </h1>
        </div>
        <span className="text-xs px-3 py-1 rounded-full font-medium bg-success/20 text-success uppercase">
          {t('common.status.promotable')}
        </span>
      </header>

      {/* Metadata + Actions grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {/* Left: Metadata */}
        <div className="lg:col-span-2 space-y-6">
          <div className="bg-surface-low rounded-lg p-6">
            <h2 className="text-base font-medium text-on-surface mb-4">{t('campaignDetail.metadata')}</h2>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <MetricCard label="PPV" value={`${ppvPct}%`} color={ppvColor} />
              <MetricCard label={t('campaigns.hits')} value={String(campaign.hits_total)} />
              <MetricCard label={t('campaigns.leadTime')} value={`${campaign.lead_time_hours}h`} />
              <MetricCard label={t('campaignDetail.created')} value={formatDate(campaign.created_at)} />
            </div>
          </div>

          {/* Messages */}
          <div className="bg-surface-low rounded-lg p-6">
            <h2 className="text-base font-medium text-on-surface mb-4">
              {t('campaignDetail.messages')} {messages && <span className="text-on-surface-dim font-normal">({messages.length})</span>}
            </h2>
            {loadingMessages ? (
              <Loading message={t('campaignDetail.loadingMessages')} />
            ) : (
              <MessagesTable messages={messages ?? []} t={t} />
            )}
          </div>

          {/* Profile */}
          <div className="bg-surface-low rounded-lg p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-base font-medium text-on-surface">{t('campaignDetail.profile')}</h2>
              <button
                onClick={() => profileMutation.mutate()}
                disabled={profileMutation.isPending}
                className="px-3 py-1.5 rounded text-xs font-medium bg-accent text-on-accent hover:bg-accent/90 disabled:opacity-50 transition-colors cursor-pointer"
              >
                {profileMutation.isPending ? t('campaignDetail.profiling') : t('campaignDetail.generateProfile')}
              </button>
            </div>

            {profileMutation.isSuccess && (
              <div className="space-y-2">
                {profileMutation.data.cache_hit && (
                  <span className="text-xs px-2 py-0.5 rounded bg-surface-high text-on-surface-dim">{t('campaignDetail.cacheHit')}</span>
                )}
                <pre className="bg-surface rounded-md p-4 text-xs text-on-surface font-mono overflow-x-auto whitespace-pre-wrap">
                  {profileMutation.data.profile_yaml}
                </pre>
              </div>
            )}

            {profileMutation.isError && (
              <p className="text-error text-sm">
                {(profileMutation.error as { response?: { data?: { error?: string } } })?.response?.data?.error ?? t('campaignDetail.profileError')}
              </p>
            )}

            {!profileMutation.isSuccess && !profileMutation.isPending && !profileMutation.isError && (
              <p className="text-on-surface-dim text-sm">{t('campaignDetail.noProfile')}</p>
            )}
          </div>
        </div>

        {/* Right: Actions */}
        <div className="space-y-6">
          <div className="bg-surface-low rounded-lg p-6">
            <h2 className="text-base font-medium text-on-surface mb-4">{t('campaignDetail.actions')}</h2>
            <div className="space-y-3">

              {/* Promote */}
              {!showConfirm ? (
                <button
                  onClick={() => setShowConfirm(true)}
                  disabled={!isPromotable || promoteMutation.isSuccess}
                  className="w-full px-4 py-2.5 rounded-md text-sm font-medium bg-success/20 text-success hover:bg-success/30 disabled:opacity-40 disabled:cursor-not-allowed transition-colors cursor-pointer"
                >
                  {promoteMutation.isSuccess ? t('campaignDetail.promoted') : t('campaignDetail.promote')}
                </button>
              ) : (
                <div className="bg-warning/10 border border-warning/30 rounded-md p-3 space-y-2">
                  <p className="text-sm text-warning">{t('campaignDetail.confirmPromote')}</p>
                  <div className="flex gap-2">
                    <button
                      onClick={() => { promoteMutation.mutate(campaign.rule_id); setShowConfirm(false); }}
                      className="px-3 py-1.5 rounded text-xs font-medium bg-success text-white cursor-pointer"
                    >
                      {t('campaignDetail.confirmYes')}
                    </button>
                    <button
                      onClick={() => setShowConfirm(false)}
                      className="px-3 py-1.5 rounded text-xs font-medium bg-surface-high text-on-surface cursor-pointer"
                    >
                      {t('campaignDetail.confirmNo')}
                    </button>
                  </div>
                </div>
              )}

              {!isPromotable && (
                <p className="text-xs text-on-surface-dim">{t('campaignDetail.notPromotable')}</p>
              )}

              {promoteMutation.isError && (
                <p className="text-error text-xs">
                  {(promoteMutation.error as { response?: { data?: { error?: string } } })?.response?.data?.error ?? t('campaignDetail.promoteError')}
                </p>
              )}

              {/* STIX Export */}
              <button
                onClick={() => stixMutation.mutate(campaignId)}
                disabled={stixMutation.isPending}
                className="w-full px-4 py-2.5 rounded-md text-sm font-medium bg-accent/20 text-accent hover:bg-accent/30 disabled:opacity-50 transition-colors cursor-pointer"
              >
                {stixMutation.isPending ? t('campaignDetail.exporting') : t('campaignDetail.exportStix')}
              </button>

              {stixMutation.isSuccess && (
                <p className="text-success text-xs">{t('campaignDetail.exportSuccess')}</p>
              )}
            </div>
          </div>

          {/* Rule Info */}
          <div className="bg-surface-low rounded-lg p-6">
            <h2 className="text-base font-medium text-on-surface mb-4">{t('campaignDetail.ruleInfo')}</h2>
            <div className="space-y-2 text-sm">
              <InfoRow label={t('campaignDetail.ruleId')} value={campaign.rule_id.slice(0, 12)} mono />
              <InfoRow label="PPV" value={`${ppvPct}%`} />
              <InfoRow label={t('campaigns.hits')} value={String(campaign.hits_total)} />
              <InfoRow label={t('campaigns.leadTime')} value={`${campaign.lead_time_hours}h`} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function MetricCard({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <div className="bg-surface rounded-md p-3">
      <p className="text-xs text-on-surface-dim">{label}</p>
      <p className={`text-lg font-semibold ${color ?? 'text-on-surface'}`}>{value}</p>
    </div>
  );
}

function InfoRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex items-center justify-between py-1">
      <span className="text-on-surface-variant">{label}</span>
      <span className={`text-on-surface ${mono ? 'font-mono text-xs' : ''}`}>{value}</span>
    </div>
  );
}

export default CampaignDetail;
