# Verwendung

## Konfiguration (`config/hybrid-search.php`)

| Schlüssel | Standard | |
|---|---|---|
| `connection` | null (Standardverbindung) | die Verbindung des Index; richten Sie sie auf ein separates PostgreSQL, wenn der Index der Anwendungsdatenbank entwächst |
| `table` | `search_documents` | |
| `language` | `simple` | die Textsuchkonfiguration für Dokumente, die keine angeben |
| `queue` | `true` | in einem Job nach dem Commit indexieren; `false` indexiert sofort |
| `queue_connection`, `queue_name` | null | wohin die Jobs gehen |
| `models` | `[]` | was `search:reindex` durchläuft |
| `snippet.max_words`, `snippet.min_words` | 30, 12 | das hervorgehobene Fragment; `max_words` kürzt auch das Snippet eines reinen Vektortreffers |
| `vector.enabled` | `false` (`HYBRID_SEARCH_VECTOR`) | die Chunk-Migration, Embeddings beim Indexieren, `hybrid()` |
| `vector.table` | `search_chunks` | |
| `vector.dimensions` | 1024 (`HYBRID_SEARCH_VECTOR_DIMENSIONS`) | die Größe von `vector(N)`, 1–2000; muss zum Embedder passen |
| `vector.chunk_chars`, `vector.chunk_overlap` | 1200, 150 | Chunk-Größe und Überlappung innerhalb eines Abschnitts, in Zeichen |
| `vector.candidates` | 50 | Kandidaten pro Seite vor der Fusion |
| `vector.rrf_k` | 60 | das k der Reciprocal Rank Fusion |
| `vector.max_distance` | null | Vektorkandidaten jenseits dieser Kosinusdistanz verwerfen |
| `vector.weights.text`, `vector.weights.meaning` | 1, 1 (`HYBRID_SEARCH_WEIGHT_TEXT`, `HYBRID_SEARCH_WEIGHT_MEANING`) | wie stark jede der beiden Ranglisten in die Fusion eingeht |
| `vector.iterative_scan` | `relaxed_order` | iterative Index-Scans von pgvector 0.8+; null bei älterem pgvector |
| `vector.embedder.url` | `http://127.0.0.1:11434/v1` (`HYBRID_SEARCH_EMBEDDING_URL`) | Basis-URL einer OpenAI-kompatiblen API |
| `vector.embedder.model` | `bge-m3` (`HYBRID_SEARCH_EMBEDDING_MODEL`) | |
| `vector.embedder.key` | null (`HYBRID_SEARCH_EMBEDDING_KEY`) | wird als Bearer-Token gesendet |
| `vector.embedder.batch`, `vector.embedder.timeout` | 32, 60 | Texte pro Anfrage, Sekunden pro Anfrage |
| `vector.embedder.query_prefix`, `vector.embedder.document_prefix` | leer (`HYBRID_SEARCH_QUERY_PREFIX`, `HYBRID_SEARCH_DOCUMENT_PREFIX`) | vor Anfrage und Chunk beim Einbetten: `query: ` und `passage: ` für e5; bge-m3 und OpenAI brauchen keins. Ein neues Dokumentpräfix bettet die Chunks neu ein |

## Der Index

Eine Zeile pro Dokument: `type`, `key` (gemeinsam eindeutig), `title`, `body`,
`access_key`, `facets` (jsonb), `url`, `language` (regconfig) und ein
generiertes `tsv` — Titel mit Gewicht A, Text mit Gewicht B. Indizes: GIN auf
`tsv`, B-Tree auf `(access_key, type)`, GIN auf `facets`.

## Ranking

`ts_rank_cd` der websearch-Abfrage zählt doppelt, die der Präfixabfrage
einfach; ein exakter Schlüssel addiert 10. Bei Gleichstand gewinnt das zuletzt
indexierte Dokument.

## Vektorsuche

### Speicherung

Eine zweite Migration legt `search_chunks` an: `document_id` (verweist auf die
Dokumente, `on delete cascade`), `ordinal`, `content`, `content_hash`,
`embedding vector(N)`, `model`, `created_at`; dazu ein HNSW-Index mit
`vector_cosine_ops`. Sie läuft nur bei eingeschaltetem `vector.enabled` —
schalten Sie es vor dem Migrieren ein oder rollen Sie diese Migration zurück
und führen Sie sie erneut aus — und bricht mit einer Meldung ab, wenn der Typ
`vector` nicht erreichbar ist. Der Typ wird nie mit einem Schema qualifiziert:
Installieren Sie pgvector in einem beliebigen Schema und nehmen Sie dieses
Schema in den search_path auf (`ALTER DATABASE … SET search_path = public,
extensions`).

### Chunking

`Chunking\MarkdownChunker` zerlegt den Text: Eine ATX-Überschrift beginnt
einen Chunk und wird am Anfang jedes Chunks ihres Abschnitts wiederholt;
Absätze werden bis `chunk_chars` gepackt; ein Chunk, der einen Abschnitt
fortsetzt, beginnt mit den letzten `chunk_overlap` Zeichen des vorangehenden
Fließtexts; ein umzäunter Codeblock bleibt ganz, solange er in 1,5 ×
`chunk_chars` passt, sonst wird er zwischen Zeilen geteilt und neu umzäunt;
ein überlanger Absatz wird zwischen Sätzen, dann zwischen Wörtern geteilt.
Eingebettet wird der Titel, eine Leerzeile und der Chunk.

