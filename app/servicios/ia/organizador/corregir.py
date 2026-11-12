"""Correccion determinista de dictado: ortografia, acentos y puntuacion.

Nunca agrega ni quita palabras, nunca toca cifras, dosis, fechas, correos o
identificadores. Cada cambio queda registrado para que una persona lo audite.
"""

from __future__ import annotations

import re

from .lexico import Lexico, cargar

# Lo primero que coincide se protege: no se corrige nada que contenga cifras.
_PROTEGIDO = re.compile(
    r"https?://\S+"
    r"|[\w.+-]+@[\w-]+\.[\w.]+"
    r"|\b[A-Z]{4}\d{6}[A-Z0-9]{8}\b"
    r"|\b[A-Za-z]*\d+[A-Za-z0-9./:-]*\b"
)
_PALABRA = re.compile(r"[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+")
_ESPACIOS = re.compile(r"[ \t]{2,}")
ANTES_PUNTUACION = re.compile(r"\s+([,;:.!?])")
DESPUES_PUNTUACION = re.compile(r"([,;:])(?=[^\s\d])")
PUNTUACION_REPETIDA = re.compile(r"([,;:.!?])\1+")
MEZCLA_FINAL = re.compile(r"([!?])\.+")
PUNTO_TRAS_COMA = re.compile(r",\s*\.")
# Solo titubeos sin valor lexico. "este", "bueno" o "pues" se conservan porque
# tambien son demostrativos, adjetivos y conjunciones: quitarlos cambiaria el sentido.
_MULETILLA = re.compile(
    r"(?<![\w.])(?:eh+|mm+|ah+|este\s*(?:\.{2,}|…))(?![\w.])",
    re.IGNORECASE,
)


def _normalizar_puntuacion(texto: str) -> tuple[str, list[dict[str, str]]]:
    cambios: list[dict[str, str]] = []
    original = texto

    texto = PUNTUACION_REPETIDA.sub(r"\1", texto)
    texto = MEZCLA_FINAL.sub(r"\1", texto)
    texto = PUNTO_TRAS_COMA.sub(".", texto)
    texto = ANTES_PUNTUACION.sub(r"\1", texto)
    texto = DESPUES_PUNTUACION.sub(r"\1 ", texto)
    texto = _ESPACIOS.sub(" ", texto).strip()
    texto = re.sub(r"\s+([,;:.!?])", r"\1", texto)

    if texto != original:
        cambios.append({"de": original, "a": texto, "motivo": "puntuacion"})
    return texto, cambios


def _capitalizar_oraciones(texto: str) -> str:
    partes = re.split(r"(?<=[.!?])\s+", texto)
    resultado = []
    for parte in partes:
        if not parte:
            continue
        indice = 0
        while indice < len(parte) and not parte[indice].isalpha():
            indice += 1
        if indice < len(parte):
            parte = parte[:indice] + parte[indice].upper() + parte[indice + 1:]
        resultado.append(parte)
    return " ".join(resultado)


def corregir_texto(
    texto: str,
    lexico: Lexico | None = None,
    quitar_muletillas: bool = False,
    separar_pegadas: bool = False,
) -> dict[str, object]:
    if not isinstance(texto, str):
        raise TypeError("El texto a corregir debe ser una cadena.")
    lexico = lexico if lexico is not None else cargar()
    original = texto
    cambios: list[dict[str, str]] = []

    # Antes de normalizar puntuacion: "este..." se vuelve "este." y deja de reconocerse.
    if quitar_muletillas:
        for coincidencia in reversed(list(_MULETILLA.finditer(texto))):
            cambios.append({
                "de": coincidencia.group(0), "a": "", "motivo": "muletilla",
            })
            texto = texto[:coincidencia.start()] + texto[coincidencia.end():]
        texto = _ESPACIOS.sub(" ", texto).strip()

    texto, cambios_puntuacion = _normalizar_puntuacion(texto)
    cambios.extend(cambios_puntuacion)

    protegidos: list[tuple[int, int]] = [
        (coincidencia.start(), coincidencia.end())
        for coincidencia in _PROTEGIDO.finditer(texto)
    ]

    def en_zona_protegida(inicio: int, fin: int) -> bool:
        return any(desde <= inicio and fin <= hasta for desde, hasta in protegidos)

    piezas: list[str] = []
    posicion = 0
    for coincidencia in _PALABRA.finditer(texto):
        inicio, fin = coincidencia.span()
        piezas.append(texto[posicion:inicio])
        palabra = coincidencia.group(0)
        if en_zona_protegida(inicio, fin) or palabra.isupper():
            piezas.append(palabra)
        else:
            baja = palabra.lower()
            propuesta = lexico.corregir_palabra(baja)
            partes = lexico.separar_pegadas(baja) if (
                propuesta is None and separar_pegadas
            ) else None
            if propuesta is not None:
                nueva, motivo = propuesta
                if palabra[0].isupper():
                    nueva = nueva[0].upper() + nueva[1:]
                piezas.append(nueva)
                cambios.append({"de": palabra, "a": nueva, "motivo": motivo})
            elif partes is not None:
                nueva = " ".join(
                    (lexico.corregir_palabra(parte) or (parte, ""))[0] for parte in partes
                )
                if palabra[0].isupper():
                    nueva = nueva[0].upper() + nueva[1:]
                piezas.append(nueva)
                cambios.append({"de": palabra, "a": nueva, "motivo": "palabras_pegadas"})
            else:
                piezas.append(palabra)
        posicion = fin
    piezas.append(texto[posicion:])
    texto = "".join(piezas)

    capitalizado = _capitalizar_oraciones(texto)
    if capitalizado != texto:
        cambios.append({"de": texto, "a": capitalizado, "motivo": "mayusculas"})
        texto = capitalizado

    if texto and texto[-1] not in ".!?":
        texto += "."
        cambios.append({"de": original, "a": texto, "motivo": "punto_final"})

    return {
        "texto": texto,
        "original": original,
        "cambios": cambios,
        "palabras_antes": len(_PALABRA.findall(original)),
        "palabras_despues": len(_PALABRA.findall(texto)),
    }


if __name__ == "__main__":
    import json
    import sys

    muestra = sys.argv[1] if len(sys.argv) > 1 else (
        "me duele la pansa desde ayer..  no tengo diabetis ,tengo hipertencion "
        "y mi correo es prueba@example.invalid CURP SIMU1234567890"
    )
    print(json.dumps(corregir_texto(muestra), ensure_ascii=False, indent=2))