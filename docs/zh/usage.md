# 使用指南

## 配置（`config/hybrid-search.php`）

| 键 | 默认值 | |
|---|---|---|
| `connection` | null（默认连接） | 索引所用的数据库连接；当索引超出应用数据库的承载能力时，可指向单独的 PostgreSQL |
| `table` | `search_documents` | |
| `language` | `simple` | 未指定文本搜索配置的文档所使用的配置 |
| `queue` | `true` | 在事务提交后通过队列任务建立索引；`false` 则立即建立索引 |
| `queue_connection`、`queue_name` | null | 任务发送到的队列 |
| `models` | `[]` | `search:reindex` 要遍历的模型 |
| `snippet.max_words`、`snippet.min_words` | 30、12 | 高亮片段；`max_words` 也用于截断仅由向量命中的文档的摘要 |
| `vector.enabled` | `false`（`HYBRID_SEARCH_VECTOR`） | 分块迁移、索引时生成嵌入、`hybrid()` |
| `vector.table` | `search_chunks` | |
| `vector.dimensions` | 1024（`HYBRID_SEARCH_VECTOR_DIMENSIONS`） | `vector(N)` 的维度，1–2000；必须与嵌入器一致 |
| `vector.chunk_chars`、`vector.chunk_overlap` | 1200、150 | 分块大小及同一章节内的重叠长度，单位为字符 |
| `vector.candidates` | 50 | 融合前每一侧取出的候选数量 |
| `vector.rrf_k` | 60 | Reciprocal Rank Fusion 的 k 值 |
| `vector.max_distance` | null | 丢弃余弦距离超过该值的向量候选 |
| `vector.iterative_scan` | `relaxed_order` | pgvector 0.8+ 的迭代索引扫描；较旧的 pgvector 请设为 null |
| `vector.embedder.url` | `http://127.0.0.1:11434/v1`（`HYBRID_SEARCH_EMBEDDING_URL`） | OpenAI 兼容 API 的基础地址 |
| `vector.embedder.model` | `bge-m3`（`HYBRID_SEARCH_EMBEDDING_MODEL`） | |
| `vector.embedder.key` | null（`HYBRID_SEARCH_EMBEDDING_KEY`） | 作为 bearer 令牌发送 |
| `vector.embedder.batch`、`vector.embedder.timeout` | 32、60 | 每个请求的文本数、每个请求的超时秒数 |

## 索引

每个文档一行：`type`、`key`（二者组合唯一）、`title`、`body`、
`access_key`、`facets`（jsonb）、`url`、`language`（regconfig）以及一个生成列
`tsv` —— 标题权重为 A，正文权重为 B。索引：`tsv` 上的 GIN 索引，
`(access_key, type)` 上的 B-tree 索引，`facets` 上的 GIN 索引。

## 排序

websearch 查询的 `ts_rank_cd` 计两次，前缀查询的计一次；键完全匹配加 10 分。
分数相同时，最近建立索引的文档排在前面。

## 向量搜索

### 存储

第二个迁移创建 `search_chunks` 表：`document_id`（引用文档表，
`on delete cascade`）、`ordinal`、`content`、`content_hash`、
`embedding vector(N)`、`model`、`created_at`；以及使用 `vector_cosine_ops`
的 HNSW 索引。该迁移仅在开启 `vector.enabled` 时执行 —— 请在迁移之前开启，
或者回滚该迁移后重新执行 —— 当 `vector` 类型不可用时，它会给出提示并停止。
该类型从不带模式（schema）限定：可以把 pgvector 安装到任意模式中，并将该模式
加入 search_path（`ALTER DATABASE … SET search_path = public, extensions`）。

### 分块

`Chunking\MarkdownChunker` 切分正文：ATX 标题开启一个新块，并在其所属章节的
每个块开头重复；段落被打包至 `chunk_chars` 长度；延续某一章节的块以前文正文的
最后 `chunk_overlap` 个字符开头；围栏代码块只要能容纳在 1.5 × `chunk_chars`
之内就保持完整，否则在行与行之间切开并重新加上围栏；过长的段落先在句子之间
切分，再在单词之间切分。最终用于嵌入的内容是：标题、一个空行和该块。

