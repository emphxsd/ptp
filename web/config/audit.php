<?php

return [
    // журнал действий хранит IP-адреса (персональные данные): записи старше срока удаляются (model:prune)
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),
];
