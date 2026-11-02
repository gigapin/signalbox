<?php

declare(strict_types=1);

/** Freeze only consenting subscribers of public pages that include the newly published entry's monitor. */

namespace App\Services\StatusPages;

use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use Illuminate\Support\Facades\DB;

/** Called once inside ManageIncidents::publish's transaction; preparation errors roll publication back. */
final readonly class PreparePublicUpdateEmails
{
    /**
     * @param StatusSubscriptions $subscriptions Durable recipient intents.
     * @param StatusSubscriptionLinks $links Live password binding and unsubscribe capability.
     */
    public function __construct(private StatusSubscriptions $subscriptions, private StatusSubscriptionLinks $links)
    {
    }

    /**
     * @param Incident $incident Locked tenant-owned incident; private fields never enter the email.
     * @param IncidentUpdate $update Entry that has just become public.
     */
    public function record(Incident $incident, IncidentUpdate $update): void
    {
        $components = DB::table('status_page_component_monitors as selection')
            ->join('status_page_components as component', 'component.id', '=', 'selection.status_page_component_id')
            ->where('selection.team_id', $incident->team_id)->where('selection.monitor_id', $incident->monitor_id)
            ->select(['selection.status_page_id', 'component.name'])->get();

        foreach ($components as $component) {
            $page = StatusPage::query()->where('is_public', true)->find((int) $component->status_page_id);

            if ($page === null) {
                continue;
            }

            StatusPageSubscriber::query()->where('status_page_id', $page->id)->whereNotNull('verified_at')
                ->whereNull('unsubscribed_at')->where('access_hash', $this->links->accessHash($page))
                ->chunkById(100, function ($subscribers) use ($page, $component, $update): void {
                    foreach ($subscribers as $subscriber) {
                        $text = $page->title."\n".$component->name.': '.$update->status->value."\n"
                            .$update->published_at?->utc()->toIso8601String()."\n\n".$update->message
                            ."\n\nView status page: ".rtrim((string) config('app.url'), '/').'/status/'.$page->slug
                            ."\nUnsubscribe: ".$this->links->action($subscriber, 'unsubscribe');

                        $this->subscriptions->enqueue($subscriber, 'update', $update->id, [
                            'subject' => 'Status page update',
                            'text' => $text,
                        ]);
                    }
                });
        }
    }
}
