<?php namespace Nicolaslopezj\Searchable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Trait SearchableTrait
 * @package Nicolaslopezj\Searchable
 * @property array $searchable
 * @property string $table
 * @property string $primaryKey
 * @method string getTable()
 */
trait SearchableTrait
{
    /**
     * @var array
     */
    protected $search_bindings = [];

    /**
     * Creates the search scope.
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @param string $search
     * @param float|null $threshold
     * @param  boolean $entireText
     * @param  boolean $entireTextOnly
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch(Builder $q, $search, $threshold = null, $entireText = false, $entireTextOnly = false)
    {
        return $this->scopeSearchRestricted($q, $search, null, $threshold, $entireText, $entireTextOnly);
    }

    public function scopeSearchRestricted(Builder $q, $search, $restriction, $threshold = null, $entireText = false, $entireTextOnly = false)
    {
        $query = clone $q;
        $query->select($this->getTable() . '.*');
        $this->makeJoins($query);

        if ( ! $search)
        {
            return $q;
        }

        $search = mb_strtolower(trim($search));
        $words = explode(' ', $search);

        $selects = [];
        $this->search_bindings = [];
        $relevance_count = 0;

        $columnConditions = $this->getConditions();

        foreach ($this->getColumns() as $column => $relevance)
        {
            // Filter the words that are applicable to this column.
            // Skip this column if we have no words left.
            if (! ($words = $this->filterWords($column, $columnConditions, $words))) {
                continue; // with next column
            };

            $relevance_count += $relevance;

            if (!$entireTextOnly) {
                $queries = $this->getSearchQueriesForColumn($query, $column, $relevance, $words);
            } else {
                $queries = [];
            }

            if ( ($entireText === true && count($words) > 1) || $entireTextOnly === true )
            {
                $queries[] = $this->getSearchQuery($query, $column, $relevance, [$search], 50, '', '');
                $queries[] = $this->getSearchQuery($query, $column, $relevance, [$search], 30, '%', '%');
            }

            foreach ($queries as $select)
            {
                $selects[] = $select;
            }
        }

        if ($selects) {

            $this->addSelectsToQuery($query, $selects);

            // Default the threshold if no value was passed.
            if (is_null($threshold)) {
                $threshold = $relevance_count / 4;
            }

            $this->filterQueryWithRelevance($query, $selects, $threshold);

            $this->makeGroupBy($query);

            $this->addBindingsToQuery($query, $this->search_bindings);

            if (is_callable($restriction)) {
                $query = $restriction($query);
            }

            $this->mergeQueries($query, $q);

        } else {

            // If no words from the query apply to any column, make sure to return nothing (instead of all records)
            $q->whereRaw("1=0");

            // The outside world might still expect a relevance field (e.g. for orderBy('relevance'))
            $q->addSelect(new Expression('0 as relevance'));

        }

        return $q;
    }

    /**
     * Returns database driver Ex: mysql, pgsql, sqlite.
     *
     * @return array
     */
    protected function getDatabaseDriver() {
        $key = $this->connection ?: Config::get('database.default');
        return Config::get('database.connections.' . $key . '.driver');
    }

    /**
     * Returns the search columns.
     *
     * @return array
     */
    protected function getColumns()
    {
        if (array_key_exists('columns', $this->searchable)) {
            $driver = $this->getDatabaseDriver();
            $prefix = Config::get("database.connections.$driver.prefix");
            $columns = [];
            foreach($this->searchable['columns'] as $column => $priority){
                $columns[$prefix . $column] = $priority;
            }
            return $columns;
        } else {
            return DB::connection()->getSchemaBuilder()->getColumnListing($this->table);
        }
    }

    /**
     * Returns the conditions. They specify when a search word may be taken into account
     * for the column, eg. "only if the length > 3" (for texts) or "only if it is a number >0 and <120" (for age column).
     *
     * You can specify one regexp or closure, or arrays of them ("OR", so if one of them applies, the word will be searched).
     *
     * @return array|null
     */
    protected function getConditions()
    {
        if (array_key_exists('conditions', $this->searchable)) {
            return $this->searchable['conditions'];
        }
        return null;
    }

    /**
     * @param string $column
     * @param array|null  $columnConditions
     * @param array  $words
     *
     * @return array
     */
    protected function filterWords($column, $columnConditions, $words)
    {
        if (is_null($columnConditions) || ! array_key_exists($column, $columnConditions)) {
            // No conditions apply to this column, return all words
            return $words;
        }

        // Make conditions an array
        $conditions = is_array($columnConditions[$column]) ? $columnConditions[$column] : [$columnConditions[$column]];

        // Filter the words for which at least one condition applies (using OR, not AND)
        return array_filter($words, function ($word) use ($conditions) {
            foreach ($conditions as $condition) {

                if (is_string($condition) && preg_match('/^'.$condition.'$/', $word)) {
                    return true;

                    // TODO: Untested, and closures are not allowed as value in $searchable.
                } elseif (($condition instanceof \Closure) && call_user_func($condition, $word)) {
                    return true;

                }
            }

            // None of the conditions apply, return FALSE for this word
            return false;
        });
    }

    /**
     * Returns whether or not to keep duplicates.
     *
     * @return array
     */
    protected function getGroupBy()
    {
        if (array_key_exists('groupBy', $this->searchable)) {
            return $this->searchable['groupBy'];
        }

        return false;
    }

