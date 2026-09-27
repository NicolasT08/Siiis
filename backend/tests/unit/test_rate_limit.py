from app.rate_limit import RateLimiter
from tests.support import FixedClock


def test_permite_hasta_el_maximo_y_luego_bloquea() -> None:
    limiter = RateLimiter(FixedClock())

    for _ in range(3):
        assert limiter.hit("login", "1.2.3.4", 3, 60) == 0
    assert limiter.hit("login", "1.2.3.4", 3, 60) == 60


def test_separa_por_accion_y_por_clave() -> None:
    limiter = RateLimiter(FixedClock())
    limiter.hit("login", "1.2.3.4", 1, 60)

    assert limiter.hit("login", "1.2.3.4", 1, 60) > 0
    assert limiter.hit("login", "5.6.7.8", 1, 60) == 0
    assert limiter.hit("forgot", "1.2.3.4", 1, 60) == 0


def test_reinicia_al_vencer_la_ventana() -> None:
    clock = FixedClock()
    limiter = RateLimiter(clock)
    limiter.hit("login", "ip", 1, 60)

    clock.advance(30)
    assert limiter.hit("login", "ip", 1, 60) == 30

    clock.advance(30)
    assert limiter.hit("login", "ip", 1, 60) == 0


def test_los_intentos_bloqueados_no_alargan_la_ventana() -> None:
    clock = FixedClock()
    limiter = RateLimiter(clock)
    limiter.hit("login", "ip", 1, 60)

    for _ in range(5):
        clock.advance(10)
        limiter.hit("login", "ip", 1, 60)

    clock.advance(10)
    assert limiter.hit("login", "ip", 1, 60) == 0


def test_no_guarda_la_ip_en_claro() -> None:
    limiter = RateLimiter(FixedClock())
    limiter.hit("login", "198.51.100.7", 5, 60)

    assert "198.51.100.7" not in repr(limiter._contadores)
