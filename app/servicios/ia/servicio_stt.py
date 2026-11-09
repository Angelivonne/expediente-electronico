#!/usr/bin/env python3
"""
Servicio local de transcripción de voz (Whisper large-v3, faster-whisper sobre CUDA).
Mantiene el modelo cargado en memoria para respuestas rápidas.
Escucha en 127.0.0.1:8766. Solo accesible desde el equipo local.

Vocabulario médico en español mexicano incluido como prompt inicial.
Versión expandida: conversación natural doctor-paciente con detección
de secciones del expediente e identificación de hablante.
"""
import json, os, sys, subprocess, tempfile, re, time, io, wave, threading
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

# Con pythonw.exe (arranque automático sin ventana) sys.stdout es None y print() falla.
# En ese caso se escribe todo a un log en la carpeta temporal.
if sys.stdout is None or sys.stderr is None:
    _log = open(os.path.join(tempfile.gettempdir(), 'expediente_stt.log'), 'a', encoding='utf-8', buffering=1)
    if sys.stdout is None:
        sys.stdout = _log
    if sys.stderr is None:
        sys.stderr = _log

MODELO = "large-v3"          # Systran/faster-whisper-large-v3 (caché de HuggingFace)
DISPOSITIVO = "cuda"         # GPU NVIDIA; si CUDA no está disponible, cae a CPU
TIPO_COMPUTO = "float16"     # float16 en GPU (int8 al caer a CPU)
FFMPEG = r"C:\ffmpeg\ffmpeg-9.0.2-essentials_build\bin\ffmpeg.exe"
PUERTO = 8766
VOCAB_PATH = r"C:\xampp\htdocs\ExpedienteFEMECI\app\servicios\ia\vocabulario_medico_es.txt"

PROMPT_MEDICO = (
    "Expediente clínico mexicano. Conversación entre médico y paciente. "
    "El médico pregunta y el paciente responde. Vocabulario clínico en español mexicano. "
    "Paciente con dolor de cabeza, fiebre, tos, náusea, vómito, diarrea, mareo, fatiga, "
    "debilidad, dolor abdominal, dolor torácico, disnea, hipertensión, diabetes, obesidad, "
    "asma, EPOC, gastritis, colitis, paracetamol, ibuprofeno, amoxicilina, omeprazol, "
    "metformina, losartán, exploración física, diagnóstico, pronóstico, plan de tratamiento, "
    "nota de evolución, signos vitales, tensión arterial, frecuencia cardíaca, "
    "frecuencia respiratoria, temperatura, saturación de oxígeno, peso, talla, "
    "motivo de consulta, padecimiento actual, CIE-10, receta médica, "
    "interconsulta, referencia, urgencias, hospitalización, egreso, incapacidad. "
    "Jerga mexicana: chorrillo, pipí, popo, panza, pata, soponcio, patatús, calentura, "
    "catarro, gripe, estómago suelto, gases, acidez, ardor, comezón, ronchas, sarpullido, "
    "desmayo, ataque, escalofríos, presión alta, presión baja, azúcar alta, azúcar baja, "
    "anemia, infección, congestión, mocos, tos con flema, tos seca, mareo, vértigo, "
    "fatiga, cansancio, debilidad, falta de aire, ardor al orinar, orina con sangre, "
    "orina frecuente, embarazo, menstruación, regla, dolor menstrual. "
    "Conversación natural: me duele, tengo, soy, mi nombre es, tengo años, "
    "no tengo, nunca he tenido, me siento, se me subió, se me bajó, "
    "me dio, me dio chorrillo, me dio la vuelta, me dio un patatús, "
    "me dio un soponcio, me dio un ataque, me dio un infarto, me dio un derrame. "
    "Frases del médico: ¿qué te duele?, ¿cuántos años tienes?, ¿eres alérgico?, "
    "¿tomas medicamentos?, ¿tienes antecedentes?, ¿qué te trae?, ¿desde cuándo?, "
    "¿ha tenido fiebre?, ¿ha vomitado?, ¿ha tenido diarrea?, ¿ha tenido mareo?, "
    "a la exploración, se observa, se palpa, se ausculta, se percute, "
    "impresión diagnóstica, plan de manejo, se indica, se prescribe, se ordena, "
    "pronóstico favorable, pronóstico reservado, pronóstico grave. "
    "Medicamentos: paracetamol, acetaminofén, ibuprofeno, naproxeno, diclofenaco, "
    "ketorolaco, tramadol, codeína, morfina, fentanyl, amoxicilina, ampicilina, "
    "penicilina, cefalexina, ceftriaxona, azitromicina, claritromicina, eritromicina, "
    "ciprofloxacino, levofloxacino, metronidazol, trimetoprim-sulfametoxazol, "
    "omeprazol, pantoprazol, ranitidina, metoclopramida, metformina, glibenclamida, "
    "insulina, sitagliptina, losartán, enalapril, amlodipino, hidroclorotiazida, "
    "furosemida, atenolol, metoprolol, carvedilol, bisoprolol, atorvastatina, "
    "simvastatina, rosuvastatina, salbutamol, budesonida, fluticasona, montelukast, "
    "prednisona, dexametasona, hidrocortisona, clonazepam, alprazolam, diazepam, "
    "lorazepam, sertralina, fluoxetina, paroxetina, citalopram, escitalopram, "
    "amitriptilina, imipramina, venlafaxina, duloxetina, haloperidol, risperidona, "
    "olanzapina, quetiapina, carbamazepina, fenitoína, ácido valproico, lamotrigina, "
    "levetiracetam, gabapentina, pregabalina, topiramato, levotiroxina, metimazol, "
    "propiltiouracilo, ácido fólico, hierro, vitamina B12, vitamina D, calcio, "
    "warfarina, heparina, enoxaparina, rivaroxabán, apixabán, aspirina, clopidogrel, "
    "ticagrelor, ondansetrón, metoclopramida, domperidona, loperamida, racecadotrilo, "
    "loratadina, cetirizina, clorfeniramina, difenhidramina, pseudoefedrina, "
    "fenilefrina, nafazolina, oximetazolina, clotrimazol, ketoconazol, fluconazol, "
    "itraconazol, aciclovir, valaciclovir, oseltamivir, ivermectina, albendazol, "
    "mebendazol, metronidazol, tinidazol, doxiciclina, tetraciclina, gentamicina, "
    "amikacina, vancomicina, clindamicina, linezolid, rifampicina, isoniazida, "
    "pirazinamida, etambutol, benzodiazepinas, opioides, antiinflamatorios no "
    "esteroideos, AINEs, antibióticos, antivirales, antifúngicos, antiparasitarios, "
    "antihipertensivos, antidiabéticos, anticoagulantes, antidepresivos, "
    "antipsicóticos, anticonvulsivantes, broncodilatadores, corticosteroides, "
    "antihistamínicos, analgésicos, antipiréticos, antieméticos, antidiarreicos, "
    "laxantes, diuréticos, vitaminas, suplementos. "
    "Enfermedades: diabetes mellitus, hipertensión arterial, obesidad, dislipidemia, "
    "síndrome metabólico, hipotiroidismo, hipertiroidismo, anemia ferropénica, "
    "anemia megaloblástica, leucemia, linfoma, trombocitopenia, hemofilia, "
    "enfermedad de von Willebrand, asma bronquial, EPOC, neumonía, tuberculosis, "
    "bronquitis, sinusitis, rinitis alérgica, faringitis, laringitis, otitis media, "
    "conjuntivitis, blefaritis, queratitis, glaucoma, cataratas, gastritis, "
    "úlcera péptica, enfermedad por reflujo gastroesofágico, síndrome de intestino "
    "irritable, colitis ulcerosa, enfermedad de Crohn, hepatitis A, hepatitis B, "
    "hepatitis C, cirrosis hepática, colelitiasis, colecistitis, pancreatitis, "
    "apendicitis, infección de vías urinarias, cistitis, pielonefritis, nefrolitiasis, "
    "hiperplasia prostática benigna, prostatitis, epididimitis, vaginitis, cervicitis, "
    "enfermedad pélvica inflamatoria, endometriosis, miomatosis uterina, "
    "síndrome de ovario poliquístico, embarazo, preeclampsia, eclampsia, "
    "diabetes gestacional, artritis reumatoide, osteoartritis, osteoporosis, "
    "fibromialgia, lupus eritematoso sistémico, esclerosis múltiple, migraña, "
    "cefalea tensional, epilepsia, enfermedad de Parkinson, enfermedad de Alzheimer, "
    "depresión, ansiedad, trastorno bipolar, esquizofrenia, trastorno de déficit de "
    "atención, autismo, dermatitis atópica, psoriasis, acné, herpes zóster, "
    "herpes simple, varicela, sarampión, rubéola, parotiditis, escarlatina, "
    "dengue, zika, chikungunya, COVID-19, influenza, VIH/SIDA, sífilis, gonorrea, "
    "clamidia, herpes genital, cáncer de mama, cáncer de próstata, cáncer de colon, "
    "cáncer de pulmón, cáncer de estómago, cáncer de hígado, cáncer de páncreas, "
    "cáncer de ovario, cáncer de útero, cáncer de testículo, cáncer de piel, "
    "melanoma, cáncer de tiroides, cáncer de riñón, cáncer de vejiga, cáncer de "
    "cerebro, cáncer de hueso, fractura, luxación, esguince, contusión, herida, "
    "quemadura, traumatismo craneoencefálico, traumatismo torácico, traumatismo abdominal. "
    "Entidades: Aguascalientes, Baja California, Baja California Sur, Campeche, "
    "Chiapas, Chihuahua, Coahuila, Colima, Ciudad de México, Durango, Guanajuato, "
    "Guerrero, Hidalgo, Jalisco, Michoacán, Morelos, Nayarit, Nuevo León, Oaxaca, "
    "Puebla, Querétaro, Quintana Roo, San Luis Potosí, Sinaloa, Sonora, Tabasco, "
    "Tamaulipas, Tlaxcala, Veracruz, Yucatán, Zacatecas. "
    "Nacionalidades: mexicana, estadounidense, española, guatemalteca, hondureña, "
    "salvadoreña, colombiana, venezolana, argentina, brasileña, chilena, peruana, "
    "ecuatoriana. "
    "Religiones: católica, protestante, evangélica, testigo de Jehová, mormón, "
    "judía, musulmana, budista, atea, agnóstica, sin religión. "
    "Lenguas indígenas: náhuatl, maya, mixteco, zapoteco, tzeltal, tzotzil, "
    "otomí, totonaco, mazateco, chinanteco, mixe, triqi, purépecha, tarahumara, "
    "yaqui, seri, kumiai, cucapá. "
    "Especialidades: medicina general, medicina interna, pediatría, ginecología, "
    "obstetricia, cirugía, ortopedia, traumatología, cardiología, neurología, "
    "psiquiatría, dermatología, oftalmología, otorrinolaringología, urología, "
    "nefrología, gastroenterología, endocrinología, neumología, reumatología, "
    "oncología, hematología, infectología, geriatría, medicina familiar, "
    "medicina del trabajo, medicina deportiva, medicina legal, patología, "
    "radiología, anestesiología, medicina crítica, terapia intensiva, urgencias. "
    "Tipos de personal: médico, enfermera, pasante, internista, residente, "
    "especialista, subespecialista, técnico, auxiliar, recepcionista, "
    "administrador, director. "
    "Formación académica: médico cirujano, licenciatura, especialidad, "
    "subespecialidad, maestría, doctorado, diplomado, curso, certificación. "
    "Material de curación: gasa, venda, esparadrapo, algodón, alcohol, yodo, "
    "agua oxigenada, suero fisiológico, solución salina, guantes, jeringa, "
    "aguja, catéter, sonda, tubo, bolsa, curita, apósito, venda elástica, "
    "venda de yeso, yeso. "
    "Instrumental: estetoscopio, termómetro, esfigmomanómetro, oxímetro, "
    "glucómetro, nebulizador, aspirador, ambú, laringoscopio, otoscopio, "
    "oftalmoscopio, reflejómetro, tijeras, pinzas, bisturí. "
    "Tipos de consulta: primera vez, subsecuente, control, seguimiento, "
    "urgencia, emergencia. "
    "Tipos de atención: consulta, procedimiento, cirugía, hospitalización, "
    "observación, urgencia. "
    "Tipos de establecimiento: consultorio, clínica, hospital, centro de salud, "
    "unidad médica, sanatorio, laboratorio, farmacia, consultorio dental. "
    "Formas farmacéuticas: tabletas, cápsulas, grageas, solución, suspensión, "
    "inyección, crema, pomada, gel, ungüento, gotas, spray, inhalador, "
    "supositorio, óvulo, parche, jarabe, elíxir, tintura. "
    "Vías de administración: oral, intravenosa, intramuscular, subcutánea, "
    "tópica, rectal, vaginal, ocular, ótica, nasal, inhalada, sublingual. "
    "Estados de tratamiento: en curso, finalizado, suspendido, completado, "
    "cancelado, pendiente. "
    "Entidades: Aguascalientes, Baja California, Baja California Sur, Campeche, "
    "Chiapas, Chihuahua, Coahuila, Colima, Ciudad de México, Durango, "
    "Guanajuato, Guerrero, Hidalgo, Jalisco, Michoacán, Morelos, Nayarit, "
    "Nuevo León, Oaxaca, Puebla, Querétaro, Quintana Roo, San Luis Potosí, "
    "Sinaloa, Sonora, Tabasco, Tamaulipas, Tlaxcala, Veracruz, Yucatán, Zacatecas. "
    "Nacionalidades: mexicana, estadounidense, española, guatemalteca, "
    "hondureña, salvadoreña, colombiana, venezolana, argentina, brasileña, "
    "chilena, peruana, ecuatoriana. "
    "Religiones: católica, protestante, evangélica, testigo de Jehová, mormón, "
    "judía, musulmana, budista, atea, agnóstica, sin religión, otra. "
    "Lenguas indígenas: náhuatl, maya, mixteco, zapoteco, tzeltal, tzotzil, "
    "otomí, totonaco, mazateco, chinanteco, mixe, triqi, purépecha, "
    "tarahumara, yaqui, seri, kumiai, cucapá."
)

