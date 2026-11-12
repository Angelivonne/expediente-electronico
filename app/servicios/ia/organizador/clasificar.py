"""Interfaz CLI de inferencia; devuelve sugerencias sin escribir en la base."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

import torch

from .esquema import DOCUMENTOS
from .modelo import VERSION_ESQUEMA, Organizador, codificar, empaquetar_lote

RAIZ_PROYECTO = Path(__file__).resolve().parents[4]
RUTA_MODELO = RAIZ_PROYECTO / "models" / "organizador_clinico" / "organizador_sintetico.pt"


def cargar_modelo(ruta: Path = RUTA_MODELO) -> tuple[Organizador, list[str], list[str]]:
    if not ruta.is_file():
        raise FileNotFoundError(
            f"No existe el modelo {ruta}. Ejecuta primero entrenar.py."
        )
    checkpoint = torch.load(ruta, map_location="cpu", weights_only=True)
    if checkpoint.get("version_esquema") != VERSION_ESQUEMA:
        raise ValueError("El modelo no coincide con la version actual del esquema.")
    tipos = checkpoint["tipos"]
    campos = checkpoint["campos"]
    modelo = Organizador(len(tipos), len(campos))
    modelo.load_state_dict(checkpoint["state_dict"])
    modelo.eval()
    return modelo, tipos, campos


def clasificar(
    texto: str,
    modelo: Organizador,
    tipos: list[str],
    campos: list[str],
    umbral_tipo: float = 0.65,
    umbral_campo: float = 0.65,
    tipo_forzado: str | None = None,
) -> dict[str, object]:
    if not texto.strip():
        raise ValueError("El texto de entrada no puede estar vacio.")
    if not 0.0 <= umbral_tipo <= 1.0 or not 0.0 <= umbral_campo <= 1.0:
        raise ValueError("Los umbrales deben estar entre 0 y 1.")

    if tipo_forzado is not None:
        if tipo_forzado not in DOCUMENTOS:
            raise ValueError(f"Tipo de documento desconocido: {tipo_forzado}")
        tipo = tipo_forzado
        confianza_tipo_valor = None
    else:
        entrada_tipo, offsets_tipo = empaquetar_lote([codificar(texto, "")])
        with torch.inference_mode():
            logits_tipos, _ = modelo(entrada_tipo, offsets_tipo)
            probabilidades_tipos = torch.softmax(logits_tipos[0], dim=0)
        confianza_tipo, indice_tipo = probabilidades_tipos.max(dim=0)
        tipo = tipos[indice_tipo.item()]
        confianza_tipo_valor = confianza_tipo.item()
    resultado: dict[str, object] = {
        "producto": "Asistente clínico local",
        "tipo_documento": (
            tipo if confianza_tipo_valor is None or confianza_tipo_valor >= umbral_tipo else None
        ),
        "confianza_tipo": (
            round(confianza_tipo_valor, 4) if confianza_tipo_valor is not None else None
        ),
        "campos": [],
        "sin_clasificar": [],
        "requiere_revision_profesional": True,
        "modelo_entrenado_solo_con_datos_sinteticos": True,
    }
    if confianza_tipo_valor is not None and confianza_tipo_valor < umbral_tipo:
        resultado["sin_clasificar"] = [texto]
        return resultado

    segmentos = [parte.strip() for parte in re.split(r"(?<=[.!?;])\s+|\n+", texto) if parte.strip()]
    propuestas: list[dict[str, object]] = []
    sin_clasificar: list[str] = []
    permitidos = set(DOCUMENTOS[tipo])
    indices_permitidos = [indice for indice, campo in enumerate(campos) if campo in permitidos]
    for segmento in segmentos:
        if "?" in segmento or "¿" in segmento:
            sin_clasificar.append(segmento)
            continue
        entrada, offsets = empaquetar_lote([codificar(segmento, tipo)])
        with torch.inference_mode():
            _, logits_campos = modelo(entrada, offsets)
        if not indices_permitidos:
            raise ValueError(f"El esquema no contiene campos para el tipo {tipo}.")
        probabilidad_filtrada = torch.softmax(logits_campos[0, indices_permitidos], dim=0)
        confianza, posicion = probabilidad_filtrada.max(dim=0)
        indice_campo = indices_permitidos[posicion.item()]
        if confianza.item() < umbral_campo:
            sin_clasificar.append(segmento)
            continue
        propuestas.append({
            "campo": campos[indice_campo],
            "texto_original": segmento,
            "confianza": round(confianza.item(), 4),
        })
    resultado["campos"] = propuestas
    resultado["sin_clasificar"] = sin_clasificar
    return resultado


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tipo", choices=DOCUMENTOS, help="Fuerza un tipo de formulario.")
    parser.add_argument("--umbral-tipo", type=float, default=0.65)
    parser.add_argument("--umbral-campo", type=float, default=0.65)
    parser.add_argument("texto", help="Texto ficticio para probar, entre comillas.")
    args = parser.parse_args()
    modelo, tipos, campos = cargar_modelo()
    resultado = clasificar(
        args.texto, modelo, tipos, campos,
        args.umbral_tipo, args.umbral_campo, args.tipo,
    )
    print(json.dumps(resultado, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
