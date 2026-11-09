<?php

declare(strict_types=1);

return [
    'client_id'           => env('GOOGLE_DRIVE_CLIENT_ID', ''),
    'client_secret'       => env('GOOGLE_DRIVE_CLIENT_SECRET', ''),
    'redirect_uri'        => env('GOOGLE_DRIVE_REDIRECT_URI', ''),
    'backup_folder'       => env('GOOGLE_DRIVE_BACKUP_FOLDER', 'FireflyIII/Backups'),
    'budget_plans_folder' => env('GOOGLE_DRIVE_BUDGET_PLANS_FOLDER', 'FireflyIII/BudgetPlans'),
];