PROMPT_CORTO = (
    "Expediente clínico mexicano. Conversación médico-paciente. "
    "Motivo de consulta, padecimiento actual, exploración física, diagnóstico, "
    "plan de tratamiento, nota de evolución, pronóstico. "
    "Medicamentos: paracetamol, ibuprofeno, amoxicilina, omeprazol, metformina, "
    "losartán, salbutamol, prednisona. Dosis: miligramos, cada ocho horas, vía oral."
)

# Términos que el modelo confunde seguido. Se pasan como "hotwords": sesgan la
# transcripción sin alargar el prompt inicial (un prompt enorme vuelve lento a Whisper).
HOTWORDS_CLINICOS = (
    "motivo de consulta, padecimiento actual, exploración física, diagnóstico, "
    "plan de tratamiento, nota de evolución, pronóstico, signos vitales, "
    "paracetamol, ibuprofeno, metamizol, ketorolaco, diclofenaco, amoxicilina, "
    "azitromicina, cefalexina, omeprazol, metformina, losartán, enalapril, "
    "amlodipino, atorvastatina, furosemida, levotiroxina, salbutamol, prednisona, "
    "sertralina, clonazepam, loratadina, ondansetrón"
)

import numpy as np
print("Cargando modelo Whisper large-v3 (faster-whisper, CUDA)...", flush=True)

MESES = {
    'enero': 1, 'febrero': 2, 'marzo': 3, 'abril': 4, 'mayo': 5, 'junio': 6,
    'julio': 7, 'agosto': 8, 'septiembre': 9, 'setiembre': 9, 'octubre': 10,
    'noviembre': 11, 'diciembre': 12
}

# ----------------------------------------------------------------------------
# CARGA DE VOCABULARIO EXPANDIDO DESDE ARCHIVO (~2.8 MB)
# ----------------------------------------------------------------------------
def cargar_vocabulario():
    """Carga el vocabulario expandido desde el archivo de texto."""
    terminos = set()
    secciones = set()
    hablantes = set()
    conversacion = set()
    if os.path.exists(VOCAB_PATH):
        try:
            with open(VOCAB_PATH, 'r', encoding='utf-8') as f:
                for linea in f:
                    linea = linea.strip()
                    if not linea or linea.startswith('#'):
                        continue
                    # Detectar secciones (empiezan con SECCION_)
                    if linea.startswith('SECCION_'):
                        secciones.add(linea)
                    # Detectar hablantes (empiezan con HABLANTE_ o EL_/LA_)
                    elif linea.startswith('HABLANTE_') or linea.startswith('EL_') or linea.startswith('LA_'):
                        hablantes.add(linea)
                    # Detectar marcadores de conversación (contienen :)
                    elif ':' in linea and '_MARCADOR' in linea:
                        conversacion.add(linea)
                    else:
                        terminos.add(linea)
        except Exception as e:
            print(f"Advertencia: no se pudo cargar vocabulario: {e}", flush=True)
    return terminos, secciones, hablantes, conversacion

VOCAB_TERMINOS, VOCAB_SECCIONES, VOCAB_HABLANTES, VOCAB_CONVERSACION = cargar_vocabulario()
print(f"Vocabulario cargado: {len(VOCAB_TERMINOS)} términos, {len(VOCAB_SECCIONES)} secciones, {len(VOCAB_HABLANTES)} hablantes", flush=True)

# ----------------------------------------------------------------------------
# DETECCIÓN DE HABLANTE (médico/paciente, hombre/mujer)
# ----------------------------------------------------------------------------
def _plano(texto):
    """Minúsculas y sin acentos: 'Médica' y 'medica' se comparan igual."""
    return texto.lower().translate(str.maketrans('áéíóúüñ', 'aeiouun'))


def _menciona(texto_plano, frases):
    """Frases presentes como palabra(s) completa(s).

    Antes se usaba `frase in texto`, y así 'medica' coincidía con 'medicamento' y 'el'
    con cualquier palabra. Con límites de palabra eso ya no pasa.
    """
    encontradas = 0
    vistas = set()
    for frase in frases:
        normal = _plano(frase)
        if normal in vistas:
            continue
        vistas.add(normal)
        patron = r'(?<![a-z0-9])' + re.escape(normal) + r'(?![a-z0-9])'
        if re.search(patron, texto_plano):
            encontradas += 1
    return encontradas


def detectar_hablante(texto):
    """Identifica quién está hablando: médico o paciente, hombre o mujer.

    Devuelve además `confianza` (0–1) para que el cliente sepa si el dato es firme
    o solo una pista.
    """
    t = _plano(texto)
    hablante = {'rol': 'desconocido', 'genero': 'desconocido',
                'se_autoidentifica': False, 'mencionado_por_tercero': False,
                'confianza': 0.0}
    if not t.strip():
        return hablante

    # Roles. Las frases largas ya incluyen a las cortas; se cuentan por separado
    # solo para medir confianza, no para decidir el rol.
    roles_medico = ['doctor', 'doctora', 'medico', 'medica', 'enfermera', 'enfermero',
                    'pasante', 'residente', 'especialista', 'tecnico', 'auxiliar',
                    'cirujano', 'cirujana', 'nutriologo', 'nutriologa', 'psicologo', 'psicologa']
    roles_paciente = ['paciente', 'pacienta', 'mujer', 'hombre', 'senora', 'senor',
                      'nina', 'nino', 'joven', 'adulto', 'adulto mayor',
                      'acompanante', 'familiar', 'tutor', 'representante']
    n_medico = _menciona(t, roles_medico)
    n_paciente = _menciona(t, roles_paciente)
    if n_medico and not n_paciente:
        hablante['rol'] = 'medico'
    elif n_paciente and not n_medico:
        hablante['rol'] = 'paciente'
    elif n_medico and n_paciente:
        hablante['rol'] = 'ambos'

    # Género. Solo palabras realmente marcadas; 'paciente' es neutro y 'el'/'la'
    # aparecen en cualquier frase, así que no cuentan.
    genero_femenino = ['mujer', 'senora', 'senorita', 'nina', 'doctora', 'medica',
                       'enfermera', 'pacienta', 'ella', 'embarazada', 'gestante',
                       'nutriologa', 'psicologa', 'cirujana', 'la paciente', 'la joven']
    genero_masculino = ['hombre', 'senor', 'nino', 'doctor', 'medico', 'enfermero',
                        'nutriologo', 'psicologo', 'cirujano', 'el paciente', 'el joven']
    n_femenino = _menciona(t, genero_femenino)
    n_masculino = _menciona(t, genero_masculino)
    if n_femenino and not n_masculino:
        hablante['genero'] = 'femenino'
    elif n_masculino and not n_femenino:
        hablante['genero'] = 'masculino'
    elif n_femenino and n_masculino:
        hablante['genero'] = 'ambos'

    # Autoidentificación y mención por tercero (con límites de palabra).
    if re.search(r'(?<![a-z0-9])yo soy (el |la )?(doctor|doctora|medico|medica|paciente|pacienta|mujer|hombre|enfermera|enfermero)(?![a-z0-9])', t):
        hablante['se_autoidentifica'] = True
    if re.search(r'(?<![a-z0-9])(el|la) (doctor|doctora|medico|medica|paciente|pacienta) (dice|comenta|pregunta|refiere|responde)(?![a-z0-9])', t):
        hablante['mencionado_por_tercero'] = True

    # Confianza: una pista aislada es débil; varias palabras concordantes la suben.
    pistas = n_medico + n_paciente + n_femenino + n_masculino
    if hablante['rol'] == 'desconocido' and hablante['genero'] == 'desconocido':
        hablante['confianza'] = 0.0
    else:
        hablante['confianza'] = round(min(0.35 + 0.15 * pistas, 0.95), 2)
    if hablante['se_autoidentifica']:
        hablante['confianza'] = round(min(hablante['confianza'] + 0.2, 0.99), 2)
    return hablante

