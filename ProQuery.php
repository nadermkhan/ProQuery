<?php
/**
 * ProQuery - A lightweight, single-file SQLite ORM with Query Builder
 * Features: Relations, Eager Loading, N+1 Prevention, Migrations, Query Caching
 *
 * @version 1.0.0
 * @license MIT
 * @developer Nader Mahbub Khan
 * @github https://github.com/nadermkhan/ProQuery
 */

// ================================
// Core Database Connection Manager
// ================================

class ProQuery {
    private static $instance = null;
    private $pdo;
    private $config = [];
    private $queryLog = [];
    private $cache = [];
    private $eagerLoads = [];

    private function __construct($database = null) {
        $database = $database ?: ':memory:';

        try {
            $this->pdo = new PDO("sqlite:{$database}");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Enable foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            // Optimize SQLite
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = -64000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');

        } catch (PDOException $e) {
            throw new Exception("Connection failed: " . $e->getMessage());
        }
    }

    public static function init($database = null) {
        if (self::$instance === null) {
            self::$instance = new self($database);
        }
        return self::$instance;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }

    public function getQueryLog() {
        return $this->queryLog;
    }

    public function enableQueryLog() {
        $this->config['log_queries'] = true;
    }

    public function logQuery($sql, $bindings = [], $time = null) {
        if (!empty($this->config['log_queries'])) {
            $this->queryLog[] = [
                'query' => $sql,
                'bindings' => $bindings,
                'time' => $time
            ];
        }
    }
}

// ================================
// Enhanced QueryBuilder with Proper Eager Loading
// ================================

class QueryBuilder {
    protected $pdo;
    protected $table;
    protected $select = ['*'];
    protected $joins = [];
    protected $where = [];
    protected $groupBy = [];
    protected $having = [];
    protected $orderBy = [];
    protected $limit;
    protected $offset;
    protected $bindings = [];
    protected $eagerLoad = [];
    protected $withRelations = [];
    protected $modelClass = null;

    public function __construct($table = null, $modelClass = null) {
        $this->pdo = ProQuery::getInstance()->getPdo();
        $this->table = $table;
        $this->modelClass = $modelClass;
    }

    public static function table($table) {
        return new static($table);
    }

    public function setModel($modelClass) {
        $this->modelClass = $modelClass;
        return $this;
    }

    public function getModel() {
        if ($this->modelClass) {
            return new $this->modelClass;
        }
        return null;
    }

    public function select(...$columns) {
        $this->select = empty($columns) ? ['*'] : $columns;
        return $this;
    }

    public function where($column, $operator = null, $value = null) {
        if (is_array($column)) {
            foreach ($column as $key => $val) {
                $this->where($key, '=', $val);
            }
            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'AND'];
        $this->bindings[] = $value;
        return $this;
    }

