"""Шаг 7: метрики качества извлечения — сравнение значений, precision/recall/F1, проверка человеком, время."""
import pytest

from evaluation.metrics import FieldOutcome, review_stats, score, score_by, time_stats, values_match


def outcome(expected, predicted, needs_review=False, field="f", doc_type="invoice") -> FieldOutcome:
    return FieldOutcome(
        doc_id="d1", doc_type=doc_type, kind="pdf", field=field, expected=expected, predicted=predicted,
        needs_review=needs_review,
    )


@pytest.mark.parametrize(
    ("expected", "predicted", "same"),
    [
        ("ООО \"Ромашка\"", "ооо  «ромашка»", True),
        ("Пётр Иванов", "Петр Иванов", True),
        ("15000.00", "15000.00", True),
        ("15000.00", "1500.00", False),
        ("Иванов И. И.", "Иванов И.И.", True),
    ],
    ids=["case-quotes-spaces", "yo", "same", "different", "initials-spacing"],
)
def test_values_match(expected, predicted, same):
    assert values_match(expected, predicted) is same


def test_outcome_kinds():
    assert outcome("a", "a").outcome == "tp"
    assert outcome("a", None).outcome == "fn"
    assert outcome(None, "a").outcome == "fp"
    assert outcome("a", "b").outcome == "wrong"  # и ложное срабатывание, и пропуск
    assert outcome(None, None).outcome == "tn"


def test_scores_count_wrong_value_as_fp_and_fn():
    result = score([outcome("a", "a"), outcome("a", "a"), outcome("b", "x"), outcome("c", None), outcome(None, "z")])

    assert (result.tp, result.fp, result.fn) == (2, 2, 2)
    assert result.precision == pytest.approx(0.5)
    assert result.recall == pytest.approx(0.5)
    assert result.f1 == pytest.approx(0.5)


def test_scores_without_predictions_are_zero_not_error():
    result = score([outcome("a", None), outcome(None, None)])

    assert (result.precision, result.recall, result.f1) == (0.0, 0.0, 0.0)


def test_score_by_groups():
    groups = score_by(
        [outcome("a", "a", doc_type="invoice"), outcome("a", None, doc_type="act")], lambda o: o.doc_type
    )

    assert groups["invoice"].f1 == 1.0
    assert groups["act"].recall == 0.0


def test_review_stats():
    stats = review_stats(
        [
            outcome("a", "a"),  # верно, не помечено — автоматически
            outcome("a", "a", needs_review=True),  # верно, но помечено — лишняя проверка
            outcome("a", "b", needs_review=True),  # ошибка поймана
            outcome("a", None),  # пропуск не пойман
            outcome(None, None),  # поля нет в документе — не учитывается
        ]
    )

    assert stats.errors == 2 and stats.errors_flagged == 1
    assert stats.error_catch_rate == pytest.approx(0.5)
    assert stats.correct == 2 and stats.correct_flagged == 1
    assert stats.automation_rate == pytest.approx(0.25)  # 1 из 4 полей с эталоном принят без проверки


def test_time_stats():
    stats = time_stats([100, 200, 300, 400, 31_000], limit_ms=30_000)

    assert stats.mean_ms == pytest.approx(6400)
    assert stats.median_ms == 300
    assert stats.max_ms == 31_000
    assert stats.within_limit == pytest.approx(0.8)