# ----------------------------------------------------------------------------
# DETECCIÓN DE SECCIÓN DEL EXPEDIENTE
# ----------------------------------------------------------------------------
def detectar_seccion(texto):
    """Detecta a qué sección del expediente electrónico pertenece el texto."""
    t = texto.lower()
    seccion = {'principal': 'general', 'subsecciones': [], 'confianza': 0.0}

    marcadores = {
        'datos_personales': [
            'nombre', 'apellido', 'fecha de nacimiento', 'edad', 'sexo', 'curp',
            'telefono', 'correo', 'domicilio', 'municipio', 'estado', 'nacionalidad',
            'entidad de nacimiento', 'estado civil', 'ocupacion', 'escolaridad',
            'contacto de emergencia', 'telefono de emergencia'
        ],
        'antecedentes': [
            'antecedentes', 'heredofamiliares', 'personales patologicos',
            'personales no patologicos', 'ginecobstetricos', 'habitos', 'sustancias',
            'inmunizaciones', 'tutor', 'representante', 'afiliacion'
        ],
        'motivo_consulta': [
            'motivo de consulta', 'motivo', 'consulta por', 'viene por', 'acude por',
            'que le trae', 'en que le puedo ayudar', 'cual es el motivo'
        ],
        'padecimiento_actual': [
            'padecimiento actual', 'padecimiento', 'enfermedad actual',
            'problema actual', 'condicion actual', 'estado actual', 'que tiene',
            'que le paso', 'que le sucede', 'que le ocurre'
        ],
        'exploracion_fisica': [
            'exploracion fisica', 'exploración física', 'examen fisico',
            'a la exploracion', 'se observa', 'se palpa', 'se ausculta', 'se percute',
            'se revisa', 'se examina'
        ],
        'diagnostico': [
            'diagnostico', 'impresion diagnostica', 'diagnostico probable',
            'diagnostico definitivo', 'diagnostico diferencial', 'se diagnostica'
        ],
        'plan_tratamiento': [
            'plan de tratamiento', 'plan y tratamiento', 'plan de manejo',
            'se indica', 'se prescribe', 'se ordena', 'se recomienda',
            'tratamiento a seguir', 'medicamento a tomar'
        ],
        'nota_evolucion': [
            'nota de evolucion', 'evolucion', 'progreso', 'evolucion del paciente',
            'estado del paciente', 'como va', 'como esta', 'mejora', 'empeora'
        ],
        'pronostico': [
            'pronostico', 'pronostico favorable', 'pronostico reservado',
            'pronostico grave', 'pronostico dudoso', 'se espera', 'evolucion esperada'
        ],
        'signos_vitales': [
            'signos vitales', 'tension arterial', 'presion arterial', 'pulso',
            'frecuencia cardiaca', 'temperatura', 'saturacion', 'peso', 'talla', 'glucosa'
        ],
        'alergias': [
            'alergia', 'alergico', 'alergica', 'alergias', 'alergico a', 'alergica a',
            'no tolera', 'reacciona a'
        ],
        'padecimientos_cronicos': [
            'padece', 'padecimiento cronico', 'enfermedad cronica', 'condicion cronica',
            'problema cronico', 'enfermedad de base', 'antecedente cronico'
        ],
        'medicamentos_actuales': [
            'toma', 'toma medicamentos', 'medicamentos actuales', 'medicamentos que toma',
            'medicamentos en uso', 'medicamentos recetados', 'medicamentos que esta tomando'
        ],
    }

    mejor_seccion = 'general'
    mejor_puntaje = 0
    for nombre, palabras in marcadores.items():
        puntaje = _menciona(t, palabras)
        if puntaje > mejor_puntaje:
            mejor_puntaje = puntaje
            mejor_seccion = nombre
    if mejor_puntaje > 0:
        seccion['principal'] = mejor_seccion
        seccion['confianza'] = min(mejor_puntaje / 3.0, 1.0)
        # Subsecciones detectadas
        for nombre, palabras in marcadores.items():
            if nombre != mejor_seccion:
                sub = _menciona(t, palabras)
                if sub > 0:
                    seccion['subsecciones'].append(nombre)
    return seccion

# ----------------------------------------------------------------------------
# ANÁLISIS DE CONTEXTO CONVERSACIONAL
# ----------------------------------------------------------------------------
def analizar_contexto_conversacional(texto):
    """Analiza el contexto conversacional médico-paciente."""
    t = texto.lower()
    contexto = {
        'tipo': 'general',
        'direccion': None,  # medico->paciente o paciente->medico
        'tiempo_verbal': 'presente',
        'es_pregunta': False,
        'es_respuesta': False,
        'es_instruccion': False,
        'es_diagnostico': False,
        'es_prescripcion': False,
        'urgencia': 'normal',
    }
    # Tipo de frase
    if re.search(r'\b(¿|\?)', texto) or re.search(r'\b(que|cual|cuales|cundo|donde|como|cuales|cuantos|cuantas)\b', t):
        contexto['es_pregunta'] = True
        contexto['tipo'] = 'pregunta'
    if re.search(r'\b(responde|contesta|dice que|afirma que|menciona que)\b', t):
        contexto['es_respuesta'] = True
        contexto['tipo'] = 'respuesta'
    if re.search(r'\b(se indica|se prescribe|se ordena|se recomienda|se sugiere|tomar|aplicar|administrar)\b', t):
        contexto['es_instruccion'] = True
        contexto['tipo'] = 'instruccion'
    if re.search(r'\b(diagnostico|impresion diagnostica|diagnostico probable|diagnostico definitivo)\b', t):
        contexto['es_diagnostico'] = True
        contexto['tipo'] = 'diagnostico'
    if re.search(r'\b(receta|prescribir|medicamento|dosis|tableta|capsula|inyeccion)\b', t):
        contexto['es_prescripcion'] = True
        contexto['tipo'] = 'prescripcion'

    # Dirección de la conversación
    patrones_medico_a_paciente = [
        'que le trae', 'en que le puedo ayudar', 'cual es el motivo', 'desde cuando',
        'ha tenido', 'ha vomitado', 'ha tenido diarrea', 'toma algun medicamento',
        'es alergico', 'tiene alguna enfermedad', 'fuma', 'toma alcohol', 'cuantos anos'
    ]
    patrones_paciente_a_medico = [
        'me duele', 'tengo', 'me siento', 'me dio', 'empezo hace', 'desde hace',
        'no me siento bien', 'me siento debil', 'me siento cansado', 'me siento mareado'
    ]
    if any(p in t for p in patrones_medico_a_paciente):
        contexto['direccion'] = 'medico_a_paciente'
    elif any(p in t for p in patrones_paciente_a_medico):
        contexto['direccion'] = 'paciente_a_medico'

    # Tiempo verbal
    pasados = ['tuve', 'tuvo', 'tuvieron', 'estuve', 'estuvo', 'estuvieron', 'sentí',
               'sintió', 'fui', 'fue', 'fueron', 'vine', 'vino', 'vinieron', 'pude',
               'pudo', 'pudieron', 'quise', 'quiso', 'quisieron', 'dolió', 'dolieron',
               'dio', 'dieron', 'empezó', 'empezaron', 'comenzó', 'comenzaron']
    futuros = ['tendré', 'tendrá', 'tendremos', 'tendrán', 'estaré', 'estará',
               'estaremos', 'estarán', 'sentiré', 'sentirá', 'ire', 'irá', 'iremos',
               'irán', 'vendré', 'vendrá', 'podré', 'podrá', 'necesitaré', 'necesitará']
    condicionales = ['quisiera', 'quisieran', 'me gustaría', 'podría', 'debería',
                     'sería', 'estaría', 'tendría', 'satisfaría']
    if any(p in t for p in pasados):
        contexto['tiempo_verbal'] = 'pasado'
    elif any(p in t for p in futuros):
        contexto['tiempo_verbal'] = 'futuro'
    elif any(p in t for p in condicionales):
        contexto['tiempo_verbal'] = 'condicional'
    else:
        contexto['tiempo_verbal'] = 'presente'

    # Urgencia
    palabras_urgencia = ['urgencia', 'emergencia', 'grave', 'critico', 'critica',
                         'infarto', 'derrame', 'embolia', 'trombosis', 'ahoga',
                         'no puede respirar', 'desmayo', 'convulsion', 'sangrado']
    if any(p in t for p in palabras_urgencia):
        contexto['urgencia'] = 'alta'
    elif any(p in t for p in ['control', 'seguimiento', 'subsecuente', 'rutinario']):
        contexto['urgencia'] = 'baja'
    else:
        contexto['urgencia'] = 'normal'

    return contexto

# ============================================================
# JERGA MEXICANA EXPANDIDA - 300+ ENTRADAS
# ============================================================

