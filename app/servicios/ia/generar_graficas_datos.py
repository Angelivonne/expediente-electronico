"""
Generador de Gráficas Estadísticas Formales con Matplotlib para Expediente FEMECI
Estilo estricto: Letra negra, tipografía Arial, escala de grises / monocromático, 300 DPI.
"""
import os
import sys
import json
import matplotlib
matplotlib.use('Agg')
import matplotlib.pyplot as plt
import numpy as np

# Configuración formal de Matplotlib
plt.rcParams['font.family'] = 'sans-serif'
plt.rcParams['font.sans-serif'] = ['Arial', 'Helvetica', 'DejaVu Sans']
plt.rcParams['font.size'] = 11
plt.rcParams['text.color'] = '#000000'
plt.rcParams['axes.labelcolor'] = '#000000'
plt.rcParams['xtick.color'] = '#000000'
plt.rcParams['ytick.color'] = '#000000'
plt.rcParams['axes.edgecolor'] = '#000000'
plt.rcParams['axes.linewidth'] = 1.0
plt.rcParams['figure.dpi'] = 300

OUTPUT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', 'assets', 'img', 'reportes'))
os.makedirs(OUTPUT_DIR, exist_ok=True)

def generar_grafica_top_morbilidad(datos, ruta_salida):
    """
    Gráfica 1: Top 10 Patologías más Frecuentes (Casos Únicos)
    Barras horizontales, ordenadas, tonos de gris con etiquetas de conteo y porcentaje.
    """
    nombres = [d['nombre'][:38] + ('...' if len(d['nombre']) > 38 else '') for d in reversed(datos)]
    casos = [d['casos'] for d in reversed(datos)]
    porcentajes = [d['porcentaje'] for d in reversed(datos)]

    fig, ax = plt.subplots(figsize=(8.5, 4.8), dpi=300)
    fig.patch.set_facecolor('#ffffff')
    ax.set_facecolor('#ffffff')

    y_pos = np.arange(len(nombres))
    
    # Colores en escala de grises con borde negro
    barras = ax.barh(y_pos, casos, height=0.65, color='#444444', edgecolor='#000000', linewidth=1.0)
    
    # Alternar sombreado para máxima claridad
    for i, bar in enumerate(barras):
        if i % 2 == 0:
            bar.set_color('#222222')
            bar.set_edgecolor('#000000')
        else:
            bar.set_color('#555555')
            bar.set_edgecolor('#000000')

    ax.set_yticks(y_pos)
    ax.set_yticklabels(nombres, fontsize=9.5, fontweight='normal', color='#000000')
    ax.set_xlabel('Número de Casos Únicos (Pacientes sin duplicar)', fontsize=10, fontweight='bold', labelpad=8)
    ax.set_title('FIGURA 1. Diez Principales Causas de Morbilidad en el Consultorio', fontsize=11, fontweight='bold', pad=14, loc='center')

    # Grid vertical sutil
    ax.xaxis.grid(True, linestyle='--', alpha=0.5, color='#888888', linewidth=0.7)
    ax.set_axisbelow(True)

    # Añadir valores y porcentajes sobre las barras
    max_val = max(casos) if casos else 1
    ax.set_xlim(0, max_val * 1.25)
    for i, (v, p) in enumerate(zip(casos, porcentajes)):
        ax.text(v + (max_val * 0.02), i, f'{v} ({p:.1f}%)', va='center', ha='left', fontsize=9, fontweight='bold', color='#000000')

    # Espacio y formato
    plt.tight_layout()
    plt.savefig(ruta_salida, dpi=300, facecolor='#ffffff', edgecolor='none')
    plt.close(fig)
    print(f"Generada: {ruta_salida}")

