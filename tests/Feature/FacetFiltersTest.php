<?php

declare(strict_types=1);

use Dskripchenko\HybridSearch\Document;
use Dskripchenko\HybridSearch\Facades\Search;
use Dskripchenko\HybridSearch\Hit;

beforeEach(function (): void {
    $notes = [
        ['A-1', 'Release notes for the mobile app', ['room' => 1, 'author' => 10, 'at' => 100, 'tags' => ['red', 'blue']]],
        ['A-2', 'Release checklist and the staging run', ['room' => 2, 'author' => 11, 'at' => 300, 'tags' => ['blue']]],
        ['A-3', 'The release slipped by a week', ['room' => 3, 'author' => 10, 'at' => 200, 'tags' => []]],
        ['A-4', 'Release of the web app', ['room' => 1, 'author' => 12, 'at' => 400]],
    ];
    foreach ($notes as [$key, $body, $facets]) {
        Search::put(new Document(type: 'message', key: $key, title: '', body: $body, accessKey: 'team:1', facets: $facets, language: 'english'));
    }
});

/**
 * @param  list<Hit>  $hits
 * @return list<string>
 */
function found(array $hits): array
{
    return array_map(static fn (Hit $h): string => $h->key, $hits);
}

it('narrows to any of several values of a facet', function (): void {
    expect(found(Search::query('release')->accessKeys(['team:1'])->whereIn('room', [1, 3])->orderByFacet('at')->get()))->toBe(['A-4', 'A-3', 'A-1'])
        ->and(Search::query('release')->accessKeys(['team:1'])->whereIn('room', [])->get())->toBe([]);
});

it('narrows a numeric facet to a range, open on either side', function (): void {
    $q = static fn () => Search::query('release')->accessKeys(['team:1'])->orderByFacet('at', 'asc');

    expect(found($q()->whereBetween('at', 150, 350)->get()))->toBe(['A-3', 'A-2'])
        ->and(found($q()->whereBetween('at', 300, null)->get()))->toBe(['A-2', 'A-4'])
        ->and(found($q()->whereBetween('at', null, 100)->get()))->toBe(['A-1']);
});

it('finds documents whose list facet holds a value', function (): void {
    expect(found(Search::query('release')->accessKeys(['team:1'])->whereContains('tags', 'blue')->orderByFacet('at')->get()))->toBe(['A-2', 'A-1'])
        ->and(found(Search::query('release')->accessKeys(['team:1'])->whereContains('tags', 'red')->get()))->toBe(['A-1']);
});

it('orders by a numeric facet, newest first, the rest after', function (): void {
    expect(found(Search::query('release')->accessKeys(['team:1'])->orderByFacet('at')->get()))->toBe(['A-4', 'A-2', 'A-3', 'A-1']);
});

it('counts the values of a facet while one of them is chosen', function (): void {
    $counts = Search::query('release')->accessKeys(['team:1'])->whereIn('author', [10])->whereBetween('at', 150, null)->countsBy('author');

    // The author filter is left out of its own counts; the range stays.
    expect($counts)->toEqualCanonicalizing(['10' => 1, '11' => 1, '12' => 1]);
});

it('keeps access first: no filter widens what the keys cover', function (): void {
    expect(Search::query('release')->accessKeys(['team:2'])->whereIn('room', [1, 2, 3])->get())->toBe([]);
});

it('stops counting at a bound, for queries too broad to count whole', function (): void {
    expect(Search::query('release')->accessKeys(['team:1'])->countsByType())->toBe(['message' => 4])
        ->and(Search::query('release')->accessKeys(['team:1'])->countsByType(upTo: 2))->toBe(['message' => 2])
        ->and(array_sum(Search::query('release')->accessKeys(['team:1'])->countsBy('room', upTo: 3)))->toBe(3);
});

it('ranks only the newest matches when asked, and orders by the index order', function (): void {
    // A-4 entered the index last: newest first.
    expect(found(Search::query('release')->accessKeys(['team:1'])->orderByNewest()->get()))->toBe(['A-4', 'A-3', 'A-2', 'A-1'])
        // Only the two newest are ranked at all.
        ->and(found(Search::query('release')->accessKeys(['team:1'])->candidates(2)->get()))->toEqualCanonicalizing(['A-4', 'A-3']);
});

it('counts the total and several facets in one pass', function (): void {
    $counts = Search::query('release')->accessKeys(['team:1'])->whereBetween('at', 150, null)->counts(['room', 'author']);

    expect($counts['total'])->toBe(3)
        ->and($counts['facets']['room'])->toEqualCanonicalizing(['1' => 1, '2' => 1, '3' => 1])
        ->and($counts['facets']['author'])->toEqualCanonicalizing(['10' => 1, '11' => 1, '12' => 1])
        ->and(Search::query('release')->accessKeys(['team:1'])->counts(['room'], upTo: 2)['total'])->toBe(2)
        ->and(Search::query('release')->accessKeys([])->counts(['room']))->toBe(['total' => 0, 'facets' => ['room' => []]]);
});

it('sorts few matches newest first without walking the index', function (): void {
    expect(found(Search::query('release')->accessKeys(['team:1'])->whereIn('room', [1, 3])->orderByNewest(fewMatches: true)->get()))->toBe(['A-4', 'A-3', 'A-1'])
        ->and(Search::query('nothing-like-this')->accessKeys(['team:1'])->orderByNewest(fewMatches: true)->get())->toBe([]);
});

it('looks among the newest documents of the index only', function (): void {
    expect(found(Search::query('release')->accessKeys(['team:1'])->withinNewest(2)->orderByNewest()->get()))->toBe(['A-4', 'A-3'])
        ->and(Search::query('release')->accessKeys(['team:1'])->withinNewest(2)->counts(['room'])['total'])->toBe(2)
        ->and(Search::query('release')->accessKeys(['team:1'])->withinNewest(100)->counts([])['total'])->toBe(4);
});

it('matches beginnings of words unless told not to', function (): void {
    expect(found(Search::query('relea')->accessKeys(['team:1'])->orderByNewest()->get()))->toHaveCount(4)
        ->and(Search::query('relea')->accessKeys(['team:1'])->prefixes(false)->get())->toBe([])
        ->and(found(Search::query('releases')->accessKeys(['team:1'])->prefixes(false)->orderByNewest()->get()))->toHaveCount(4);
});

it('highlights the matched words with prefixes off too', function (): void {
    $hits = Search::query('checklist')->accessKeys(['team:1'])->prefixes(false)->get();

    expect($hits[0]->snippet)->toContain('**checklist**');
});
