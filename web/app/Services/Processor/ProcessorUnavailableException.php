<?php

namespace App\Services\Processor;

use RuntimeException;

/** Сервис обработки не ответил или ответил не по формату; текст — для пользователя, без деталей запроса. */
class ProcessorUnavailableException extends RuntimeException {}
