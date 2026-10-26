<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Facades\Search;
use Dskripchenko\HybridSearch\Hit;
use Dskripchenko\HybridSearch\Indexer;
use Dskripchenko\HybridSearch\Tests\Fixtures\Note;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Note::query()->create(['title' => 'Customer data', 'body' => 'Every customer lives in a separate schema of PostgreSQL.', 'scope' => 'project:1', 'lang' => 'english', 'status' => 'open']);
    Note::query()->create(['title' => 'Boards and sprints', 'body' => 'Planning sprints on a Kanban board with swimlanes.', 'scope' => 'project:1', 'lang' => 'english', 'status' => 'done']);
    Note::query()->create(['title' => 'Secret customers', 'body' => 'Customers of project two live in separate schemas too.', 'scope' => 'project:2', 'lang' => 'english']);
    Note::query()->create(['title' => 'Customer boards', 'body' => 'A board per customer, kept in a separate schema.', 'scope' => 'project:1', 'lang' => 'english']);
});

/** @return list<string> */
function hitKeys(array $hits): array
{
    return array_map(fn (Hit $h) => $h->key, $hits);
}

it('finds by meaning what full-text cannot find by words', function (): void {
    $plain = Search::query('tenant isolation')->accessKeys(['project:1']);
    $hybrid = Search::query('tenant isolation')->accessKeys(['project:1'])->hybrid();
    $hits = $hybrid->get();

    expect($plain->get())->toBe([])
        ->and($hybrid->degraded())->toBeNull()
        ->and($hits[0]->key)->toBe('N-1')
        // Found by vectors alone: the snippet is the best chunk's text.
        ->and($hits[0]->snippet)->toBe('Every customer lives in a separate schema of PostgreSQL.')
        ->and($hits[0]->title)->toBe('Customer data')
        ->and($hits[0]->url)->toBe('/notes/1')
        ->and($hits[0]->facets)->toBe(['status' => 'open']);
});

it('ranks first what both full-text and vectors agree on', function (): void {
    $hits = Search::query('customer board')->accessKeys(['project:1'])->hybrid()->get();

    // N-4 is the only full-text match and the nearest by meaning.
    expect($hits[0]->key)->toBe('N-4')
        ->and($hits[0]->score)->toEqualWithDelta(2 / 61, 1e-9)
        ->and($hits[0]->snippet)->toContain('**')
        ->and(hitKeys($hits))->toEqualCanonicalizing(['N-1', 'N-2', 'N-4'])
        ->and($hits[1]->score)->toBeLessThan($hits[0]->score);
});

it('still ranks an exact key first', function (): void {
    expect(Search::query('N-2')->accessKeys(['project:1'])->hybrid()->get()[0]->key)->toBe('N-2');
});

it('applies the access, type and facet filter to the vector side too', function (): void {
    $embedded = count($this->embedder->embedded);

    expect(hitKeys(Search::query('tenant isolation')->accessKeys(['project:2'])->hybrid()->get()))->toBe(['N-3'])
        ->and(hitKeys(Search::query('tenant isolation')->accessKeys(['project:1'])->hybrid()->get()))->not->toContain('N-3')
        ->and(hitKeys(Search::query('tenant isolation')->everywhere()->hybrid()->get()))->toContain('N-3')
        ->and(Search::query('tenant isolation')->accessKeys(['project:1'])->types('page')->hybrid()->get())->toBe([])
        ->and(hitKeys(Search::query('tenant isolation')->accessKeys(['project:1'])->where('status', 'done')->hybrid()->get()))->toBe(['N-2'])
        ->and(Search::query('tenant isolation')->accessKeys([])->hybrid()->get())->toBe([])
        ->and(fn () => Search::query('tenant isolation')->hybrid()->get())->toThrow(LogicException::class);
    // An empty key list is answered without asking the embedder.
    expect(count($this->embedder->embedded))->toBe($embedded + 5);
});

it('drops vector candidates beyond max_distance', function (): void {
    config(['hybrid-search.vector.max_distance' => 0.9]);

    expect(hitKeys(Search::query('tenant isolation')->accessKeys(['project:1'])->hybrid()->get()))->toEqualCanonicalizing(['N-1', 'N-4']);
});