    /**
     * Returns the table columns.
     *
     * @return array
     */
    public function getTableColumns()
    {
        return $this->searchable['table_columns'];
    }

    /**
     * Returns the tables that are to be joined.
     *
     * @return array
     */
    protected function getJoins()
    {
        return array_get($this->searchable, 'joins', []);
    }

    /**
     * Adds the sql joins to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function makeJoins(Builder $query)
    {
        foreach ($this->getJoins() as $table => $keys) {
            $query->leftJoin($table, function ($join) use ($keys) {
                $join->on($keys[0], '=', $keys[1]);
                if (array_key_exists(2, $keys) && array_key_exists(3, $keys)) {
                    $join->where($keys[2], '=', $keys[3]);
                }
            });
        }
    }

    /**
     * Makes the query not repeat the results.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function makeGroupBy(Builder $query)
    {
        if ($groupBy = $this->getGroupBy()) {
            $query->groupBy($groupBy);
        } else {
            $driver = $this->getDatabaseDriver();

            if ($driver == 'sqlsrv') {
                $columns = $this->getTableColumns();
            } else {
                $columns = $this->getTable() . '.' .$this->primaryKey;
            }

            $query->groupBy($columns);

            $joins = array_keys(($this->getJoins()));

            foreach ($this->getColumns() as $column => $relevance) {
                array_map(function ($join) use ($column, $query) {
                    if (Str::contains($column, $join)) {
                        $query->groupBy($column);
                    }
                }, $joins);
            }
        }
    }

    /**
     * Puts all the select clauses to the main query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $selects
     */
    protected function addSelectsToQuery(Builder $query, array $selects)
    {
        $selects = new Expression('max(' . implode(' + ', $selects) . ') as relevance');
        $query->addSelect($selects);
    }

    /**
     * Adds the relevance filter to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $selects
     * @param float $relevance_count
     */
    protected function filterQueryWithRelevance(Builder $query, array $selects, $relevance_count)
    {
        $comparator = $this->getDatabaseDriver() != 'mysql' ? implode(' + ', $selects) : 'relevance';

        $relevance_count=number_format($relevance_count,2,'.','');

        $query->havingRaw("$comparator > $relevance_count");
        $query->orderBy('relevance', 'desc');

        // add bindings to postgres
    }

    /**
     * Returns the search queries for the specified column.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param float $relevance
     * @param array $words
     * @return array
     */
    protected function getSearchQueriesForColumn(Builder $query, $column, $relevance, array $words)
    {
        $queries = [];

        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 6);
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 4, '', '%');
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 2, '%', '');
        $queries[] = $this->getSearchQuery($query, $column, $relevance, $words, 1, '%', '%');

        return $queries;
    }

    /**
     * Returns the sql string for the given parameters.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $column
     * @param string $relevance
     * @param array $words
     * @param string $compare
     * @param float $relevance_multiplier
     * @param string $pre_word
     * @param string $post_word
     * @return string
     */
    protected function getSearchQuery(Builder $query, $column, $relevance, array $words, $relevance_multiplier, $pre_word = '', $post_word = '')
    {
        $like_comparator = $this->getDatabaseDriver() == 'pgsql' ? 'ILIKE' : 'LIKE';
        $cases = [];

        foreach ($words as $word)
        {
            $cases[] = $this->getCaseCompare($column, $like_comparator, $relevance * $relevance_multiplier);
            $this->search_bindings[] = $pre_word . $word . $post_word;
        }

        return implode(' + ', $cases);
    }

    /**
     * Returns the comparison string.
     *
     * @param string $column
     * @param string $compare
     * @param float $relevance
     * @return string
     */
    protected function getCaseCompare($column, $compare, $relevance) {
        if($this->getDatabaseDriver() == 'pgsql') {
            $field = "LOWER(" . $column . ") " . $compare . " ?";
            return '(case when ' . $field . ' then ' . $relevance . ' else 0 end)';
        }

        $column = str_replace('.', '`.`', $column);
        $field = "LOWER(`" . $column . "`) " . $compare . " ?";
        return '(case when ' . $field . ' then ' . $relevance . ' else 0 end)';
    }

    /**
     * Adds the bindings to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $bindings
     */
    protected function addBindingsToQuery(Builder $query, array $bindings) {
        $count = $this->getDatabaseDriver() != 'mysql' ? 2 : 1;
        for ($i = 0; $i < $count; $i++) {
            foreach($bindings as $binding) {
                $type = $i == 0 ? 'select' : 'having';
                $query->addBinding($binding, $type);
            }
        }
    }

    /**
     * Merge our cloned query builder with the original one.
     *
     * @param \Illuminate\Database\Eloquent\Builder $clone
     * @param \Illuminate\Database\Eloquent\Builder $original
     */
    protected function mergeQueries(Builder $clone, Builder $original) {
        $tableName = DB::connection($this->connection)->getTablePrefix() . $this->getTable();
        if ($this->getDatabaseDriver() == 'pgsql') {
            $original->from(DB::connection($this->connection)->raw("({$clone->toSql()}) as {$tableName}"));
        } else {
            $original->from(DB::connection($this->connection)->raw("({$clone->toSql()}) as `{$tableName}`"));
        }
        $original->mergeBindings($clone->getQuery());
    }
}
