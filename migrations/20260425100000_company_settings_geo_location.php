<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CompanySettingsGeoLocation extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('company_settings')) {
            return;
        }

        $table = $this->table('company_settings');

        if (!$table->hasColumn('geo_company_latitude')) {
            $table->addColumn('geo_company_latitude', 'decimal', [
                'precision' => 10,
                'scale' => 7,
                'null' => true,
                'after' => 'geo_requires_acknowledgement',
            ])->update();
        }

        $table = $this->table('company_settings');

        if (!$table->hasColumn('geo_company_longitude')) {
            $table->addColumn('geo_company_longitude', 'decimal', [
                'precision' => 10,
                'scale' => 7,
                'null' => true,
                'after' => 'geo_company_latitude',
            ])->update();
        }

        $table = $this->table('company_settings');

        if (!$table->hasColumn('geo_company_location_label')) {
            $table->addColumn('geo_company_location_label', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'geo_company_longitude',
            ])->update();
        }

        $table = $this->table('company_settings');

        if (!$table->hasColumn('geo_company_geocoded_at')) {
            $table->addColumn('geo_company_geocoded_at', 'datetime', [
                'null' => true,
                'after' => 'geo_company_location_label',
            ])->update();
        }
    }

    public function down(): void
    {
        if (!$this->hasTable('company_settings')) {
            return;
        }

        $table = $this->table('company_settings');

        foreach (['geo_company_geocoded_at', 'geo_company_location_label', 'geo_company_longitude', 'geo_company_latitude'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->update();
                $table = $this->table('company_settings');
            }
        }
    }
}
