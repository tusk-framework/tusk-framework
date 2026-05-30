<?php

namespace Tusk\Data\Model\Relations;

use Tusk\Data\Model\Model;

class HasMany
{
    public function __construct(
        protected Model $parent,
        protected string $relatedClass,
        protected string $foreignKey,
        protected string $localKey = 'id'
    ) {}

    public function getResults(): array
    {
        $related = new $this->relatedClass;
        $foreignValue = $this->parent->{$this->localKey};

        // Query the related table where the foreign key equals our local key value
        return $related::query()
            ->where($this->foreignKey, '=', $foreignValue)
            ->get();
    }
}
