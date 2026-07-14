<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TerminalTransportDiagnostics extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('terminals')) {
            return;
        }

        $table = $this->table('terminals');
        $columns = [
            'last_firmware_version' => ['string', ['limit' => 120, 'null' => true]],
            'last_transport' => ['string', ['limit' => 16, 'null' => true]],
            'last_tls_state' => ['string', ['limit' => 40, 'null' => true]],
            'active_trust_bundle_version' => ['integer', ['signed' => false, 'null' => true]],
            'last_trust_checked_at' => ['datetime', ['null' => true]],
            'trust_warning_state' => ['string', ['limit' => 40, 'null' => true]],
            'offline_queue_depth' => ['integer', ['signed' => false, 'null' => true]],
            'last_https_success_at' => ['datetime', ['null' => true]],
            'last_recovery_status' => ['string', ['limit' => 80, 'null' => true]],
        ];

        foreach ($columns as $name => [$type, $options]) {
            if (!$table->hasColumn($name)) {
                $table->addColumn($name, $type, $options);
            }
        }

        $table->update();
    }

    public function down(): void
    {
        if (!$this->hasTable('terminals')) {
            return;
        }

        $table = $this->table('terminals');
        foreach (['last_firmware_version', 'last_transport', 'last_tls_state', 'active_trust_bundle_version', 'last_trust_checked_at', 'trust_warning_state', 'offline_queue_depth', 'last_https_success_at', 'last_recovery_status'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }
}
