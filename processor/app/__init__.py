"""Сервис обработки документов: извлечение текста, правила, NER, LLM."""

__version__ = "0.1.0"

import os as _os

# NER (numpy) на мелких матрицах в 10–100 раз медленнее с многопоточным BLAS:
# задаём один поток до первого импорта numpy; внешняя настройка окружения имеет приоритет
for _var in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS"):
    _os.environ.setdefault(_var, "1")
