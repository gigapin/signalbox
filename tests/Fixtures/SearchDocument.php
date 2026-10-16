<?php

/** Provide a temporary PostgreSQL search model without exposing any production searchable entity. */

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Search synthetic documents only; production tenant scopes are implemented on domain models in M1/M2.
 *
 * @property int $id Synthetic document identifier.
 * @property int $team_id Synthetic owner used to exercise explicit search constraints.
 * @property string $title Public test document title.
 */
class SearchDocument extends Model
{
    /** Use the real Scout database adapter with this disposable fixture. */
    use Searchable;

    /** @var string $table Temporary relation created only inside an opt-in test transaction. */
    protected $table = 'signalbox_search_documents';

    /** @var bool $timestamps Temporary documents do not have auditing columns. */
    public $timestamps = false;

    /**
     * Search document titles, never ownership identifiers or unrelated attributes.
     *
     * @return array{title: string} Text columns used by the real PostgreSQL search query.
     */
    public function toSearchableArray(): array
    {
        return ['title' => $this->title];
    }
}
