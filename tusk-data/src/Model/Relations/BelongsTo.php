<?php

namespace Tusk\Data\Model\Relations;

use Tusk\Data\Model\Model;

class BelongsTo
{
    public function __construct(
        protected Model $child,
        protected string $relatedClass,
        protected string $foreignKey,
        protected string $ownerKey = 'id'
    ) {}

    public function getResults(): ?Model
    {
        $related = new $this->relatedClass;
        $foreignValue = $this->child->{$this->foreignKey};

        if (is_null($foreignValue)) {
            return null;
        }

        // Query the related table where its owner key equals the child's foreign key value
        $data = $related::query()
            ->where($this->ownerKey, '=', $foreignValue)
            ->first();

        if ($data) {
            return $related->newInstance($data); // Note: Assuming newInstance is public or accessible
        }

        return null;
    }
}
