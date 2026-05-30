<?php

namespace Tusk\Data\Model;

use JsonSerializable;
use Tusk\Data\DB;
use Tusk\Data\Model\Relations\BelongsTo;
use Tusk\Data\Model\Relations\HasMany;
use Tusk\Data\Query\Builder;

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
        $instance = new static;

        return DB::table($instance->getTable());
    }

    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(int|string $id): ?static
    {
        $instance = new static;
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

        return strtolower($class).'s';
    }

    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    public function newInstance(array $attributes = []): static
    {
        $model = new static;
        $model->attributes = $attributes;

        return $model;
    }

    protected function hasMany(string $relatedClass, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new HasMany($this, $relatedClass, $foreignKey, $localKey);
    }

    protected function belongsTo(string $relatedClass, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        if (is_null($foreignKey)) {
            $class = basename(str_replace('\\', '/', $relatedClass));
            $foreignKey = strtolower($class).'_id';
        }

        $ownerKey = $ownerKey ?? 'id';

        return new BelongsTo($this, $relatedClass, $foreignKey, $ownerKey);
    }

    protected function getForeignKey(): string
    {
        $class = basename(str_replace('\\', '/', static::class));

        return strtolower($class).'_id';
    }

    public function jsonSerialize(): mixed
    {
        return $this->attributes;
    }
}
