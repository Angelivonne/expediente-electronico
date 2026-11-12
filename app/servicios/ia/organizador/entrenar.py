"""Entrena y evalua el piloto usando unicamente textos sinteticos locales."""

from __future__ import annotations

import argparse
import json
import random
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import torch
from torch import nn

from .esquema import DOCUMENTOS
from .modelo import (
    DIMENSION_EMBEDDING,
    DIMENSION_CARACTERISTICAS,
    VERSION_ESQUEMA,
    Organizador,
    codificar,
    empaquetar_lote,
)
from .sinteticos import generar_ejemplos, generar_preguntas_sin_respuesta

RAIZ_PROYECTO = Path(__file__).resolve().parents[4]
DIRECTORIO_DATOS = RAIZ_PROYECTO / "privado" / "cache" / "organizador_clinico"
DIRECTORIO_MODELOS = RAIZ_PROYECTO / "models" / "organizador_clinico"


def _partir_por_etiqueta(
    ejemplos: list[dict[str, str]],
    etiqueta: str,
    semilla: int,
) -> dict[str, list[dict[str, str]]]:
    rng = random.Random(semilla)
    grupos: dict[str, list[dict[str, str]]] = defaultdict(list)
    for ejemplo in ejemplos:
        grupos[ejemplo[etiqueta]].append(ejemplo)
    resultado = {"entrenamiento": [], "validacion": [], "prueba": []}
    for grupo in grupos.values():
        rng.shuffle(grupo)
        cantidad = len(grupo)
        corte_entrenamiento = max(1, int(cantidad * 0.70))
        corte_validacion = max(corte_entrenamiento + 1, int(cantidad * 0.85))
        if corte_validacion >= cantidad:
            corte_validacion = cantidad - 1
        if corte_validacion <= corte_entrenamiento:
            raise ValueError(f"No hay suficientes ejemplos para la etiqueta {etiqueta}.")
        resultado["entrenamiento"].extend(grupo[:corte_entrenamiento])
        resultado["validacion"].extend(grupo[corte_entrenamiento:corte_validacion])
        resultado["prueba"].extend(grupo[corte_validacion:])
    for conjunto in resultado.values():
        rng.shuffle(conjunto)
    return resultado


def _crear_documentos(
    ejemplos: list[dict[str, str]],
    semilla: int,
    dialogos_largos_por_tipo: int = 0,
) -> list[dict[str, str]]:
    rng = random.Random(semilla)
    por_tipo: dict[str, list[dict[str, str]]] = defaultdict(list)
    for ejemplo in ejemplos:
        por_tipo[ejemplo["tipo"]].append(ejemplo)

    documentos: list[dict[str, str]] = []
    for tipo, grupo in por_tipo.items():
        rng.shuffle(grupo)
        for inicio in range(0, len(grupo) - 5, 6):
            fragmentos = grupo[inicio:inicio + 6]
            documentos.append({
                "tipo": tipo,
                "texto": " ".join(fragmento["texto"] for fragmento in fragmentos),
                "sintetico": "faker-es_MX",
            })
        if tipo == "registro_paciente" and dialogos_largos_por_tipo:
            por_campo: dict[str, list[str]] = defaultdict(list)
            for ejemplo in grupo:
                por_campo[ejemplo["campo"]].append(ejemplo["texto"])
            for _ in range(dialogos_largos_por_tipo):
                fragmentos = [
                    rng.choice(por_campo[campo])
                    for campo in DOCUMENTOS[tipo]
                    if por_campo.get(campo)
                ]
                documentos.append({
                    "tipo": tipo,
                    "texto": " ".join(fragmentos),
                    "sintetico": "dialogo-largo-faker-es_MX",
                })
    return documentos


def _preparar_muestras(
    documentos: list[dict[str, str]],
    ejemplos: list[dict[str, str]],
    tipos: list[str],
    campos: list[str],
) -> list[dict[str, object]]:
    id_tipo = {etiqueta: indice for indice, etiqueta in enumerate(tipos)}
    id_campo = {etiqueta: indice for indice, etiqueta in enumerate(campos)}
    muestras: list[dict[str, object]] = []
    for documento in documentos:
        muestras.append({
            "texto": documento["texto"], "tipo": "",
            "tipo_id": id_tipo[documento["tipo"]], "campo_id": -100,
        })
    for ejemplo in ejemplos:
        muestras.append({
            "texto": ejemplo["texto"], "tipo": ejemplo["tipo"],
            "tipo_id": -100, "campo_id": id_campo[ejemplo["campo"]],
        })
    return muestras


def _lote(muestras: list[dict[str, object]]) -> tuple[torch.Tensor, ...]:
    caracteristicas = [
        codificar(str(muestra["texto"]), str(muestra["tipo"]))
        for muestra in muestras
    ]
    entrada, offsets = empaquetar_lote(caracteristicas)
    etiqueta_tipos = torch.tensor([int(muestra["tipo_id"]) for muestra in muestras])
    etiqueta_campos = torch.tensor([int(muestra["campo_id"]) for muestra in muestras])
    return entrada, offsets, etiqueta_tipos, etiqueta_campos


