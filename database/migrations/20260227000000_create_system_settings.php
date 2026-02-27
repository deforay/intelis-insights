<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSystemSettings extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('system_settings', [
            'id'          => false,
            'primary_key' => ['key'],
            'engine'      => 'InnoDB',
            'collation'   => 'utf8mb4_unicode_ci',
            'comment'     => 'System-wide key-value configuration store',
        ]);

        $table
            ->addColumn('key', 'string', ['limit' => 100])
            ->addColumn('value', 'json')
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->create();
    }
}
