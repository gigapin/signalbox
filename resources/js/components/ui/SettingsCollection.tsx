/** Share guarded creation, searchable cards and ten-item local pagination across team settings. */
import { useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useNavigationGuard } from '../app/NavigationGuard';
import Button from '../ui/Button';
import Modal from '../ui/Modal';
import SearchPicker, { type SearchChoice } from '../ui/SearchPicker';
import Pagination from '../monitors/Pagination';

/** Local collections are bounded by the team's existing configuration limits. */
const PAGE_SIZE = 10;

/** Domain renderers retain ownership of mutations and row drafts. */
interface CollectionProps<T extends { id: number }> {
    /** Searchable display label without changing domain projections. */
    itemLabel: (item: T) => string;
    /** Authoritative quota consumption. */
    usedCount: number;
    /** Domain-specific quota explanation. */
    limitMessage: string;
    /** Domain-specific read-only explanation. */
    readOnlyMessage: string;
    /** Team-local safe identities. */
    items: T[];
    /** Heading action destination inside the authorized shell. */
    actionsTarget: HTMLElement | null;
    /** Server-projected management permission. */
    canManage: boolean;
    /** Maximum permitted collection size. */
    limit: number;
    /** Accessible search name. */
    searchLabel: string;
    /** Heading creation action. */
    addLabel: string;
    /** Creation dialog heading. */
    modalTitle: string;
    /** Accessible collection name. */
    listLabel: string;
    /** Stable prefix of focusable row IDs. */
    cardPrefix: string;
    /** Empty collection explanation. */
    emptyText: string;
    /** Empty search explanation. */
    noMatchText: string;
    /** Mounted row renderer; hidden rows preserve drafts. */
    renderItem: (item: T, hidden: boolean) => ReactNode;
    /** Creation renderer; only confirmed acceptance should call onSaved. */
    renderCreate: (onSaved: () => void) => ReactNode;
}

/** Keep editors mounted across filtering, pagination and ordinary dialog dismissal. */
export default function SettingsCollection<T extends { id: number }>({
    items,
    itemLabel,
    usedCount,
    limitMessage,
    readOnlyMessage,
    actionsTarget,
    canManage,
    limit,
    searchLabel,
    addLabel,
    modalTitle,
    listLabel,
    cardPrefix,
    emptyText,
    noMatchText,
    renderItem,
    renderCreate,
}: CollectionProps<T>) {
    const guard = useNavigationGuard();
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [creationStarted, setCreationStarted] = useState(false);
    const [creating, setCreating] = useState(false);
    const [creationVersion, setCreationVersion] = useState(0);
    const canCreate = canManage && usedCount < limit;
    const [previousCanCreate, setPreviousCanCreate] = useState(canCreate);
    if (previousCanCreate !== canCreate) {
        setPreviousCanCreate(canCreate);
        if (!canCreate) setCreating(false);
    }

    const term = search.trim().toLocaleLowerCase();
    const matching = items.filter((item) => itemLabel(item).toLocaleLowerCase().includes(term));
    const last = Math.max(1, Math.ceil(matching.length / PAGE_SIZE));
    const current = Math.min(page, last);
    if (page !== current) setPage(current);
    const visible = new Set(
        matching.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE).map((item) => item.id),
    );

    /** Begin filtering from page one; no row or unsent form is disposed. */
    function searchFor(value: string): void {
        setSearch(value);
        setPage(1);
    }

    /** Reveal an existing card and move focus after React removes its hidden attribute. */
    function selectItem(choice: SearchChoice): void {
        searchFor(choice.label);
        window.requestAnimationFrame(() => {
            const card = document.getElementById(`${cardPrefix}-${choice.id}`);
            if (!card || card.hidden) return;
            card.focus({ preventScroll: true });
            card.scrollIntoView({ block: 'nearest' });
        });
    }

    /** Closing preserves the creation draft and is unavailable during foreground work. */
    function closeCreation(): void {
        if (!guard.busy) setCreating(false);
    }

    /** Only confirmed acceptance closes and resets the creation editor. */
    function saved(): void {
        setCreating(false);
        setCreationVersion((value) => value + 1);
    }

    const controls = (
        <div className="ui-channel-toolbar">
            <SearchPicker
                label={searchLabel}
                query={search}
                choices={matching.map((item) => ({ id: item.id, label: itemLabel(item) }))}
                disabled={guard.busy}
                onQueryChange={searchFor}
                onSelect={selectItem}
            />
            {canManage && (
                <Button
                    disabled={guard.busy || !canCreate}
                    onClick={() => {
                        setCreationStarted(true);
                        setCreating(true);
                    }}
                    aria-haspopup="dialog"
                >
                    {addLabel}
                </Button>
            )}
        </div>
    );

    return (
        <>
            {actionsTarget && createPortal(controls, actionsTarget)}
            {items.length === 0 && <p>{emptyText}</p>}
            {items.length > 0 && matching.length === 0 && <p role="status">{noMatchText}</p>}
            <ul aria-label={listLabel} className="ui-channel-list ui-notification-list">
                {items.map((item) => renderItem(item, !visible.has(item.id)))}
            </ul>
            <Pagination
                current={current}
                last={last}
                disabled={guard.busy}
                onPageChange={setPage}
            />
            {canManage && !canCreate && <p className="ui-help">{limitMessage}</p>}
            {!canManage && <p className="ui-help">{readOnlyMessage}</p>}
            <Modal
                open={creating && canCreate}
                title={modalTitle}
                onRequestClose={closeCreation}
                closeDisabled={guard.busy}
            >
                {canCreate && creationStarted && (
                    <div key={creationVersion}>{renderCreate(saved)}</div>
                )}
            </Modal>
        </>
    );
}
