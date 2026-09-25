<?php

return [

    'bash' => env('MVS_BACKUP_BASH', 'bash'),

    'export_script' => base_path('scripts/production/export-company.sh'),

    'validate_script' => base_path('scripts/production/validate-company-export.sh'),

    'restore_script' => base_path('scripts/production/restore-company.sh'),

    'storage_dir' => 'company-backups',

    'restore_db_template' => 'mvs_company_%d_company_restore_test',

];