SINTOMAS_MEXICANOS = {
    # Dolor de cabeza
    'dolor de cabeza': 'cefalea', 'me duele la cabeza': 'cefalea',
    'me duele el cerebro': 'cefalea', 'me zumba la cabeza': 'cefalea',
    'dolor de cráneo': 'cefalea', 'dolor en la cabeza': 'cefalea',
    'jaqueca': 'migraña', 'migraña': 'migraña', 'me duele la frente': 'cefalea frontal',
    'dolor frontal': 'cefalea frontal', 'dolor de sien': 'cefalea temporal',
    'dolor de nuca': 'cefalea occipital', 'dolor de coronilla': 'cefalea parietal',
    'me duele la nuca': 'cefalea occipital', 'me duele la sien': 'cefalea temporal',
    'me duele la frente': 'cefalea frontal', 'dolor en la frente': 'cefalea frontal',
    'dolor en la nuca': 'cefalea occipital', 'dolor en la sien': 'cefalea temporal',
    # Dolor abdominal
    'dolor de estómago': 'dolor abdominal', 'dolor de panza': 'dolor abdominal',
    'dolor abdominal': 'dolor abdominal', 'me duele el estómago': 'dolor abdominal',
    'me duele la panza': 'dolor abdominal', 'dolor en el estómago': 'dolor abdominal',
    'dolor en la panza': 'dolor abdominal', 'dolor de vientre': 'dolor abdominal',
    'me duele el vientre': 'dolor abdominal', 'dolor de barriga': 'dolor abdominal',
    'me duele la barriga': 'dolor abdominal', 'me duele el abdomen': 'dolor abdominal',
    'dolor en el abdomen': 'dolor abdominal', 'dolor en la barriga': 'dolor abdominal',
    'cólico': 'cólico abdominal', 'me duele con cólico': 'cólico abdominal',
    'me retuerce el estómago': 'cólico abdominal', 'me retuerce la panza': 'cólico abdominal',
    # Dolor torácico
    'dolor de pecho': 'dolor torácico', 'dolor torácico': 'dolor torácico',
    'me duele el pecho': 'dolor torácico', 'dolor en el pecho': 'dolor torácico',
    'dolor de tórax': 'dolor torácico', 'me duele el tórax': 'dolor torácico',
    'dolor retroesternal': 'dolor torácico', 'me duele el esternón': 'dolor torácico',
    'me duele el corazón': 'dolor precordial', 'dolor precordial': 'dolor precordial',
    'me duele el pecho al respirar': 'dolor pleurítico', 'dolor pleurítico': 'dolor pleurítico',
    # Odinofagia
    'dolor de garganta': 'odinofagia', 'me duele la garganta': 'odinofagia',
    'dolor en la garganta': 'odinofagia', 'garganta roja': 'faringitis',
    'garganta irritada': 'faringitis', 'garganta inflamada': 'faringitis',
    'garganta con pus': 'amigdalitis', 'amígdalas inflamadas': 'amigdalitis',
    'me duele al tragar': 'odinofagia', 'dolor al tragar': 'odinofagia',
    'me raspa la garganta': 'faringitis', 'raspa la garganta': 'faringitis',
    # Otalgia
    'dolor de oído': 'otalgia', 'me duele el oído': 'otalgia',
    'dolor en el oído': 'otalgia', 'me zumba el oído': 'tinnitus',
    'zumbido en el oído': 'tinnitus', 'zumbido': 'tinnitus',
    'se me tapó el oído': 'hipoacusia', 'no oigo bien': 'hipoacusia',
    'oído tapado': 'hipoacusia', 'dolor de oídos': 'otalgia',
    'me duele el oído izquierdo': 'otalgia izquierda', 'me duele el oído derecho': 'otalgia derecha',
    'me zumba la cabeza': 'tinnitus', 'zumbido en la cabeza': 'tinnitus',
    # Odontalgia
    'dolor de muela': 'odontalgia', 'me duele la muela': 'odontalgia',
    'dolor de diente': 'odontalgia', 'me duele el diente': 'odontalgia',
    'dolor dental': 'odontalgia', 'caries': 'caries dental',
    'dolor de encía': 'gingivitis', 'me duele la encía': 'gingivitis',
    'encía inflamada': 'gingivitis', 'encía sangrante': 'gingivorragia',
    # Lumbalgia
    'dolor de espalda': 'lumbalgia', 'me duele la espalda': 'lumbalgia',
    'dolor de cintura': 'lumbalgia', 'me duele la cintura': 'lumbalgia',
    'dolor de riñón': 'lumbalgia', 'me duele el riñón': 'lumbalgia',
    'dolor en la espalda': 'lumbalgia', 'dolor en la cintura': 'lumbalgia',
    'dolor lumbar': 'lumbalgia', 'me duele la lumbar': 'lumbalgia',
    'dolor de columna': 'lumbalgia', 'me duele la columna': 'lumbalgia',
    'dolor de cuello': 'cervicalgia', 'me duele el cuello': 'cervicalgia',
    'dolor cervical': 'cervicalgia', 'me duele la cervical': 'cervicalgia',
    # Artralgia
    'dolor de articulaciones': 'artralgia', 'me duele la articulación': 'artralgia',
    'dolor en las articulaciones': 'artralgia', 'me duelen las articulaciones': 'artralgia',
    'dolor articular': 'artralgia', 'articulaciones inflamadas': 'artritis',
    'me duelen las coyunturas': 'artralgia', 'dolor en las coyunturas': 'artralgia',
    'me duelen los huesos': 'ostealgia', 'dolor en los huesos': 'ostealgia',
    'dolor de huesos': 'ostealgia', 'me duele el hueso': 'ostealgia',
    # Oftalmalgia
    'dolor de ojo': 'oftalmalgia', 'me duele el ojo': 'oftalmalgia',
    'dolor en el ojo': 'oftalmalgia', 'me duele la vista': 'oftalmalgia',
    'veo borroso': 'visión borrosa', 'veo doble': 'diplopía',
    'visión borrosa': 'visión borrosa', 'diplopía': 'diplopía',
    'ojo rojo': 'conjuntivitis', 'ojo irritado': 'conjuntivitis',
    'ojo hinchado': 'edema palpebral', 'ojo lloroso': 'epífora',
    'ojo seco': 'ojo seco', 'picazón de ojo': 'prurito ocular',
    'me arde el ojo': 'conjuntivitis', 'me pica el ojo': 'prurito ocular',
    # Otras dolencias
    'dolor de nariz': 'dolor nasal', 'me duele la nariz': 'dolor nasal',
    'dolor de boca': 'estomatodinia', 'me duele la boca': 'estomatodinia',
    'dolor de lengua': 'glosodinia', 'me duele la lengua': 'glosodinia',
    'dolor de labio': 'queilitis', 'me duele el labio': 'queilitis',
    'dolor de cuello': 'cervicalgia', 'me duele el cuello': 'cervicalgia',
    'dolor cervical': 'cervicalgia', 'me duele la cervical': 'cervicalgia',
    'dolor de hombro': 'omalgia', 'me duele el hombro': 'omalgia',
    'dolor de codo': 'epicondilitis', 'me duele el codo': 'epicondilitis',
    'dolor de muñeca': 'dolor en muñeca', 'me duele la muñeca': 'dolor en muñeca',
    'dolor de dedo': 'dolor en dedo', 'me duele el dedo': 'dolor en dedo',
    'dolor de uña': 'paroniquia', 'me duele la uña': 'paroniquia',
    'dolor de vagina': 'vaginodinia', 'me duele la vagina': 'vaginodinia',
    'dolor de ano': 'anodinia', 'me duele el ano': 'anodinia',
    'dolor de recto': 'proctalgia', 'me duele el recto': 'proctalgia',
    'dolor de testículo': 'orquialgia', 'me duele el testículo': 'orquialgia',
    'dolor de pene': 'penalgia', 'me duele el pene': 'penalgia',
    'dolor de próstata': 'prostatodinia', 'me duele la próstata': 'prostatodinia',
    'dolor de útero': 'histeralgia', 'me duele el útero': 'histeralgia',
    'dolor de ovario': 'ovarialgia', 'me duele el ovario': 'ovarialgia',
    'dolor de vejiga': 'cistalgia', 'me duele la vejiga': 'cistalgia',
    'dolor de riñón': 'nefralgia', 'me duele el riñón': 'nefralgia',
    'dolor de hígado': 'hepatomegalia', 'me duele el hígado': 'hepatomegalia',
    'dolor de vesícula': 'cólico biliar', 'me duele la vesícula': 'cólico biliar',
    'dolor de páncreas': 'pancreatitis', 'me duele el páncreas': 'pancreatitis',
    'dolor de intestino': 'enteritis', 'me duele el intestino': 'enteritis',
    'dolor de colon': 'colitis', 'me duele el colon': 'colitis',
    'dolor de corazón': 'cardiopatía', 'me duele el corazón': 'cardiopatía',
    'dolor de pulmón': 'neumopatía', 'me duele el pulmón': 'neumopatía',
    'dolor de cadera': 'coxalgia', 'me duele la cadera': 'coxalgia',
    'dolor de rodilla': 'gonalgia', 'me duele la rodilla': 'gonalgia',
    'dolor de tobillo': 'talalgia', 'me duele el tobillo': 'talalgia',
    'dolor de pie': 'podalgia', 'me duele el pie': 'podalgia',
    'dolor de mano': 'dolor en mano', 'me duele la mano': 'dolor en mano',
    'dolor de brazo': 'dolor en brazo', 'me duele el brazo': 'dolor en brazo',
    'dolor de pierna': 'dolor en pierna', 'me duele la pierna': 'dolor en pierna',
    'dolor de muslo': 'dolor en muslo', 'me duele el muslo': 'dolor en muslo',
    'dolor de pantorrilla': 'dolor en pantorrilla', 'me duele la pantorrilla': 'dolor en pantorrilla',
    'dolor de talón': 'talalgia', 'me duele el talón': 'talalgia',
    'dolor de planta': 'plantalgia', 'me duele la planta': 'plantalgia',
    'dolor de uña': 'paroniquia', 'me duele la uña': 'paroniquia',
    # Síntomas generales
    'fiebre': 'fiebre', 'calentura': 'fiebre', 'tengo calentura': 'fiebre',
    'tos': 'tos', 'tengo tos': 'tos', 'tos seca': 'tos seca',
    'tos con flema': 'tos productiva', 'toso con flema': 'tos productiva',
    'toso seco': 'tos seca', 'náusea': 'náusea', 'ganas de vomitar': 'náusea',
    'vómito': 'vómito', 'vomitó': 'vómito', 'diarrea': 'diarrea',
    'estómago suelto': 'diarrea', 'tengo chorrillo': 'diarrea',
    'me dio chorrillo': 'diarrea', 'chorrillo': 'diarrea',
    'mareo': 'mareo', 'me dio mareo': 'mareo', 'tengo mareo': 'mareo',
    'vértigo': 'vértigo', 'me da vértigo': 'vértigo',
    'fatiga': 'fatiga', 'cansancio': 'fatiga', 'me siento cansado': 'fatiga',
    'debilidad': 'debilidad', 'me siento débil': 'debilidad',
    'falta de aire': 'disnea', 'se ahoga': 'disnea', 'no puede respirar': 'disnea',
    'se le va el aire': 'disnea', 'no le alcanza el aire': 'disnea',
    'ardor': 'pirosis', 'acidez': 'pirosis', 'me da ardor': 'pirosis',
    'comezón': 'prurito', 'picazón': 'prurito', 'me pica': 'prurito',
    'sarpullido': 'exantema', 'ronchas': 'urticaria', 'ronchitas': 'urticaria',
    'desmayo': 'síncope', 'se desmayó': 'síncope', 'se desmayo': 'síncope',
    'patatús': 'síncope', 'soponcio': 'síncope', 'se fue': 'síncope',
    'convulsión': 'convulsión', 'ataque': 'convulsión', 'convulsionó': 'convulsión',
    'escalofríos': 'escalofríos', 'tengo escalofríos': 'escalofríos',
    'sudor frío': 'diaforesis', 'suda frío': 'diaforesis', 'sudoración': 'diaforesis',
    'pérdida de peso': 'pérdida de peso', 'bajó de peso': 'pérdida de peso',
    'subió de peso': 'ganancia de peso', 'no duerme': 'insomnio',
    'insomnio': 'insomnio', 'no puede dormir': 'insomnio',
    'ansiedad': 'ansiedad', 'depresión': 'depresión', 'tristeza': 'depresión',
    'palpitaciones': 'palpitaciones', 'latidos rápidos': 'taquicardia',
    'latidos lentos': 'bradicardia', 'presión alta': 'hipertensión',
    'presión baja': 'hipotensión', 'se me subió la presión': 'hipertensión',
    'se me bajó la presión': 'hipotensión',
    'azúcar alta': 'hiperglucemia', 'azúcar baja': 'hipoglucemia',
    'colesterol alto': 'hipercolesterolemia', 'triglicéridos altos': 'hipertrigliceridemia',
    'anemia': 'anemia', 'falta de hierro': 'anemia ferropénica',
    'infección': 'infección', 'inflamación': 'inflamación',
    'congestión': 'congestión nasal', 'mocos': 'rinorrea',
    'gripe': 'influenza', 'resfriado': 'rinofaringitis', 'catarro': 'rinofaringitis',
    'bronquitis': 'bronquitis', 'pulmonía': 'neumonía', 'neumonía': 'neumonía',
    'asma': 'asma', 'alergia': 'alergia', 'rinitis': 'rinitis',
    'sinusitis': 'sinusitis', 'otitis': 'otitis', 'conjuntivitis': 'conjuntivitis',
    'gastritis': 'gastritis', 'úlcera': 'úlcera gástrica', 'colitis': 'colitis',
    'estreñimiento': 'estreñimiento', 'gases': 'flatulencia',
    'ardor al orinar': 'disuria', 'orina con sangre': 'hematuria',
    'orina frecuente': 'poliuria', 'dolor al orinar': 'disuria',
    'infección de vías urinarias': 'IVU', 'IVU': 'infección de vías urinarias',
    'embarazo': 'embarazo', 'está embarazada': 'embarazo',
    'menstruación': 'menstruación', 'regla': 'menstruación',
    'dolor menstrual': 'dismenorrea', 'sangrado abundante': 'menorragia',
    'me siento mal': 'malestar general', 'me siento débil': 'astenia',
    'no tengo energía': 'astenia', 'me siento mareado': 'mareo',
    'me duele todo': 'dolor generalizado', 'me duele el cuerpo': 'mialgia generalizada',
    'me duelen los huesos': 'ostealgia', 'me duelen las articulaciones': 'artralgia',
    'no tengo hambre': 'anorexia', 'no quiere comer': 'anorexia',
    'no duerme': 'insomnio', 'no puede dormir': 'insomnio',
    'veo borroso': 'visión borrosa', 'veo doble': 'diplopía',
    'me zumba el oído': 'tinnitus', 'zumbido en el oído': 'tinnitus',
    'sangrado nasal': 'epistaxis', 'sangre de la nariz': 'epistaxis',
    'sangrado de encías': 'gingivorragia', 'sangrado de nariz': 'epistaxis',
    # Jerga mexicana para partes del cuerpo
    'panza': 'abdomen', 'pata': 'pierna', 'ojo': 'ojo',
    'oído': 'oído', 'nariz': 'nariz', 'boca': 'boca', 'garganta': 'garganta',
    'pecho': 'tórax', 'espalda': 'espalda', 'cintura': 'lumbares',
    'riñón': 'lumbares', 'pierna': 'pierna', 'rodilla': 'rodilla',
    'tobillo': 'tobillo', 'pie': 'pie', 'cabeza': 'cabeza',
    'cerebro': 'cerebro', 'corazón': 'corazón', 'pulmón': 'pulmón',
    'hígado': 'hígado', 'vesícula': 'vesícula', 'páncreas': 'páncreas',
    'intestino': 'intestino', 'colon': 'colon', 'vejiga': 'vejiga',
    'próstata': 'próstata', 'útero': 'útero', 'ovario': 'ovario',
    'testículo': 'testículo', 'pene': 'pene', 'vagina': 'vagina',
    'ano': 'ano', 'recto': 'recto', 'mano': 'mano', 'brazo': 'brazo',
    'hombro': 'hombro', 'codo': 'codo', 'muñeca': 'muñeca',
    'dedo': 'dedo', 'uña': 'uña', 'cuello': 'cuello',
    'cadera': 'cadera', 'muslo': 'muslo', 'pantorrilla': 'pantorrilla',
    'talón': 'talón', 'planta': 'planta del pie',
    # Jerga mexicana para orina y heces
    'pipí': 'orina', 'hacer pipí': 'orinar', 'no puede hacer pipí': 'retención urinaria',
    'popo': 'heces', 'hacer popo': 'defecar', 'popo suelta': 'diarrea',
    'popo dura': 'estreñimiento', 'sangre en la popo': 'hematoquecia',
    'sangre en el pipí': 'hematuria', 'pipí con sangre': 'hematuria',
    'pipí turbio': 'orina turbia', 'pipí amarillo': 'orina concentrada',
    'pipí con olor': 'orina fétida', 'pipí espumoso': 'proteinuria',
    'pipí frecuente': 'poliuria', 'mucha pipí': 'poliuria',
    'poca pipí': 'oliguria', 'no hace pipí': 'anuria',
    # Jerga mexicana general
    'me dio chorrillo': 'diarrea', 'me dio la vuelta': 'mareo',
    'se me subió la presión': 'hipertensión', 'se me bajó la presión': 'hipotensión',
    'me dio un patatús': 'síncope', 'me dio un soponcio': 'síncope',
    'me dio un ataque': 'convulsión', 'me dio un infarto': 'infarto agudo de miocardio',
    'me dio un derrame': 'evento vascular cerebral', 'me dio un embolia': 'embolia',
    'me dio un trombo': 'trombosis', 'me dio diabetes': 'diabetes mellitus',
    'me dio presión': 'hipertensión', 'me dio azúcar': 'diabetes mellitus',
    'me dio colesterol': 'hipercolesterolemia', 'me dio triglicéridos': 'hipertrigliceridemia',
    'me dio anemia': 'anemia', 'me dio gastritis': 'gastritis',
    'me dio colitis': 'colitis', 'me dio úlcera': 'úlcera gástrica',
    'me dio hernia': 'hernia', 'me dio apendicitis': 'apendicitis',
    'me dio amigdalitis': 'amigdalitis', 'me dio otitis': 'otitis',
    'me dio conjuntivitis': 'conjuntivitis', 'me dio sinusitis': 'sinusitis',
    'me dio bronquitis': 'bronquitis', 'me dio neumonía': 'neumonía',
    'me dio asma': 'asma', 'me dio alergia': 'alergia',
    'me dio rinitis': 'rinitis', 'me dio gripe': 'influenza',
    'me dio resfriado': 'rinofaringitis', 'me dio catarro': 'rinofaringitis',
    'me dio calentura': 'fiebre', 'tengo calentura': 'fiebre',
    'me dio tos': 'tos', 'tengo tos': 'tos',
    'me dio náusea': 'náusea', 'tengo náusea': 'náusea',
    'me dio vómito': 'vómito', 'tengo vómito': 'vómito',
    'me dio diarrea': 'diarrea', 'tengo diarrea': 'diarrea',
    'me dio mareo': 'mareo', 'tengo mareo': 'mareo',
    'me dio fatiga': 'fatiga', 'tengo fatiga': 'fatiga',
    'me dio debilidad': 'debilidad', 'tengo debilidad': 'debilidad',
    'me dio falta de aire': 'disnea', 'tengo falta de aire': 'disnea',
    'me dio ardor': 'pirosis', 'tengo ardor': 'pirosis',
    'me dio comezón': 'prurito', 'tengo comezón': 'prurito',
    'me dio sarpullido': 'exantema', 'tengo sarpullido': 'exantema',
    'me dio ronchas': 'urticaria', 'tengo ronchas': 'urticaria',
    'me dio desmayo': 'síncope', 'me desmayé': 'síncope',
    'me dio convulsión': 'convulsión', 'tengo convulsión': 'convulsión',
    'me dio escalofríos': 'escalofríos', 'tengo escalofríos': 'escalofríos',
    'me dio sudor frío': 'diaforesis', 'tengo sudor frío': 'diaforesis',
    'me dio pérdida de peso': 'pérdida de peso', 'bajé de peso': 'pérdida de peso',
    'me dio insomnio': 'insomnio', 'no duermo': 'insomnio',
    'me dio ansiedad': 'ansiedad', 'tengo ansiedad': 'ansiedad',
    'me dio depresión': 'depresión', 'tengo depresión': 'depresión',
    'me dio palpitaciones': 'palpitaciones', 'tengo palpitaciones': 'palpitaciones',
    'me dio hipertensión': 'hipertensión', 'tengo hipertensión': 'hipertensión',
    'me dio hipotensión': 'hipotensión', 'tengo hipotensión': 'hipotensión',
    'me dio diabetes': 'diabetes mellitus', 'tengo diabetes': 'diabetes mellitus',
    'me dio anemia': 'anemia', 'tengo anemia': 'anemia',
    'me dio gastritis': 'gastritis', 'tengo gastritis': 'gastritis',
    'me dio colitis': 'colitis', 'tengo colitis': 'colitis',
    'me dio estreñimiento': 'estreñimiento', 'tengo estreñimiento': 'estreñimiento',
    'me dio gases': 'flatulencia', 'tengo gases': 'flatulencia',
    'me dio IVU': 'infección de vías urinarias', 'tengo IVU': 'infección de vías urinarias',
    'me dio embarazo': 'embarazo', 'estoy embarazada': 'embarazo',
    'me dio menstruación': 'menstruación', 'tengo menstruación': 'menstruación',
    'me dio dolor menstrual': 'dismenorrea', 'tengo dolor menstrual': 'dismenorrea',
    'me dio sangrado abundante': 'menorragia', 'tengo sangrado abundante': 'menorragia',
    'me siento mal': 'malestar general', 'me siento débil': 'astenia',
    'no tengo energía': 'astenia', 'me siento mareado': 'mareo',
    'me duele todo': 'dolor generalizado', 'me duele el cuerpo': 'mialgia generalizada',
    'no tengo hambre': 'anorexia', 'no quiere comer': 'anorexia',
    'no duerme': 'insomnio', 'no puede dormir': 'insomnio',
    'veo borroso': 'visión borrosa', 'veo doble': 'diplopía',
    'me zumba el oído': 'tinnitus', 'zumbido en el oído': 'tinnitus',
    'sangrado nasal': 'epistaxis', 'sangre de la nariz': 'epistaxis',
    'sangrado de encías': 'gingivorragia', 'sangrado de nariz': 'epistaxis',
    # Números y edades
    'años': 'años', 'meses': 'meses', 'días': 'días', 'semanas': 'semanas',
    'hora': 'hora', 'horas': 'horas', 'minuto': 'minuto', 'minutos': 'minutos',
    'segundo': 'segundo', 'segundos': 'segundos',
    # Medidas
    'kilogramos': 'kg', 'kilos': 'kg', 'gramos': 'g', 'libras': 'lb',
    'metros': 'm', 'centímetros': 'cm', 'milímetros': 'mm',
    'litros': 'L', 'mililitros': 'mL', 'celsius': '°C', 'fahrenheit': '°F',
    # Unidades de tiempo
    'cada': 'cada', 'cada hora': 'cada hora', 'cada día': 'cada día',
    'cada semana': 'cada semana', 'cada mes': 'cada mes',
    'por la mañana': 'por la mañana', 'por la tarde': 'por la tarde',
    'por la noche': 'por la noche', 'en ayunas': 'en ayunas',
    'antes de comer': 'antes de comer', 'después de comer': 'después de comer',
    'con los alimentos': 'con los alimentos', 'sin alimentos': 'sin alimentos',
    # Formas farmacéuticas
    'tabletas': 'tabletas', 'cápsulas': 'cápsulas', 'grageas': 'grageas',
    'solución': 'solución', 'suspensión': 'suspensión', 'inyección': 'inyección',
    'crema': 'crema', 'pomada': 'pomada', 'gel': 'gel', 'ungüento': 'ungüento',
    'gotas': 'gotas', 'spray': 'spray', 'inhalador': 'inhalador',
    'supositorio': 'supositorio', 'óvulo': 'óvulo', 'parche': 'parche',
    'jarabe': 'jarabe', 'elíxir': 'elíxir', 'tintura': 'tintura',
    # Vías de administración
    'oral': 'oral', 'por la boca': 'oral', 'vía oral': 'oral',
    'intravenosa': 'intravenosa', 'IV': 'intravenosa', 'por la vena': 'intravenosa',
    'intramuscular': 'intramuscular', 'IM': 'intramuscular', 'en el músculo': 'intramuscular',
    'subcutánea': 'subcutánea', 'SC': 'subcutánea', 'bajo la piel': 'subcutánea',
    'tópica': 'tópica', 'en la piel': 'tópica', 'rectal': 'rectal',
    'vaginal': 'vaginal', 'ocular': 'ocular', 'ótica': 'ótica',
    'nasal': 'nasal', 'inhalada': 'inhalada', 'sublingual': 'sublingual',
    # Estados de tratamiento
    'en curso': 'en curso', 'finalizado': 'finalizado', 'suspendido': 'suspendido',
    'completado': 'completado', 'cancelado': 'cancelado', 'pendiente': 'pendiente',
    # Tipos de atención
    'consulta general': 'consulta general', 'consulta de primera vez': 'primera vez',
    'consulta subsecuente': 'subsecuente', 'consulta de urgencia': 'urgencia',
    'consulta de control': 'control', 'consulta de seguimiento': 'seguimiento',
    # Modalidades
    'presencial': 'presencial', 'a distancia': 'a distancia',
    'telemedicina': 'telemedicina', 'domicilio': 'domicilio',
    # Tipos de establecimiento
    'consultorio': 'consultorio', 'clínica': 'clínica', 'hospital': 'hospital',
    'centro de salud': 'centro de salud', 'unidad médica': 'unidad médica',
    'sanatorio': 'sanatorio', 'laboratorio': 'laboratorio',
    'farmacia': 'farmacia', 'consultorio dental': 'consultorio dental',
    # Especialidades
    'medicina general': 'medicina general', 'medicina interna': 'medicina interna',
    'pediatría': 'pediatría', 'ginecología': 'ginecología',
    'obstetricia': 'obstetricia', 'cirugía': 'cirugía',
    'ortopedia': 'ortopedia', 'traumatología': 'traumatología',
    'cardiología': 'cardiología', 'neurología': 'neurología',
    'psiquiatría': 'psiquiatría', 'dermatología': 'dermatología',
    'oftalmología': 'oftalmología', 'otorrinolaringología': 'otorrinolaringología',
    'urología': 'urología', 'nefrología': 'nefrología',
    'gastroenterología': 'gastroenterología', 'endocrinología': 'endocrinología',
    'neumología': 'neumología', 'reumatología': 'reumatología',
    'oncología': 'oncología', 'hematología': 'hematología',
    'infectología': 'infectología', 'geriatría': 'geriatría',
    'medicina familiar': 'medicina familiar', 'medicina del trabajo': 'medicina del trabajo',
    'medicina deportiva': 'medicina deportiva', 'medicina legal': 'medicina legal',
    'medicina forense': 'medicina forense', 'patología': 'patología',
    'radiología': 'radiología', 'anestesiología': 'anestesiología',
    'medicina crítica': 'medicina crítica', 'terapia intensiva': 'terapia intensiva',
    'urgencias': 'urgencias', 'medicina de urgencias': 'medicina de urgencias',
    # Tipos de personal de salud
    'médico': 'médico', 'doctor': 'médico', 'doctora': 'médico',
    'enfermera': 'enfermería', 'enfermero': 'enfermería',
    'pasante': 'pasante', 'internista': 'internista',
    'residente': 'residente', 'especialista': 'especialista',
    'subespecialista': 'subespecialista', 'técnico': 'técnico',
    'auxiliar': 'auxiliar', 'recepcionista': 'recepcionista',
    'administrador': 'administrador', 'director': 'director',
    # Formación académica
    'médico cirujano': 'médico cirujano', 'licenciatura': 'licenciatura',
    'especialidad': 'especialidad', 'subespecialidad': 'subespecialidad',
    'maestría': 'maestría', 'doctorado': 'doctorado',
    'diplomado': 'diplomado', 'curso': 'curso', 'certificación': 'certificación',
    # Material de curación
    'gasa': 'gasa', 'venda': 'venda', 'esparadrapo': 'esparadrapo',
    'algodón': 'algodón', 'alcohol': 'alcohol', 'yodo': 'yodo',
    'agua oxigenada': 'agua oxigenada', 'suero fisiológico': 'suero fisiológico',
    'solución salina': 'solución salina', 'guantes': 'guantes',
    'jeringa': 'jeringa', 'aguja': 'aguja', 'catéter': 'catéter',
    'sonda': 'sonda', 'tubo': 'tubo', 'bolsa': 'bolsa',
    'curita': 'curita', 'apósito': 'apósito', 'venda elástica': 'venda elástica',
    'venda de yeso': 'venda de yeso', 'yeso': 'yeso',
    # Instrumental
    'estetoscopio': 'estetoscopio', 'termómetro': 'termómetro',
    'esfigmomanómetro': 'esfigmomanómetro', 'oxímetro': 'oxímetro',
    'glucómetro': 'glucómetro', 'nebulizador': 'nebulizador',
    'aspirador': 'aspirador', 'ambú': 'ambú', 'laringoscopio': 'laringoscopio',
    'otoscopio': 'otoscopio', 'oftalmoscopio': 'oftalmoscopio',
    'reflejómetro': 'reflejómetro', 'tijeras': 'tijeras',
    'pinzas': 'pinzas', 'bisturí': 'bisturí', 'aguja': 'aguja',
    'jeringa': 'jeringa', 'catéter': 'catéter', 'sonda': 'sonda',
    'tubo': 'tubo', 'bolsa': 'bolsa', 'curita': 'curita',
    # Tipos de consulta
    'primera vez': 'primera vez', 'subsecuente': 'subsecuente',
    'control': 'control', 'seguimiento': 'seguimiento',
    'urgencia': 'urgencia', 'emergencia': 'emergencia',
    # Tipos de atención
    'consulta': 'consulta', 'procedimiento': 'procedimiento',
    'cirugía': 'cirugía', 'hospitalización': 'hospitalización',
    'observación': 'observación', 'urgencia': 'urgencia',
    # Tipos de establecimiento
    'consultorio': 'consultorio', 'clínica': 'clínica', 'hospital': 'hospital',
    'centro de salud': 'centro de salud', 'unidad médica': 'unidad médica',
    'sanatorio': 'sanatorio', 'laboratorio': 'laboratorio',
    'farmacia': 'farmacia', 'consultorio dental': 'consultorio dental',
    # Entidades federativas
    'aguascalientes': 'Aguascalientes', 'baja california': 'Baja California',
    'baja california sur': 'Baja California Sur', 'campeche': 'Campeche',
    'chiapas': 'Chiapas', 'chihuahua': 'Chihuahua', 'coahuila': 'Coahuila',
    'colima': 'Colima', 'ciudad de méxico': 'Ciudad de México',
    'cdmx': 'Ciudad de México', 'durango': 'Durango', 'guanajuato': 'Guanajuato',
    'guerrero': 'Guerrero', 'hidalgo': 'Hidalgo', 'jalisco': 'Jalisco',
    'michoacán': 'Michoacán', 'morelos': 'Morelos', 'nayarit': 'Nayarit',
    'nuevo león': 'Nuevo León', 'oaxaca': 'Oaxaca', 'puebla': 'Puebla',
    'querétaro': 'Querétaro', 'quintana roo': 'Quintana Roo',
    'san luis potosí': 'San Luis Potosí', 'sinaloa': 'Sinaloa',
    'sonora': 'Sonora', 'tabasco': 'Tabasco', 'tamaulipas': 'Tamaulipas',
    'tlaxcala': 'Tlaxcala', 'veracruz': 'Veracruz', 'yucatán': 'Yucatán',
    'zacatecas': 'Zacatecas',
    # Nacionalidades
    'mexicana': 'mexicana', 'mexicano': 'mexicano', 'estadounidense': 'estadounidense',
    'española': 'española', 'español': 'español', 'guatemalteca': 'guatemalteca',
    'hondureña': 'hondureña', 'salvadoreña': 'salvadoreña',
    'colombiana': 'colombiana', 'venezolana': 'venezolana',
    'argentina': 'argentina', 'brasileña': 'brasileña', 'chilena': 'chilena',
    'peruana': 'peruana', 'ecuatoriana': 'ecuatoriana',
    # Religiones
    'católica': 'católica', 'católico': 'católico', 'protestante': 'protestante',
    'evangélica': 'evangélica', 'testigo de jehová': 'testigo de Jehová',
    'mormón': 'mormón', 'judía': 'judía', 'musulmana': 'musulmana',
    'budista': 'budista', 'atea': 'atea', 'agnóstica': 'agnóstica',
    'sin religión': 'sin religión', 'otra': 'otra',
    # Lenguas indígenas
    'náhuatl': 'náhuatl', 'maya': 'maya', 'mixteco': 'mixteco',
    'zapoteco': 'zapoteco', 'tzeltal': 'tzeltal', 'tzotzil': 'tzotzil',
    'otomí': 'otomí', 'totonaco': 'totonaco', 'mazateco': 'mazateco',
    'chinanteco': 'chinanteco', 'mixe': 'mixe', 'triqui': 'triqui',
    'purépecha': 'purépecha', 'tarahumara': 'tarahumara', 'yaqui': 'yaqui',
    'seri': 'seri', 'kumiai': 'kumiai', 'cucapá': 'cucapá',
}