it('pages through the fused list', function (): void {
    $all = hitKeys(Search::query('customer board')->accessKeys(['project:1'])->hybrid()->get());

    expect(hitKeys(Search::query('customer board')->accessKeys(['project:1'])->hybrid()->limit(1, 1)->get()))->toBe([$all[1]]);
});

it('falls back to full-text when the embedder fails, and says so', function (): void {
    $this->embedder->failWith(new RuntimeException('connection refused'));
    $query = Search::query('sprints')->accessKeys(['project:1'])->hybrid();

    expect(hitKeys($query->get()))->toBe(['N-2'])
        ->and($query->degraded())->toBe('The embedder failed: connection refused');

    $this->embedder->failWith(null);
    $query->get();
    expect($query->degraded())->toBeNull();
});

it('embeds only chunks that changed', function (): void {
    $note = Note::query()->findOrFail(2);
    $chunks = fn () => DB::table('search_chunks')->where('document_id', 2)->orderBy('ordinal')->pluck('content')->all();
    $before = count($this->embedder->embedded);

    $note->update(['status' => 'open']);
    expect(count($this->embedder->embedded))->toBe($before);

    $note->update(['body' => "# Plan\nSprints.\n\n# Board\nKanban."]);
    expect(count($this->embedder->embedded))->toBe($before + 2)
        ->and($chunks())->toBe(["# Plan\n\nSprints.", "# Board\n\nKanban."]);

    $note->update(['body' => "# Plan\nSprints.\n\n# Board\nScrum board."]);
    expect(count($this->embedder->embedded))->toBe($before + 3)
        ->and(end($this->embedder->embedded))->toBe("Boards and sprints\n\n# Board\n\nScrum board.")
        ->and($chunks())->toBe(["# Plan\n\nSprints.", "# Board\n\nScrum board."]);

    // The title is part of every embedded text.
    $note->update(['title' => 'Planning']);
    expect(count($this->embedder->embedded))->toBe($before + 5);
});

it('re-embeds with the command: missing ones, or all with --force', function (): void {
    DB::table('search_chunks')->where('document_id', 1)->delete();
    $before = count($this->embedder->embedded);

    $this->artisan('hybrid-search:embed')->expectsOutputToContain('Documents: 4, chunks embedded: 1')->assertSuccessful();
    $this->artisan('hybrid-search:embed', ['--force' => true, '--type' => 'note'])->expectsOutputToContain('chunks embedded: 4')->assertSuccessful();
    $this->artisan('hybrid-search:embed', ['--type' => 'page'])->expectsOutputToContain('Documents: 0')->assertSuccessful();

    expect(count($this->embedder->embedded))->toBe($before + 5);
});

it('takes chunks out with their document', function (): void {
    expect(DB::table('search_chunks')->count())->toBe(4);

    Note::query()->findOrFail(1)->delete();
    expect(DB::table('search_chunks')->where('document_id', 1)->count())->toBe(0)
        ->and(DB::table('search_chunks')->count())->toBe(3);

    app(Indexer::class)->remove('note', 'N-2');
    expect(DB::table('search_chunks')->count())->toBe(2);

    app(Indexer::class)->forgetType('note');
    expect(DB::table('search_chunks')->count())->toBe(0);
});

it('creates the chunks table with the configured size and an HNSW index', function (): void {
    $size = DB::scalar("select atttypmod from pg_attribute where attrelid = 'search_chunks'::regclass and attname = 'embedding'");
    $index = DB::scalar("select indexdef from pg_indexes where indexname = 'search_chunks_embedding'");

    expect($size)->toBe(1024)
        ->and($index)->toContain('hnsw')->toContain('vector_cosine_ops');
});

it('refuses to migrate when pgvector is not on the search_path', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_10_27_000002_create_search_chunks_table.php';
    DB::statement('drop table search_chunks');
    DB::statement('set search_path to public');

    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'not reachable through the search_path');
    } finally {
        DB::statement('set search_path to public, extensions');
    }
});