    public function orWhere($column, $operator = null, $value = null) {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => 'OR'];
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn($column, array $values) {
        if (empty($values)) {
            $this->where[] = ['type' => 'raw', 'sql' => '1 = 0', 'boolean' => 'AND'];
            return $this;
        }

        $this->where[] = ['type' => 'in', 'column' => $column, 'values' => $values, 'boolean' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function whereNotIn($column, array $values) {
        if (empty($values)) {
            return $this;
        }

        $this->where[] = ['type' => 'notIn', 'column' => $column, 'values' => $values, 'boolean' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function whereNull($column) {
        $this->where[] = ['type' => 'null', 'column' => $column, 'boolean' => 'AND'];
        return $this;
    }

    public function whereNotNull($column) {
        $this->where[] = ['type' => 'notNull', 'column' => $column, 'boolean' => 'AND'];
        return $this;
    }

    public function whereBetween($column, array $values) {
        $this->where[] = ['type' => 'between', 'column' => $column, 'values' => $values, 'boolean' => 'AND'];
        $this->bindings = array_merge($this->bindings, $values);
        return $this;
    }

    public function join($table, $first, $operator = null, $second = null) {
        if (func_num_args() === 3) {
            $second = $operator;
            $operator = '=';
        }

        $this->joins[] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }

    public function leftJoin($table, $first, $operator = null, $second = null) {
        if (func_num_args() === 3) {
            $second = $operator;
            $operator = '=';
        }

        $this->joins[] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }

    public function rightJoin($table, $first, $operator = null, $second = null) {
        if (func_num_args() === 3) {
            $second = $operator;
            $operator = '=';
        }

        $this->joins[] = [
            'type' => 'RIGHT',
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second
        ];
        return $this;
    }

    public function groupBy(...$columns) {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    public function having($column, $operator = null, $value = null) {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->having[] = "{$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function orderBy($column, $direction = 'ASC') {
        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    public function latest($column = 'created_at') {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest($column = 'created_at') {
        return $this->orderBy($column, 'ASC');
    }

    public function limit($limit) {
        $this->limit = $limit;
        return $this;
    }

    public function offset($offset) {
        $this->offset = $offset;
        return $this;
    }

    public function take($limit) {
        return $this->limit($limit);
    }

    public function skip($offset) {
        return $this->offset($offset);
    }

    public function with($relations) {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        foreach ((array)$relations as $relation) {
            if (strpos($relation, '.') !== false) {
                // Handle nested relations
                $parts = explode('.', $relation, 2);
                $this->withRelations[$parts[0]][] = $parts[1];
            } else {
                $this->withRelations[$relation] = [];
            }
        }

        return $this;
    }

    protected function buildWhere() {
        if (empty($this->where)) {
            return '';
        }

        $sql = ' WHERE ';
        $conditions = [];

        foreach ($this->where as $index => $condition) {
            $clause = '';

            if ($index > 0) {
                $clause .= ' ' . $condition['boolean'] . ' ';
            }

            switch ($condition['type']) {
                case 'basic':
                    $clause .= "{$condition['column']} {$condition['operator']} ?";
                    break;
                case 'in':
                    $placeholders = implode(',', array_fill(0, count($condition['values']), '?'));
                    $clause .= "{$condition['column']} IN ({$placeholders})";
                    break;
                case 'notIn':
                    $placeholders = implode(',', array_fill(0, count($condition['values']), '?'));
                    $clause .= "{$condition['column']} NOT IN ({$placeholders})";
                    break;
                case 'null':
                    $clause .= "{$condition['column']} IS NULL";
                    break;
                case 'notNull':
                    $clause .= "{$condition['column']} IS NOT NULL";
                    break;
                case 'between':
                    $clause .= "{$condition['column']} BETWEEN ? AND ?";
                    break;
                case 'raw':
                    $clause .= $condition['sql'];
                    break;
            }

            $conditions[] = $clause;
        }

        return $sql . implode('', $conditions);
    }

    protected function buildJoins() {
        if (empty($this->joins)) {
            return '';
        }

        $sql = '';
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        return $sql;
    }

    protected function buildQuery() {
        $sql = 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->table;

        $sql .= $this->buildJoins();
        $sql .= $this->buildWhere();

        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if (!empty($this->having)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    public function get() {
        $sql = $this->buildQuery();

        $startTime = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $results = $stmt->fetchAll();

        ProQuery::getInstance()->logQuery($sql, $this->bindings, microtime(true) - $startTime);

        // Convert to model instances if model class is set
        if ($this->modelClass && !empty($results)) {
            $models = [];
            foreach ($results as $result) {
                $model = new $this->modelClass();
                $model->setRawAttributes($result, true);
                $models[] = $model;
            }
            $results = $models;
        }

        // Handle eager loading to prevent N+1
        if (!empty($this->withRelations) && !empty($results)) {
            $this->eagerLoadRelations($results);
        }

        return $results;
    }

    public function first() {
        $this->limit(1);
        $results = $this->get();
        return $results ? $results[0] : null;
    }

    public function find($id) {
        return $this->where('id', $id)->first();
    }

    public function findOrFail($id) {
        $result = $this->find($id);
        if (!$result) {
            throw new Exception("Record not found with ID: {$id}");
        }
        return $result;
    }

    public function count($column = '*') {
        $this->select = ["COUNT({$column}) as aggregate"];
        $result = $this->first();
        return $result ? (int)($result instanceof Model ? $result->aggregate : $result['aggregate']) : 0;
    }

    public function max($column) {
        $this->select = ["MAX({$column}) as aggregate"];
        $result = $this->first();
        return $result ? ($result instanceof Model ? $result->aggregate : $result['aggregate']) : null;
    }

    public function min($column) {
        $this->select = ["MIN({$column}) as aggregate"];
        $result = $this->first();
        return $result ? ($result instanceof Model ? $result->aggregate : $result['aggregate']) : null;
    }

    public function avg($column) {
        $this->select = ["AVG({$column}) as aggregate"];
        $result = $this->first();
        return $result ? ($result instanceof Model ? $result->aggregate : $result['aggregate']) : null;
    }

    public function sum($column) {
        $this->select = ["SUM({$column}) as aggregate"];
        $result = $this->first();
        return $result ? ($result instanceof Model ? $result->aggregate : $result['aggregate']) : 0;
    }

    public function exists() {
        return $this->count() > 0;
    }

    public function doesntExist() {
        return !$this->exists();
    }

    public function insert(array $data) {
        // Handle batch insert
        if (isset($data[0]) && is_array($data[0])) {
            return $this->insertBatch($data);
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $startTime = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute(array_values($data));

        ProQuery::getInstance()->logQuery($sql, array_values($data), microtime(true) - $startTime);

        return $result ? $this->pdo->lastInsertId() : false;
    }

    public function insertGetId(array $data) {
        return $this->insert($data);
    }

    public function insertBatch(array $records) {
        if (empty($records)) {
            return false;
        }

        $columns = array_keys($records[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($records), $placeholders));

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES " . $allPlaceholders;

        $values = [];
        foreach ($records as $record) {
            foreach ($columns as $column) {
                $values[] = $record[$column] ?? null;
            }
        }

        $startTime = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($values);

        ProQuery::getInstance()->logQuery($sql, $values, microtime(true) - $startTime);

        return $result;
    }

    public function update(array $data) {
        $sets = [];
        $values = [];

        foreach ($data as $column => $value) {
            if ($value instanceof Expression) {
                $sets[] = "{$column} = {$value->getValue()}";
            } else {
                $sets[] = "{$column} = ?";
                $values[] = $value;
            }
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets) . $this->buildWhere();
        $values = array_merge($values, $this->bindings);

        $startTime = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($values);

        ProQuery::getInstance()->logQuery($sql, $values, microtime(true) - $startTime);

        return $stmt->rowCount();
    }

    public function increment($column, $amount = 1) {
        return $this->update([$column => new Expression("{$column} + {$amount}")]);
    }

    public function decrement($column, $amount = 1) {
        return $this->update([$column => new Expression("{$column} - {$amount}")]);
    }

    public function delete() {
        $sql = "DELETE FROM {$this->table}" . $this->buildWhere();

        $startTime = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($this->bindings);

        ProQuery::getInstance()->logQuery($sql, $this->bindings, microtime(true) - $startTime);

        return $stmt->rowCount();
    }

    public function truncate() {
        $sql = "DELETE FROM {$this->table}";
        $this->pdo->exec($sql);
        $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name='{$this->table}'");
        return true;
    }

    public function raw($sql, $bindings = []) {
        $startTime = microtime(true);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        ProQuery::getInstance()->logQuery($sql, $bindings, microtime(true) - $startTime);

        return $stmt->fetchAll();
    }

    public function toSql() {
        return $this->buildQuery();
    }

    public function dd() {
        var_dump([
            'query' => $this->toSql(),
            'bindings' => $this->bindings
        ]);
        die();
    }

    public function dump() {
        var_dump([
            'query' => $this->toSql(),
            'bindings' => $this->bindings
        ]);
        return $this;
    }

    public function paginate($perPage = 15, $page = null) {
        $page = $page ?: (isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $page = max(1, $page);

        $total = $this->count();

        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;

        $results = $this->get();

        return new Paginator($results, $total, $perPage, $page);
    }

    public function chunk($size, callable $callback) {
        $page = 1;

        do {
            $results = $this->limit($size)->offset(($page - 1) * $size)->get();

            if (empty($results)) {
                break;
            }

            if ($callback($results, $page) === false) {
                break;
            }

            $page++;
        } while (count($results) === $size);
    }

    /**
     * Production-ready eager loading implementation
     * Properly handles relations and prevents N+1 queries
     */
    protected function eagerLoadRelations(&$results) {
        if (empty($results)) {
            return;
        }

        // Ensure we have model instances
        $isModelArray = $results[0] instanceof Model;

        if (!$isModelArray) {
            return;
        }

        foreach ($this->withRelations as $relation => $nestedRelations) {
            $this->loadRelation($results, $relation, $nestedRelations);
        }
    }

    /**
     * Load a specific relation for a collection of models
     * This is the production-ready implementation
     */
    protected function loadRelation(&$models, $relationName, $nestedRelations = []) {
        if (empty($models)) {
            return;
        }

        // Get the first model to determine relation type
        $firstModel = $models[0];

        if (!method_exists($firstModel, $relationName)) {
            throw new Exception("Relation {$relationName} does not exist on " . get_class($firstModel));
        }

        // Get relation instance
        $relation = $firstModel->$relationName();

        if ($relation instanceof BelongsTo) {
            $this->loadBelongsTo($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof HasOne) {
            $this->loadHasOne($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof HasMany) {
            $this->loadHasMany($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof BelongsToMany) {
            $this->loadBelongsToMany($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof HasManyThrough) {
            $this->loadHasManyThrough($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof HasOneThrough) {
            $this->loadHasOneThrough($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof MorphTo) {
            $this->loadMorphTo($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof MorphOne || $relation instanceof MorphMany) {
            $this->loadMorphOneOrMany($models, $relationName, $relation, $nestedRelations);
        } elseif ($relation instanceof MorphToMany) {
            $this->loadMorphToMany($models, $relationName, $relation, $nestedRelations);
        }
    }

    protected function loadBelongsTo(&$models, $relationName, $relation, $nestedRelations) {
        // Collect foreign key values
        $foreignKeyValues = [];
        foreach ($models as $model) {
            $value = $model->getAttribute($relation->getForeignKey());
            if ($value !== null) {
                $foreignKeyValues[] = $value;
            }
        }

        if (empty($foreignKeyValues)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, null);
            }
            return;
        }

        // Load related models in one query
        $relatedModels = $relation->getRelated()
            ->newQuery()
            ->whereIn($relation->getOwnerKey(), array_unique($foreignKeyValues));

        // Apply nested eager loading
        if (!empty($nestedRelations)) {
            $relatedModels->with($nestedRelations);
        }

        $relatedModels = $relatedModels->get();

        // Index by owner key
        $indexed = [];
        foreach ($relatedModels as $related) {
            $indexed[$related->getAttribute($relation->getOwnerKey())] = $related;
        }

        // Assign to models
        foreach ($models as $model) {
            $foreignValue = $model->getAttribute($relation->getForeignKey());
            $model->setRelation($relationName, $indexed[$foreignValue] ?? null);
        }
    }

    protected function loadHasOne(&$models, $relationName, $relation, $nestedRelations) {
        // Collect parent key values
        $parentKeys = [];
        foreach ($models as $model) {
            $parentKeys[] = $model->getAttribute($relation->getLocalKey());
        }

        if (empty($parentKeys)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, null);
            }
            return;
        }

        // Load related models in one query
        $relatedModels = $relation->getRelated()
            ->newQuery()
            ->whereIn($relation->getForeignKey(), array_unique($parentKeys));

        // Apply nested eager loading
        if (!empty($nestedRelations)) {
            $relatedModels->with($nestedRelations);
        }

        $relatedModels = $relatedModels->get();

        // Index by foreign key
        $indexed = [];
        foreach ($relatedModels as $related) {
            $foreignValue = $related->getAttribute($relation->getForeignKey());
            if (!isset($indexed[$foreignValue])) {
                $indexed[$foreignValue] = $related;
            }
        }

        // Assign to models
        foreach ($models as $model) {
            $localValue = $model->getAttribute($relation->getLocalKey());
            $model->setRelation($relationName, $indexed[$localValue] ?? null);
        }
    }

    protected function loadHasMany(&$models, $relationName, $relation, $nestedRelations) {
        // Collect parent key values
        $parentKeys = [];
        foreach ($models as $model) {
            $parentKeys[] = $model->getAttribute($relation->getLocalKey());
        }

        if (empty($parentKeys)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        // Load related models in one query
        $relatedModels = $relation->getRelated()
            ->newQuery()
            ->whereIn($relation->getForeignKey(), array_unique($parentKeys));

        // Apply nested eager loading
        if (!empty($nestedRelations)) {
            $relatedModels->with($nestedRelations);
        }

        $relatedModels = $relatedModels->get();

        // Group by foreign key
        $grouped = [];
        foreach ($relatedModels as $related) {
            $foreignValue = $related->getAttribute($relation->getForeignKey());
            if (!isset($grouped[$foreignValue])) {
                $grouped[$foreignValue] = [];
            }
            $grouped[$foreignValue][] = $related;
        }

        // Assign to models
        foreach ($models as $model) {
            $localValue = $model->getAttribute($relation->getLocalKey());
            $model->setRelation($relationName, $grouped[$localValue] ?? []);
        }
    }

    protected function loadBelongsToMany(&$models, $relationName, $relation, $nestedRelations) {
        // Collect parent key values
        $parentKeys = [];
        foreach ($models as $model) {
            $parentKeys[] = $model->getAttribute($relation->getParentKey());
        }

        if (empty($parentKeys)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        // Load pivot records
        $pivotRecords = QueryBuilder::table($relation->getTable())
            ->whereIn($relation->getForeignPivotKey(), array_unique($parentKeys))
            ->get();

        if (empty($pivotRecords)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        // Collect related IDs
        $relatedIds = array_unique(array_column($pivotRecords, $relation->getRelatedPivotKey()));

        // Load related models in one query
        $relatedModels = $relation->getRelated()
            ->newQuery()
            ->whereIn($relation->getRelatedKey(), $relatedIds);

        // Apply nested eager loading
        if (!empty($nestedRelations)) {
            $relatedModels->with($nestedRelations);
        }

        $relatedModels = $relatedModels->get();

        // Index related models
        $relatedIndexed = [];
        foreach ($relatedModels as $related) {
            $relatedIndexed[$related->getAttribute($relation->getRelatedKey())] = $related;
        }

        // Group pivot records by parent key
        $pivotGrouped = [];
        foreach ($pivotRecords as $pivot) {
            $parentId = $pivot[$relation->getForeignPivotKey()];
            if (!isset($pivotGrouped[$parentId])) {
                $pivotGrouped[$parentId] = [];
            }
            $pivotGrouped[$parentId][] = $pivot;
        }

        // Assign to models with pivot data
        foreach ($models as $model) {
            $parentId = $model->getAttribute($relation->getParentKey());
            $relatedForModel = [];

            if (isset($pivotGrouped[$parentId])) {
                foreach ($pivotGrouped[$parentId] as $pivot) {
                    $relatedId = $pivot[$relation->getRelatedPivotKey()];
                    if (isset($relatedIndexed[$relatedId])) {
                        $related = clone $relatedIndexed[$relatedId];
                        // Attach pivot data
                        $related->pivot = (object) $pivot;
                        $relatedForModel[] = $related;
                    }
                }
            }

            $model->setRelation($relationName, $relatedForModel);
        }
    }

    protected function loadHasManyThrough(&$models, $relationName, $relation, $nestedRelations) {
        // Implementation for HasManyThrough
        $parentKeys = [];
        foreach ($models as $model) {
            $parentKeys[] = $model->getAttribute($relation->getLocalKey());
        }

        if (empty($parentKeys)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        // Build the through query
        $query = $relation->getRelated()->newQuery()
            ->select($relation->getRelated()->getTable() . '.*')
            ->join(
                $relation->getThroughParent()->getTable(),
                $relation->getQualifiedParentKeyName(),
                '=',
                $relation->getQualifiedFirstKeyName()
            )
            ->whereIn($relation->getQualifiedLocalKeyName(), array_unique($parentKeys));

        // Apply nested eager loading
        if (!empty($nestedRelations)) {
            $query->with($nestedRelations);
        }

        $relatedModels = $query->get();

        // Group by the through parent's foreign key
        $grouped = [];
        foreach ($relatedModels as $related) {
            $throughKey = $related->getAttribute($relation->getFirstKeyName());
            if (!isset($grouped[$throughKey])) {
                $grouped[$throughKey] = [];
            }
            $grouped[$throughKey][] = $related;
        }

        // Assign to models
        foreach ($models as $model) {
            $localValue = $model->getAttribute($relation->getLocalKey());
            $model->setRelation($relationName, $grouped[$localValue] ?? []);
        }
    }

    protected function loadHasOneThrough(&$models, $relationName, $relation, $nestedRelations) {
        // Similar to HasManyThrough but expecting single result
        $this->loadHasManyThrough($models, $relationName, $relation, $nestedRelations);

        // Convert collections to single items
        foreach ($models as $model) {
            $related = $model->getRelation($relationName);
            $model->setRelation($relationName, !empty($related) ? $related[0] : null);
        }
    }

    protected function loadMorphTo(&$models, $relationName, $relation, $nestedRelations) {
        // Group models by morph type
        $grouped = [];
        foreach ($models as $model) {
            $type = $model->getAttribute($relation->getMorphType());
            $id = $model->getAttribute($relation->getForeignKey());

            if ($type && $id) {
                if (!isset($grouped[$type])) {
                    $grouped[$type] = [];
                }
                $grouped[$type][] = $id;
            }
        }

        // Load each type separately
        $results = [];
        foreach ($grouped as $type => $ids) {
            $relatedClass = $relation->getMorphedModel($type);
            if (!$relatedClass) {
                continue;
            }

            $query = (new $relatedClass)->newQuery()->whereIn('id', array_unique($ids));

            // Apply nested eager loading
            if (!empty($nestedRelations)) {
                $query->with($nestedRelations);
            }

            $typeResults = $query->get();

            foreach ($typeResults as $result) {
                $results[$type . '_' . $result->getAttribute('id')] = $result;
            }
        }

        // Assign to models
        foreach ($models as $model) {
            $type = $model->getAttribute($relation->getMorphType());
            $id = $model->getAttribute($relation->getForeignKey());
            $key = $type . '_' . $id;

            $model->setRelation($relationName, $results[$key] ?? null);
        }
    }

    protected function loadMorphOneOrMany(&$models, $relationName, $relation, $nestedRelations) {
        // Collect parent keys
        $parentKeys = [];
        foreach ($models as $model) {
            $parentKeys[] = $model->getAttribute($relation->getLocalKey());
        }

        if (empty($parentKeys)) {
            $default = $relation instanceof MorphOne ? null : [];
            foreach ($models as $model) {
                $model->setRelation($relationName, $default);
            }
            return;
        }

        // Load related models
        $query = $relation->getRelated()->newQuery()
            ->where($relation->getMorphType(), $relation->getMorphClass())
            ->whereIn($relation->getForeignKey(), array_unique($parentKeys));

        // Apply nested eager loading
        if (!empty($nestedRelations)) {
            $query->with($nestedRelations);
        }

        $relatedModels = $query->get();

        // Group or index results
        if ($relation instanceof MorphOne) {
            $indexed = [];
            foreach ($relatedModels as $related) {
                $foreignValue = $related->getAttribute($relation->getForeignKey());
                if (!isset($indexed[$foreignValue])) {
                    $indexed[$foreignValue] = $related;
                }
            }

            foreach ($models as $model) {
                $localValue = $model->getAttribute($relation->getLocalKey());
                $model->setRelation($relationName, $indexed[$localValue] ?? null);
            }
        } else {
            $grouped = [];
            foreach ($relatedModels as $related) {
                $foreignValue = $related->getAttribute($relation->getForeignKey());
                if (!isset($grouped[$foreignValue])) {
                    $grouped[$foreignValue] = [];
                }
                $grouped[$foreignValue][] = $related;
            }

            foreach ($models as $model) {
                $localValue = $model->getAttribute($relation->getLocalKey());
                $model->setRelation($relationName, $grouped[$localValue] ?? []);
            }
        }
    }

    protected function loadMorphToMany(&$models, $relationName, $relation, $nestedRelations) {
        // Similar to BelongsToMany but with morph columns
        $parentKeys = [];
        foreach ($models as $model) {
            $parentKeys[] = $model->getAttribute($relation->getParentKey());
        }

        if (empty($parentKeys)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        // Load pivot records with morph type constraint
        $pivotRecords = QueryBuilder::table($relation->getTable())
            ->where($relation->getMorphType(), $relation->getMorphClass())
            ->whereIn($relation->getForeignPivotKey(), array_unique($parentKeys))
            ->get();

        if (empty($pivotRecords)) {
            foreach ($models as $model) {
                $model->setRelation($relationName, []);
            }
            return;
        }

        // Continue as with BelongsToMany
        $this->loadBelongsToManyPivotData($models, $relationName, $relation, $pivotRecords, $nestedRelations);
    }

    protected function loadBelongsToManyPivotData(&$models, $relationName, $relation, $pivotRecords, $nestedRelations) {
        // Extract related IDs from pivot
        $relatedIds = array_unique(array_column($pivotRecords, $relation->getRelatedPivotKey()));

        // Load related models
        $relatedModels = $relation->getRelated()
            ->newQuery()
            ->whereIn($relation->getRelatedKey(), $relatedIds);

        if (!empty($nestedRelations)) {
            $relatedModels->with($nestedRelations);
        }

        $relatedModels = $relatedModels->get();

        // Index related models
        $relatedIndexed = [];
        foreach ($relatedModels as $related) {
            $relatedIndexed[$related->getAttribute($relation->getRelatedKey())] = $related;
        }

        // Group pivot by parent
        $pivotGrouped = [];
        foreach ($pivotRecords as $pivot) {
            $parentId = $pivot[$relation->getForeignPivotKey()];
            if (!isset($pivotGrouped[$parentId])) {
                $pivotGrouped[$parentId] = [];
            }
            $pivotGrouped[$parentId][] = $pivot;
        }

        // Assign to models
        foreach ($models as $model) {
            $parentId = $model->getAttribute($relation->getParentKey());
            $relatedForModel = [];

            if (isset($pivotGrouped[$parentId])) {
                foreach ($pivotGrouped[$parentId] as $pivot) {
                    $relatedId = $pivot[$relation->getRelatedPivotKey()];
                    if (isset($relatedIndexed[$relatedId])) {
                        $related = clone $relatedIndexed[$relatedId];
                        $related->pivot = (object) $pivot;
                        $relatedForModel[] = $related;
                    }
                }
            }

            $model->setRelation($relationName, $relatedForModel);
        }
    }
}

// ================================
// Base Relation Class
// ================================

abstract class Relation
{
    protected $query;
    protected $parent;
    protected $related;
    protected $foreignKey;
    protected $localKey;

    public function __construct($query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();
    }

    public function getQuery()
    {
        return $this->query;
    }

    public function getParent()
    {
        return $this->parent;
    }

    public function getRelated()
    {
        return $this->related;
    }

    public function getForeignKey()
    {
        return $this->foreignKey;
    }

    public function getLocalKey()
    {
        return $this->localKey;
    }

    public function getQualifiedForeignKeyName()
    {
        return $this->related->getTable() . '.' . $this->foreignKey;
    }

    public function getQualifiedLocalKeyName()
    {
        return $this->parent->getTable() . '.' . $this->localKey;
    }

    abstract public function getResults();

    abstract public function initRelation(array $models, $relation);

    abstract public function match(array $models, $results, $relation);
}

// ================================
// HasOne Relation
// ================================

class HasOne extends Relation
{
    protected $foreignKey;
    protected $localKey;

    public function __construct($query, Model $parent, $foreignKey, $localKey)
    {
        parent::__construct($query, $parent);

        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        $this->query->where($foreignKey, $parent->getAttribute($localKey));
    }

    public function getResults()
    {
        return $this->query->first();
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, $results, $relation)
    {
        $indexed = [];

        foreach ($results as $result) {
            $indexed[$result->getAttribute($this->foreignKey)] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);

            if (isset($indexed[$key])) {
                $model->setRelation($relation, $indexed[$key]);
            }
        }

        return $models;
    }
}

// ================================
// HasMany Relation
// ================================

class HasMany extends Relation
{
    protected $foreignKey;
    protected $localKey;

    public function __construct($query, Model $parent, $foreignKey, $localKey)
    {
        parent::__construct($query, $parent);

        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        $this->query->where($foreignKey, $parent->getAttribute($localKey));
    }

    public function getResults()
    {
        return $this->query->get();
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, []);
        }

        return $models;
    }

    public function match(array $models, $results, $relation)
    {
        $grouped = [];

        foreach ($results as $result) {
            $key = $result->getAttribute($this->foreignKey);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->localKey);

            if (isset($grouped[$key])) {
                $model->setRelation($relation, $grouped[$key]);
            }
        }

        return $models;
    }
}

// ================================
// BelongsTo Relation
// ================================

class BelongsTo extends Relation
{
    protected $foreignKey;
    protected $ownerKey;

    public function __construct($query, Model $child, $foreignKey, $ownerKey)
    {
        parent::__construct($query, $child);

        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;

        $this->query->where($ownerKey, $child->getAttribute($foreignKey));
    }

    public function getOwnerKey()
    {
        return $this->ownerKey;
    }

    public function getResults()
    {
        return $this->query->first();
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }

    public function match(array $models, $results, $relation)
    {
        $indexed = [];

        foreach ($results as $result) {
            $indexed[$result->getAttribute($this->ownerKey)] = $result;
        }

        foreach ($models as $model) {
            $key = $model->getAttribute($this->foreignKey);

            if (isset($indexed[$key])) {
                $model->setRelation($relation, $indexed[$key]);
            }
        }

        return $models;
    }
}

// ================================
// BelongsToMany Relation
// ================================

class BelongsToMany extends Relation
{
    protected $table;
    protected $foreignPivotKey;
    protected $relatedPivotKey;
    protected $parentKey;
    protected $relatedKey;
    protected $pivotColumns = [];
    protected $pivotWheres = [];
    protected $withTimestamps = false;

    public function __construct($query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey)
    {
        parent::__construct($query, $parent);

        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getForeignPivotKey()
    {
        return $this->foreignPivotKey;
    }

    public function getRelatedPivotKey()
    {
        return $this->relatedPivotKey;
    }

    public function getParentKey()
    {
        return $this->parentKey;
    }

    public function getRelatedKey()
    {
        return $this->relatedKey;
    }

    public function withPivot($columns)
    {
        if (is_array($columns)) {
            $this->pivotColumns = array_merge($this->pivotColumns, $columns);
        } else {
            $this->pivotColumns = array_merge($this->pivotColumns, func_get_args());
        }

        return $this;
    }

    public function withTimestamps()
    {
        $this->withTimestamps = true;
        return $this->withPivot('created_at', 'updated_at');
    }

    public function wherePivot($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->pivotWheres[] = compact('column', 'operator', 'value');

        return $this;
    }

    public function getResults()
    {
        // Get pivot records
        $pivotQuery = QueryBuilder::table($this->table)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));

        // Apply pivot wheres
        foreach ($this->pivotWheres as $where) {
            $pivotQuery->where($where['column'], $where['operator'], $where['value']);
        }

        $pivotRecords = $pivotQuery->get();

        if (empty($pivotRecords)) {
            return [];
        }

        // Get related records
        $relatedIds = array_column($pivotRecords, $this->relatedPivotKey);
        $relatedRecords = $this->query->whereIn($this->relatedKey, $relatedIds)->get();

        // Map pivot data to related records
        $pivotMap = [];
        foreach ($pivotRecords as $pivot) {
            $pivotMap[$pivot[$this->relatedPivotKey]] = $pivot;
        }

        foreach ($relatedRecords as $related) {
            $relatedId = $related->getAttribute($this->relatedKey);
            if (isset($pivotMap[$relatedId])) {
                $related->pivot = (object)$pivotMap[$relatedId];
            }
        }

        return $relatedRecords;
    }

    public function attach($ids, array $attributes = [])
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $records = [];
        foreach ($ids as $id) {
            $record = array_merge($attributes, [
                $this->foreignPivotKey => $this->parent->getAttribute($this->parentKey),
                $this->relatedPivotKey => $id
            ]);

            if ($this->withTimestamps) {
                $now = date('Y-m-d H:i:s');
                $record['created_at'] = $now;
                $record['updated_at'] = $now;
            }

            $records[] = $record;
        }

        return QueryBuilder::table($this->table)->insert($records);
    }

    public function detach($ids = null)
    {
        $query = QueryBuilder::table($this->table)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey));

        if ($ids !== null) {
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $query->whereIn($this->relatedPivotKey, $ids);
        }

        return $query->delete();
    }

    public function sync(array $ids, $detaching = true)
    {
        $current = QueryBuilder::table($this->table)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))
            ->get();

        $currentIds = array_column($current, $this->relatedPivotKey);

        // Determine what to attach
        $attach = array_diff($ids, $currentIds);

        // Determine what to detach
        $detach = $detaching ? array_diff($currentIds, $ids) : [];

        if (!empty($attach)) {
            $this->attach($attach);
        }

        if (!empty($detach)) {
            $this->detach($detach);
        }

        return [
            'attached' => $attach,
            'detached' => $detach
        ];
    }

    public function updateExistingPivot($id, array $attributes)
    {
        if ($this->withTimestamps) {
            $attributes['updated_at'] = date('Y-m-d H:i:s');
        }

        return QueryBuilder::table($this->table)
            ->where($this->foreignPivotKey, $this->parent->getAttribute($this->parentKey))
            ->where($this->relatedPivotKey, $id)
            ->update($attributes);
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, []);
        }

        return $models;
    }

    public function match(array $models, $results, $relation)
    {
        // Implementation handled by eager loading
        return $models;
    }
}

// Additional relation types for completeness

class HasManyThrough extends Relation
{
    protected $throughParent;
    protected $firstKey;
    protected $secondKey;
    protected $localKey;
    protected $secondLocalKey;

    public function __construct($query, Model $farParent, Model $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey)
    {
        parent::__construct($query, $farParent);

        $this->throughParent = $throughParent;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;
        $this->localKey = $localKey;
        $this->secondLocalKey = $secondLocalKey;
    }

    public function getThroughParent()
    {
        return $this->throughParent;
    }

    public function getFirstKeyName()
    {
        return $this->firstKey;
    }

    public function getQualifiedParentKeyName()
    {
        return $this->parent->getTable() . '.' . $this->secondKey;
    }

    public function getQualifiedFirstKeyName()
    {
        return $this->throughParent->getTable() . '.' . $this->firstKey;
    }

    public function getQualifiedLocalKeyName()
    {
        return $this->parent->getTable() . '.' . $this->localKey;
    }

    public function getResults()
    {
        return $this->query->get();
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, []);
        }

        return $models;
    }

    public function match(array $models, $results, $relation)
    {
        // Implementation handled by eager loading
        return $models;
    }
}

class HasOneThrough extends HasManyThrough
{
    public function getResults()
    {
        return $this->query->first();
    }

    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, null);
        }

        return $models;
    }
}

// Polymorphic relations
class MorphTo extends BelongsTo
{
    protected $morphType;
    protected $morphableTypes = [];

    public function __construct($query, Model $parent, $foreignKey, $ownerKey, $morphType)
    {
        $this->morphType = $morphType;

        parent::__construct($query, $parent, $foreignKey, $ownerKey);
    }

    public function getMorphType()
    {
        return $this->morphType;
    }

    public function morphWith(array $types)
    {
        $this->morphableTypes = $types;
        return $this;
    }

    public function getMorphedModel($alias)
    {
        return $this->morphableTypes[$alias] ?? $alias;
    }
}

class MorphOne extends HasOne
{
    protected $morphType;
    protected $morphClass;

    public function __construct($query, Model $parent, $morphType, $foreignKey, $localKey)
    {
        $this->morphType = $morphType;
        $this->morphClass = get_class($parent);

        parent::__construct($query, $parent, $foreignKey, $localKey);

        $this->query->where($morphType, $this->morphClass);
    }

    public function getMorphType()
    {
        return $this->morphType;
    }

    public function getMorphClass()
    {
        return $this->morphClass;
    }
}

class MorphMany extends HasMany
{
    protected $morphType;
    protected $morphClass;

    public function __construct($query, Model $parent, $morphType, $foreignKey, $localKey)
    {
        $this->morphType = $morphType;
        $this->morphClass = get_class($parent);

        parent::__construct($query, $parent, $foreignKey, $localKey);

        $this->query->where($morphType, $this->morphClass);
    }

    public function getMorphType()
    {
        return $this->morphType;
    }

    public function getMorphClass()
    {
        return $this->morphClass;
    }
}

class MorphToMany extends BelongsToMany
{
    protected $morphType;
    protected $morphClass;

    public function __construct($query, Model $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $morphType)
    {
        $this->morphType = $morphType;
        $this->morphClass = get_class($parent);

        parent::__construct($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    public function getMorphType()
    {
        return $this->morphType;
    }

    public function getMorphClass()
    {
        return $this->morphClass;
    }
}
// ================================
// Model Base Class
// ================================


/**
 * Enhanced Model Class with Full Relation Support
 */
abstract class Model
{
    protected static $table;
    protected static $primaryKey = 'id';
    protected static $fillable = [];
    protected static $guarded = ['id'];
    protected static $hidden = [];
    protected static $casts = [];
    protected static $timestamps = true;
    protected static $dateFormat = 'Y-m-d H:i:s';

    protected $attributes = [];
    protected $original = [];
    protected $relations = [];
    protected $exists = false;
    protected $wasRecentlyCreated = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public static function query()
    {
        $instance = new static;
        return (new QueryBuilder(static::getTable(), static::class))->setModel(static::class);
    }

    public function newQuery()
    {
        return static::query();
    }

    public static function all()
    {
        return static::query()->get();
    }

    public static function find($id)
    {
        return static::query()->find($id);
    }

    public static function findOrFail($id)
    {
        return static::query()->findOrFail($id);
    }

    public static function where($column, $operator = null, $value = null)
    {
        return static::query()->where($column, $operator, $value);
    }

    public static function create(array $attributes)
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    public static function updateOrCreate(array $attributes, array $values = [])
    {
        $query = static::query();

        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }

        $record = $query->first();

        if ($record) {
            $record->update($values);
            return $record;
        }

        return static::create(array_merge($attributes, $values));
    }

    public static function firstOrCreate(array $attributes, array $values = [])
    {
        $query = static::query();

        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }

        $record = $query->first();

        if ($record) {
            return $record;
        }

        return static::create(array_merge($attributes, $values));
    }

    public static function firstOrNew(array $attributes, array $values = [])
    {
        $query = static::query();

        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }

        $record = $query->first();

        if ($record) {
            return $record;
        }

        return new static(array_merge($attributes, $values));
    }

    public static function destroy($ids)
    {
        $ids = is_array($ids) ? $ids : func_get_args();
        return static::query()->whereIn(static::$primaryKey, $ids)->delete();
    }

    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    public function forceFill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    public function save()
    {
        if (static::$timestamps) {
            $now = date(static::$dateFormat);

            if (!$this->exists) {
                $this->setAttribute('created_at', $now);
            }

            $this->setAttribute('updated_at', $now);
        }

        if ($this->exists) {
            $result = static::query()
                ->where(static::$primaryKey, $this->getAttribute(static::$primaryKey))
                ->update($this->getDirty());

            $this->wasRecentlyCreated = false;
        } else {
            $id = static::query()->insertGetId($this->attributes);

            if ($id) {
                $this->setAttribute(static::$primaryKey, $id);
                $this->exists = true;
                $this->wasRecentlyCreated = true;
            }
        }

        $this->syncOriginal();

        return true;
    }

    public function update(array $attributes)
    {
        $this->fill($attributes);
        return $this->save();
    }

    public function delete()
    {
        if ($this->exists) {
            $result = static::query()
                ->where(static::$primaryKey, $this->getAttribute(static::$primaryKey))
                ->delete();

            $this->exists = false;
            return $result > 0;
        }

        return false;
    }

    public function fresh(array $with = [])
    {
        if (!$this->exists) {
            return null;
        }

        $query = static::query()->where(static::$primaryKey, $this->getAttribute(static::$primaryKey));

        if (!empty($with)) {
            $query->with($with);
        }

        return $query->first();
    }

    public function refresh()
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = $this->fresh();

        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
            $this->relations = $fresh->relations;
        }

