import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Card, CardHeader, Input, Label, Select, Skeleton } from '@/components/ui';
import { ConfirmDialog, Dialog } from '@/components/Dialog';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/features/auth/AuthProvider';
import * as cmsApi from '../api';
import { BilingualField, LocaleToggle, type EditLocale } from '../components/BilingualField';
import type { ContentPageSummary, Menu, MenuItemNode } from '../types';

/* ----------------------------------------------------------------- Menu */

function MenuDialog({ menu, onClose }: { menu: Menu | null; onClose: () => void }) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [code, setCode] = useState(menu?.code ?? '');
    const [name, setName] = useState({ en: menu?.name ?? '', bn: menu?.name_bn ?? '' });
    const [isActive, setIsActive] = useState(menu?.is_active ?? true);

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.saveMenu(menu?.ulid ?? null, {
                code,
                name: name.en,
                name_bn: name.bn || null,
                is_active: isActive,
            }),
        onSuccess: () => {
            push('success', menu ? 'Menu updated.' : 'Menu created.');
            void queryClient.invalidateQueries({ queryKey: ['cms-menus'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title={menu ? 'Edit menu' : 'New menu'} className="max-w-md">
            <div className="space-y-4">
                <div className="flex justify-end"><LocaleToggle locale={locale} onChange={setLocale} /></div>

                <div>
                    <Label htmlFor="menu-code">Code</Label>
                    <Input id="menu-code" value={code} placeholder="primary" onChange={(e) => setCode(e.target.value)} />
                    <p className="mt-1 text-[11.5px] text-text-faint">
                        The public site fetches a region by this code, so changing it on a live menu breaks that region until the frontend is updated.
                    </p>
                </div>

                <BilingualField label="Name" locale={locale} value={name} onChange={setName} />

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
                    Active
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" disabled={!code.trim() || !name.en.trim() || saveMutation.isPending} onClick={() => void saveMutation.mutateAsync()}>
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

/* ------------------------------------------------------------ Menu item */

function MenuItemDialog({
    menu,
    item,
    parentUlid,
    pages,
    onClose,
}: {
    menu: Menu;
    item: MenuItemNode | null;
    parentUlid: string | null;
    pages: ContentPageSummary[];
    onClose: () => void;
}) {
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [locale, setLocale] = useState<EditLocale>('en');
    const [label, setLabel] = useState({ en: item?.label ?? '', bn: item?.label_bn ?? '' });
    // The API rejects sending both, and an internal page reference wins at
    // render time, so the form is a two-way choice rather than two fields.
    const [linkKind, setLinkKind] = useState<'page' | 'url'>(item?.page_ulid ? 'page' : item?.url ? 'url' : 'page');
    const [pageUlid, setPageUlid] = useState(item?.page_ulid ?? '');
    const [url, setUrl] = useState(item?.url ?? '');
    const [target, setTarget] = useState<'_self' | '_blank'>(item?.target ?? '_self');
    const [position, setPosition] = useState(String(item?.position ?? 0));
    const [isVisible, setIsVisible] = useState(item?.is_visible ?? true);

    const saveMutation = useMutation({
        mutationFn: () =>
            cmsApi.saveMenuItem(menu.ulid, item?.ulid ?? null, {
                label: label.en,
                label_bn: label.bn || null,
                page_ulid: linkKind === 'page' ? pageUlid || null : null,
                url: linkKind === 'url' ? url || null : null,
                target,
                position: Number(position) || 0,
                is_visible: isVisible,
                ...(item ? {} : { parent_ulid: parentUlid }),
            }),
        onSuccess: () => {
            push('success', item ? 'Menu item updated.' : 'Menu item added.');
            void queryClient.invalidateQueries({ queryKey: ['cms-menus'] });
            onClose();
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <Dialog open onClose={onClose} title={item ? 'Edit menu item' : 'Add menu item'} className="max-w-md">
            <div className="space-y-4">
                <div className="flex justify-end"><LocaleToggle locale={locale} onChange={setLocale} /></div>

                <BilingualField label="Label" locale={locale} value={label} onChange={setLabel} />

                <div>
                    <Label htmlFor="link-kind">Links to</Label>
                    <Select id="link-kind" value={linkKind} onChange={(e) => setLinkKind(e.target.value as 'page' | 'url')}>
                        <option value="page">A page on this site</option>
                        <option value="url">An external or custom URL</option>
                    </Select>
                </div>

                {linkKind === 'page' ? (
                    <div>
                        <Label htmlFor="item-page">Page</Label>
                        <Select id="item-page" value={pageUlid} onChange={(e) => setPageUlid(e.target.value)}>
                            <option value="">Select a page…</option>
                            {pages.map((p) => <option key={p.ulid} value={p.ulid}>{p.title} (/{p.slug})</option>)}
                        </Select>
                        <p className="mt-1 text-[11.5px] text-text-faint">
                            Renaming the page's slug re-points this item automatically.
                        </p>
                    </div>
                ) : (
                    <div>
                        <Label htmlFor="item-url">URL</Label>
                        <Input id="item-url" value={url} placeholder="https://example.com" onChange={(e) => setUrl(e.target.value)} />
                    </div>
                )}

                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="item-target">Opens in</Label>
                        <Select id="item-target" value={target} onChange={(e) => setTarget(e.target.value as '_self' | '_blank')}>
                            <option value="_self">Same tab</option>
                            <option value="_blank">New tab</option>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="item-position">Position</Label>
                        <Input id="item-position" type="number" min={0} value={position} onChange={(e) => setPosition(e.target.value)} />
                    </div>
                </div>

                <label className="flex items-center gap-2 text-[13px] text-text">
                    <input type="checkbox" checked={isVisible} onChange={(e) => setIsVisible(e.target.checked)} />
                    Visible
                </label>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" size="sm" onClick={onClose}>Cancel</Button>
                    <Button size="sm" disabled={!label.en.trim() || saveMutation.isPending} onClick={() => void saveMutation.mutateAsync()}>
                        {saveMutation.isPending ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </div>
        </Dialog>
    );
}

function ItemRow({
    item,
    depth,
    canUpdate,
    canDelete,
    onEdit,
    onAddChild,
    onDelete,
}: {
    item: MenuItemNode;
    depth: number;
    canUpdate: boolean;
    canDelete: boolean;
    onEdit: (item: MenuItemNode) => void;
    onAddChild: (parentUlid: string) => void;
    onDelete: (item: MenuItemNode) => void;
}) {
    return (
        <>
            <div className="flex items-center gap-2 rounded-xl border border-border px-3 py-2" style={{ marginLeft: depth * 20 }}>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] font-medium text-text">{item.label}</div>
                    <div className="flex items-center gap-1 truncate text-[11.5px] text-text-faint">
                        {item.resolved_url ? (
                            <>
                                <ExternalLink size={11} /> {item.resolved_url}
                            </>
                        ) : (
                            // resolvedUrl() returns null when the linked page is
                            // no longer live — the public site drops the item.
                            <span className="flex items-center gap-1 text-warning-fg">
                                <AlertTriangle size={11} /> Target is not live — this item will not render
                            </span>
                        )}
                    </div>
                </div>
                {!item.is_visible && <Badge tone="neutral" size="sm">Hidden</Badge>}
                {canUpdate && <Button variant="ghost" size="sm" aria-label="Add child item" onClick={() => onAddChild(item.ulid)}><Plus size={13} /></Button>}
                {canUpdate && <Button variant="ghost" size="sm" aria-label="Edit item" onClick={() => onEdit(item)}><Pencil size={13} /></Button>}
                {canDelete && <Button variant="ghost" size="sm" aria-label="Delete item" onClick={() => onDelete(item)}><Trash2 size={13} /></Button>}
            </div>

            {item.children.map((child) => (
                <ItemRow
                    key={child.ulid}
                    item={child}
                    depth={depth + 1}
                    canUpdate={canUpdate}
                    canDelete={canDelete}
                    onEdit={onEdit}
                    onAddChild={onAddChild}
                    onDelete={onDelete}
                />
            ))}
        </>
    );
}

/* ------------------------------------------------------------------ Tab */

export default function MenusTab() {
    const { can } = useAuth();
    const { push } = useToast();
    const queryClient = useQueryClient();
    const [editingMenu, setEditingMenu] = useState<Menu | null | undefined>(undefined);
    const [deletingMenu, setDeletingMenu] = useState<Menu | null>(null);
    const [itemDialog, setItemDialog] = useState<{ menu: Menu; item: MenuItemNode | null; parentUlid: string | null } | null>(null);
    const [deletingItem, setDeletingItem] = useState<{ menu: Menu; item: MenuItemNode } | null>(null);

    const { data: menus, isLoading, isError } = useQuery({
        queryKey: ['cms-menus'],
        queryFn: () => cmsApi.fetchMenus(),
    });

    // Only for the item dialog's page picker; menus are small, so one
    // unpaginated fetch is cheaper than a search box here.
    const { data: pages } = useQuery({
        queryKey: ['cms-pages', 'menu-picker'],
        queryFn: () => cmsApi.fetchPages({ per_page: 100 }),
    });

    const deleteMenuMutation = useMutation({
        mutationFn: (ulid: string) => cmsApi.deleteMenu(ulid),
        onSuccess: () => {
            push('success', 'Menu deleted.');
            void queryClient.invalidateQueries({ queryKey: ['cms-menus'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    const deleteItemMutation = useMutation({
        mutationFn: ({ menuUlid, itemUlid }: { menuUlid: string; itemUlid: string }) => cmsApi.deleteMenuItem(menuUlid, itemUlid),
        onSuccess: () => {
            push('success', 'Menu item removed.');
            void queryClient.invalidateQueries({ queryKey: ['cms-menus'] });
        },
        onError: (e: Error) => push('critical', e.message),
    });

    return (
        <div className="space-y-4">
            <div className="flex justify-end">
                {can('content.create') && <Button size="sm" onClick={() => setEditingMenu(null)}><Plus size={15} /> New menu</Button>}
            </div>

            {isLoading && <Skeleton className="h-48 w-full" />}
            {isError && <p className="text-[13px] text-critical-fg">Failed to load menus.</p>}
            {menus && menus.length === 0 && <p className="py-8 text-center text-[13px] text-text-muted">No navigation menus yet.</p>}

            {menus?.map((menu) => (
                <Card key={menu.ulid}>
                    <CardHeader
                        title={menu.name}
                        subtitle={`Code: ${menu.code}`}
                        action={
                            <div className="flex items-center gap-1">
                                <Badge tone={menu.is_active ? 'success' : 'neutral'} size="sm">{menu.is_active ? 'Active' : 'Inactive'}</Badge>
                                {can('content.create') && (
                                    <Button variant="ghost" size="sm" onClick={() => setItemDialog({ menu, item: null, parentUlid: null })}>
                                        <Plus size={14} /> Item
                                    </Button>
                                )}
                                {can('content.update') && (
                                    <Button variant="ghost" size="sm" aria-label="Edit menu" onClick={() => setEditingMenu(menu)}><Pencil size={14} /></Button>
                                )}
                                {can('content.delete') && (
                                    <Button variant="ghost" size="sm" aria-label="Delete menu" onClick={() => setDeletingMenu(menu)}><Trash2 size={14} /></Button>
                                )}
                            </div>
                        }
                    />
                    <div className="space-y-1.5 px-5 pb-5 pt-3">
                        {(menu.items?.length ?? 0) === 0 && <p className="py-4 text-center text-[12.5px] text-text-muted">No items in this menu.</p>}
                        {menu.items?.map((item) => (
                            <ItemRow
                                key={item.ulid}
                                item={item}
                                depth={0}
                                canUpdate={can('content.update')}
                                canDelete={can('content.delete')}
                                onEdit={(target) => setItemDialog({ menu, item: target, parentUlid: null })}
                                onAddChild={(parentUlid) => setItemDialog({ menu, item: null, parentUlid })}
                                onDelete={(target) => setDeletingItem({ menu, item: target })}
                            />
                        ))}
                    </div>
                </Card>
            ))}

            {editingMenu !== undefined && <MenuDialog menu={editingMenu} onClose={() => setEditingMenu(undefined)} />}

            {itemDialog && (
                <MenuItemDialog
                    menu={itemDialog.menu}
                    item={itemDialog.item}
                    parentUlid={itemDialog.parentUlid}
                    pages={pages?.data ?? []}
                    onClose={() => setItemDialog(null)}
                />
            )}

            <ConfirmDialog
                open={deletingMenu !== null}
                onClose={() => setDeletingMenu(null)}
                onConfirm={async () => { if (deletingMenu) await deleteMenuMutation.mutateAsync(deletingMenu.ulid); }}
                title="Delete this menu?"
                description="Every item in it goes too, and the public site loses that navigation region."
                confirmLabel="Delete menu"
            />

            <ConfirmDialog
                open={deletingItem !== null}
                onClose={() => setDeletingItem(null)}
                onConfirm={async () => {
                    if (deletingItem) {
                        await deleteItemMutation.mutateAsync({ menuUlid: deletingItem.menu.ulid, itemUlid: deletingItem.item.ulid });
                    }
                }}
                title="Delete this menu item?"
                description="Anything nested under it is removed as well."
                confirmLabel="Delete item"
            />
        </div>
    );
}