### 嵌入器

```php
interface Embedder
{
    /** @param list<string> $texts @return list<list<float>> */
    public function embed(array $texts): array;
    public function model(): string;      // 与每个块一起存储
    public function dimensions(): int;
}
```

`Embedders\OpenAiCompatibleEmbedder` 将 `{model, input: [...]}` 分批发送到
`{url}/embeddings`，并按 `index` 对返回结果重新排序 —— 适用于 OpenAI、
Ollama 的 `/v1`、vLLM、LocalAI。要替换它，请绑定你自己的实现：

```php
$this->app->singleton(Embedder::class, MyEmbedder::class);
```

`Embedders\FakeEmbedder` 是确定性的（一个哈希化的词袋，可选的同义词表用来
模拟语义），并记录它嵌入过的内容；`failWith()` 会让它抛出异常。

### 建立索引

开启向量搜索后，`Indexer::put()` —— 因此还有 `IndexRecord`、
`IndexedForSearch` trait 和 `search:reindex` —— 会在保存文档之后重建其分块。
一个块由模型与其嵌入文本的哈希来标识：未变化的块保留原有嵌入（在数据库内部
复制），只有新的块才会发送给嵌入器，完全没有变化的文档则根本不会写入。
修改标题或更换模型会重新嵌入所有块。生成嵌入很慢，所以请保持 `queue` 开启；
嵌入器失败会导致任务失败，重试时会重新处理整个文档。

`php artisan hybrid-search:embed` 会遍历已存储的文档（`--type=` 仅处理一种
类型，`--force` 连未变化的块也重新嵌入，`--chunk=` 为分页大小）：适用于开启
向量搜索之后、修改分块大小或更换模型之后。

### 混合排序

`SearchQuery::hybrid()` 对查询只做一次嵌入，取全文搜索的前 `candidates` 条
结果，以及按其最近块的余弦距离排序的前 `candidates` 个文档，然后用
Reciprocal Rank Fusion 融合两个列表：score = Σ 1 / (`rrf_k` + rank)。
键完全匹配加 10 分，因此仍然排在首位。`Hit` 的结构保持不变：`score` 是融合
后的分数；摘要是高亮的全文片段，对于仅凭语义找到的文档，则是其最佳块的前
`snippet.max_words` 个词。`limit()` 和偏移量在融合后的列表内分页。
`countsByType()` 仍只基于全文搜索。

最近邻总是存在的：如果不设置 `max_distance`，混合查询也会返回与问题“最不
远”的文档，无论它们实际上有多远。

如果嵌入器抛出异常（或返回维度错误的向量），`get()` 会执行普通的全文查询，
`degraded()` 返回原因，异常会被报告给应用的异常处理器。关闭向量搜索时，
`hybrid()` 就是普通的全文搜索，`degraded()` 也会说明这一点。

## 访问控制

每个文档带有一个访问键；查询时传入发起搜索者的访问键。请根据你自己的权限
模型构造这些键 —— 例如在任务跟踪系统中，为此人可浏览的每个项目生成
`project:<id>`。过滤条件是 WHERE 子句的一部分，在排序和截取之前应用，绝不在
之后 —— 向量一侧同样如此：块与其文档关联，并在最近邻扫描内部按相同的访问、
类型和分面条件过滤（pgvector 的迭代扫描会持续进行，直到有足够多的行通过）。
未调用 `accessKeys()` 会抛出异常，空列表不返回任何结果，也不会调用嵌入器。

## 多租户宿主

进程内不缓存任何内容。在每个租户一个模式的方案中，这些表位于每个租户自己的
模式中（请为每个租户执行迁移；`vendor:publish
--tag=hybrid-search-migrations` 会复制迁移文件），队列任务像其他任务一样携带
你的租户上下文。pgvector 在每个数据库中只需在一个共享模式（例如
`extensions`）中安装一次；每个租户的 search_path 都必须包含它
（`tenant_42, extensions`），在迁移时和查询时都是如此。

## 路线图

- 按操作对 OpenAPI 进行分块；
- 为超过 2000 维的模型支持 `halfvec`；
- 通过 dskripchenko/laravel-llm 提供带引用的回答（检索增强生成）。