def normalizar_sintoma(texto):
    t = texto.lower()
    for jerga, clinico in SINTOMAS_MEXICANOS.items():
        if jerga in t:
            return clinico
    return texto

def extraer_campos(texto):
    campos = {}
    t = texto.lower().strip()
    if not t:
        return campos

    # CURP
    m = re.search(r'\b([A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d)\b', texto, re.IGNORECASE)
    if m:
        campos['curp'] = m.group(1).upper()

    # Teléfono (10 dígitos, corrizos o agrupados, cerca de la palabra teléfono/celular)
    def telefonoDe(fragmento):
        """Del fragmento dado, devuelve los 10 dígitos del teléfono o None."""
        corridos = re.findall(r'\d[\d\s]{8,16}\d', fragmento)
        for corrido in corridos:
            d = re.sub(r'\D', '', corrido)
            if len(d) == 10:
                return d[:2] + ' ' + d[2:6] + ' ' + d[6:]
        return None

    m = re.search(r'(?:tel[ée]fono|celular|n[úu]mero|whatsapp)[^0-9]*(\d[\d\s]{8,16}\d)', t)
    if m:
        tel = telefonoDe(m.group(0))
        if tel:
            campos['telefono'] = tel
    if 'telefono' not in campos:
        m = re.search(r'\b(\d{2}\s?\d{4}\s?\d{4})\b', t)
        if m:
            tel = telefonoDe(m.group(1))
            if tel:
                campos['telefono'] = tel

    # Correo: se reconstruye el correo HABLADO ("juan arroba gmail punto com",
    # o "mario.calderon arroba gmail.com" cuando la transcription ya mete puntos).
    def correo_hablado(t):
        m = re.search(r"correos?\s+(?:electr[oó]nico|electronico)\s*(?:es|ser[ií]a|son)?\s*[:;,]?\s*|correos?\s+(?:es|ser[ií]a)\s*[:;,]?\s*|email\s*(?:es|ser[ií]a)?\s*[:;,]?\s*", t)
        if not m:
            return None
        frag = t[m.end():].strip()
        frag = re.sub(r"gui[oó]n\s+bajo|guion\s+bajo", "_", frag)
        frag = re.sub(r"gui[oó]n\s+medio|guion\s+medio", "-", frag)
        toks = re.findall(r"arroba|punto|@|[a-z0-9._%+-]+", frag)
        TLDS = {"com", "mx", "net", "org", "gob", "edu", "es", "cl", "me", "io", "info", "biz", "tv"}
        acumulado = ""
        for tok in toks:
            if tok == "arroba" or tok == "@":
                if "@" in acumulado:
                    return None
                acumulado += "@"
            elif tok == "punto":
                acumulado += "."
            else:
                acumulado += tok
            if len(acumulado) > 64 or not re.match(r"^[a-z0-9._%+-]*(@[a-z0-9.-]*)?$", acumulado):
                return None   # ya no se parece a un correo: se corta
            if "@" in acumulado and re.search(r"\.[a-z]{2,6}$", acumulado) and acumulado.split(".")[-1] in TLDS:
                return acumulado
        return None

    correo = correo_hablado(t)
    if correo:
        campos['correo'] = correo
    else:
        m = re.search(r'\b([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b', texto)
        if m:
            campos['correo'] = m.group(1).lower()

    # Fecha de nacimiento: no confundir una fecha de síntomas o consulta con el nacimiento.
    marca_nacimiento = r'(?:fecha\s+(?:de\s+)?nacimiento|fecha\s+natal|cumplea(?:ños|nos)|nac[ií](?:[oó])?|nacid[oa])'
    m = re.search(r'\b' + marca_nacimiento + r'[^.;\n]{0,70}?(\d{1,2})\s*(?:de\s+)?(' + '|'.join(MESES.keys()) + r')\s*(?:de\s+|del\s+)?(\d{4})', t)
    if m:
        d, mes, a = int(m.group(1)), MESES[m.group(2)], int(m.group(3))
        from datetime import date
        if 1 <= d <= 31 and 1 <= mes <= 12 and 1900 <= a <= date.today().year:
            campos['fecha_nacimiento'] = f"{a:04d}-{mes:02d}-{d:02d}"

    # Sexo
    if re.search(r'\b(femenin[oa]|mujer|niña|señora|señorita|femenino)\b', t):
        campos['sexo'] = 'M'
    elif re.search(r'\b(masculin[oa]|hombre|niño|señor|masculino)\b', t):
        campos['sexo'] = 'H'

    # Grupo sanguíneo
    m = re.search(r'\b(O|A|B|AB)\s*(positivo|negativo|\+|-)\b', t, re.IGNORECASE)
    if m:
        grupo = m.group(1).upper() + ('+' if m.group(2).lower() in ('positivo', '+') else '-')
        if grupo in ('O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'):
            campos['grupo_sanguineo'] = grupo

    # Nombre y apellidos (convención mexicana: nombre(s) + primer apellido + segundo apellido)
    def capitalizar_nombre(s):
        return ' '.join(p.capitalize() for p in s.split())
    m = re.search(r'(?:paciente|señor[a]?|recibo a|atiendo a|el joven|la joven|el niño|la niña|la señora|el señor|me llamo|mi nombre es|soy)\s+([A-Za-záéíóúñÁÉÍÓÚÑ]+(?:\s+[A-Za-záéíóúñÁÉÍÓÚÑ]+){1,3})', texto, re.IGNORECASE)
    if m:
        partes = m.group(1).split()
        if len(partes) == 2:          # nombre + primer apellido
            campos['nombre'] = capitalizar_nombre(partes[0])
            campos['primer_apellido'] = capitalizar_nombre(partes[1])
        elif len(partes) == 3:        # nombre + apellido + apellido
            campos['nombre'] = capitalizar_nombre(partes[0])
            campos['primer_apellido'] = capitalizar_nombre(partes[1])
            campos['segundo_apellido'] = capitalizar_nombre(partes[2])
        elif len(partes) >= 4:        # nombre compuesto + apellido + apellido
            campos['nombre'] = capitalizar_nombre(' '.join(partes[:-2]))
            campos['primer_apellido'] = capitalizar_nombre(partes[-2])
            campos['segundo_apellido'] = capitalizar_nombre(partes[-1])

    # Alergias
    m = re.search(r'alérgic[oa]\s+(?:a\s+)?(?:el|la|los|las)?\s*(.+?)(?:\.|,|$)', t)
    if m:
        valor = m.group(1).strip()
        valor = re.sub(r'^(el|la|los|las)\s+', '', valor)
        # "penicilina y a la aspirina" → "penicilina, aspirina"
        valor = re.sub(r'\s+y\s+a\s+(?:la|el|los|las)\s+', ', ', valor)
        valor = re.sub(r'\s+y\s+(?:al|a\s+el)\s+', ', ', valor)
        campos['alergias'] = valor.strip().capitalize()

    # Padecimientos crónicos
    m = re.search(r'padece\s+(.+?)(?:\.|,|$)', t)
    if m:
        campos['padecimientos_cronicos'] = m.group(1).strip().capitalize()

    # Medicamentos actuales
    m = re.search(r'toma\s+(.+?)(?:\.|,|$)', t)
    if m:
        campos['medicamentos_actuales'] = m.group(1).strip().capitalize()

    # Motivo de consulta
    m = re.search(r'(?:viene por|acude por|motivo[:\s]+|consulta por|motivo de consulta[:\s]+)\s+(.+?)(?:\.|,|$)', t)
    if m:
        campos['motivo_consulta'] = m.group(1).strip().capitalize()

    # Domicilio (aceptado en primera y tercera persona, con o sin la palabra "calle")
    m = re.search(r'(?:mi\s+direcci[óo]n\s+es|mi\s+domicilio\s+es|vivo\s+en|vive\s+en|viviendo\s+en|habitamos\s+en|habito\s+en|domicilio\s*[:\s]|direcci[óo]n\s*[:\s])\s*(.+?)(?:\.|$)', t)
    if m:
        campos['domicilio'] = capitalizar_nombre(m.group(1).strip())
    else:
        # "calle X" sueltos (sin "vivo en")
        m = re.search(r'(?:en\s+la\s+)?calle\s+(.+?)(?:\.|,|$)', t)
        if m:
            campos['domicilio'] = 'Calle ' + capitalizar_nombre(m.group(1).strip())

    # Municipio / alcaldía (hablado)
    m = re.search(r'(?:municipios?|alcald[ií]a|delegaci[oó]n)\s+(?:de\s+)?([^\.]{3,80})', t)
    if m:
        campos['municipio_estado'] = m.group(1).strip().rstrip(',').capitalize()

    # Contacto de emergencia

    # Contacto de emergencia
    m = re.search(r'(?:contacto de emergencia|contacto emergencia)[:\s]+(.+?)(?:\.|,|$)', t)
    if m:
        campos['contacto_emergencia'] = m.group(1).strip().capitalize()

    # Teléfono de emergencia
    m = re.search(r'(?:teléfono de emergencia|tel emergencia)[:\s]+(.+?)(?:\.|,|$)', t)
    if m:
        tel = telefonoDe(m.group(1))
        if tel:
            campos['telefono_emergencia'] = tel

    # Repetimos el patrón antiguo por compatibilidad con dictados formales ("municipio: Coyoacán")
    m = re.search(r'municipio\s*:\s*(.+?)(?:\.|,|$)', t)
    if m and 'municipio_estado' not in campos:
        campos['municipio_estado'] = m.group(1).strip().capitalize()

    # Notas clínicas - secciones (expandido)
    secciones_clinicas = {
        'motivo_consulta': r'(?:motivo de consulta|m\.? de consulta)[:\s]+(.+?)(?:\.\s*(?:padecimiento|exploración|diagnóstico|plan|nota)|$)',
        'padecimiento_actual': r'(?:padecimiento actual|p\.? actual)[:\s]+(.+?)(?:\.\s*(?:exploración|diagnóstico|plan|nota)|$)',
        'exploracion_fisica': r'(?:exploración física|exploración)[:\s]+(.+?)(?:\.\s*(?:diagnóstico|plan|nota)|$)',
        'diagnostico': r'(?:diagnóstico)[:\s]+(.+?)(?:\.\s*(?:plan|nota|pronóstico)|$)',
        'plan_tratamiento': r'(?:plan y tratamiento|plan)[:\s]+(.+?)(?:\.\s*(?:pronóstico|nota)|$)',
        'nota_evolucion': r'(?:nota de evolución|evolución)[:\s]+(.+?)(?:\.\s*(?:plan|pronóstico)|$)',
        'pronostico': r'(?:pronóstico)[:\s]+(.+?)(?:\.|$)',
    }
    for campo, patron in secciones_clinicas.items():
        m = re.search(patron, t, re.IGNORECASE | re.DOTALL)
        if m:
            campos[campo] = m.group(1).strip().capitalize()

    # Signos vitales
    m = re.search(r'(?:tensión arterial|ta|presión)[:\s]+(\d{2,3})\s*/\s*(\d{2,3})', t)
    if m:
        campos['ta_sistolica'] = m.group(1)
        campos['ta_diastolica'] = m.group(2)
    m = re.search(r'(?:frecuencia cardíaca|fc|pulso)[:\s]+(\d{2,3})', t)
    if m:
        campos['frecuencia_cardiaca'] = m.group(1)
    m = re.search(r'(?:temperatura|temp)[:\s]+(\d{2,3}\.?\d?)', t)
    if m:
        campos['temperatura'] = m.group(1)
    m = re.search(r'(?:frecuencia respiratoria|fr)[:\s]+(\d{1,2})', t)
    if m:
        campos['frecuencia_respiratoria'] = m.group(1)
    m = re.search(r'(?:saturación|sato2|spo2)[:\s]+(\d{2,3})', t)
    if m:
        campos['saturacion_o2'] = m.group(1)
    m = re.search(r'(?:peso)[:\s]+(\d{2,3}\.?\d?)', t)
    if m:
        campos['peso'] = m.group(1)
    m = re.search(r'(?:talla|altura)[:\s]+(\d{1,3}\.?\d?)', t)
    if m:
        campos['talla'] = m.group(1)
    m = re.search(r'(?:glucosa)[:\s]+(\d{2,3}\.?\d?)', t)
    if m:
        campos['glucosa'] = m.group(1)

    # Normalizar jerga mexicana en campos de texto
    for campo in ['motivo_consulta', 'padecimiento_actual', 'exploracion_fisica', 'diagnostico', 'plan_tratamiento', 'nota_evolucion', 'pronostico', 'alergias', 'padecimientos_cronicos', 'medicamentos_actuales']:
        if campo in campos:
            campos[campo] = normalizar_sintoma(campos[campo])

    # ============================================================
    # NUEVO: Detección de sección del expediente
    # ============================================================
    seccion = detectar_seccion(texto)
    campos['seccion'] = seccion['principal']
    campos['seccion_confianza'] = round(seccion['confianza'], 2)
    campos['secciones_sub'] = seccion['subsecciones']

    # ============================================================
    # NUEVO: Identificación de hablante
    # ============================================================
    hablante = detectar_hablante(texto)
    campos['hablante_rol'] = hablante['rol']
    campos['hablante_genero'] = hablante['genero']
    campos['hablante_confianza'] = hablante.get('confianza', 0.0)
    campos['hablante_se_autoidentifica'] = hablante.get('se_autoidentifica', False)
    campos['hablante_mencionado_por_tercero'] = hablante.get('mencionado_por_tercero', False)

    # ============================================================
    # NUEVO: Contexto conversacional
    # ============================================================
    contexto = analizar_contexto_conversacional(texto)
    campos['contexto_tipo'] = contexto['tipo']
    campos['contexto_direccion'] = contexto['direccion']
    campos['contexto_tiempo_verbal'] = contexto['tiempo_verbal']
    campos['contexto_es_pregunta'] = contexto['es_pregunta']
    campos['contexto_es_respuesta'] = contexto['es_respuesta']
    campos['contexto_es_instruccion'] = contexto['es_instruccion']
    campos['contexto_es_diagnostico'] = contexto['es_diagnostico']
    campos['contexto_es_prescripcion'] = contexto['es_prescripcion']
    campos['contexto_urgencia'] = contexto['urgencia']

    return campos

