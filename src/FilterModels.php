<?php

declare(strict_types=1);

namespace HackerEsq\FilterModels;

use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class FilterModels
{
    public Builder $query;
    public array $searchableColumns;
    public array $filterableRelations = [];
    public array $scopes;
    public array $select = [];
    public int $itemsPerPage = 15;

    public function __construct() {
        
        $this->apply();
    }

    public function setModel(string $model): void
    {
        $this->query = (new $model)->query();
    }

    public function setQuery(Builder $query): void
    {
        $this->query = $query;
    }

    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Sets eager loads on the underlying query
     *
     * @param array $scopes
     * @return void
     */
    public function setEagerRelations(string|array $relations)
    {
        $this->query = $this->query->with($relations);
    }

    /**
     * Sets scopes on the underlying query
     *
     * @param array $scopes
     * @return void
     */
    public function setScopes(string|array $scopes): void
    {
        $this->query = $this->query->scopes($scopes);
    }

    /**
     * Allows nested json data to be aliased into a virtual column for the query
     *
     * @param array $columns can be an array of keys (e.g. `body.total_amount`)
     * @return void
     */
    public function setVirtualColumns(array $columns)
    {
        foreach($columns as $column) {
            $column = Str::replace('.', '->', $column);
            $alias = Str::snake(Str::afterLast($column, '->'));

            array_push($this->select, $column . ' as ' . $alias);
        }   
    }

    /**
     * Set columns that should be searched 
     *
     * @param array $columns can be an array of keys (e.g. `user.name` or a nested array with 
     *              `relation`, `table`, and `column` attributes)
     * @return void
     */
    public function setSearchableColumns(array $columns)
    {
        $this->searchableColumns = $columns;
    }

    /**
     * Set relations that can be filtered
     *
     * @param array $relations is an array of keys (relations) and values (columns)
     * @return void
     */
    public function setFilterableRelations(array $relations)
    {
        $this->filterableRelations = $relations;
    }

    /**
     * Set related columns using aggregate function (e.g. `package.label` would become `package_label`)
     * which enables filtering, searching, and sorting on the front end
     *
     * @param array $columns can be an array of keys (e.g. `user.name` or a nested array with 
     *              `relation`, `table`, and `column` attributes)
     * @return void
     */
    public function setRelationshipColumns(array $columns)
    {
        foreach($columns as $column) {

            // advanced
            if (is_array($column)) {
                
                $this->query->withAggregate($column['relation'], $column['table'].'.'.$column['column']);

                continue;
            }

            // not a relationship
            if(!Str::contains($column, '.')) {
                continue;
            }

            // normal rx
            $relationship = Str::before($column, '.');
            $key = Str::after($column, '.');

            $this->query->withAggregate($relationship, $key);
        }
    }

    /**
     * Sets default items per page
     *
     * @param int $itemsPerPage
     * @return void
     */
    public function setItemsPerPage(int $itemsPerPage): void
    {
        $this->itemsPerPage = $itemsPerPage;
    }

    public function relationshipFilter(string $filter, mixed $param): void
    {
        $this->query->whereHas(Str::before($filter, '.'), function($query) use ($filter, $param) {

            $suffix = Str::after($filter, '.');

            if (str_contains($suffix, '.')) {

                $this->relationshipFilter($suffix, $param);

            } else {

                $query->where($suffix, $param);
            }
        });
    }

    public function buildQuery(): void
    {
        // handle sort 
        if (request()->has('sortBy')) {

            if (Str::contains(request()->query('sortBy'), '.')) {
                $this->query->joinRelation(Str::before(request()->query('sortBy'), '.'));
            } 
            
            $this->query->orderBy(
                request()->query('sortBy'), 
                request()->query('sortDesc', false) == "true" ? 'DESC' : 'ASC'
            );
        }

        // handle filter
        if (request()->has('filter')) {

            foreach(request()->query('filter') as $filter => $params) {

                foreach(explode(',', $params ?? '') as $param) { 

                    $param = $this->convertToNative($param);
                    
                    if (array_key_exists($filter, array_flip($this->filterableRelations))) {
                        // filtered rx
                    
                        $this->relationshipFilter($filter, $param);

                    } else {
                        // traditional filter

                        $this->query->having($filter, $param); 
                    }
                }
            }
        }

        // handle search
        if (request()->has('search') && !empty($this->searchableColumns)) {
            // make searchable relationships aggregate columns
            $this->setRelationshipColumns($this->searchableColumns);

            $this->query->where(function($query) {
                
                foreach($this->searchableColumns as $column) {

                    // advanced
                    if (is_array($column)) {
                        $query->orWhereHas($column['relation'], function($query) use ($column) {
                            $query->where($column['table'].'.'.$column['column'], "like", '%' . request()->query('search') . '%');
                        });

                        continue;
                    }

                    // normal RX
                    if(Str::contains($column, '.')) {

                        $query->orWhereHas(Str::before($column, '.'), function($query) use ($column) {
                            $query->where(Str::after($column, '.'), "like", '%' . request()->query('search') . '%');
                        });

                        continue;
                    }
                        
                    // not rx
                    $query->orWhere($column, "like", '%' . request()->query('search') . '%');
                }
            });
        }

        // handle add to the select query
        $this->query->addSelect($this->select);
    }

    /**
     * Get the resulting collection
     *
     * @return 
     */
    public function get(): Collection
    {
        $this->buildQuery();

        // run
        return $this->query->get();
    }

    /**
     * Get the resulting paginated collection
     *
     * @return LengthAwarePaginator
     */
    public function paginated(): LengthAwarePaginator
    {
        $this->buildQuery();

        // handle per page
        if (request()->query('itemsPerPage') == "-1") {
            $perPage = $this->query->count();
        } else {
            $perPage = request()->query('itemsPerPage', $this->itemsPerPage);
        }
        
        // run
        return $this->query->paginate($perPage);
    }

    public function convertToNative(string $param): mixed
    {

        if ($param == 'true') {
            
            $param = true;
            
        } elseif ($param == 'false') {
            
            $param = false;
            
        } elseif ($param == 'null') {
            
            $param = null;
            
        } elseif (is_numeric($param)) {
            
            $param = (int) $param;
            
        }

        return $param;
    }

    public function apply(): void
    {
        // implement in child class
    }
}
