<?php

namespace Tusk\Data\Query;

use Tusk\Data\Contract\ConnectionInterface;

class Builder
{
    protected string $table;

    protected array $wheres = [];

    protected array $bindings = [];

    protected array $columns = ['*'];

    protected ?int $limit = null;

    protected ?int $offset = null;

    protected array $orders = [];

    public function __construct(
        protected ConnectionInterface $connection
    ) {}

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function select(array $columns = ['*']): self
    {
        $this->columns = $columns;

        return $this;
    }

    public function where(string $column, string $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'type' => 'Basic',
            'column' => $column,
            'operator' => $operator,
            'boolean' => 'AND',
        ];

        $this->bindings[] = $value;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = compact('column', 'direction');

        return $this;
    }

    public function limit(int $value): self
    {
        $this->limit = $value;

        return $this;
    }

    public function get(): array
    {
        $query = $this->toSql();

        return $this->connection->query($query, $this->bindings);
    }

    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();

        return $results[0] ?? null;
    }

    public function toSql(): string
    {
        if (! isset($this->table)) {
            throw new \RuntimeException('No table set for query.');
        }

        $sql = 'SELECT '.implode(', ', $this->columns).' FROM '.$this->table;

        if (! empty($this->wheres)) {
            $sql .= ' WHERE '.$this->compileWheres();
        }

        if (! empty($this->orders)) {
            $sql .= ' ORDER BY '.$this->compileOrders();
        }

        if (isset($this->limit)) {
            $sql .= ' LIMIT '.$this->limit;
        }

        return $sql;
    }

    protected function compileWheres(): string
    {
        $sql = [];

        foreach ($this->wheres as $where) {
            $sql[] = "{$where['column']} {$where['operator']} ?";
        }

        return implode(' AND ', $sql);
    }

    protected function compileOrders(): string
    {
        $sql = [];

        foreach ($this->orders as $order) {
            $sql[] = "{$order['column']} ".strtoupper($order['direction']);
        }

        return implode(', ', $sql);
    }

    public function insert(array $values): bool
    {
        if (empty($values)) {
            return false;
        }

        $columns = implode(', ', array_keys($values));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        
        $sql = "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})";
        
        return $this->connection->execute($sql, array_values($values));
    }

    public function update(array $values): bool
    {
        if (empty($values)) {
            return false;
        }

        $columns = [];
        $bindings = [];
        
        foreach ($values as $key => $value) {
            $columns[] = "{$key} = ?";
            $bindings[] = $value;
        }
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $columns);
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }
        
        $bindings = array_merge($bindings, $this->bindings);
        
        return $this->connection->execute($sql, $bindings);
    }

    public function delete(): bool
    {
        $sql = "DELETE FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }
        
        return $this->connection->execute($sql, $this->bindings);
    }
}