        return $this;
    }

    public function replicate(array $except = [])
    {
        $attributes = array_diff_key($this->attributes, array_flip($except));

        unset($attributes[static::$primaryKey]);

        if (static::$timestamps) {
            unset($attributes['created_at'], $attributes['updated_at']);
        }

        $model = new static;
        $model->setRawAttributes($attributes);

        return $model;
    }

    protected function isFillable($key)
    {
        if (in_array($key, static::$guarded)) {
            return false;
        }

        if (empty(static::$fillable)) {
            return true;
        }

        return in_array($key, static::$fillable);
    }

    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $this->castAttribute($key, $value);
    }

    public function getAttribute($key)
    {
        // Check attributes
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        // Check relations
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        // Check if it's a relation method
        if (method_exists($this, $key)) {
            return $this->getRelationValue($key);
        }

        return null;
    }

    public function getRelationValue($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (!method_exists($this, $key)) {
            return null;
        }

        $relation = $this->$key();

        if (!$relation instanceof Relation) {
            return null;
        }

        $results = $relation->getResults();

        $this->setRelation($key, $results);

        return $results;
    }

    public function setRelation($relation, $value)
    {
        $this->relations[$relation] = $value;
        return $this;
    }

    public function relationLoaded($key)
    {
        return array_key_exists($key, $this->relations);
    }

    public function getRelation($relation)
    {
        return $this->relations[$relation] ?? null;
    }

    public function setRawAttributes(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    public function syncOriginal()
    {
        $this->original = $this->attributes;
        return $this;
    }

    public function isDirty($attributes = null)
    {
        $dirty = $this->getDirty();

        if (is_null($attributes)) {
            return count($dirty) > 0;
        }

        if (!is_array($attributes)) {
            $attributes = func_get_args();
        }

        foreach ($attributes as $attribute) {
            if (array_key_exists($attribute, $dirty)) {
                return true;
            }
        }

        return false;
    }

    public function isClean($attributes = null)
    {
        return !$this->isDirty($attributes);
    }

    public function getDirty()
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    public function getOriginal($key = null, $default = null)
    {
        if ($key) {
            return $this->original[$key] ?? $default;
        }

        return $this->original;
    }

    public function only($keys)
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        return array_intersect_key($this->attributes, array_flip($keys));
    }

    public function wasRecentlyCreated()
    {
        return $this->wasRecentlyCreated;
    }

    protected function castAttribute($key, $value)
    {
        if (!isset(static::$casts[$key])) {
            return $value;
        }

        switch (static::$casts[$key]) {
            case 'int':
            case 'integer':
                return (int)$value;
            case 'float':
            case 'double':
            case 'real':
                return (float)$value;
            case 'string':
                return (string)$value;
            case 'bool':
            case 'boolean':
                return (bool)$value;
            case 'array':
            case 'json':
                return is_string($value) ? json_decode($value, true) : $value;
            case 'object':
                return is_string($value) ? json_decode($value) : $value;
            case 'date':
            case 'datetime':
                return is_string($value) ? new DateTime($value) : $value;
            case 'timestamp':
                return is_numeric($value) ? $value : strtotime($value);
            default:
                return $value;
        }
    }

    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    public function __isset($key)
    {
        return !is_null($this->getAttribute($key));
    }

    public function __unset($key)
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function __call($method, $parameters)
    {
        // Forward calls to query builder
        return $this->newQuery()->$method(...$parameters);
    }

    public static function __callStatic($method, $parameters)
    {
        return (new static)->$method(...$parameters);
    }

    public function toArray()
    {
        $attributes = $this->attributes;

        // Add loaded relations
        foreach ($this->relations as $key => $value) {
            if ($value instanceof Model) {
                $attributes[$key] = $value->toArray();
            } elseif (is_array($value)) {
                $attributes[$key] = array_map(function ($item) {
                    return $item instanceof Model ? $item->toArray() : $item;
                }, $value);
            } else {
                $attributes[$key] = $value;
            }
        }

        // Remove hidden attributes
        foreach (static::$hidden as $hidden) {
            unset($attributes[$hidden]);
        }

        return $attributes;
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public static function getTable()
    {
        if (static::$table) {
            return static::$table;
        }
        $className = (new \ReflectionClass(static::class))->getShortName();
        return strtolower($className) . 's';
    }

    public function getKeyName()
    {
        return static::$primaryKey;
    }

    public function getKey()
    {
        return $this->getAttribute($this->getKeyName());
    }

    public function getModel()
    {
        return $this;
    }

    // ================================
    // Relationship Methods
    // ================================

    public function hasOne($related, $foreignKey = null, $localKey = null)
    {
        $instance = new $related;

        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        return new HasOne($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    public function hasMany($related, $foreignKey = null, $localKey = null)
    {
        $instance = new $related;

        $foreignKey = $foreignKey ?: $this->getForeignKey();
        $localKey = $localKey ?: $this->getKeyName();

        return new HasMany($instance->newQuery(), $this, $foreignKey, $localKey);
    }

    public function belongsTo($related, $foreignKey = null, $ownerKey = null)
    {
        $instance = new $related;

        if (is_null($foreignKey)) {
            $foreignKey = $this->guessBelongsToRelation() . '_id';
        }

        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return new BelongsTo($instance->newQuery(), $this, $foreignKey, $ownerKey);
    }

    public function belongsToMany($related, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null)
    {
        $instance = new $related;

        $table = $table ?: $this->joiningTable($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        $parentKey = $parentKey ?: $this->getKeyName();
        $relatedKey = $relatedKey ?: $instance->getKeyName();

        return new BelongsToMany(
            $instance->newQuery(), $this, $table,
            $foreignPivotKey, $relatedPivotKey,
            $parentKey, $relatedKey
        );
    }

    public function hasManyThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $throughInstance = new $through;
        $relatedInstance = new $related;

        $firstKey = $firstKey ?: $this->getForeignKey();
        $secondKey = $secondKey ?: $throughInstance->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();
        $secondLocalKey = $secondLocalKey ?: $throughInstance->getKeyName();

        return new HasManyThrough(
            $relatedInstance->newQuery(), $this, $throughInstance,
            $firstKey, $secondKey, $localKey, $secondLocalKey
        );
    }

    public function hasOneThrough($related, $through, $firstKey = null, $secondKey = null, $localKey = null, $secondLocalKey = null)
    {
        $throughInstance = new $through;
        $relatedInstance = new $related;

        $firstKey = $firstKey ?: $this->getForeignKey();
        $secondKey = $secondKey ?: $throughInstance->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();
        $secondLocalKey = $secondLocalKey ?: $throughInstance->getKeyName();

        return new HasOneThrough(
            $relatedInstance->newQuery(), $this, $throughInstance,
            $firstKey, $secondKey, $localKey, $secondLocalKey
        );
    }

    public function morphTo($name = null, $type = null, $id = null)
    {
        $name = $name ?: $this->guessBelongsToRelation();

        $type = $type ?: $name . '_type';
        $id = $id ?: $name . '_id';

        $class = $this->getAttribute($type);

        if (!$class) {
            return new MorphTo(
                $this->newQuery(), $this, $id, 'id', $type
            );
        }

        $instance = new $class;

        return new MorphTo(
            $instance->newQuery(), $this, $id, $instance->getKeyName(), $type
        );
    }

    public function morphOne($related, $name, $type = null, $id = null, $localKey = null)
    {
        $instance = new $related;

        $type = $type ?: $name . '_type';
        $id = $id ?: $name . '_id';
        $localKey = $localKey ?: $this->getKeyName();

        return new MorphOne($instance->newQuery(), $this, $type, $id, $localKey);
    }

    public function morphMany($related, $name, $type = null, $id = null, $localKey = null)
    {
        $instance = new $related;

        $type = $type ?: $name . '_type';
        $id = $id ?: $name . '_id';
        $localKey = $localKey ?: $this->getKeyName();

        return new MorphMany($instance->newQuery(), $this, $type, $id, $localKey);
    }

    public function morphToMany($related, $name, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null)
    {
        $instance = new $related;

        $table = $table ?: $name . 'ables';

        $foreignPivotKey = $foreignPivotKey ?: $name . '_id';
        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        $parentKey = $parentKey ?: $this->getKeyName();
        $relatedKey = $relatedKey ?: $instance->getKeyName();

        return new MorphToMany(
            $instance->newQuery(), $this, $table,
            $foreignPivotKey, $relatedPivotKey,
            $parentKey, $relatedKey,
            $name . '_type'
        );
    }

    public function morphedByMany($related, $name, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null)
    {
        $instance = new $related;

        $table = $table ?: $name . 'ables';

        $foreignPivotKey = $foreignPivotKey ?: $instance->getForeignKey();
        $relatedPivotKey = $relatedPivotKey ?: $name . '_id';

        $parentKey = $parentKey ?: $this->getKeyName();
        $relatedKey = $relatedKey ?: $instance->getKeyName();

        return new MorphToMany(
            $instance->newQuery(), $this, $table,
            $foreignPivotKey, $relatedPivotKey,
            $parentKey, $relatedKey,
            $name . '_type'
        );
    }

    protected function getForeignKey()
    {
        return strtolower((new \ReflectionClass($this))->getShortName()) . '_' . $this->getKeyName();
    }

    protected function joiningTable($related)
    {
        $models = [
            strtolower((new \ReflectionClass($this))->getShortName()),
            strtolower((new \ReflectionClass(new $related))->getShortName())
        ];

        sort($models);

        return implode('_', $models);
    }

    protected function guessBelongsToRelation() {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return isset($backtrace[2]['function']) ? $backtrace[2]['function'] : null;
    }
}
// ================================
// Schema Builder
// ================================

class Schema {
    //protected static $pdo;

    protected static function getPdo() {
        return ProQuery::getInstance()->getPdo();
    }
    public static function create($table, callable $callback) {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $sql = $blueprint->toSql();
        self::getPdo()->exec($sql);

        // Create indexes
        foreach ($blueprint->getIndexes() as $index) {
            self::getPdo()->exec($index);
        }

        return true;
    }

    public static function drop($table) {
        $sql = "DROP TABLE IF EXISTS {$table}";
        self::getPdo()->exec($sql);
        return true;
    }

    public static function dropIfExists($table) {
        return self::drop($table);
    }

    public static function rename($from, $to) {
        $sql = "ALTER TABLE {$from} RENAME TO {$to}";
        self::getPdo()->exec($sql);
        return true;
    }

    public static function hasTable($table) {
        $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
        $stmt = self::getPdo()->prepare($sql);
        $stmt->execute([$table]);
        return $stmt->fetch() !== false;
    }

    public static function hasColumn($table, $column) {
        $sql = "PRAGMA table_info({$table})";
        $stmt = self::getPdo()->query($sql);
        $columns = $stmt->fetchAll();

        foreach ($columns as $col) {
            if ($col['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    public static function getColumnType($table, $column) {
        $sql = "PRAGMA table_info({$table})";
        $stmt = self::getPdo()->query($sql);
        $columns = $stmt->fetchAll();

        foreach ($columns as $col) {
            if ($col['name'] === $column) {
                return $col['type'];
            }
        }

        return null;
    }

    public static function getColumns($table) {
        $sql = "PRAGMA table_info({$table})";
        $stmt = self::getPdo()->query($sql);
        return array_column($stmt->fetchAll(), 'name');
    }
}

// ================================
// Blueprint for Schema Building
// ================================

class Blueprint {
    protected $table;
    protected $columns = [];
    protected $indexes = [];
    protected $primaryKey = null;
    protected $foreignKeys = [];

    public function __construct($table) {
        $this->table = $table;
    }

    public function id($name = 'id') {
        $this->primaryKey = $name;
        $this->columns[] = "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
        return $this;
    }

    public function bigIncrements($name) {
        return $this->id($name);
    }

    public function integer($name, $autoIncrement = false, $unsigned = false) {
        $type = 'INTEGER';
        if ($autoIncrement) {
            $type .= ' PRIMARY KEY AUTOINCREMENT';
            $this->primaryKey = $name;
        }
        $this->columns[] = "{$name} {$type}";
        return $this;
    }

    public function bigInteger($name) {
        $this->columns[] = "{$name} BIGINT";
        return $this;
    }

    public function float($name, $total = 8, $places = 2) {
        $this->columns[] = "{$name} REAL";
        return $this;
    }

    public function double($name) {
        $this->columns[] = "{$name} REAL";
        return $this;
    }

    public function decimal($name, $total = 8, $places = 2) {
        $this->columns[] = "{$name} DECIMAL({$total},{$places})";
        return $this;
    }

    public function string($name, $length = 255) {
        $this->columns[] = "{$name} VARCHAR({$length})";
        return $this;
    }

    public function text($name) {
        $this->columns[] = "{$name} TEXT";
        return $this;
    }

    public function mediumText($name) {
        $this->columns[] = "{$name} TEXT";
        return $this;
    }

    public function longText($name) {
        $this->columns[] = "{$name} TEXT";
        return $this;
    }

    public function boolean($name) {
        $this->columns[] = "{$name} BOOLEAN DEFAULT 0";
        return $this;
    }

    public function date($name) {
        $this->columns[] = "{$name} DATE";
        return $this;
    }

    public function dateTime($name) {
        $this->columns[] = "{$name} DATETIME";
        return $this;
    }

    public function time($name) {
        $this->columns[] = "{$name} TIME";
        return $this;
    }

    public function timestamp($name) {
        $this->columns[] = "{$name} TIMESTAMP";
        return $this;
    }

    public function timestamps() {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        return $this;
    }

    public function json($name) {
        $this->columns[] = "{$name} TEXT";
        return $this;
    }

    public function jsonb($name) {
        return $this->json($name);
    }

    public function binary($name) {
        $this->columns[] = "{$name} BLOB";
        return $this;
    }

    public function enum($name, array $values) {
        $values = array_map(function($value) {
            return "'{$value}'";
        }, $values);
        $this->columns[] = "{$name} TEXT CHECK({$name} IN (" . implode(',', $values) . "))";
        return $this;
    }

    public function nullable($column = null) {
        if ($column === null && !empty($this->columns)) {
            $lastIndex = count($this->columns) - 1;
            $this->columns[$lastIndex] = str_replace(
                ['NOT NULL', 'PRIMARY KEY AUTOINCREMENT'],
                ['', 'PRIMARY KEY AUTOINCREMENT'],
                $this->columns[$lastIndex]
            );
        }
        return $this;
    }

    public function default($value) {
        if (!empty($this->columns)) {
            $lastIndex = count($this->columns) - 1;
            if (is_string($value)) {
                $value = "'{$value}'";
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif ($value === null) {
                $value = 'NULL';
            }
            $this->columns[$lastIndex] .= " DEFAULT {$value}";
        }
        return $this;
    }

    public function unique($column = null) {
        if ($column === null && !empty($this->columns)) {
            $lastIndex = count($this->columns) - 1;
            $this->columns[$lastIndex] .= " UNIQUE";
        } else {
            $this->indexes[] = "CREATE UNIQUE INDEX idx_{$this->table}_{$column} ON {$this->table} ({$column})";
        }
        return $this;
    }

    public function index($column) {
        $this->indexes[] = "CREATE INDEX idx_{$this->table}_{$column} ON {$this->table} ({$column})";
        return $this;
    }

    public function foreign($column) {
        return new ForeignKeyDefinition($this, $column);
    }

    public function primary($columns) {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        $this->primaryKey = $columns;
        return $this;
    }

    public function toSql() {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (\n    ";

        // Add NOT NULL to columns by default (except those marked nullable)
        $processedColumns = [];
        foreach ($this->columns as $column) {
            if (!strpos($column, 'PRIMARY KEY') &&
                !strpos($column, 'DEFAULT') &&
                !strpos($column, 'NULL')) {
                $column .= ' NOT NULL';
            }
            $processedColumns[] = $column;
        }

        $sql .= implode(",\n    ", $processedColumns);

        // Add foreign key constraints
        if (!empty($this->foreignKeys)) {
            $sql .= ",\n    " . implode(",\n    ", $this->foreignKeys);
        }

        $sql .= "\n)";

        return $sql;
    }

    public function getIndexes() {
        return $this->indexes;
    }

    public function addForeignKey($definition) {
        $this->foreignKeys[] = $definition;
    }
}

// ================================
// Foreign Key Definition
// ================================

class ForeignKeyDefinition {
    protected $blueprint;
    protected $column;
    protected $references;
    protected $on;
    protected $onDelete = 'RESTRICT';
    protected $onUpdate = 'RESTRICT';

    public function __construct(Blueprint $blueprint, $column) {
        $this->blueprint = $blueprint;
        $this->column = $column;
    }

    public function references($column) {
        $this->references = $column;
        return $this;
    }

    public function on($table) {
        $this->on = $table;
        $this->build();
        return $this->blueprint;
    }

    public function onDelete($action) {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate($action) {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    public function cascadeOnDelete() {
        return $this->onDelete('CASCADE');
    }

    public function cascadeOnUpdate() {
        return $this->onUpdate('CASCADE');
    }

    public function restrictOnDelete() {
        return $this->onDelete('RESTRICT');
    }

    public function restrictOnUpdate() {
        return $this->onUpdate('RESTRICT');
    }

    public function nullOnDelete() {
        return $this->onDelete('SET NULL');
    }

    protected function build() {
        $definition = "FOREIGN KEY ({$this->column}) REFERENCES {$this->on}({$this->references})";
        $definition .= " ON DELETE {$this->onDelete}";
        $definition .= " ON UPDATE {$this->onUpdate}";

        $this->blueprint->addForeignKey($definition);
    }
}

// ================================
// Paginator
// ================================

class Paginator {
    protected $items;
    protected $total;
    protected $perPage;
    protected $currentPage;
    protected $lastPage;

    public function __construct($items, $total, $perPage, $currentPage) {
        $this->items = $items;
        $this->total = $total;
        $this->perPage = $perPage;
        $this->currentPage = $currentPage;
        $this->lastPage = (int) ceil($total / $perPage);
    }

    public function items() {
        return $this->items;
    }

    public function total() {
        return $this->total;
    }

    public function perPage() {
        return $this->perPage;
    }

    public function currentPage() {
        return $this->currentPage;
    }

    public function lastPage() {
        return $this->lastPage;
    }

    public function hasMorePages() {
        return $this->currentPage < $this->lastPage;
    }

    public function hasPages() {
        return $this->lastPage > 1;
    }

    public function onFirstPage() {
        return $this->currentPage === 1;
    }

    public function onLastPage() {
        return $this->currentPage === $this->lastPage;
    }

    public function previousPageUrl() {
        if ($this->currentPage > 1) {
            return '?page=' . ($this->currentPage - 1);
        }
        return null;
    }

    public function nextPageUrl() {
        if ($this->hasMorePages()) {
            return '?page=' . ($this->currentPage + 1);
        }
        return null;
    }

    public function url($page) {
        return '?page=' . $page;
    }

    public function links() {
        $html = '<div class="pagination">';

        // Previous button
        if ($this->onFirstPage()) {
            $html .= '<span class="disabled">Previous</span>';
        } else {
            $html .= '<a href="' . $this->previousPageUrl() . '">Previous</a>';
        }

        // Page numbers
        for ($i = 1; $i <= $this->lastPage; $i++) {
            if ($i == $this->currentPage) {
                $html .= '<span class="current">' . $i . '</span>';
            } else {
                $html .= '<a href="' . $this->url($i) . '">' . $i . '</a>';
            }
        }

        // Next button
        if ($this->onLastPage()) {
            $html .= '<span class="disabled">Next</span>';
        } else {
            $html .= '<a href="' . $this->nextPageUrl() . '">Next</a>';
        }

        $html .= '</div>';

        return $html;
    }

    public function toArray() {
        return [
            'data' => $this->items,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'has_more_pages' => $this->hasMorePages(),
            'previous_page_url' => $this->previousPageUrl(),
            'next_page_url' => $this->nextPageUrl(),
        ];
    }

    public function toJson() {
        return json_encode($this->toArray());
    }
}

// ================================
// Expression Class for Raw SQL
// ================================

class Expression {
    protected $value;

    public function __construct($value) {
        $this->value = $value;
    }

    public function getValue() {
        return $this->value;
    }

    public function __toString() {
        return (string) $this->value;
    }
}

// ================================
// Migration Base Class
// ================================

abstract class Migration {
    abstract public function up();
    abstract public function down();
}

// ================================
// Migration Runner
// ================================

class Migrator {
    protected $pdo;
    protected $migrationsPath;

    public function __construct($migrationsPath = './migrations') {
        $this->pdo = ProQuery::getInstance()->getPdo();
        $this->migrationsPath = $migrationsPath;
        $this->createMigrationsTable();
    }

    protected function createMigrationsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL,
            batch INTEGER NOT NULL
        )";
        $this->pdo->exec($sql);
    }

    public function run() {
        $migrations = $this->getMigrationFiles();
        $ran = $this->getRanMigrations();
        $batch = $this->getNextBatchNumber();

        foreach ($migrations as $migration) {
            if (!in_array($migration, $ran)) {
                $this->runMigration($migration, $batch);
            }
        }
    }

    public function rollback($steps = 1) {
        $migrations = $this->getMigrationsToRollback($steps);

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration);
        }
    }

    public function reset() {
        $migrations = $this->getAllRanMigrations();

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration);
        }
    }

    public function refresh() {
        $this->reset();
        $this->run();
    }

    protected function getMigrationFiles() {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php');
        return array_map(function($file) {
            return pathinfo($file, PATHINFO_FILENAME);
        }, $files);
    }

    protected function getRanMigrations() {
        $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY id");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function getAllRanMigrations() {
        $stmt = $this->pdo->query("SELECT migration FROM migrations ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function getMigrationsToRollback($steps) {
        $sql = "SELECT migration FROM migrations WHERE batch >= (
            SELECT MAX(batch) - ? + 1 FROM migrations
        ) ORDER BY id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$steps]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function getNextBatchNumber() {
        $stmt = $this->pdo->query("SELECT MAX(batch) as max_batch FROM migrations");
        $result = $stmt->fetch();
        return ($result['max_batch'] ?? 0) + 1;
    }

    protected function runMigration($migration, $batch) {
        require_once $this->migrationsPath . '/' . $migration . '.php';

        $className = $this->getMigrationClassName($migration);
        $instance = new $className();

        $instance->up();

        $stmt = $this->pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);

        echo "Migrated: {$migration}\n";
    }

    protected function rollbackMigration($migration) {
        require_once $this->migrationsPath . '/' . $migration . '.php';

        $className = $this->getMigrationClassName($migration);
        $instance = new $className();

        $instance->down();

        $stmt = $this->pdo->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$migration]);

        echo "Rolled back: {$migration}\n";
    }

    protected function getMigrationClassName($migration) {
        $parts = explode('_', $migration);
        $parts = array_slice($parts, 4); // Remove timestamp
        $className = str_replace(' ', '', ucwords(implode(' ', $parts)));
        return $className;
    }
}

// ================================
// Database Seeder Base Class
// ================================

abstract class Seeder {
    abstract public function run();

    public function call($seeder) {
        if (is_string($seeder)) {
            $instance = new $seeder();
        } else {
            $instance = $seeder;
        }

        $instance->run();
    }
}

// ================================
// Helper Functions
// ================================

if (!function_exists('db')) {
    function db() {
        return ProQuery::getInstance();
    }
}

if (!function_exists('table')) {
    function table($table) {
        return QueryBuilder::table($table);
    }
}

if (!function_exists('raw')) {
    function raw($value) {
        return new Expression($value);
    }
}
