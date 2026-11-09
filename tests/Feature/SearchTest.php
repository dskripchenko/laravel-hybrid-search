<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Facades\Search;
use Dskripchenko\HybridSearch\Hit;
use Dskripchenko\HybridSearch\Tests\Fixtures\Note;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Note::query()->create(['title' => 'Настройка арендаторов', 'body' => 'Арендаторы живут в отдельных схемах PostgreSQL.', 'scope' => 'project:1', 'lang' => 'russian']);
    Note::query()->create(['title' => 'Boards and sprints', 'body' => 'Planning sprints on a Kanban board with swimlanes.', 'scope' => 'project:1', 'lang' => 'english', 'status' => 'done']);
    Note::query()->create(['title' => 'Секретный план', 'body' => 'Арендатор номер два.', 'scope' => 'project:2', 'lang' => 'russian']);
});

/** @return list<string> */
function keys(array $hits): array
{
    return array_map(fn (Hit $h) => $h->key, $hits);
}

it('finds by word forms in the document language', function (): void {
    expect(keys(Search::query('арендатор')->accessKeys(['project:1'])->get()))->toBe(['N-1'])
        ->and(keys(Search::query('planned sprint')->accessKeys(['project:1'])->get()))->toBe(['N-2']);
});

it('leaves out what the query excludes with a minus, prefixes included', function (): void {
    // "sprint" is in N-2 only; "-board" must drop it although "board" is a prefix of "boards".
    expect(keys(Search::query('sprint -board')->accessKeys(['project:1'])->get()))->toBe([])
        ->and(keys(Search::query('sprint -arendator')->accessKeys(['project:1'])->get()))->toBe(['N-2'])
        ->and(keys(Search::query('арендатор -postgresql')->accessKeys(['project:1'])->get()))->toBe([])
        // Only an exclusion: everything without the word, as websearch_to_tsquery reads it.
        ->and(keys(Search::query('-board')->accessKeys(['project:1'])->get()))->toBe(['N-1']);
});

it('finds by the beginning of a word while typing', function (): void {
    expect(keys(Search::query('swim')->accessKeys(['project:1'])->get()))->toBe(['N-2']);
});

it('ranks an exact key first and highlights the snippet', function (): void {
    $hits = Search::query('N-2')->accessKeys(['project:1'])->get();

    expect($hits[0]->key)->toBe('N-2')
        ->and(Search::query('схемах')->accessKeys(['project:1'])->get()[0]->snippet)->toContain('**схемах**');
});

it('never shows what the access keys do not cover', function (): void {
    expect(keys(Search::query('арендатор')->accessKeys(['project:2'])->get()))->toBe(['N-3'])
        ->and(Search::query('арендатор')->accessKeys([])->get())->toBe([])
        ->and(keys(Search::query('арендатор')->everywhere()->get()))->toEqualCanonicalizing(['N-1', 'N-3'])
        ->and(fn () => Search::query('арендатор')->get())->toThrow(LogicException::class);
});

it('narrows by type and facets and counts by type', function (): void {
    expect(Search::query('sprints')->accessKeys(['project:1'])->where('status', 'open')->get())->toBe([])
        ->and(keys(Search::query('sprints')->accessKeys(['project:1'])->where('status', 'done')->types('note')->get()))->toBe(['N-2'])
        ->and(Search::query('арендатор')->everywhere()->countsByType())->toBe(['note' => 2]);
});

it('follows the record: updated, deleted, reindexed', function (): void {
    $note = Note::query()->findOrFail(1);
    $note->update(['title' => 'Доски и спринты']);

    expect(Search::query('доска')->accessKeys(['project:1'])->get()[0]->key)->toBe('N-1');

    $note->delete();
    expect(Search::query('доска')->accessKeys(['project:1'])->get())->toBe([]);

    DB::table('search_documents')->delete();
    config(['hybrid-search.models' => [Note::class]]);
    $this->artisan('search:reindex')->assertSuccessful();
    expect(Search::query('sprints')->everywhere()->get())->toHaveCount(1);
});

it('treats the query as data, not syntax', function (): void {
    expect(Search::query("'); drop table notes; --")->everywhere()->get())->toBe([])
        ->and(Search::query('арендатор & | ! :*')->accessKeys(['project:1'])->get())->toHaveCount(1);
});

it('counts by the values of a facet, leaving out its own filter but keeping the others', function (): void {
    Note::query()->create(['title' => 'Planning a sprint', 'body' => 'Sprints of the second project.', 'scope' => 'project:1', 'lang' => 'english', 'status' => 'open']);
    Note::query()->create(['title' => 'Hidden sprints', 'body' => 'Not for project one.', 'scope' => 'project:2', 'lang' => 'english', 'status' => 'open']);

    expect(Search::query('sprints')->accessKeys(['project:1'])->countsBy('status'))->toEqualCanonicalizing(['done' => 1, 'open' => 1])
        // A chosen value keeps the counts of the others.
        ->and(Search::query('sprints')->accessKeys(['project:1'])->where('status', 'done')->countsBy('status'))->toEqualCanonicalizing(['done' => 1, 'open' => 1])
        ->and(Search::query('sprints')->accessKeys(['project:1'])->types('page')->countsBy('status'))->toBe([])
        ->and(Search::query('sprints')->everywhere()->countsBy('status'))->toEqualCanonicalizing(['done' => 1, 'open' => 2])
        ->and(Search::query('sprints')->accessKeys(['project:1'])->countsBy('missing'))->toBe([])
        ->and(Search::query('sprints')->accessKeys([])->countsBy('status'))->toBe([])
        ->and(fn () => Search::query('sprints')->countsBy('status'))->toThrow(LogicException::class);
});

it('marks no full-text hit as found by meaning', function (): void {
    expect(Search::query('арендатор')->accessKeys(['project:1'])->get()[0]->byMeaning)->toBeFalse()
        ->and(Search::query('арендатор')->accessKeys(['project:1'])->hybrid()->get()[0]->byMeaning)->toBeFalse();
});
