/** Manage the team's public status pages. */
import SettingsCollection from '../../components/ui/SettingsCollection';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../components/app/AppLayout';
import StatusPageForm from '../../components/status-pages/StatusPageForm';
import StatusPageRow from '../../components/status-pages/StatusPageRow';
import type { StatusPagesPageProps } from '../../components/status-pages/types';

/** Every member sees the pages; owners and admins get the forms. Domain refusals arrive on the `page` key. */
function PageContent({ actionsTarget }: { actionsTarget: HTMLElement | null }) {
    const { team, pages, monitors, themeChoices, limits, can, errors } =
        usePage<StatusPagesPageProps>().props;
    const collectionPath = `/teams/${team.id}/status-pages`;

    return (
        <>
            {errors.page && (
                <p role="alert" className="mb-4 text-ui-danger">
                    {errors.page}
                </p>
            )}
            <SettingsCollection
                items={pages}
                itemLabel={(page) => page.title}
                usedCount={limits.used}
                limit={limits.pages}
                limitMessage={`The team has reached its plan limit of ${limits.pages} status pages.`}
                readOnlyMessage="Only owners and admins can change status pages."
                actionsTarget={actionsTarget}
                canManage={can.manage}
                searchLabel="Find a status page"
                addLabel="Add status page"
                modalTitle="Add a status page"
                listLabel="Status pages"
                cardPrefix="status-page"
                emptyText="No status pages yet."
                noMatchText="No status pages match this search."
                renderItem={(page, hidden) => (
                    <StatusPageRow
                        key={page.id}
                        path={`${collectionPath}/${page.id}`}
                        page={page}
                        hidden={hidden}
                        monitors={monitors}
                        themeChoices={themeChoices}
                        limits={limits}
                        canManage={can.manage}
                    />
                )}
                renderCreate={(onSaved) => (
                    <StatusPageForm
                        idPrefix="page-new"
                        action={collectionPath}
                        method="post"
                        page={null}
                        monitors={monitors}
                        themeChoices={themeChoices}
                        limits={limits}
                        submitLabel="Add status page"
                        onSaved={onSaved}
                    />
                )}
            />
        </>
    );
}

/** Keep all page-owned forms and live sessions inside the removable authorized team boundary. */
export default function Page() {
    const { team } = usePage<StatusPagesPageProps>().props;
    const [actionsTarget, setActionsTarget] = useState<HTMLDivElement | null>(null);
    return (
        <AppLayout
            team={team}
            section="status-pages"
            title="Status pages"
            actions={<div ref={setActionsTarget} className="contents" />}
        >
            <PageContent actionsTarget={actionsTarget} />
        </AppLayout>
    );
}