# ----------------------------------------------------------------------------
# MODELO EN CACHÉ: se carga una sola vez al arrancar y se reutiliza en cada petición.
# ----------------------------------------------------------------------------
_modelo = None
_modelo_listo = threading.Event()
_modelo_error = ''
_lock_modelo = threading.Lock()


def _crear_modelo(dispositivo, tipo):
    """Carga el modelo desde la caché local.

    Sin `local_files_only` faster-whisper consulta HuggingFace al arrancar y, si la red
    va lenta o está cortada, se queda colgado sin responder (el .bat parecía «no hacer nada»).
    Solo si el modelo todavía no está descargado se permite ir a la red, una única vez.
    """
    from faster_whisper import WhisperModel
    try:
        return WhisperModel(MODELO, device=dispositivo, compute_type=tipo, local_files_only=True)
    except Exception as e:
        texto = str(e).lower()
        if 'local' in texto or 'not found' in texto or 'no such file' in texto or 'cannot find' in texto:
            print("El modelo no está en la caché local; descargándolo una vez…", flush=True)
            return WhisperModel(MODELO, device=dispositivo, compute_type=tipo)
        raise


def obtener_modelo():
    """Devuelve Whisper large-v3 precargado (GPU float16; CPU int8 si CUDA falla)."""
    global _modelo, _modelo_error
    if _modelo is not None:
        return _modelo
    with _lock_modelo:
        if _modelo is not None:
            return _modelo
        t = time.perf_counter()
        try:
            try:
                _modelo = _crear_modelo(DISPOSITIVO, TIPO_COMPUTO)
                print(f"Whisper large-v3 cargado en CUDA ({time.perf_counter() - t:.1f} s)", flush=True)
            except Exception as e:
                print(f"CUDA no disponible ({e}), cayendo a CPU int8…", flush=True)
                _modelo = _crear_modelo("cpu", "int8")
                print(f"Whisper large-v3 cargado en CPU ({time.perf_counter() - t:.1f} s)", flush=True)
            _modelo_error = ''
        except Exception as e:
            _modelo_error = str(e)
            print(f"ERROR al cargar el modelo: {e}", flush=True)
            raise
        finally:
            _modelo_listo.set()
    return _modelo


