<?php

namespace Tusk\Data\Migration;

interface MigrationInterface
{
    public function up(): void;

    public function down(): void;
}
