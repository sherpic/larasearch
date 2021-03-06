<?php

namespace Gtk\Larasearch\Engines;

use Closure;
use Gtk\Larasearch\Builder;
use Psr\Log\LoggerInterface;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Elasticsearch\Connections\ConnectionFactoryInterface;

class ElasticEngine extends Engine
{
    /**
     * The Elastic client.
     *
     * @var \Elasticsearch\Client
     */
    protected $elastic;

    /**
     * The Elastic index.
     *
     * @var string
     */
    protected $index;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client $elastic
     * @return void
     */
    public function __construct(Elastic $elastic, $index)
    {
        $this->elastic = $elastic;
        
        $this->index = $index;
    }

    /**
     * Build a new client from the provided config.
     *
     * @param  array $config
     * @return \Elasticsearch\Client
     */
    public static function buildClient(array $config)
    {
        $clientBuilder = ClientBuilder::create()
            ->setHosts($config['hosts'])
            ->setRetries($config['retries'])
            ->setHandler(call_user_func('\Elasticsearch\ClientBuilder::' . $config['handler'] . 'Handler'))
            ->setConnectionPool($config['connection_pool'])
            ->setSelector($config['selector'])
            ->setSerializer($config['serializer']);

        if ($config['ssl_verification']) {
            $clientBuilder->setSSLVerification($config['ssl_verification']);
        }

        if ($config['log']) {
            $logger = isset($config['logger']) && $config['logger'] instanceof LoggerInterface
                ? $config['logger']
                : ClientBuilder::defaultLogger($config['log_path'], $config['log_level']);

            $clientBuilder->setLogger($logger);
        }

        if (isset($config['connection_factory']) && $config['connection_factory'] instanceof ConnectionFactoryInterface) {
            $clientBuilder->setConnectionFactory($config['connection_factory']);
        }

        if (isset($config['endpoint']) && $config['endpoint'] instanceof Closure) {
            $clientBuilder->setEndpoint($config['endpoint']);
        }

        return $clientBuilder->build();
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $body = new BaseCollection();

        $models->each(function ($model) use ($body) {
            $searchableArray = $model->toSearchableArray();

            if (empty($searchableArray)) {
                return;
            }

            $body->push([
                'index' => [
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                    '_id' => $model->getKey(),
                ]
            ]);

            $body->push($searchableArray);
        });

        $this->elastic->bulk([
            'refresh' => true,
            'body' => $body->all(),
        ]);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $body = new BaseCollection();

        $models->each(function ($model) use ($body) {
            $body->push([
                'delete' => [
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                    '_id' => $model->getKey(),
                ]
            ]);
        });

        $this->elastic->bulk([
            'refresh' => true,
            'body' => $body->all(),
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Gtk\Larasearch\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'size' => $builder->limit ?: 10000,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Gtk\Larasearch\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $results = $this->performSearch($builder, [
            'filters' => $this->filters($builder),
            'size' => $perPage,
            'from' => ($page - 1) * $perPage,
        ]);

        $builder->total = $results['hits']['total'];

        return $results;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Gtk\Larasearch\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type' => $builder->index ?: $builder->model->searchableAs(),
            'body' => [
                'query' => [
                    'filtered' => $options['filters'],
                ],
            ],
        ];

        if (isset($options['size'])) {
            $params['size'] = $options['size'];
        }

        if (isset($options['from'])) {
            $params['from'] = $options['from'];
        }

        return $this->elastic->search($params);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Gtk\Larasearch\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $termFilters = [];

        $matchQueries[] = [
            'match' => [
                '_all' => [
                    'query' => $builder->query,
                    'fuzziness' => 1,
                ]
            ]
        ];

        foreach ($builder->wheres as $field => $value) {
            if (is_numeric($value)) {
                $termFilters[] = [
                    'term' => [
                        $field => $value,
                    ],
                ];
            } elseif (is_string($value)) {
                $matchQueries[] = [
                    'match' => [
                        $field => [
                            'query' => $value,
                            'operator' => 'and',
                        ],
                    ],
                ];
            }
        }

        return [
            'filter' => $termFilters,
            'query' => [
                'bool' => [
                    'must' => $matchQueries,
                ]
            ],
        ];
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if ($results['hits']['total'] === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits']['hits'])->pluck('_id')->values()->all();

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
            $key = $hit['_source'][$model->getKeyName()];

            if (isset($models[$key])) {
                return $models[$key];
            }
        })->filter();
    }
}