def transcribir_pcm(pcm):
    """Transcribe PCM 16 kHz mono float32 con Whisper en caché."""
    modelo = obtener_modelo()
    # Silencio o ruido casi nulo: no gastes GPU ni inventes palabras.
    if pcm is None or pcm.size == 0:
        return ""
    rms = float(np.sqrt(np.mean(np.square(pcm))))
    if rms < 0.003:
        return ""
    segments, _ = modelo.transcribe(
        pcm, language="es", beam_size=5,
        initial_prompt=PROMPT_CORTO, hotwords=HOTWORDS_CLINICOS,
        condition_on_previous_text=False,
        no_speech_threshold=0.6, log_prob_threshold=-1.0,
        compression_ratio_threshold=2.4,
        vad_filter=True,
        vad_parameters={"threshold": 0.55, "min_speech_duration_ms": 250,
                        "min_silence_duration_ms": 350, "speech_pad_ms": 250},
    )
    return texto_de_segmentos(segments)


def texto_de_segmentos(segments):
    """Descarta segmentos de baja confianza y frases de relleno típicas de audio/video."""
    partes = []
    for segmento in segments:
        texto = (getattr(segmento, "text", "") or "").strip()
        if not texto:
            continue
        # La confianza del segmento evita que pausas y ruido se conviertan en palabras.
        if getattr(segmento, "no_speech_prob", 0.0) >= 0.6:
            continue
        if getattr(segmento, "avg_logprob", 0.0) < -1.0:
            continue
        if getattr(segmento, "compression_ratio", 0.0) > 2.4:
            continue
        partes.append(texto)

    texto = " ".join(partes).strip()
    if not texto:
        return ""

    # Whisper suele completar silencio con despedidas de videos, anuncios o sesiones.
    patrones_relleno = (
        r"^(?:muchas gracias|gracias) por (?:su|tu) atención$",
        r"^(?:bienvenidos?|bienvenidas?) a (?:la|esta) sesión(?: de hoy)?$",
        r"^(?:seguimos|continuamos) con (?:la )?presentación$",
        r"^(?:para más información|más información en) enlace$",
        r"^(?:agradecimientos? a los asociados de este video|gracias por ver este video)$",
        r"^(?:ja ){2,}ja$",
        r"^(?:ja){2,}$",
    )
    frases = re.split(r"(?<=[.!?])\s+|,\s+(?=(?:bienvenid|seguimos|continuamos|para más información))", texto)
    utiles = []
    for frase in frases:
        plano = re.sub(r"https?://\S+|www\.\S+", " enlace ", frase.lower())
        plano = re.sub(r"[^a-záéíóúüñ0-9]+", " ", plano).strip()
        if not plano or re.fullmatch(r"(?:enlace|jaja?|ja+)(?:\s+(?:enlace|jaja?|ja+))*", plano):
            continue
        if any(re.fullmatch(patron, plano) for patron in patrones_relleno):
            continue
        utiles.append(frase.strip())
    return " ".join(utiles).strip()


