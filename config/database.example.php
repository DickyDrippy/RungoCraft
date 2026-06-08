<?php
declare(strict_types=1);

return [
    'db_user' => getenv('ORACLE_DB_USER') ?: 'admin',
    'db_pass' => getenv('ORACLE_DB_PASS') ?: 'CHANGE_ME',
    'db_name' => getenv('ORACLE_DB_NAME') ?: 'your_oracle_service_high',
    'wallet_path' => getenv('TNS_ADMIN') ?: '/opt/oracle/wallet',
];