def generar_grafica_piramide_edad(datos, ruta_salida):
    """
    Gráfica 2: Distribución por Grupos de Edad y Sexo (Femenino vs Masculino)
    """
    grupos = [d['grupo'] for d in datos]
    femenino = [d['femenino'] for d in datos]
    masculino = [d['masculino'] for d in datos]

    fig, ax = plt.subplots(figsize=(8.5, 4.2), dpi=300)
    fig.patch.set_facecolor('#ffffff')
    ax.set_facecolor('#ffffff')

    x = np.arange(len(grupos))
    ancho = 0.35

    rects1 = ax.bar(x - ancho/2, femenino, ancho, label='Femenino', color='#333333', edgecolor='#000000', linewidth=1.0, hatch='//')
    rects2 = ax.bar(x + ancho/2, masculino, ancho, label='Masculino', color='#777777', edgecolor='#000000', linewidth=1.0)

    ax.set_xlabel('Grupos de Edad', fontsize=10, fontweight='bold', labelpad=8)
    ax.set_ylabel('Casos Únicos de Pacientes', fontsize=10, fontweight='bold', labelpad=8)
    ax.set_title('FIGURA 2. Distribución de Pacientes Atendidos por Rango Etario y Sexo', fontsize=11, fontweight='bold', pad=14, loc='center')
    ax.set_xticks(x)
    ax.set_xticklabels(grupos, fontsize=9.5, color='#000000')
    ax.legend(frameon=True, edgecolor='#000000', facecolor='#ffffff', fontsize=9.5)

    ax.yaxis.grid(True, linestyle='--', alpha=0.5, color='#888888', linewidth=0.7)
    ax.set_axisbelow(True)

    max_val = max(max(femenino, default=1), max(masculino, default=1))
    ax.set_ylim(0, max_val * 1.25)

    for rect in rects1:
        h = rect.get_height()
        if h > 0:
            ax.annotate(f'{h}', xy=(rect.get_x() + rect.get_width() / 2, h), xytext=(0, 3),
                        textcoords="offset points", ha='center', va='bottom', fontsize=9, fontweight='bold')

    for rect in rects2:
        h = rect.get_height()
        if h > 0:
            ax.annotate(f'{h}', xy=(rect.get_x() + rect.get_width() / 2, h), xytext=(0, 3),
                        textcoords="offset points", ha='center', va='bottom', fontsize=9, fontweight='bold')

    plt.tight_layout()
    plt.savefig(ruta_salida, dpi=300, facecolor='#ffffff', edgecolor='none')
    plt.close(fig)
    print(f"Generada: {ruta_salida}")

def generar_grafica_categorias(datos, ruta_salida):
    """
    Gráfica 3: Distribución por Categorías Patológicas (Sistemas afectados)
    """
    categorias = [d['categoria'] for d in datos]
    casos = [d['casos'] for d in datos]
    porcentajes = [d['porcentaje'] for d in datos]

    fig, ax = plt.subplots(figsize=(8.5, 4.0), dpi=300)
    fig.patch.set_facecolor('#ffffff')
    ax.set_facecolor('#ffffff')

    x = np.arange(len(categorias))
    barras = ax.bar(x, casos, width=0.55, color='#444444', edgecolor='#000000', linewidth=1.0)
    
    # Texturas y tonos alternados
    patrones = ['', '//', '\\\\', 'xx', '..', '++']
    tonos = ['#1a1a1a', '#3a3a3a', '#5a5a5a', '#7a7a7a', '#9a9a9a', '#b0b0b0']
    for i, bar in enumerate(barras):
        bar.set_facecolor(tonos[i % len(tonos)])
        bar.set_hatch(patrones[i % len(patrones)])
        bar.set_edgecolor('#000000')

    ax.set_xlabel('Categoría Nosológica / Sistema Orgánico', fontsize=10, fontweight='bold', labelpad=8)
    ax.set_ylabel('Pacientes Únicos', fontsize=10, fontweight='bold', labelpad=8)
    ax.set_title('FIGURA 3. Carga de Morbilidad por Sistema Nosológico', fontsize=11, fontweight='bold', pad=14, loc='center')
    ax.set_xticks(x)
    ax.set_xticklabels(categorias, fontsize=9, rotation=15, ha='right', color='#000000')

    ax.yaxis.grid(True, linestyle='--', alpha=0.5, color='#888888', linewidth=0.7)
    ax.set_axisbelow(True)

    max_val = max(casos) if casos else 1
    ax.set_ylim(0, max_val * 1.25)

    for i, (v, p) in enumerate(zip(casos, porcentajes)):
        ax.text(i, v + (max_val * 0.02), f'{v}\n({p:.1f}%)', ha='center', va='bottom', fontsize=8.5, fontweight='bold', color='#000000')

    plt.tight_layout()
    plt.savefig(ruta_salida, dpi=300, facecolor='#ffffff', edgecolor='none')
    plt.close(fig)
    print(f"Generada: {ruta_salida}")

