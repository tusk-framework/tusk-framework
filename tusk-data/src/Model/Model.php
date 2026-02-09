<?php

namespace Tusk\Data\Model;

use Tusk\Data\DB;
use Tusk\Data\Query\Builder;
use JsonSerializable;

abstract class Model implements JsonSerializable
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $attributes = [];
    protected array $fillable = [];

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            if (in_array($key, $this->fillable)) {
                $this->attributes[$key] = $value;
            }
        }
        return $this;
    }

    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function __set(string $key, mixed $value)
    {
        $this->attributes[$key] = $value;
    }

    public static function query(): Builder
    {
        $instance = new static();
        return DB::table($instance->getTable());
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(int|string $id): ?static
    {
        $instance = new static();
        $data = static::query()->where($instance->getKeyName(), '=', $id)->first();

        if ($data) {
            return $instance->newInstance($data);
        }

        return null;
    }

    public static function create(array $attributes): static
    {
        $instance = new static($attributes);
        $instance->save();
        return $instance;
    }

    public function save(): bool
    {
        // For POC: simple insert/update logic
        $query = DB::table($this->getTable());

        if (isset($this->attributes[$this->primaryKey])) {
            // Update
            return (bool) $query->where($this->primaryKey, '=', $this->attributes[$this->primaryKey])
                ->update($this->attributes);

        } else {
            // Insert
            $result = $query->insert($this->attributes);
            if ($result) {
                $this->attributes[$this->primaryKey] = DB::connection()->lastInsertId();
            }
            return $result;
        }
    }

    public function getTable(): string
    {
        if (isset($this->table)) {
            return $this->table;
        }

        // Simple pluralizer: User -> users
        $class = basename(str_replace('\\', '/', static::class));
        return strtolower($class) . 's';
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    protected function newInstance(array $attributes = []): static
    {
        $model = new static();
        $model->attributes = $attributes;
        return $model;
    }

    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }
}
