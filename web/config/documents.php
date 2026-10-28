<?php

return [
    // как PROCESSOR_MAX_FILE_MB в сервисе обработки; PHP (upload_max_filesize, post_max_size) должен пропускать столько же
    'max_file_mb' => (int) env('DOCUMENTS_MAX_FILE_MB', 20),

    'max_files_per_upload' => 10,

    'uploads_per_minute' => (int) env('DOCUMENTS_UPLOADS_PER_MINUTE', 30),

    // квоты пользователя: число документов и объём файлов на диске
    'max_documents_per_user' => (int) env('DOCUMENTS_MAX_PER_USER', 1000),
    'max_storage_mb_per_user' => (int) env('DOCUMENTS_MAX_STORAGE_MB_PER_USER', 2048),

    // документы с персональными данными не хранятся бессрочно (152-ФЗ): старше срока удаляются вместе с файлами
    'retention_days' => (int) env('DOCUMENTS_RETENTION_DAYS', 365),

    // выгрузка выборки за один раз (CSV/JSON собирается в памяти запроса)
    'max_export_documents' => (int) env('DOCUMENTS_MAX_EXPORT', 1000),

    // false — флажок LLM скрыт, документы обрабатываются только правилами и NER
    'llm_enabled' => (bool) env('DOCUMENTS_LLM_ENABLED', true),

    // где работает LLM: false — локальная модель (Ollama), документ не покидает сервер;
    // true — облачная (GigaChat), фрагменты уходят во внешний сервис. Должно совпадать с PROCESSOR_LLM_PROVIDER
    'llm_external' => (bool) env('DOCUMENTS_LLM_EXTERNAL', false),

    // расширения, которые принимает форма; формат всё равно определяется по содержимому файла
    'extensions' => ['pdf', 'docx', 'png', 'jpg', 'jpeg'],
];