### Embedder

```php
interface Embedder
{
    /** @param list<string> $texts @return list<list<float>> */
    public function embed(array $texts): array;
    public function model(): string;      // wird mit jedem Chunk gespeichert
    public function dimensions(): int;
}
```

`Embedders\OpenAiCompatibleEmbedder` sendet `{model, input: [...]}` in Stapeln
an `{url}/embeddings` und ordnet die Antwort nach `index` — OpenAI, Ollamas
`/v1`, vLLM, LocalAI. Binden Sie eine eigene Implementierung, um ihn zu
ersetzen:

```php
$this->app->singleton(Embedder::class, MyEmbedder::class);
```

`Embedders\FakeEmbedder` ist deterministisch (ein gehashter Bag of Words mit
optionaler Synonymtabelle als Ersatz für Bedeutung) und protokolliert, was er
eingebettet hat; `failWith()` lässt ihn eine Exception werfen.

### Indexierung

Bei eingeschalteter Vektorsuche baut `Indexer::put()` — und damit
`IndexRecord`, der Trait `IndexedForSearch` und `search:reindex` — nach dem
Speichern des Dokuments dessen Chunks neu auf. Ein Chunk wird über den Hash
von Modell und eingebettetem Text identifiziert: Unveränderte Chunks behalten
ihre Embeddings (innerhalb der Datenbank kopiert), nur neue gehen an den
Embedder, und ein Dokument ohne Änderungen wird gar nicht geschrieben. Ein
geänderter Titel oder ein anderes Modell bettet alle Chunks neu ein.
Embedding ist langsam, lassen Sie `queue` also eingeschaltet; ein Fehler des
Embedders lässt den Job scheitern, und der erneute Versuch verarbeitet das
ganze Dokument noch einmal.

`php artisan hybrid-search:embed` durchläuft die gespeicherten Dokumente
(`--type=` ein Typ, `--force` bettet auch unveränderte Chunks neu ein,
`--chunk=` Seitengröße): nach dem Einschalten der Vektorsuche oder nach einer
Änderung der Chunk-Größe oder des Modells.

### Hybrides Ranking

`SearchQuery::hybrid()` bettet die Anfrage einmal ein und nimmt die besten
`candidates` der Volltextsuche sowie die besten `candidates` Dokumente nach
der Kosinusdistanz ihres nächstgelegenen Chunks; dann werden beide Listen per
Reciprocal Rank Fusion zusammengeführt: score = Σ w / (`rrf_k` + rank), mit `w` aus `vector.weights`. Ein
exakter Schlüssel addiert 10 und steht daher weiterhin an erster Stelle.
`Hit` behält seine Form: `score` ist der fusionierte Wert; das Snippet ist das
hervorgehobene Volltextfragment oder, für ein allein über die Bedeutung
gefundenes Dokument, die ersten `snippet.max_words` Wörter seines besten
Chunks. `limit()` und der Offset blättern innerhalb der fusionierten Liste.
`countsByType()` bleibt reine Volltextsuche.

Nächste Nachbarn gibt es immer: Ohne `max_distance` liefert eine hybride
Abfrage auch die Dokumente, die der Frage am wenigsten fern sind — wie fern
auch immer.

Wirft der Embedder eine Exception (oder liefert er einen Vektor falscher
Größe), führt `get()` die reine Volltextabfrage aus, `degraded()` gibt den
Grund zurück, und die Exception wird an den Exception-Handler der Anwendung
gemeldet. Bei ausgeschalteter Vektorsuche ist `hybrid()` reine Volltextsuche,
und `degraded()` sagt das.

## Zugriff

Jedes Dokument trägt einen Zugriffsschlüssel; eine Abfrage übergibt die
Schlüssel der fragenden Person. Bilden Sie die Schlüssel aus Ihrem eigenen
Berechtigungsmodell — für einen Tracker `project:<id>` für jedes Projekt, das
die Person sehen darf. Der Filter ist Teil der WHERE-Klausel und wird vor
Ranking und Limitierung angewendet, nie danach — auch auf der Vektorseite:
Chunks werden mit ihren Dokumenten verbunden und innerhalb der
Nächste-Nachbarn-Suche nach denselben Zugriffs-, Typ- und Facettenbedingungen
gefiltert (der iterative Scan von pgvector läuft weiter, bis genug Zeilen
durchkommen). Ohne `accessKeys()` wird eine Exception geworfen, eine leere
Liste liefert nichts, ohne den Embedder aufzurufen.

## Mandantenfähige Hosts

Im Prozess wird nichts zwischengespeichert. Bei einem Schema pro Mandant
liegen die Tabellen im Schema jedes Mandanten (führen Sie die Migrationen pro
Mandant aus; `vendor:publish --tag=hybrid-search-migrations` kopiert sie), und
die Jobs in der Queue tragen Ihren Mandantenkontext wie jeder andere Job.
pgvector wird einmal pro Datenbank in einem gemeinsamen Schema installiert
(z. B. `extensions`); der search_path jedes Mandanten muss es enthalten
(`tenant_42, extensions`), sowohl beim Migrieren als auch bei Abfragen.

## Roadmap

- Chunking von OpenAPI nach Operationen;
- `halfvec` für Modelle mit mehr als 2000 Dimensionen;
- Antworten mit Quellenangaben (Retrieval-Augmented Generation) über
  dskripchenko/laravel-llm.