def main():
    if len(sys.argv) > 1 and os.path.exists(sys.argv[1]):
        with open(sys.argv[1], 'r', encoding='utf-8') as f:
            data = json.load(f)
    else:
        # Datos de prueba para verificación inmediata
        data = {
            'top_morbilidad': [
                {'nombre': 'Hipertensión esencial (primaria) [I10]', 'casos': 25, 'porcentaje': 25.0},
                {'nombre': 'Diabetes mellitus tipo 2 [E11.9]', 'casos': 18, 'porcentaje': 18.0},
                {'nombre': 'Amigdalitis aguda bacteriana [J03.9]', 'casos': 14, 'porcentaje': 14.0},
                {'nombre': 'Lumbago no especificado [M54.5]', 'casos': 11, 'porcentaje': 11.0},
                {'nombre': 'Gastroenteritis infecciosa aguda [A09]', 'casos': 9, 'porcentaje': 9.0},
                {'nombre': 'Gastritis no especificada [K29.7]', 'casos': 8, 'porcentaje': 8.0},
                {'nombre': 'Infección de vías urinarias [N39.0]', 'casos': 6, 'porcentaje': 6.0},
                {'nombre': 'Rinitis alérgica estacional [J30.2]', 'casos': 4, 'porcentaje': 4.0},
                {'nombre': 'Cefalea debida a tensión [G44.2]', 'casos': 3, 'porcentaje': 3.0},
                {'nombre': 'Examen médico general [Z00.0]', 'casos': 2, 'porcentaje': 2.0}
            ],
            'piramide_edad': [
                {'grupo': '< 15 años', 'femenino': 4, 'masculino': 5},
                {'grupo': '15 - 24 años', 'femenino': 8, 'masculino': 7},
                {'grupo': '25 - 44 años', 'femenino': 16, 'masculino': 14},
                {'grupo': '45 - 64 años', 'femenino': 15, 'masculino': 16},
                {'grupo': '65+ años', 'femenino': 7, 'masculino': 8}
            ],
            'categorias': [
                {'categoria': 'Cardiovascular', 'casos': 25, 'porcentaje': 25.0},
                {'categoria': 'Endocrino / Metabólico', 'casos': 18, 'porcentaje': 18.0},
                {'categoria': 'Respiratorio', 'casos': 18, 'porcentaje': 18.0},
                {'categoria': 'Músculo-esquelético', 'casos': 11, 'porcentaje': 11.0},
                {'categoria': 'Digestivo', 'casos': 17, 'porcentaje': 17.0},
                {'categoria': 'Genitourinario', 'casos': 6, 'porcentaje': 6.0},
                {'categoria': 'Preventivo / General', 'casos': 5, 'porcentaje': 5.0}
            ]
        }

    r1 = os.path.join(OUTPUT_DIR, 'morbilidad_top10.png')
    r2 = os.path.join(OUTPUT_DIR, 'piramide_etaria.png')
    r3 = os.path.join(OUTPUT_DIR, 'categorias_nosologicas.png')

    generar_grafica_top_morbilidad(data['top_morbilidad'], r1)
    generar_grafica_piramide_edad(data['piramide_edad'], r2)
    generar_grafica_categorias(data['categorias'], r3)
    print("Todas las gráficas de Matplotlib fueron generadas con éxito en alta resolución (300 DPI, escala de grises, Arial 12pt).")

if __name__ == '__main__':
    main()
