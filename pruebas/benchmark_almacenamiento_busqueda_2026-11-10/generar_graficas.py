#!/usr/bin/env python3
"""Genera gráficas reproducibles desde los CSV de la prueba de almacenamiento."""

from pathlib import Path

import matplotlib

matplotlib.use("Agg")

import matplotlib.pyplot as plt
import pandas as pd


CARPETA = Path(__file__).resolve().parent
SALIDA_DPI = 180
COLOR_AZUL = "#2457A6"
COLOR_VERDE = "#24866A"
COLOR_NARANJA = "#D27624"
COLOR_GRIS = "#596579"

plt.rcParams.update(
    {
        "figure.facecolor": "white",
        "axes.facecolor": "#F7F9FC",
        "axes.edgecolor": "#D8DEE8",
        "axes.labelcolor": "#253247",
        "axes.titleweight": "bold",
        "font.family": "DejaVu Sans",
        "grid.color": "#DCE2EB",
        "grid.linestyle": "--",
        "grid.alpha": 0.8,
        "text.color": "#253247",
        "xtick.color": "#536176",
        "ytick.color": "#536176",
    }
)


def grafica_almacenamiento() -> None:
    datos = pd.read_csv(CARPETA / "almacenamiento_tablas.csv")
    datos["mebibytes"] = datos["bytes"] / (1024**2)
    ahorro = 100 * (1 - datos.loc[1, "bytes"] / datos.loc[0, "bytes"])

    fig, ax = plt.subplots(figsize=(9, 5.2), layout="constrained")
    barras = ax.barh(
        datos["medicion"],
        datos["mebibytes"],
        color=[COLOR_AZUL, COLOR_VERDE],
        height=0.56,
    )
    ax.invert_yaxis()
    ax.set_xlabel("Tamaño físico de archivos .ibd (MiB)")
    ax.set_title("Compresión InnoDB: espacio físico en disco")
    ax.set_xlim(0, datos["mebibytes"].max() * 1.25)
    ax.grid(axis="x")
    ax.set_axisbelow(True)
    for barra, valor in zip(barras, datos["mebibytes"], strict=True):
        ax.text(
            valor + 0.45,
            barra.get_y() + barra.get_height() / 2,
            f"{valor:.2f} MiB",
            va="center",
            fontweight="bold",
        )
    ax.text(
        0.99,
        0.04,
        f"{int(datos.loc[0, 'tablas'])} tablas con datos · reducción {ahorro:.2f} %",
        transform=ax.transAxes,
        ha="right",
        color=COLOR_GRIS,
        fontsize=9,
    )
    fig.savefig(CARPETA / "01_espacio_innodb.png", dpi=SALIDA_DPI)
    plt.close(fig)


def grafica_busqueda() -> None:
    datos = pd.read_csv(CARPETA / "busqueda.csv")
    casos = list(datos["caso"].drop_duplicates())
    fig, ejes = plt.subplots(1, 2, figsize=(13, 6), layout="constrained")

    for ax, caso in zip(ejes, casos, strict=True):
        grupo = datos.loc[datos["caso"] == caso].sort_values("mediana_ms")
        barras = ax.barh(
            grupo["estrategia"],
            grupo["mediana_ms"],
            color=[
                COLOR_VERDE if indice_usado != "no" else COLOR_NARANJA
                for indice_usado in grupo["indice_usado"]
            ],
            height=0.58,
        )
        ax.invert_yaxis()
        ax.set_xscale("log")
        ax.set_xlabel("Latencia (ms, escala logarítmica)")
        ax.set_title(caso)
        ax.grid(axis="x", which="both")
        ax.set_axisbelow(True)
        ax.set_xlim(0.06, max(grupo["p95_ms"]) * 2.5)
        for barra, fila in zip(barras, grupo.itertuples(index=False), strict=True):
            ax.scatter(
                fila.p95_ms,
                barra.get_y() + barra.get_height() / 2,
                marker="|",
                s=180,
                linewidths=2,
                color="#202B3C",
                zorder=3,
            )
            ax.text(
                fila.p95_ms * 1.08,
                barra.get_y() + barra.get_height() / 2,
                f"med {fila.mediana_ms:g} · p95 {fila.p95_ms:g} ms",
                va="center",
                fontsize=8,
            )

    fig.suptitle(
        "Búsquedas equivalentes en 30.000 registros sintéticos\n"
        "Barras: mediana; marca vertical: p95. Verde: índice; naranja: barrido.",
        fontweight="bold",
        fontsize=12,
    )
    fig.savefig(CARPETA / "02_rendimiento_busqueda.png", dpi=SALIDA_DPI)
    plt.close(fig)


def grafica_respaldos() -> None:
    datos = pd.read_csv(CARPETA / "respaldo_compresion.csv")
    datos["nombre"] = datos.apply(
        lambda fila: f"{fila['algoritmo']} nivel {int(fila['nivel'])}", axis=1
    )
    datos = datos.sort_values("bytes")
    colores = [
        COLOR_VERDE
        if fila.algoritmo == "Zstandard" and fila.nivel == 3
        else COLOR_AZUL
        if fila.algoritmo == "zlib" and fila.nivel == 6
        else COLOR_GRIS
        for fila in datos.itertuples(index=False)
    ]

    fig, ax = plt.subplots(figsize=(10, 7), layout="constrained")
    barras = ax.barh(datos["nombre"], datos["bytes"] / 1024, color=colores, height=0.68)
    ax.invert_yaxis()
    ax.set_xlabel("Tamaño del respaldo comprimido (KiB)")
    ax.set_title("Compresión sin pérdida del volcado SQL de la copia")
    ax.set_xlim(0, datos["bytes"].max() / 1024 * 1.22)
    ax.grid(axis="x")
    ax.set_axisbelow(True)
    for barra, fila in zip(barras, datos.itertuples(index=False), strict=True):
        kib = fila.bytes / 1024
        ax.text(
            kib + 12,
            barra.get_y() + barra.get_height() / 2,
            f"{kib:,.0f} KiB · {fila.ahorro_pct:.2f} %",
            va="center",
            fontsize=8,
        )
    ax.text(
        0.99,
        0.015,
        "Verde: Zstandard nivel 3 · azul: zlib nivel 6 (actual)",
        transform=ax.transAxes,
        ha="right",
        color=COLOR_GRIS,
        fontsize=9,
    )
    fig.savefig(CARPETA / "03_compresion_respaldo.png", dpi=SALIDA_DPI)
    plt.close(fig)


if __name__ == "__main__":
    grafica_almacenamiento()
    grafica_busqueda()
    grafica_respaldos()
    print(f"Gráficas guardadas en {CARPETA}")
