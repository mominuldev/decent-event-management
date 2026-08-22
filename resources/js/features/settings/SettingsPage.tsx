import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Eye, Search, SlidersHorizontal, X } from 'lucide-react';
import { Button, Card, EmptyState, ErrorState, Input, Skeleton } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthProvider';
import { cn } from '@/lib/cn';
import * as settingsApi from './api';
import { groupMeta, sortGroups } from './groups';
import SettingRow from './SettingRow';
import SmsBalanceCard from './SmsBalanceCard';
import type { EventSetting, SettingsByGroup } from './types';

function matches(setting: EventSetting, query: string): boolean {
    const haystack = `${setting.label} ${setting.key} ${setting.description ?? ''}`.toLowerCase();
    return query
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .every((term) => haystack.includes(term));
}

function GroupCard({ group, settings, canEdit, searching }: { group: string; settings: EventSetting[]; canEdit: boolean; searching: boolean }) {
    const { label, description, Icon } = groupMeta(group);

    return (
        <Card>
            <div className="flex items-start gap-3 border-b border-border px-5 py-4">
                <div className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-surface-2 text-text-muted">
                    <Icon size={17} />
                </div>
                <div className="min-w-0">
                    <h2 className="text-[15px] font-semibold text-text">{label}</h2>
                    {description && <p className="mt-0.5 text-[12.5px] leading-relaxed text-text-muted">{description}</p>}
                </div>
            </div>
            <div>
                {/* The balance belongs with the credentials that fetch it, and
                    only when the whole group is on screen — during a search the
                    card would sit above whichever one row matched. */}
                {group === 'sms' && !searching && <SmsBalanceCard />}
                {settings.map((s) => (
                    <SettingRow key={s.key} setting={s} canEdit={canEdit} />
                ))}
            </div>
        </Card>
    );
}

export default function SettingsPage() {
    const { can } = useAuth();
    const canEdit = can('settings.update');

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['settings'],
        queryFn: settingsApi.fetchSettings,
    });

    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<string | null>(null);

    const groups = useMemo(() => sortGroups(Object.keys(data ?? {})), [data]);
    const searching = query.trim().length > 0;

    // Search spans every group — otherwise a reader has to already know which
    // section a setting lives in to find it, which is the thing they came here
    // to look up.
    const filtered = useMemo<SettingsByGroup>(() => {
        if (!data) return {};
        if (!searching) return data;

        return Object.fromEntries(
            Object.entries(data)
                .map(([group, settings]) => [group, settings.filter((s) => matches(s, query))] as const)
                .filter(([, settings]) => settings.length > 0),
        );
    }, [data, query, searching]);

    const activeGroup = selected && groups.includes(selected) ? selected : groups[0];
    const visibleGroups = searching ? sortGroups(Object.keys(filtered)) : activeGroup ? [activeGroup] : [];
    const resultCount = Object.values(filtered).reduce((n, s) => n + s.length, 0);

    return (
        <div className="space-y-6">
            <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <h1 className="text-[26px] font-bold tracking-tight text-text">Settings</h1>
                    <p className="mt-1 max-w-2xl text-[14px] text-text-muted">
                        Event configuration — change dates, cutoffs and toggles without a deployment. Changes take
                        effect immediately and every edit is recorded in the activity log.
                    </p>
                </div>

                <div className="relative w-full md:w-72">
                    <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-faint" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search settings…"
                        aria-label="Search settings"
                        className="pl-9"
                    />
                    {searching && (
                        <button
                            type="button"
                            onClick={() => setQuery('')}
                            aria-label="Clear search"
                            className="absolute right-2 top-1/2 grid h-6 w-6 -translate-y-1/2 place-items-center rounded-lg text-text-faint hover:bg-surface-2 hover:text-text"
                        >
                            <X size={14} />
                        </button>
                    )}
                </div>
            </div>

            {!canEdit && !isLoading && (
                <div className="flex items-center gap-2 rounded-xl border border-border bg-surface-2 px-4 py-3 text-[13px] text-text-muted">
                    <Eye size={15} className="shrink-0" />
                    You have read-only access to settings. Ask a Super Admin for the <code className="font-mono text-[12px]">settings.update</code> permission to make changes.
                </div>
            )}

            {isLoading && (
                <div className="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                    <Skeleton className="hidden h-64 w-full lg:block" />
                    <div className="space-y-4">
                        {Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} className="h-40 w-full" />)}
                    </div>
                </div>
            )}

            {isError && (
                <Card>
                    <ErrorState message="Settings could not be loaded." onRetry={() => void refetch()} />
                </Card>
            )}

            {data && groups.length === 0 && (
                <Card>
                    <EmptyState
                        icon={<SlidersHorizontal size={20} />}
                        title="No settings configured yet"
                        description="Run the event setting seeder to populate the defaults."
                    />
                </Card>
            )}

            {data && groups.length > 0 && (
                <div className="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)]">
                    <nav aria-label="Setting sections" className="lg:sticky lg:top-6 lg:self-start">
                        <ul className="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible lg:pb-0">
                            {groups.map((group) => {
                                const { label, Icon } = groupMeta(group);
                                const count = (data[group] ?? []).length;
                                const isActive = !searching && group === activeGroup;
                                const hits = searching ? (filtered[group] ?? []).length : null;

                                return (
                                    <li key={group} className="shrink-0">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSelected(group);
                                                setQuery('');
                                            }}
                                            aria-current={isActive ? 'page' : undefined}
                                            className={cn(
                                                'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-[13px] font-medium transition-colors',
                                                isActive
                                                    ? 'bg-surface-2 text-text'
                                                    : 'text-text-muted hover:bg-surface-2 hover:text-text',
                                                searching && hits === 0 && 'opacity-45',
                                            )}
                                        >
                                            <Icon size={15} className="shrink-0" />
                                            <span className="flex-1 truncate">{label}</span>
                                            <span className="tnum text-[11.5px] text-text-faint">
                                                {searching ? hits : count}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <div className="space-y-6">
                        {searching && (
                            <p className="text-[13px] text-text-muted">
                                {resultCount === 0
                                    ? 'No settings match your search.'
                                    : `${resultCount} setting${resultCount === 1 ? '' : 's'} matching “${query.trim()}”.`}
                            </p>
                        )}

                        {searching && resultCount === 0 && (
                            <Card>
                                <EmptyState
                                    icon={<Search size={20} />}
                                    title="Nothing found"
                                    description="Try a shorter term, or search by the setting key such as “registration.closes_at”."
                                />
                                <div className="flex justify-center pb-8">
                                    <Button variant="outline" size="sm" onClick={() => setQuery('')}>
                                        Clear search
                                    </Button>
                                </div>
                            </Card>
                        )}

                        {visibleGroups.map((group) => (
                            <GroupCard
                                key={group}
                                group={group}
                                settings={filtered[group] ?? []}
                                canEdit={canEdit}
                                searching={searching}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