def transcribir_wav_bytes(datos):
    """Trozo WAV recibido en vivo (16 kHz mono, 16 bits) → texto, sin tocar disco."""
    with wave.open(io.BytesIO(datos), 'rb') as w:
        if w.getframerate() != 16000 or w.getnchannels() != 1 or w.getsampwidth() != 2:
            return None
        import numpy as np
        pcm = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768.0
    if pcm.size < 1600:  # menos de 0.1 s: silencio o ruido, no vale la pena transcribir
        return ''
    return transcribir_pcm(pcm)


def transcribir_audio(audio_path):
    wav_path = audio_path
    if not audio_path.lower().endswith('.wav'):
        fd, wav_path = tempfile.mkstemp(suffix='.wav')
        os.close(fd)
        try:
            subprocess.run([FFMPEG, '-y', '-i', audio_path, '-ar', '16000', '-ac', '1', '-f', 'wav', wav_path],
                           capture_output=True, check=True, timeout=60)
        except Exception as e:
            return None, str(e)

    try:
        import numpy as np, wave
        with wave.open(wav_path, 'rb') as w:
            frames = w.readframes(w.getnframes())
            pcm = np.frombuffer(frames, dtype=np.int16).astype(np.float32) / 32768.0
        modelo = obtener_modelo()
        segments, _ = modelo.transcribe(
            pcm, language="es", beam_size=5,
            initial_prompt=PROMPT_CORTO, hotwords=HOTWORDS_CLINICOS,
            condition_on_previous_text=False,
            no_speech_threshold=0.6, log_prob_threshold=-1.0,
            compression_ratio_threshold=2.4,
            vad_filter=True,
            vad_parameters={"threshold": 0.55, "min_speech_duration_ms": 250,
                            "min_silence_duration_ms": 500, "speech_pad_ms": 350},
        )
        texto = texto_de_segmentos(segments)
        campos = extraer_campos(texto)
        return texto, campos
    except Exception as e:
        return None, str(e)
    finally:
        if wav_path != audio_path and os.path.exists(wav_path):
            os.remove(wav_path)

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        """Estado del servicio: sirve para comprobar rápido si el transcriptor está listo."""
        listo = _modelo_listo.is_set() and _modelo is not None
        self._responder(200, {
            "ok": listo,
            "modelo": "Traductor Cl�nico (large-v3)",`n            "nombre": "Traductor Cl�nico",
            "listo": listo,
            "error": _modelo_error,
        })

    def do_POST(self):
        content_length = int(self.headers.get('Content-Length', 0))
        if content_length == 0:
            self._responder(400, {"ok": False, "error": "No se recibió audio."})
            return

        # /en-vivo: trozos WAV cortos mientras el médico habla. Solo devuelve el texto
        # (sin extraer campos) para responder rápido; los campos se extraen al final en PHP.
        if self.path.rstrip('/') == '/en-vivo':
            if content_length > 4 * 1024 * 1024:
                self._responder(413, {"ok": False, "error": "Trozo de audio demasiado grande."})
                return
            chunk = self.rfile.read(content_length)
            try:
                texto = transcribir_wav_bytes(chunk)
            except Exception as e:
                self._responder(500, {"ok": False, "error": str(e)})
                return
            if texto is None:
                self._responder(415, {"ok": False, "error": "Se esperaba WAV 16 kHz mono de 16 bits."})
                return
            self._responder(200, {"ok": True, "texto": texto})
            return

        audio_data = self.rfile.read(content_length)
        fd, audio_path = tempfile.mkstemp(suffix='.webm')
        os.write(fd, audio_data)
        os.close(fd)

        texto, campos = transcribir_audio(audio_path)
        try:
            os.remove(audio_path)
        except:
            pass

        if texto is None:
            self._responder(500, {"ok": False, "error": campos})
            return

        self._responder(200, {
            "ok": True,
            "texto": texto,
            "campos": campos,
            "idioma": "es",
            "modelo": "whisper-large-v3"
        })

    def _responder(self, codigo, datos):
        self.send_response(codigo)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(datos, ensure_ascii=True).encode('utf-8'))

    def log_message(self, format, *args):
        pass

if __name__ == "__main__":
    # El servidor empieza a escuchar de inmediato y el modelo se carga en segundo plano:
    # así el puerto responde aunque el modelo tarde y nunca parece «muerto».
    threading.Thread(target=obtener_modelo, daemon=True).start()
    server = ThreadingHTTPServer(('127.0.0.1', PUERTO), Handler)
    print(f"Cargando modelo {MODELO}… el servicio ya escucha en 127.0.0.1:{PUERTO}", flush=True)
    print("Deja esta ventana abierta mientras uses el dictado.", flush=True)
    server.serve_forever()

