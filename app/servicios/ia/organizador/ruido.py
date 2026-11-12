"""Ruido realista de dictado y ASR para entrenar y evaluar con texto sucio.

Las sustituciones consonanticas salen del mismo modelo fonetico que usa el
corrector (lexico.clave_fonetica), asi que el ruido imita errores que el
sistema de verdad puede encontrar, no basura aleatoria.
"""

from __future__ import annotations

import random
import re

from .lexico import ERRORES_CONOCIDOS, sin_acentos


def _construir_homofonos() -> dict[str, tuple[str, ...]]:
    """Invierte el mapa del corrector: el ruido produce justo los errores que el sabe arreglar."""
    por_limpia: dict[str, list[str]] = {}
    for sucia, limpia in ERRORES_CONOCIDOS.items():
        if sucia != limpia:
            por_limpia.setdefault(limpia, []).append(sucia)
    return {limpia: tuple(variantes) for limpia, variantes in por_limpia.items()}


HOMOFONOS = _construir_homofonos()

MULETILLAS = ("este", "eh", "o sea", "pues", "este...", "mmm", "bueno", "digamos")

_CONFUSIONES = (
    ("z", "s"), ("v", "b"), ("ll", "y"), ("qu", "c"),
    ("ce", "se"), ("ci", "si"), ("ge", "je"), ("gi", "ji"),
)
_PALABRA = re.compile(r"[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+")
# Las negaciones son el dato clinico que no se puede perder por ruido.
_CUES_NEGACION = frozenset({
    "no", "nunca", "niega", "niego", "tampoco", "sin", "ningun", "ninguna",
    "descarta", "negativo", "jamas",
})


def _confusion_fonetica(palabra: str, rng: random.Random) -> str:
    for origen, destino in _CONFUSIONES:
        if origen in palabra and rng.random() < 0.5:
            return palabra.replace(origen, destino, 1)
    if palabra.startswith("h") and len(palabra) > 4 and rng.random() < 0.4:
        return palabra[1:]
    return palabra


def _error_tipografico(palabra: str, rng: random.Random) -> str:
    if len(palabra) < 5:
        return palabra
    modo = rng.random()
    indice = rng.randrange(1, len(palabra) - 1)
    if modo < 0.34:
        return palabra[:indice] + palabra[indice] + palabra[indice:]
    if modo < 0.67:
        return palabra[:indice] + palabra[indice + 1:]
    return palabra[:indice] + palabra[indice + 1] + palabra[indice] + palabra[indice + 2:]


def aplicar_ruido(
    texto: str,
    rng: random.Random,
    intensidad: float = 0.5,
) -> tuple[str, list[str]]:
    """Devuelve (texto_sucio, transformaciones_aplicadas). El texto limpio es la referencia."""
    if not 0.0 <= intensidad <= 1.0:
        raise ValueError("La intensidad debe estar entre 0 y 1.")
    aplicadas: list[str] = []
    sucio = texto

    if rng.random() < intensidad:
        plano = sin_acentos(sucio)
        if plano != sucio.lower():
            aplicadas.append("sin_acentos")
        sucio = plano

    if rng.random() < intensidad * 0.8:
        aplicadas.append("minusculas")
        sucio = sucio.lower()

    def sustituir(coincidencia: re.Match[str]) -> str:
        palabra = coincidencia.group(0)
        baja = palabra.lower()
        if baja in _CUES_NEGACION:
            return palabra
        if baja in HOMOFONOS and rng.random() < intensidad:
            aplicadas.append("homofono")
            return rng.choice(HOMOFONOS[baja])
        if rng.random() < intensidad * 0.45:
            nueva = _confusion_fonetica(baja, rng)
            if nueva != baja:
                aplicadas.append("confusion_fonetica")
                return nueva
        if rng.random() < intensidad * 0.25:
            nueva = _error_tipografico(baja, rng)
            if nueva != baja:
                aplicadas.append("tipografico")
                return nueva
        return palabra

    sucio = _PALABRA.sub(sustituir, sucio)

    if rng.random() < intensidad * 0.5:
        palabras = sucio.split()
        if len(palabras) > 3:
            indice = rng.randrange(len(palabras) - 1)
            if palabras[indice].isalpha() and palabras[indice + 1].isalpha():
                palabras[indice] += palabras[indice + 1]
                del palabras[indice + 1]
                sucio = " ".join(palabras)
                aplicadas.append("palabras_pegadas")

    if rng.random() < intensidad * 0.6:
        posicion = rng.randrange(len(sucio.split()) + 1)
        palabras = sucio.split()
        palabras.insert(posicion, rng.choice(MULETILLAS))
        sucio = " ".join(palabras)
        aplicadas.append("muletilla")

    if rng.random() < intensidad * 0.7:
        modo = rng.random()
        if modo < 0.4:
            sucio = re.sub(r"[.,;]", "", sucio)
            aplicadas.append("sin_puntuacion")
        elif modo < 0.7:
            sucio = re.sub(r"\.", "..", sucio)
            aplicadas.append("puntuacion_duplicada")
        else:
            sucio = re.sub(r"\s+([,.;])", r" \1", sucio)
            sucio = re.sub(r",(?=\S)", ", ", sucio)
            aplicadas.append("puntuacion_descolocada")

    sucio = re.sub(r"[ \t]{2,}", " ", sucio).strip()
    return sucio, sorted(set(aplicadas))


def perfil_asr(texto: str, rng: random.Random) -> tuple[str, list[str]]:
    """Como sale de un transcriptor: minusculas, sin acentos, sin puntuacion, con muletillas."""
    sucio, aplicadas = aplicar_ruido(texto, rng, intensidad=0.9)
    sucio = re.sub(r"[^\w\s]", "", sin_acentos(sucio)).lower()
    sucio = re.sub(r"\s{2,}", " ", sucio).strip()
    return sucio, sorted(set(aplicadas) | {"perfil_asr"})


if __name__ == "__main__":
    rng = random.Random(7)
    limpio = "Me duele la panza desde ayer. No tengo diabetes, pero sí hipertensión."
    for intensidad in (0.3, 0.6, 0.9):
        sucio, marcas = aplicar_ruido(limpio, rng, intensidad)
        print(f"[{intensidad}] {sucio}\n    {marcas}")
    print("[asr]", perfil_asr(limpio, rng)[0])