def _evaluar(
    modelo: Organizador,
    muestras: list[dict[str, object]],
    tipos: list[str],
    campos: list[str],
    dispositivo: torch.device,
) -> dict[str, object]:
    modelo.eval()
    correctos = {"tipo": 0, "campo": 0}
    correctos_aceptados = {"tipo": 0, "campo": 0}
    aceptados = {"tipo": 0, "campo": 0}
    totales = {"tipo": 0, "campo": 0}
    matriz_campos = [[0 for _ in campos] for _ in campos]
    with torch.inference_mode():
        for inicio in range(0, len(muestras), 128):
            lote = muestras[inicio:inicio + 128]
            entrada, offsets, _, _ = _lote(lote)
            logits_tipos, logits_campos = modelo(
                entrada.to(dispositivo), offsets.to(dispositivo),
            )
            probabilidades_tipos = torch.softmax(logits_tipos, dim=1).cpu()
            confianza_tipos, pred_tipos = probabilidades_tipos.max(dim=1)
            logits_campos = logits_campos.cpu()
            for indice, muestra in enumerate(lote):
                real_tipo = int(muestra["tipo_id"])
                real_campo = int(muestra["campo_id"])
                if real_tipo >= 0:
                    totales["tipo"] += 1
                    prediccion_tipo = pred_tipos[indice].item()
                    acierto_tipo = prediccion_tipo == real_tipo
                    correctos["tipo"] += int(acierto_tipo)
                    if confianza_tipos[indice].item() >= 0.65:
                        aceptados["tipo"] += 1
                        correctos_aceptados["tipo"] += int(acierto_tipo)
                if real_campo >= 0:
                    totales["campo"] += 1
                    permitidos = [
                        campo_indice for campo_indice, campo in enumerate(campos)
                        if campo in DOCUMENTOS[str(muestra["tipo"])]
                    ]
                    logits_filtrados = logits_campos[indice, permitidos]
                    confianza_campo = torch.softmax(logits_filtrados, dim=0).max().item()
                    prediccion = permitidos[logits_filtrados.argmax().item()]
                    correctos["campo"] += int(prediccion == real_campo)
                    matriz_campos[real_campo][prediccion] += 1
                    if confianza_campo >= 0.65:
                        aceptados["campo"] += 1
                        correctos_aceptados["campo"] += int(prediccion == real_campo)

    f1_por_campo: dict[str, float] = {}
    for indice, campo in enumerate(campos):
        verdaderos = matriz_campos[indice][indice]
        falsos_positivos = sum(fila[indice] for fila in matriz_campos) - verdaderos
        falsos_negativos = sum(matriz_campos[indice]) - verdaderos
        denominador = 2 * verdaderos + falsos_positivos + falsos_negativos
        if denominador:
            f1_por_campo[campo] = round(2 * verdaderos / denominador, 4)
    return {
        "exactitud_tipo": round(correctos["tipo"] / max(1, totales["tipo"]), 4),
        "exactitud_campo": round(correctos["campo"] / max(1, totales["campo"]), 4),
        "cobertura_tipo_umbral_065": round(
            aceptados["tipo"] / max(1, totales["tipo"]), 4,
        ),
        "exactitud_tipo_aceptados_umbral_065": round(
            correctos_aceptados["tipo"] / max(1, aceptados["tipo"]), 4,
        ),
        "cobertura_campo_umbral_065": round(
            aceptados["campo"] / max(1, totales["campo"]), 4,
        ),
        "exactitud_campo_aceptados_umbral_065": round(
            correctos_aceptados["campo"] / max(1, aceptados["campo"]), 4,
        ),
        "f1_macro_campos_presentes": round(
            sum(f1_por_campo.values()) / max(1, len(f1_por_campo)), 4,
        ),
        "f1_por_campo": f1_por_campo,
        "muestras_tipo": totales["tipo"],
        "muestras_campo": totales["campo"],
    }


