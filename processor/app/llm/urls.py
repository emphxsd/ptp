"""Проверка адреса локальной LLM. Без зависимостей: используется и настройками, и клиентом."""
import ipaddress
from urllib.parse import urlsplit


def require_local_or_https(url: str) -> str:
    """http — только к этому компьютеру и только по IP-адресу (имя «localhost» зависит от файла hosts);
    к другому хосту — https, иначе документ уйдёт по сети открытым текстом. Без user:password@ в адресе."""
    parts = urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise ValueError("адрес Ollama должен иметь вид http://127.0.0.1:11434")
    if parts.username is not None or parts.password is not None:
        raise ValueError("адрес Ollama не должен содержать логин и пароль")
    try:
        parts.port  # noqa: B018 — urlsplit проверяет порт только при обращении
    except ValueError:
        raise ValueError("в адресе Ollama неверный порт") from None
    if parts.scheme == "http" and not _is_loopback_ip(parts.hostname):
        raise ValueError("по http Ollama доступна только на этом компьютере (127.0.0.1); для другого хоста — https")
    return url.rstrip("/")


def is_loopback_url(url: str) -> bool:
    """Документ не покидает компьютер: адрес указывает на этот же компьютер."""
    hostname = urlsplit(url).hostname
    return hostname is not None and _is_loopback_ip(hostname)


def _is_loopback_ip(host: str) -> bool:
    try:
        return ipaddress.ip_address(host).is_loopback
    except ValueError:
        return False
