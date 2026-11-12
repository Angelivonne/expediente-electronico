"""Modelo compacto para propuestas locales de clasificacion de dictados."""

from __future__ import annotations

import hashlib
import re
import unicodedata
from collections.abc import Sequence

import torch
from torch import nn

DIMENSION_CARACTERISTICAS = 1 << 16
DIMENSION_EMBEDDING = 64
VERSION_ESQUEMA = 2


def normalizar(texto: str) -> str:
    sin_acentos = unicodedata.normalize("NFKD", texto.casefold())
    return "".join(caracter for caracter in sin_acentos if not unicodedata.combining(caracter))


def _id_caracteristica(caracteristica: str) -> int:
    resumen = hashlib.blake2s(caracteristica.encode("utf-8"), digest_size=8).digest()
    return int.from_bytes(resumen, "little") % DIMENSION_CARACTERISTICAS


def codificar(texto: str, tipo: str) -> list[int]:
    normalizado = normalizar(texto)
    palabras = re.findall(r"[a-z0-9]+", normalizado)
    caracteristicas = [f"tipo={normalizar(tipo)}", *(f"w={palabra}" for palabra in palabras)]
    compacto = f" {normalizado} "
    caracteristicas.extend(
        f"c={compacto[inicio:inicio + longitud]}"
        for longitud in range(2, 5)
        for inicio in range(max(0, len(compacto) - longitud + 1))
    )
    return [_id_caracteristica(caracteristica) for caracteristica in caracteristicas]


class Organizador(nn.Module):
    def __init__(self, cantidad_tipos: int, cantidad_campos: int) -> None:
        super().__init__()
        self.bolsa = nn.EmbeddingBag(
            DIMENSION_CARACTERISTICAS,
            DIMENSION_EMBEDDING,
            mode="mean",
        )
        self.proyeccion = nn.Sequential(
            nn.Linear(DIMENSION_EMBEDDING, DIMENSION_EMBEDDING),
            nn.ReLU(),
            nn.Dropout(0.2),
        )
        self.salida_tipos = nn.Linear(DIMENSION_EMBEDDING, cantidad_tipos)
        self.salida_campos = nn.Linear(DIMENSION_EMBEDDING, cantidad_campos)

    def forward(
        self,
        caracteristicas: torch.Tensor,
        offsets: torch.Tensor,
    ) -> tuple[torch.Tensor, torch.Tensor]:
        representacion = self.proyeccion(self.bolsa(caracteristicas, offsets))
        return self.salida_tipos(representacion), self.salida_campos(representacion)


def empaquetar_lote(secuencias: Sequence[Sequence[int]]) -> tuple[torch.Tensor, torch.Tensor]:
    valores: list[int] = []
    offsets: list[int] = []
    for secuencia in secuencias:
        offsets.append(len(valores))
        valores.extend(secuencia or [0])
    return torch.tensor(valores, dtype=torch.long), torch.tensor(offsets, dtype=torch.long)