def entrenar(por_documento: int, epocas: int, semilla: int) -> tuple[Path, Path, dict[str, object]]:
    if por_documento < 60:
        raise ValueError("Usa al menos 60 ejemplos por tipo para mantener particiones de prueba.")
    if epocas < 1:
        raise ValueError("El numero de epocas debe ser positivo.")
    random.seed(semilla)
    torch.manual_seed(semilla)
    torch.set_num_threads(min(4, torch.get_num_threads()))

    ejemplos = generar_ejemplos(por_documento, semilla)
    preguntas = generar_preguntas_sin_respuesta(semilla)
    documentos = _crear_documentos(ejemplos, semilla + 1, max(1, por_documento // 2))
    particion_documentos = _partir_por_etiqueta(documentos, "tipo", semilla + 2)
    particion_campos = _partir_por_etiqueta(ejemplos, "campo", semilla + 3)
    particiones = {
        nombre: _preparar_muestras(
            particion_documentos[nombre],
            particion_campos[nombre],
            list(DOCUMENTOS),
            sorted({campo for campos_tipo in DOCUMENTOS.values() for campo in campos_tipo}),
        )
        for nombre in ("entrenamiento", "validacion", "prueba")
    }
    tipos = list(DOCUMENTOS)
    campos = sorted({campo for campos_tipo in DOCUMENTOS.values() for campo in campos_tipo})
    dispositivo = torch.device("cpu")
    modelo = Organizador(len(tipos), len(campos)).to(dispositivo)
    optimizador = torch.optim.AdamW(modelo.parameters(), lr=0.003, weight_decay=0.01)
    perdida = nn.CrossEntropyLoss(ignore_index=-100)
    rng = random.Random(semilla + 4)

    for epoca in range(epocas):
        modelo.train()
        muestra_entrenamiento = particiones["entrenamiento"][:]
        rng.shuffle(muestra_entrenamiento)
        perdidas = []
        for inicio in range(0, len(muestra_entrenamiento), 64):
            lote = muestra_entrenamiento[inicio:inicio + 64]
            entrada, offsets, etiqueta_tipos, etiqueta_campos = _lote(lote)
            logits_tipos, logits_campos = modelo(entrada, offsets)
            perdida_total = perdida(logits_tipos, etiqueta_tipos) + perdida(
                logits_campos, etiqueta_campos,
            )
            optimizador.zero_grad(set_to_none=True)
            perdida_total.backward()
            optimizador.step()
            perdidas.append(perdida_total.item())
        print(
            f"Epoca {epoca + 1}/{epocas}: "
            f"perdida={sum(perdidas) / max(1, len(perdidas)):.4f}"
        )
        print(
            "  validacion=" + json.dumps(
                _evaluar(modelo, particiones["validacion"], tipos, campos, dispositivo),
                ensure_ascii=False,
                sort_keys=True,
            )
        )

    metricas = _evaluar(modelo, particiones["prueba"], tipos, campos, dispositivo)
    DIRECTORIO_DATOS.mkdir(parents=True, exist_ok=True)
    DIRECTORIO_MODELOS.mkdir(parents=True, exist_ok=True)
    ruta_dataset = DIRECTORIO_DATOS / f"ejemplos_sinteticos_{semilla}.jsonl"
    ruta_checkpoint = DIRECTORIO_MODELOS / "organizador_sintetico.pt"
    ruta_metricas = DIRECTORIO_MODELOS / "metricas_sinteticas.json"
    with ruta_dataset.open("w", encoding="utf-8", newline="\n") as archivo:
        for ejemplo in ejemplos:
            archivo.write(json.dumps(
                {"tipo_registro": "campo", **ejemplo}, ensure_ascii=False,
            ) + "\n")
    with ruta_dataset.open("a", encoding="utf-8", newline="\n") as archivo:
        for documento in documentos:
            archivo.write(json.dumps(
                {"tipo_registro": "documento", **documento}, ensure_ascii=False,
            ) + "\n")
        for pregunta in preguntas:
            archivo.write(json.dumps(pregunta, ensure_ascii=False) + "\n")
    torch.save({
        "version_esquema": VERSION_ESQUEMA,
        "tipos": tipos,
        "campos": campos,
        "dimension_caracteristicas": DIMENSION_CARACTERISTICAS,
        "dimension_embedding": DIMENSION_EMBEDDING,
        "state_dict": modelo.state_dict(),
    }, ruta_checkpoint)
    reporte = {
        "nombre": "Asistente clínico local",
        "fecha_utc": datetime.now(timezone.utc).isoformat(),
        "semilla": semilla,
        "ejemplos_campo_sinteticos": len(ejemplos),
        "documentos_sinteticos": len(documentos),
        "dialogos_largos_sinteticos": sum(
            documento["sintetico"] == "dialogo-largo-faker-es_MX"
            for documento in documentos
        ),
        "preguntas_sinteticas_no_clasificables": len(preguntas),
        "particion": {"entrenamiento": 70, "validacion": 15, "prueba": 15},
        "dispositivo": "cpu",
        "metricas_prueba_sintetica": metricas,
        "advertencia": (
            "Metricas de datos sinteticos: no demuestran desempeno clinico. "
            "Las propuestas requieren revision profesional y no se guardan automaticamente."
        ),
    }
    ruta_metricas.write_text(
        json.dumps(reporte, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    return ruta_checkpoint, ruta_dataset, reporte


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ejemplos-por-tipo", type=int, default=300)
    parser.add_argument("--epocas", type=int, default=8)
    parser.add_argument("--semilla", type=int, default=202603)
    args = parser.parse_args()
    checkpoint, dataset, reporte = entrenar(
        args.ejemplos_por_tipo, args.epocas, args.semilla,
    )
    print(f"Modelo: {checkpoint}")
    print(f"Datos: {dataset}")
    print(json.dumps(reporte, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
