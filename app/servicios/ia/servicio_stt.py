#!/usr/bin/env python3
"""
Servicio local de transcripciÃ³n de voz (Whisper large-v3, faster-whisper sobre CUDA).
Mantiene el modelo cargado en memoria para respuestas rÃ¡pidas.
Escucha en 127.0.0.1:8766. Solo accesible desde el equipo local.

Vocabulario mÃ©dico en espaÃ±ol mexicano incluido como prompt inicial.
VersiÃ³n expandida: conversaciÃ³n natural doctor-paciente con detecciÃ³n
de secciones del expediente e identificaciÃ³n de hablante.
"""
import json, os, sys, subprocess, tempfile, re, time, io, wave, threading
from pathlib import Path
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

# Con pythonw.exe (arranque automÃ¡tico sin ventana) sys.stdout es None y print() falla.
# En ese caso se escribe todo a un log en la carpeta temporal.
if sys.stdout is None or sys.stderr is None:
    _log = open(os.path.join(tempfile.gettempdir(), 'expediente_stt.log'), 'a', encoding='utf-8', buffering=1)
    if sys.stdout is None:
        sys.stdout = _log
    if sys.stderr is None:
        sys.stderr = _log

MODELO = "large-v3"          # Systran/faster-whisper-large-v3 (cachÃ© de HuggingFace)
DISPOSITIVO = "cuda"         # GPU NVIDIA; si CUDA no estÃ¡ disponible, cae a CPU
TIPO_COMPUTO = "float16"     # float16 en GPU (int8 al caer a CPU)
FFMPEG = r"C:\ffmpeg\ffmpeg-9.0.2-essentials_build\bin\ffmpeg.exe"
PUERTO = 8766
VOCAB_PATH = str(Path(__file__).resolve().with_name("vocabulario_medico_es.txt"))

PROMPT_MEDICO = (
    "Expediente clÃ­nico mexicano. ConversaciÃ³n entre mÃ©dico y paciente. "
    "El mÃ©dico pregunta y el paciente responde. Vocabulario clÃ­nico en espaÃ±ol mexicano. "
    "Paciente con dolor de cabeza, fiebre, tos, nÃ¡usea, vÃ³mito, diarrea, mareo, fatiga, "
    "debilidad, dolor abdominal, dolor torÃ¡cico, disnea, hipertensiÃ³n, diabetes, obesidad, "
    "asma, EPOC, gastritis, colitis, paracetamol, ibuprofeno, amoxicilina, omeprazol, "
    "metformina, losartÃ¡n, exploraciÃ³n fÃ­sica, diagnÃ³stico, pronÃ³stico, plan de tratamiento, "
    "nota de evoluciÃ³n, signos vitales, tensiÃ³n arterial, frecuencia cardÃ­aca, "
    "frecuencia respiratoria, temperatura, saturaciÃ³n de oxÃ­geno, peso, talla, "
    "motivo de consulta, padecimiento actual, CIE-10, receta mÃ©dica, "
    "interconsulta, referencia, urgencias, hospitalizaciÃ³n, egreso, incapacidad. "
    "Jerga mexicana: chorrillo, pipÃ­, popo, panza, pata, soponcio, patatÃºs, calentura, "
    "catarro, gripe, estÃ³mago suelto, gases, acidez, ardor, comezÃ³n, ronchas, sarpullido, "
    "desmayo, ataque, escalofrÃ­os, presiÃ³n alta, presiÃ³n baja, azÃºcar alta, azÃºcar baja, "
    "anemia, infecciÃ³n, congestiÃ³n, mocos, tos con flema, tos seca, mareo, vÃ©rtigo, "
    "fatiga, cansancio, debilidad, falta de aire, ardor al orinar, orina con sangre, "
    "orina frecuente, embarazo, menstruaciÃ³n, regla, dolor menstrual. "
    "ConversaciÃ³n natural: me duele, tengo, soy, mi nombre es, tengo aÃ±os, "
    "no tengo, nunca he tenido, me siento, se me subiÃ³, se me bajÃ³, "
    "me dio, me dio chorrillo, me dio la vuelta, me dio un patatÃºs, "
    "me dio un soponcio, me dio un ataque, me dio un infarto, me dio un derrame. "
    "Frases del mÃ©dico: Â¿quÃ© te duele?, Â¿cuÃ¡ntos aÃ±os tienes?, Â¿eres alÃ©rgico?, "
    "Â¿tomas medicamentos?, Â¿tienes antecedentes?, Â¿quÃ© te trae?, Â¿desde cuÃ¡ndo?, "
    "Â¿ha tenido fiebre?, Â¿ha vomitado?, Â¿ha tenido diarrea?, Â¿ha tenido mareo?, "
    "a la exploraciÃ³n, se observa, se palpa, se ausculta, se percute, "
    "impresiÃ³n diagnÃ³stica, plan de manejo, se indica, se prescribe, se ordena, "
    "pronÃ³stico favorable, pronÃ³stico reservado, pronÃ³stico grave. "
    "Medicamentos: paracetamol, acetaminofÃ©n, ibuprofeno, naproxeno, diclofenaco, "
    "ketorolaco, tramadol, codeÃ­na, morfina, fentanyl, amoxicilina, ampicilina, "
    "penicilina, cefalexina, ceftriaxona, azitromicina, claritromicina, eritromicina, "
    "ciprofloxacino, levofloxacino, metronidazol, trimetoprim-sulfametoxazol, "
    "omeprazol, pantoprazol, ranitidina, metoclopramida, metformina, glibenclamida, "
    "insulina, sitagliptina, losartÃ¡n, enalapril, amlodipino, hidroclorotiazida, "
    "furosemida, atenolol, metoprolol, carvedilol, bisoprolol, atorvastatina, "
    "simvastatina, rosuvastatina, salbutamol, budesonida, fluticasona, montelukast, "
    "prednisona, dexametasona, hidrocortisona, clonazepam, alprazolam, diazepam, "
    "lorazepam, sertralina, fluoxetina, paroxetina, citalopram, escitalopram, "
    "amitriptilina, imipramina, venlafaxina, duloxetina, haloperidol, risperidona, "
    "olanzapina, quetiapina, carbamazepina, fenitoÃ­na, Ã¡cido valproico, lamotrigina, "
    "levetiracetam, gabapentina, pregabalina, topiramato, levotiroxina, metimazol, "
    "propiltiouracilo, Ã¡cido fÃ³lico, hierro, vitamina B12, vitamina D, calcio, "
    "warfarina, heparina, enoxaparina, rivaroxabÃ¡n, apixabÃ¡n, aspirina, clopidogrel, "
    "ticagrelor, ondansetrÃ³n, metoclopramida, domperidona, loperamida, racecadotrilo, "
    "loratadina, cetirizina, clorfeniramina, difenhidramina, pseudoefedrina, "
    "fenilefrina, nafazolina, oximetazolina, clotrimazol, ketoconazol, fluconazol, "
    "itraconazol, aciclovir, valaciclovir, oseltamivir, ivermectina, albendazol, "
    "mebendazol, metronidazol, tinidazol, doxiciclina, tetraciclina, gentamicina, "
    "amikacina, vancomicina, clindamicina, linezolid, rifampicina, isoniazida, "
    "pirazinamida, etambutol, benzodiazepinas, opioides, antiinflamatorios no "
    "esteroideos, AINEs, antibiÃ³ticos, antivirales, antifÃºngicos, antiparasitarios, "
    "antihipertensivos, antidiabÃ©ticos, anticoagulantes, antidepresivos, "
    "antipsicÃ³ticos, anticonvulsivantes, broncodilatadores, corticosteroides, "
    "antihistamÃ­nicos, analgÃ©sicos, antipirÃ©ticos, antiemÃ©ticos, antidiarreicos, "
    "laxantes, diurÃ©ticos, vitaminas, suplementos. "
    "Enfermedades: diabetes mellitus, hipertensiÃ³n arterial, obesidad, dislipidemia, "
    "sÃ­ndrome metabÃ³lico, hipotiroidismo, hipertiroidismo, anemia ferropÃ©nica, "
    "anemia megaloblÃ¡stica, leucemia, linfoma, trombocitopenia, hemofilia, "
    "enfermedad de von Willebrand, asma bronquial, EPOC, neumonÃ­a, tuberculosis, "
    "bronquitis, sinusitis, rinitis alÃ©rgica, faringitis, laringitis, otitis media, "
    "conjuntivitis, blefaritis, queratitis, glaucoma, cataratas, gastritis, "
    "Ãºlcera pÃ©ptica, enfermedad por reflujo gastroesofÃ¡gico, sÃ­ndrome de intestino "
    "irritable, colitis ulcerosa, enfermedad de Crohn, hepatitis A, hepatitis B, "
    "hepatitis C, cirrosis hepÃ¡tica, colelitiasis, colecistitis, pancreatitis, "
    "apendicitis, infecciÃ³n de vÃ­as urinarias, cistitis, pielonefritis, nefrolitiasis, "
    "hiperplasia prostÃ¡tica benigna, prostatitis, epididimitis, vaginitis, cervicitis, "
    "enfermedad pÃ©lvica inflamatoria, endometriosis, miomatosis uterina, "
    "sÃ­ndrome de ovario poliquÃ­stico, embarazo, preeclampsia, eclampsia, "
    "diabetes gestacional, artritis reumatoide, osteoartritis, osteoporosis, "
    "fibromialgia, lupus eritematoso sistÃ©mico, esclerosis mÃºltiple, migraÃ±a, "
    "cefalea tensional, epilepsia, enfermedad de Parkinson, enfermedad de Alzheimer, "
    "depresiÃ³n, ansiedad, trastorno bipolar, esquizofrenia, trastorno de dÃ©ficit de "
    "atenciÃ³n, autismo, dermatitis atÃ³pica, psoriasis, acnÃ©, herpes zÃ³ster, "
    "herpes simple, varicela, sarampiÃ³n, rubÃ©ola, parotiditis, escarlatina, "
    "dengue, zika, chikungunya, COVID-19, influenza, VIH/SIDA, sÃ­filis, gonorrea, "
    "clamidia, herpes genital, cÃ¡ncer de mama, cÃ¡ncer de prÃ³stata, cÃ¡ncer de colon, "
    "cÃ¡ncer de pulmÃ³n, cÃ¡ncer de estÃ³mago, cÃ¡ncer de hÃ­gado, cÃ¡ncer de pÃ¡ncreas, "
    "cÃ¡ncer de ovario, cÃ¡ncer de Ãºtero, cÃ¡ncer de testÃ­culo, cÃ¡ncer de piel, "
    "melanoma, cÃ¡ncer de tiroides, cÃ¡ncer de riÃ±Ã³n, cÃ¡ncer de vejiga, cÃ¡ncer de "
    "cerebro, cÃ¡ncer de hueso, fractura, luxaciÃ³n, esguince, contusiÃ³n, herida, "
    "quemadura, traumatismo craneoencefÃ¡lico, traumatismo torÃ¡cico, traumatismo abdominal. "
    "Entidades: Aguascalientes, Baja California, Baja California Sur, Campeche, "
    "Chiapas, Chihuahua, Coahuila, Colima, Ciudad de MÃ©xico, Durango, Guanajuato, "
    "Guerrero, Hidalgo, Jalisco, MichoacÃ¡n, Morelos, Nayarit, Nuevo LeÃ³n, Oaxaca, "
    "Puebla, QuerÃ©taro, Quintana Roo, San Luis PotosÃ­, Sinaloa, Sonora, Tabasco, "
    "Tamaulipas, Tlaxcala, Veracruz, YucatÃ¡n, Zacatecas. "
    "Nacionalidades: mexicana, estadounidense, espaÃ±ola, guatemalteca, hondureÃ±a, "
    "salvadoreÃ±a, colombiana, venezolana, argentina, brasileÃ±a, chilena, peruana, "
    "ecuatoriana. "
    "Religiones: catÃ³lica, protestante, evangÃ©lica, testigo de JehovÃ¡, mormÃ³n, "
    "judÃ­a, musulmana, budista, atea, agnÃ³stica, sin religiÃ³n. "
    "Lenguas indÃ­genas: nÃ¡huatl, maya, mixteco, zapoteco, tzeltal, tzotzil, "
    "otomÃ­, totonaco, mazateco, chinanteco, mixe, triqi, purÃ©pecha, tarahumara, "
    "yaqui, seri, kumiai, cucapÃ¡. "
    "Especialidades: medicina general, medicina interna, pediatrÃ­a, ginecologÃ­a, "
    "obstetricia, cirugÃ­a, ortopedia, traumatologÃ­a, cardiologÃ­a, neurologÃ­a, "
    "psiquiatrÃ­a, dermatologÃ­a, oftalmologÃ­a, otorrinolaringologÃ­a, urologÃ­a, "
    "nefrologÃ­a, gastroenterologÃ­a, endocrinologÃ­a, neumologÃ­a, reumatologÃ­a, "
    "oncologÃ­a, hematologÃ­a, infectologÃ­a, geriatrÃ­a, medicina familiar, "
    "medicina del trabajo, medicina deportiva, medicina legal, patologÃ­a, "
    "radiologÃ­a, anestesiologÃ­a, medicina crÃ­tica, terapia intensiva, urgencias. "
    "Tipos de personal: mÃ©dico, enfermera, pasante, internista, residente, "
    "especialista, subespecialista, tÃ©cnico, auxiliar, recepcionista, "
    "administrador, director. "
    "FormaciÃ³n acadÃ©mica: mÃ©dico cirujano, licenciatura, especialidad, "
    "subespecialidad, maestrÃ­a, doctorado, diplomado, curso, certificaciÃ³n. "
    "Material de curaciÃ³n: gasa, venda, esparadrapo, algodÃ³n, alcohol, yodo, "
    "agua oxigenada, suero fisiolÃ³gico, soluciÃ³n salina, guantes, jeringa, "
    "aguja, catÃ©ter, sonda, tubo, bolsa, curita, apÃ³sito, venda elÃ¡stica, "
    "venda de yeso, yeso. "
    "Instrumental: estetoscopio, termÃ³metro, esfigmomanÃ³metro, oxÃ­metro, "
    "glucÃ³metro, nebulizador, aspirador, ambÃº, laringoscopio, otoscopio, "
    "oftalmoscopio, reflejÃ³metro, tijeras, pinzas, bisturÃ­. "
    "Tipos de consulta: primera vez, subsecuente, control, seguimiento, "
    "urgencia, emergencia. "
    "Tipos de atenciÃ³n: consulta, procedimiento, cirugÃ­a, hospitalizaciÃ³n, "
    "observaciÃ³n, urgencia. "
    "Tipos de establecimiento: consultorio, clÃ­nica, hospital, centro de salud, "
    "unidad mÃ©dica, sanatorio, laboratorio, farmacia, consultorio dental. "
    "Formas farmacÃ©uticas: tabletas, cÃ¡psulas, grageas, soluciÃ³n, suspensiÃ³n, "
    "inyecciÃ³n, crema, pomada, gel, ungÃ¼ento, gotas, spray, inhalador, "
    "supositorio, Ã³vulo, parche, jarabe, elÃ­xir, tintura. "
    "VÃ­as de administraciÃ³n: oral, intravenosa, intramuscular, subcutÃ¡nea, "
    "tÃ³pica, rectal, vaginal, ocular, Ã³tica, nasal, inhalada, sublingual. "
    "Estados de tratamiento: en curso, finalizado, suspendido, completado, "
    "cancelado, pendiente. "
    "Entidades: Aguascalientes, Baja California, Baja California Sur, Campeche, "
    "Chiapas, Chihuahua, Coahuila, Colima, Ciudad de MÃ©xico, Durango, "
    "Guanajuato, Guerrero, Hidalgo, Jalisco, MichoacÃ¡n, Morelos, Nayarit, "
    "Nuevo LeÃ³n, Oaxaca, Puebla, QuerÃ©taro, Quintana Roo, San Luis PotosÃ­, "
    "Sinaloa, Sonora, Tabasco, Tamaulipas, Tlaxcala, Veracruz, YucatÃ¡n, Zacatecas. "
    "Nacionalidades: mexicana, estadounidense, espaÃ±ola, guatemalteca, "
    "hondureÃ±a, salvadoreÃ±a, colombiana, venezolana, argentina, brasileÃ±a, "
    "chilena, peruana, ecuatoriana. "
    "Religiones: catÃ³lica, protestante, evangÃ©lica, testigo de JehovÃ¡, mormÃ³n, "
    "judÃ­a, musulmana, budista, atea, agnÃ³stica, sin religiÃ³n, otra. "
    "Lenguas indÃ­genas: nÃ¡huatl, maya, mixteco, zapoteco, tzeltal, tzotzil, "
    "otomÃ­, totonaco, mazateco, chinanteco, mixe, triqi, purÃ©pecha, "
    "tarahumara, yaqui, seri, kumiai, cucapÃ¡."
)

PROMPT_CORTO = (
    "Expediente clÃ­nico mexicano. ConversaciÃ³n mÃ©dico-paciente. "
    "Motivo de consulta, padecimiento actual, exploraciÃ³n fÃ­sica, diagnÃ³stico, "
    "plan de tratamiento, nota de evoluciÃ³n, pronÃ³stico. "
    "Medicamentos: paracetamol, ibuprofeno, amoxicilina, omeprazol, metformina, "
    "losartÃ¡n, salbutamol, prednisona. Dosis: miligramos, cada ocho horas, vÃ­a oral."
)

# TÃ©rminos que el modelo confunde seguido. Se pasan como "hotwords": sesgan la
# transcripciÃ³n sin alargar el prompt inicial (un prompt enorme vuelve lento a Whisper).
HOTWORDS_CLINICOS = (
    "motivo de consulta, padecimiento actual, exploraciÃ³n fÃ­sica, diagnÃ³stico, "
    "plan de tratamiento, nota de evoluciÃ³n, pronÃ³stico, signos vitales, "
    "paracetamol, ibuprofeno, metamizol, ketorolaco, diclofenaco, amoxicilina, "
    "azitromicina, cefalexina, omeprazol, metformina, losartÃ¡n, enalapril, "
    "amlodipino, atorvastatina, furosemida, levotiroxina, salbutamol, prednisona, "
    "sertralina, clonazepam, loratadina, ondansetrÃ³n"
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
                    # Detectar marcadores de conversaciÃ³n (contienen :)
                    elif ':' in linea and '_MARCADOR' in linea:
                        conversacion.add(linea)
                    else:
                        terminos.add(linea)
        except Exception as e:
            print(f"Advertencia: no se pudo cargar vocabulario: {e}", flush=True)
    return terminos, secciones, hablantes, conversacion

VOCAB_TERMINOS, VOCAB_SECCIONES, VOCAB_HABLANTES, VOCAB_CONVERSACION = cargar_vocabulario()
print(f"Vocabulario cargado: {len(VOCAB_TERMINOS)} tÃ©rminos, {len(VOCAB_SECCIONES)} secciones, {len(VOCAB_HABLANTES)} hablantes", flush=True)

# ----------------------------------------------------------------------------
# DETECCIÃ“N DE HABLANTE (mÃ©dico/paciente, hombre/mujer)
# ----------------------------------------------------------------------------
def _plano(texto):
    """MinÃºsculas y sin acentos: 'MÃ©dica' y 'medica' se comparan igual."""
    return texto.lower().translate(str.maketrans('Ã¡Ã©Ã­Ã³ÃºÃ¼Ã±', 'aeiouun'))


def _menciona(texto_plano, frases):
    """Frases presentes como palabra(s) completa(s).

    Antes se usaba `frase in texto`, y asÃ­ 'medica' coincidÃ­a con 'medicamento' y 'el'
    con cualquier palabra. Con lÃ­mites de palabra eso ya no pasa.
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
    """Identifica quiÃ©n estÃ¡ hablando: mÃ©dico o paciente, hombre o mujer.

    Devuelve ademÃ¡s `confianza` (0â€“1) para que el cliente sepa si el dato es firme
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

    # GÃ©nero. Solo palabras realmente marcadas; 'paciente' es neutro y 'el'/'la'
    # aparecen en cualquier frase, asÃ­ que no cuentan.
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

    # AutoidentificaciÃ³n y menciÃ³n por tercero (con lÃ­mites de palabra).
    if re.search(r'(?<![a-z0-9])yo soy (el |la )?(doctor|doctora|medico|medica|paciente|pacienta|mujer|hombre|enfermera|enfermero)(?![a-z0-9])', t):
        hablante['se_autoidentifica'] = True
    if re.search(r'(?<![a-z0-9])(el|la) (doctor|doctora|medico|medica|paciente|pacienta) (dice|comenta|pregunta|refiere|responde)(?![a-z0-9])', t):
        hablante['mencionado_por_tercero'] = True

    # Confianza: una pista aislada es dÃ©bil; varias palabras concordantes la suben.
    pistas = n_medico + n_paciente + n_femenino + n_masculino
    if hablante['rol'] == 'desconocido' and hablante['genero'] == 'desconocido':
        hablante['confianza'] = 0.0
    else:
        hablante['confianza'] = round(min(0.35 + 0.15 * pistas, 0.95), 2)
    if hablante['se_autoidentifica']:
        hablante['confianza'] = round(min(hablante['confianza'] + 0.2, 0.99), 2)
    return hablante

# ----------------------------------------------------------------------------
# DETECCIÃ“N DE SECCIÃ“N DEL EXPEDIENTE
# ----------------------------------------------------------------------------
def detectar_seccion(texto):
    """Detecta a quÃ© secciÃ³n del expediente electrÃ³nico pertenece el texto."""
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
            'exploracion fisica', 'exploraciÃ³n fÃ­sica', 'examen fisico',
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
# ANÃLISIS DE CONTEXTO CONVERSACIONAL
# ----------------------------------------------------------------------------
def analizar_contexto_conversacional(texto):
    """Analiza el contexto conversacional mÃ©dico-paciente."""
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
    if re.search(r'\b(Â¿|\?)', texto) or re.search(r'\b(que|cual|cuales|cundo|donde|como|cuales|cuantos|cuantas)\b', t):
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

    # DirecciÃ³n de la conversaciÃ³n
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
    pasados = ['tuve', 'tuvo', 'tuvieron', 'estuve', 'estuvo', 'estuvieron', 'sentÃ­',
               'sintiÃ³', 'fui', 'fue', 'fueron', 'vine', 'vino', 'vinieron', 'pude',
               'pudo', 'pudieron', 'quise', 'quiso', 'quisieron', 'doliÃ³', 'dolieron',
               'dio', 'dieron', 'empezÃ³', 'empezaron', 'comenzÃ³', 'comenzaron']
    futuros = ['tendrÃ©', 'tendrÃ¡', 'tendremos', 'tendrÃ¡n', 'estarÃ©', 'estarÃ¡',
               'estaremos', 'estarÃ¡n', 'sentirÃ©', 'sentirÃ¡', 'ire', 'irÃ¡', 'iremos',
               'irÃ¡n', 'vendrÃ©', 'vendrÃ¡', 'podrÃ©', 'podrÃ¡', 'necesitarÃ©', 'necesitarÃ¡']
    condicionales = ['quisiera', 'quisieran', 'me gustarÃ­a', 'podrÃ­a', 'deberÃ­a',
                     'serÃ­a', 'estarÃ­a', 'tendrÃ­a', 'satisfarÃ­a']
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
    'dolor de crÃ¡neo': 'cefalea', 'dolor en la cabeza': 'cefalea',
    'jaqueca': 'migraÃ±a', 'migraÃ±a': 'migraÃ±a', 'me duele la frente': 'cefalea frontal',
    'dolor frontal': 'cefalea frontal', 'dolor de sien': 'cefalea temporal',
    'dolor de nuca': 'cefalea occipital', 'dolor de coronilla': 'cefalea parietal',
    'me duele la nuca': 'cefalea occipital', 'me duele la sien': 'cefalea temporal',
    'me duele la frente': 'cefalea frontal', 'dolor en la frente': 'cefalea frontal',
    'dolor en la nuca': 'cefalea occipital', 'dolor en la sien': 'cefalea temporal',
    # Dolor abdominal
    'dolor de estÃ³mago': 'dolor abdominal', 'dolor de panza': 'dolor abdominal',
    'dolor abdominal': 'dolor abdominal', 'me duele el estÃ³mago': 'dolor abdominal',
    'me duele la panza': 'dolor abdominal', 'dolor en el estÃ³mago': 'dolor abdominal',
    'dolor en la panza': 'dolor abdominal', 'dolor de vientre': 'dolor abdominal',
    'me duele el vientre': 'dolor abdominal', 'dolor de barriga': 'dolor abdominal',
    'me duele la barriga': 'dolor abdominal', 'me duele el abdomen': 'dolor abdominal',
    'dolor en el abdomen': 'dolor abdominal', 'dolor en la barriga': 'dolor abdominal',
    'cÃ³lico': 'cÃ³lico abdominal', 'me duele con cÃ³lico': 'cÃ³lico abdominal',
    'me retuerce el estÃ³mago': 'cÃ³lico abdominal', 'me retuerce la panza': 'cÃ³lico abdominal',
    # Dolor torÃ¡cico
    'dolor de pecho': 'dolor torÃ¡cico', 'dolor torÃ¡cico': 'dolor torÃ¡cico',
    'me duele el pecho': 'dolor torÃ¡cico', 'dolor en el pecho': 'dolor torÃ¡cico',
    'dolor de tÃ³rax': 'dolor torÃ¡cico', 'me duele el tÃ³rax': 'dolor torÃ¡cico',
    'dolor retroesternal': 'dolor torÃ¡cico', 'me duele el esternÃ³n': 'dolor torÃ¡cico',
    'me duele el corazÃ³n': 'dolor precordial', 'dolor precordial': 'dolor precordial',
    'me duele el pecho al respirar': 'dolor pleurÃ­tico', 'dolor pleurÃ­tico': 'dolor pleurÃ­tico',
    # Odinofagia
    'dolor de garganta': 'odinofagia', 'me duele la garganta': 'odinofagia',
    'dolor en la garganta': 'odinofagia', 'garganta roja': 'faringitis',
    'garganta irritada': 'faringitis', 'garganta inflamada': 'faringitis',
    'garganta con pus': 'amigdalitis', 'amÃ­gdalas inflamadas': 'amigdalitis',
    'me duele al tragar': 'odinofagia', 'dolor al tragar': 'odinofagia',
    'me raspa la garganta': 'faringitis', 'raspa la garganta': 'faringitis',
    # Otalgia
    'dolor de oÃ­do': 'otalgia', 'me duele el oÃ­do': 'otalgia',
    'dolor en el oÃ­do': 'otalgia', 'me zumba el oÃ­do': 'tinnitus',
    'zumbido en el oÃ­do': 'tinnitus', 'zumbido': 'tinnitus',
    'se me tapÃ³ el oÃ­do': 'hipoacusia', 'no oigo bien': 'hipoacusia',
    'oÃ­do tapado': 'hipoacusia', 'dolor de oÃ­dos': 'otalgia',
    'me duele el oÃ­do izquierdo': 'otalgia izquierda', 'me duele el oÃ­do derecho': 'otalgia derecha',
    'me zumba la cabeza': 'tinnitus', 'zumbido en la cabeza': 'tinnitus',
    # Odontalgia
    'dolor de muela': 'odontalgia', 'me duele la muela': 'odontalgia',
    'dolor de diente': 'odontalgia', 'me duele el diente': 'odontalgia',
    'dolor dental': 'odontalgia', 'caries': 'caries dental',
    'dolor de encÃ­a': 'gingivitis', 'me duele la encÃ­a': 'gingivitis',
    'encÃ­a inflamada': 'gingivitis', 'encÃ­a sangrante': 'gingivorragia',
    # Lumbalgia
    'dolor de espalda': 'lumbalgia', 'me duele la espalda': 'lumbalgia',
    'dolor de cintura': 'lumbalgia', 'me duele la cintura': 'lumbalgia',
    'dolor de riÃ±Ã³n': 'lumbalgia', 'me duele el riÃ±Ã³n': 'lumbalgia',
    'dolor en la espalda': 'lumbalgia', 'dolor en la cintura': 'lumbalgia',
    'dolor lumbar': 'lumbalgia', 'me duele la lumbar': 'lumbalgia',
    'dolor de columna': 'lumbalgia', 'me duele la columna': 'lumbalgia',
    'dolor de cuello': 'cervicalgia', 'me duele el cuello': 'cervicalgia',
    'dolor cervical': 'cervicalgia', 'me duele la cervical': 'cervicalgia',
    # Artralgia
    'dolor de articulaciones': 'artralgia', 'me duele la articulaciÃ³n': 'artralgia',
    'dolor en las articulaciones': 'artralgia', 'me duelen las articulaciones': 'artralgia',
    'dolor articular': 'artralgia', 'articulaciones inflamadas': 'artritis',
    'me duelen las coyunturas': 'artralgia', 'dolor en las coyunturas': 'artralgia',
    'me duelen los huesos': 'ostealgia', 'dolor en los huesos': 'ostealgia',
    'dolor de huesos': 'ostealgia', 'me duele el hueso': 'ostealgia',
    # Oftalmalgia
    'dolor de ojo': 'oftalmalgia', 'me duele el ojo': 'oftalmalgia',
    'dolor en el ojo': 'oftalmalgia', 'me duele la vista': 'oftalmalgia',
    'veo borroso': 'visiÃ³n borrosa', 'veo doble': 'diplopÃ­a',
    'visiÃ³n borrosa': 'visiÃ³n borrosa', 'diplopÃ­a': 'diplopÃ­a',
    'ojo rojo': 'conjuntivitis', 'ojo irritado': 'conjuntivitis',
    'ojo hinchado': 'edema palpebral', 'ojo lloroso': 'epÃ­fora',
    'ojo seco': 'ojo seco', 'picazÃ³n de ojo': 'prurito ocular',
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
    'dolor de muÃ±eca': 'dolor en muÃ±eca', 'me duele la muÃ±eca': 'dolor en muÃ±eca',
    'dolor de dedo': 'dolor en dedo', 'me duele el dedo': 'dolor en dedo',
    'dolor de uÃ±a': 'paroniquia', 'me duele la uÃ±a': 'paroniquia',
    'dolor de vagina': 'vaginodinia', 'me duele la vagina': 'vaginodinia',
    'dolor de ano': 'anodinia', 'me duele el ano': 'anodinia',
    'dolor de recto': 'proctalgia', 'me duele el recto': 'proctalgia',
    'dolor de testÃ­culo': 'orquialgia', 'me duele el testÃ­culo': 'orquialgia',
    'dolor de pene': 'penalgia', 'me duele el pene': 'penalgia',
    'dolor de prÃ³stata': 'prostatodinia', 'me duele la prÃ³stata': 'prostatodinia',
    'dolor de Ãºtero': 'histeralgia', 'me duele el Ãºtero': 'histeralgia',
    'dolor de ovario': 'ovarialgia', 'me duele el ovario': 'ovarialgia',
    'dolor de vejiga': 'cistalgia', 'me duele la vejiga': 'cistalgia',
    'dolor de riÃ±Ã³n': 'nefralgia', 'me duele el riÃ±Ã³n': 'nefralgia',
    'dolor de hÃ­gado': 'hepatomegalia', 'me duele el hÃ­gado': 'hepatomegalia',
    'dolor de vesÃ­cula': 'cÃ³lico biliar', 'me duele la vesÃ­cula': 'cÃ³lico biliar',
    'dolor de pÃ¡ncreas': 'pancreatitis', 'me duele el pÃ¡ncreas': 'pancreatitis',
    'dolor de intestino': 'enteritis', 'me duele el intestino': 'enteritis',
    'dolor de colon': 'colitis', 'me duele el colon': 'colitis',
    'dolor de corazÃ³n': 'cardiopatÃ­a', 'me duele el corazÃ³n': 'cardiopatÃ­a',
    'dolor de pulmÃ³n': 'neumopatÃ­a', 'me duele el pulmÃ³n': 'neumopatÃ­a',
    'dolor de cadera': 'coxalgia', 'me duele la cadera': 'coxalgia',
    'dolor de rodilla': 'gonalgia', 'me duele la rodilla': 'gonalgia',
    'dolor de tobillo': 'talalgia', 'me duele el tobillo': 'talalgia',
    'dolor de pie': 'podalgia', 'me duele el pie': 'podalgia',
    'dolor de mano': 'dolor en mano', 'me duele la mano': 'dolor en mano',
    'dolor de brazo': 'dolor en brazo', 'me duele el brazo': 'dolor en brazo',
    'dolor de pierna': 'dolor en pierna', 'me duele la pierna': 'dolor en pierna',
    'dolor de muslo': 'dolor en muslo', 'me duele el muslo': 'dolor en muslo',
    'dolor de pantorrilla': 'dolor en pantorrilla', 'me duele la pantorrilla': 'dolor en pantorrilla',
    'dolor de talÃ³n': 'talalgia', 'me duele el talÃ³n': 'talalgia',
    'dolor de planta': 'plantalgia', 'me duele la planta': 'plantalgia',
    'dolor de uÃ±a': 'paroniquia', 'me duele la uÃ±a': 'paroniquia',
    # SÃ­ntomas generales
    'fiebre': 'fiebre', 'calentura': 'fiebre', 'tengo calentura': 'fiebre',
    'tos': 'tos', 'tengo tos': 'tos', 'tos seca': 'tos seca',
    'tos con flema': 'tos productiva', 'toso con flema': 'tos productiva',
    'toso seco': 'tos seca', 'nÃ¡usea': 'nÃ¡usea', 'ganas de vomitar': 'nÃ¡usea',
    'vÃ³mito': 'vÃ³mito', 'vomitÃ³': 'vÃ³mito', 'diarrea': 'diarrea',
    'estÃ³mago suelto': 'diarrea', 'tengo chorrillo': 'diarrea',
    'me dio chorrillo': 'diarrea', 'chorrillo': 'diarrea',
    'mareo': 'mareo', 'me dio mareo': 'mareo', 'tengo mareo': 'mareo',
    'vÃ©rtigo': 'vÃ©rtigo', 'me da vÃ©rtigo': 'vÃ©rtigo',
    'fatiga': 'fatiga', 'cansancio': 'fatiga', 'me siento cansado': 'fatiga',
    'debilidad': 'debilidad', 'me siento dÃ©bil': 'debilidad',
    'falta de aire': 'disnea', 'se ahoga': 'disnea', 'no puede respirar': 'disnea',
    'se le va el aire': 'disnea', 'no le alcanza el aire': 'disnea',
    'ardor': 'pirosis', 'acidez': 'pirosis', 'me da ardor': 'pirosis',
    'comezÃ³n': 'prurito', 'picazÃ³n': 'prurito', 'me pica': 'prurito',
    'sarpullido': 'exantema', 'ronchas': 'urticaria', 'ronchitas': 'urticaria',
    'desmayo': 'sÃ­ncope', 'se desmayÃ³': 'sÃ­ncope', 'se desmayo': 'sÃ­ncope',
    'patatÃºs': 'sÃ­ncope', 'soponcio': 'sÃ­ncope', 'se fue': 'sÃ­ncope',
    'convulsiÃ³n': 'convulsiÃ³n', 'ataque': 'convulsiÃ³n', 'convulsionÃ³': 'convulsiÃ³n',
    'escalofrÃ­os': 'escalofrÃ­os', 'tengo escalofrÃ­os': 'escalofrÃ­os',
    'sudor frÃ­o': 'diaforesis', 'suda frÃ­o': 'diaforesis', 'sudoraciÃ³n': 'diaforesis',
    'pÃ©rdida de peso': 'pÃ©rdida de peso', 'bajÃ³ de peso': 'pÃ©rdida de peso',
    'subiÃ³ de peso': 'ganancia de peso', 'no duerme': 'insomnio',
    'insomnio': 'insomnio', 'no puede dormir': 'insomnio',
    'ansiedad': 'ansiedad', 'depresiÃ³n': 'depresiÃ³n', 'tristeza': 'depresiÃ³n',
    'palpitaciones': 'palpitaciones', 'latidos rÃ¡pidos': 'taquicardia',
    'latidos lentos': 'bradicardia', 'presiÃ³n alta': 'hipertensiÃ³n',
    'presiÃ³n baja': 'hipotensiÃ³n', 'se me subiÃ³ la presiÃ³n': 'hipertensiÃ³n',
    'se me bajÃ³ la presiÃ³n': 'hipotensiÃ³n',
    'azÃºcar alta': 'hiperglucemia', 'azÃºcar baja': 'hipoglucemia',
    'colesterol alto': 'hipercolesterolemia', 'triglicÃ©ridos altos': 'hipertrigliceridemia',
    'anemia': 'anemia', 'falta de hierro': 'anemia ferropÃ©nica',
    'infecciÃ³n': 'infecciÃ³n', 'inflamaciÃ³n': 'inflamaciÃ³n',
    'congestiÃ³n': 'congestiÃ³n nasal', 'mocos': 'rinorrea',
    'gripe': 'influenza', 'resfriado': 'rinofaringitis', 'catarro': 'rinofaringitis',
    'bronquitis': 'bronquitis', 'pulmonÃ­a': 'neumonÃ­a', 'neumonÃ­a': 'neumonÃ­a',
    'asma': 'asma', 'alergia': 'alergia', 'rinitis': 'rinitis',
    'sinusitis': 'sinusitis', 'otitis': 'otitis', 'conjuntivitis': 'conjuntivitis',
    'gastritis': 'gastritis', 'Ãºlcera': 'Ãºlcera gÃ¡strica', 'colitis': 'colitis',
    'estreÃ±imiento': 'estreÃ±imiento', 'gases': 'flatulencia',
    'ardor al orinar': 'disuria', 'orina con sangre': 'hematuria',
    'orina frecuente': 'poliuria', 'dolor al orinar': 'disuria',
    'infecciÃ³n de vÃ­as urinarias': 'IVU', 'IVU': 'infecciÃ³n de vÃ­as urinarias',
    'embarazo': 'embarazo', 'estÃ¡ embarazada': 'embarazo',
    'menstruaciÃ³n': 'menstruaciÃ³n', 'regla': 'menstruaciÃ³n',
    'dolor menstrual': 'dismenorrea', 'sangrado abundante': 'menorragia',
    'me siento mal': 'malestar general', 'me siento dÃ©bil': 'astenia',
    'no tengo energÃ­a': 'astenia', 'me siento mareado': 'mareo',
    'me duele todo': 'dolor generalizado', 'me duele el cuerpo': 'mialgia generalizada',
    'me duelen los huesos': 'ostealgia', 'me duelen las articulaciones': 'artralgia',
    'no tengo hambre': 'anorexia', 'no quiere comer': 'anorexia',
    'no duerme': 'insomnio', 'no puede dormir': 'insomnio',
    'veo borroso': 'visiÃ³n borrosa', 'veo doble': 'diplopÃ­a',
    'me zumba el oÃ­do': 'tinnitus', 'zumbido en el oÃ­do': 'tinnitus',
    'sangrado nasal': 'epistaxis', 'sangre de la nariz': 'epistaxis',
    'sangrado de encÃ­as': 'gingivorragia', 'sangrado de nariz': 'epistaxis',
    # Jerga mexicana para partes del cuerpo
    'panza': 'abdomen', 'pata': 'pierna', 'ojo': 'ojo',
    'oÃ­do': 'oÃ­do', 'nariz': 'nariz', 'boca': 'boca', 'garganta': 'garganta',
    'pecho': 'tÃ³rax', 'espalda': 'espalda', 'cintura': 'lumbares',
    'riÃ±Ã³n': 'lumbares', 'pierna': 'pierna', 'rodilla': 'rodilla',
    'tobillo': 'tobillo', 'pie': 'pie', 'cabeza': 'cabeza',
    'cerebro': 'cerebro', 'corazÃ³n': 'corazÃ³n', 'pulmÃ³n': 'pulmÃ³n',
    'hÃ­gado': 'hÃ­gado', 'vesÃ­cula': 'vesÃ­cula', 'pÃ¡ncreas': 'pÃ¡ncreas',
    'intestino': 'intestino', 'colon': 'colon', 'vejiga': 'vejiga',
    'prÃ³stata': 'prÃ³stata', 'Ãºtero': 'Ãºtero', 'ovario': 'ovario',
    'testÃ­culo': 'testÃ­culo', 'pene': 'pene', 'vagina': 'vagina',
    'ano': 'ano', 'recto': 'recto', 'mano': 'mano', 'brazo': 'brazo',
    'hombro': 'hombro', 'codo': 'codo', 'muÃ±eca': 'muÃ±eca',
    'dedo': 'dedo', 'uÃ±a': 'uÃ±a', 'cuello': 'cuello',
    'cadera': 'cadera', 'muslo': 'muslo', 'pantorrilla': 'pantorrilla',
    'talÃ³n': 'talÃ³n', 'planta': 'planta del pie',
    # Jerga mexicana para orina y heces
    'pipÃ­': 'orina', 'hacer pipÃ­': 'orinar', 'no puede hacer pipÃ­': 'retenciÃ³n urinaria',
    'popo': 'heces', 'hacer popo': 'defecar', 'popo suelta': 'diarrea',
    'popo dura': 'estreÃ±imiento', 'sangre en la popo': 'hematoquecia',
    'sangre en el pipÃ­': 'hematuria', 'pipÃ­ con sangre': 'hematuria',
    'pipÃ­ turbio': 'orina turbia', 'pipÃ­ amarillo': 'orina concentrada',
    'pipÃ­ con olor': 'orina fÃ©tida', 'pipÃ­ espumoso': 'proteinuria',
    'pipÃ­ frecuente': 'poliuria', 'mucha pipÃ­': 'poliuria',
    'poca pipÃ­': 'oliguria', 'no hace pipÃ­': 'anuria',
    # Jerga mexicana general
    'me dio chorrillo': 'diarrea', 'me dio la vuelta': 'mareo',
    'se me subiÃ³ la presiÃ³n': 'hipertensiÃ³n', 'se me bajÃ³ la presiÃ³n': 'hipotensiÃ³n',
    'me dio un patatÃºs': 'sÃ­ncope', 'me dio un soponcio': 'sÃ­ncope',
    'me dio un ataque': 'convulsiÃ³n', 'me dio un infarto': 'infarto agudo de miocardio',
    'me dio un derrame': 'evento vascular cerebral', 'me dio un embolia': 'embolia',
    'me dio un trombo': 'trombosis', 'me dio diabetes': 'diabetes mellitus',
    'me dio presiÃ³n': 'hipertensiÃ³n', 'me dio azÃºcar': 'diabetes mellitus',
    'me dio colesterol': 'hipercolesterolemia', 'me dio triglicÃ©ridos': 'hipertrigliceridemia',
    'me dio anemia': 'anemia', 'me dio gastritis': 'gastritis',
    'me dio colitis': 'colitis', 'me dio Ãºlcera': 'Ãºlcera gÃ¡strica',
    'me dio hernia': 'hernia', 'me dio apendicitis': 'apendicitis',
    'me dio amigdalitis': 'amigdalitis', 'me dio otitis': 'otitis',
    'me dio conjuntivitis': 'conjuntivitis', 'me dio sinusitis': 'sinusitis',
    'me dio bronquitis': 'bronquitis', 'me dio neumonÃ­a': 'neumonÃ­a',
    'me dio asma': 'asma', 'me dio alergia': 'alergia',
    'me dio rinitis': 'rinitis', 'me dio gripe': 'influenza',
    'me dio resfriado': 'rinofaringitis', 'me dio catarro': 'rinofaringitis',
    'me dio calentura': 'fiebre', 'tengo calentura': 'fiebre',
    'me dio tos': 'tos', 'tengo tos': 'tos',
    'me dio nÃ¡usea': 'nÃ¡usea', 'tengo nÃ¡usea': 'nÃ¡usea',
    'me dio vÃ³mito': 'vÃ³mito', 'tengo vÃ³mito': 'vÃ³mito',
    'me dio diarrea': 'diarrea', 'tengo diarrea': 'diarrea',
    'me dio mareo': 'mareo', 'tengo mareo': 'mareo',
    'me dio fatiga': 'fatiga', 'tengo fatiga': 'fatiga',
    'me dio debilidad': 'debilidad', 'tengo debilidad': 'debilidad',
    'me dio falta de aire': 'disnea', 'tengo falta de aire': 'disnea',
    'me dio ardor': 'pirosis', 'tengo ardor': 'pirosis',
    'me dio comezÃ³n': 'prurito', 'tengo comezÃ³n': 'prurito',
    'me dio sarpullido': 'exantema', 'tengo sarpullido': 'exantema',
    'me dio ronchas': 'urticaria', 'tengo ronchas': 'urticaria',
    'me dio desmayo': 'sÃ­ncope', 'me desmayÃ©': 'sÃ­ncope',
    'me dio convulsiÃ³n': 'convulsiÃ³n', 'tengo convulsiÃ³n': 'convulsiÃ³n',
    'me dio escalofrÃ­os': 'escalofrÃ­os', 'tengo escalofrÃ­os': 'escalofrÃ­os',
    'me dio sudor frÃ­o': 'diaforesis', 'tengo sudor frÃ­o': 'diaforesis',
    'me dio pÃ©rdida de peso': 'pÃ©rdida de peso', 'bajÃ© de peso': 'pÃ©rdida de peso',
    'me dio insomnio': 'insomnio', 'no duermo': 'insomnio',
    'me dio ansiedad': 'ansiedad', 'tengo ansiedad': 'ansiedad',
    'me dio depresiÃ³n': 'depresiÃ³n', 'tengo depresiÃ³n': 'depresiÃ³n',
    'me dio palpitaciones': 'palpitaciones', 'tengo palpitaciones': 'palpitaciones',
    'me dio hipertensiÃ³n': 'hipertensiÃ³n', 'tengo hipertensiÃ³n': 'hipertensiÃ³n',
    'me dio hipotensiÃ³n': 'hipotensiÃ³n', 'tengo hipotensiÃ³n': 'hipotensiÃ³n',
    'me dio diabetes': 'diabetes mellitus', 'tengo diabetes': 'diabetes mellitus',
    'me dio anemia': 'anemia', 'tengo anemia': 'anemia',
    'me dio gastritis': 'gastritis', 'tengo gastritis': 'gastritis',
    'me dio colitis': 'colitis', 'tengo colitis': 'colitis',
    'me dio estreÃ±imiento': 'estreÃ±imiento', 'tengo estreÃ±imiento': 'estreÃ±imiento',
    'me dio gases': 'flatulencia', 'tengo gases': 'flatulencia',
    'me dio IVU': 'infecciÃ³n de vÃ­as urinarias', 'tengo IVU': 'infecciÃ³n de vÃ­as urinarias',
    'me dio embarazo': 'embarazo', 'estoy embarazada': 'embarazo',
    'me dio menstruaciÃ³n': 'menstruaciÃ³n', 'tengo menstruaciÃ³n': 'menstruaciÃ³n',
    'me dio dolor menstrual': 'dismenorrea', 'tengo dolor menstrual': 'dismenorrea',
    'me dio sangrado abundante': 'menorragia', 'tengo sangrado abundante': 'menorragia',
    'me siento mal': 'malestar general', 'me siento dÃ©bil': 'astenia',
    'no tengo energÃ­a': 'astenia', 'me siento mareado': 'mareo',
    'me duele todo': 'dolor generalizado', 'me duele el cuerpo': 'mialgia generalizada',
    'no tengo hambre': 'anorexia', 'no quiere comer': 'anorexia',
    'no duerme': 'insomnio', 'no puede dormir': 'insomnio',
    'veo borroso': 'visiÃ³n borrosa', 'veo doble': 'diplopÃ­a',
    'me zumba el oÃ­do': 'tinnitus', 'zumbido en el oÃ­do': 'tinnitus',
    'sangrado nasal': 'epistaxis', 'sangre de la nariz': 'epistaxis',
    'sangrado de encÃ­as': 'gingivorragia', 'sangrado de nariz': 'epistaxis',
    # NÃºmeros y edades
    'aÃ±os': 'aÃ±os', 'meses': 'meses', 'dÃ­as': 'dÃ­as', 'semanas': 'semanas',
    'hora': 'hora', 'horas': 'horas', 'minuto': 'minuto', 'minutos': 'minutos',
    'segundo': 'segundo', 'segundos': 'segundos',
    # Medidas
    'kilogramos': 'kg', 'kilos': 'kg', 'gramos': 'g', 'libras': 'lb',
    'metros': 'm', 'centÃ­metros': 'cm', 'milÃ­metros': 'mm',
    'litros': 'L', 'mililitros': 'mL', 'celsius': 'Â°C', 'fahrenheit': 'Â°F',
    # Unidades de tiempo
    'cada': 'cada', 'cada hora': 'cada hora', 'cada dÃ­a': 'cada dÃ­a',
    'cada semana': 'cada semana', 'cada mes': 'cada mes',
    'por la maÃ±ana': 'por la maÃ±ana', 'por la tarde': 'por la tarde',
    'por la noche': 'por la noche', 'en ayunas': 'en ayunas',
    'antes de comer': 'antes de comer', 'despuÃ©s de comer': 'despuÃ©s de comer',
    'con los alimentos': 'con los alimentos', 'sin alimentos': 'sin alimentos',
    # Formas farmacÃ©uticas
    'tabletas': 'tabletas', 'cÃ¡psulas': 'cÃ¡psulas', 'grageas': 'grageas',
    'soluciÃ³n': 'soluciÃ³n', 'suspensiÃ³n': 'suspensiÃ³n', 'inyecciÃ³n': 'inyecciÃ³n',
    'crema': 'crema', 'pomada': 'pomada', 'gel': 'gel', 'ungÃ¼ento': 'ungÃ¼ento',
    'gotas': 'gotas', 'spray': 'spray', 'inhalador': 'inhalador',
    'supositorio': 'supositorio', 'Ã³vulo': 'Ã³vulo', 'parche': 'parche',
    'jarabe': 'jarabe', 'elÃ­xir': 'elÃ­xir', 'tintura': 'tintura',
    # VÃ­as de administraciÃ³n
    'oral': 'oral', 'por la boca': 'oral', 'vÃ­a oral': 'oral',
    'intravenosa': 'intravenosa', 'IV': 'intravenosa', 'por la vena': 'intravenosa',
    'intramuscular': 'intramuscular', 'IM': 'intramuscular', 'en el mÃºsculo': 'intramuscular',
    'subcutÃ¡nea': 'subcutÃ¡nea', 'SC': 'subcutÃ¡nea', 'bajo la piel': 'subcutÃ¡nea',
    'tÃ³pica': 'tÃ³pica', 'en la piel': 'tÃ³pica', 'rectal': 'rectal',
    'vaginal': 'vaginal', 'ocular': 'ocular', 'Ã³tica': 'Ã³tica',
    'nasal': 'nasal', 'inhalada': 'inhalada', 'sublingual': 'sublingual',
    # Estados de tratamiento
    'en curso': 'en curso', 'finalizado': 'finalizado', 'suspendido': 'suspendido',
    'completado': 'completado', 'cancelado': 'cancelado', 'pendiente': 'pendiente',
    # Tipos de atenciÃ³n
    'consulta general': 'consulta general', 'consulta de primera vez': 'primera vez',
    'consulta subsecuente': 'subsecuente', 'consulta de urgencia': 'urgencia',
    'consulta de control': 'control', 'consulta de seguimiento': 'seguimiento',
    # Modalidades
    'presencial': 'presencial', 'a distancia': 'a distancia',
    'telemedicina': 'telemedicina', 'domicilio': 'domicilio',
    # Tipos de establecimiento
    'consultorio': 'consultorio', 'clÃ­nica': 'clÃ­nica', 'hospital': 'hospital',
    'centro de salud': 'centro de salud', 'unidad mÃ©dica': 'unidad mÃ©dica',
    'sanatorio': 'sanatorio', 'laboratorio': 'laboratorio',
    'farmacia': 'farmacia', 'consultorio dental': 'consultorio dental',
    # Especialidades
    'medicina general': 'medicina general', 'medicina interna': 'medicina interna',
    'pediatrÃ­a': 'pediatrÃ­a', 'ginecologÃ­a': 'ginecologÃ­a',
    'obstetricia': 'obstetricia', 'cirugÃ­a': 'cirugÃ­a',
    'ortopedia': 'ortopedia', 'traumatologÃ­a': 'traumatologÃ­a',
    'cardiologÃ­a': 'cardiologÃ­a', 'neurologÃ­a': 'neurologÃ­a',
    'psiquiatrÃ­a': 'psiquiatrÃ­a', 'dermatologÃ­a': 'dermatologÃ­a',
    'oftalmologÃ­a': 'oftalmologÃ­a', 'otorrinolaringologÃ­a': 'otorrinolaringologÃ­a',
    'urologÃ­a': 'urologÃ­a', 'nefrologÃ­a': 'nefrologÃ­a',
    'gastroenterologÃ­a': 'gastroenterologÃ­a', 'endocrinologÃ­a': 'endocrinologÃ­a',
    'neumologÃ­a': 'neumologÃ­a', 'reumatologÃ­a': 'reumatologÃ­a',
    'oncologÃ­a': 'oncologÃ­a', 'hematologÃ­a': 'hematologÃ­a',
    'infectologÃ­a': 'infectologÃ­a', 'geriatrÃ­a': 'geriatrÃ­a',
    'medicina familiar': 'medicina familiar', 'medicina del trabajo': 'medicina del trabajo',
    'medicina deportiva': 'medicina deportiva', 'medicina legal': 'medicina legal',
    'medicina forense': 'medicina forense', 'patologÃ­a': 'patologÃ­a',
    'radiologÃ­a': 'radiologÃ­a', 'anestesiologÃ­a': 'anestesiologÃ­a',
    'medicina crÃ­tica': 'medicina crÃ­tica', 'terapia intensiva': 'terapia intensiva',
    'urgencias': 'urgencias', 'medicina de urgencias': 'medicina de urgencias',
    # Tipos de personal de salud
    'mÃ©dico': 'mÃ©dico', 'doctor': 'mÃ©dico', 'doctora': 'mÃ©dico',
    'enfermera': 'enfermerÃ­a', 'enfermero': 'enfermerÃ­a',
    'pasante': 'pasante', 'internista': 'internista',
    'residente': 'residente', 'especialista': 'especialista',
    'subespecialista': 'subespecialista', 'tÃ©cnico': 'tÃ©cnico',
    'auxiliar': 'auxiliar', 'recepcionista': 'recepcionista',
    'administrador': 'administrador', 'director': 'director',
    # FormaciÃ³n acadÃ©mica
    'mÃ©dico cirujano': 'mÃ©dico cirujano', 'licenciatura': 'licenciatura',
    'especialidad': 'especialidad', 'subespecialidad': 'subespecialidad',
    'maestrÃ­a': 'maestrÃ­a', 'doctorado': 'doctorado',
    'diplomado': 'diplomado', 'curso': 'curso', 'certificaciÃ³n': 'certificaciÃ³n',
    # Material de curaciÃ³n
    'gasa': 'gasa', 'venda': 'venda', 'esparadrapo': 'esparadrapo',
    'algodÃ³n': 'algodÃ³n', 'alcohol': 'alcohol', 'yodo': 'yodo',
    'agua oxigenada': 'agua oxigenada', 'suero fisiolÃ³gico': 'suero fisiolÃ³gico',
    'soluciÃ³n salina': 'soluciÃ³n salina', 'guantes': 'guantes',
    'jeringa': 'jeringa', 'aguja': 'aguja', 'catÃ©ter': 'catÃ©ter',
    'sonda': 'sonda', 'tubo': 'tubo', 'bolsa': 'bolsa',
    'curita': 'curita', 'apÃ³sito': 'apÃ³sito', 'venda elÃ¡stica': 'venda elÃ¡stica',
    'venda de yeso': 'venda de yeso', 'yeso': 'yeso',
    # Instrumental
    'estetoscopio': 'estetoscopio', 'termÃ³metro': 'termÃ³metro',
    'esfigmomanÃ³metro': 'esfigmomanÃ³metro', 'oxÃ­metro': 'oxÃ­metro',
    'glucÃ³metro': 'glucÃ³metro', 'nebulizador': 'nebulizador',
    'aspirador': 'aspirador', 'ambÃº': 'ambÃº', 'laringoscopio': 'laringoscopio',
    'otoscopio': 'otoscopio', 'oftalmoscopio': 'oftalmoscopio',
    'reflejÃ³metro': 'reflejÃ³metro', 'tijeras': 'tijeras',
    'pinzas': 'pinzas', 'bisturÃ­': 'bisturÃ­', 'aguja': 'aguja',
    'jeringa': 'jeringa', 'catÃ©ter': 'catÃ©ter', 'sonda': 'sonda',
    'tubo': 'tubo', 'bolsa': 'bolsa', 'curita': 'curita',
    # Tipos de consulta
    'primera vez': 'primera vez', 'subsecuente': 'subsecuente',
    'control': 'control', 'seguimiento': 'seguimiento',
    'urgencia': 'urgencia', 'emergencia': 'emergencia',
    # Tipos de atenciÃ³n
    'consulta': 'consulta', 'procedimiento': 'procedimiento',
    'cirugÃ­a': 'cirugÃ­a', 'hospitalizaciÃ³n': 'hospitalizaciÃ³n',
    'observaciÃ³n': 'observaciÃ³n', 'urgencia': 'urgencia',
    # Tipos de establecimiento
    'consultorio': 'consultorio', 'clÃ­nica': 'clÃ­nica', 'hospital': 'hospital',
    'centro de salud': 'centro de salud', 'unidad mÃ©dica': 'unidad mÃ©dica',
    'sanatorio': 'sanatorio', 'laboratorio': 'laboratorio',
    'farmacia': 'farmacia', 'consultorio dental': 'consultorio dental',
    # Entidades federativas
    'aguascalientes': 'Aguascalientes', 'baja california': 'Baja California',
    'baja california sur': 'Baja California Sur', 'campeche': 'Campeche',
    'chiapas': 'Chiapas', 'chihuahua': 'Chihuahua', 'coahuila': 'Coahuila',
    'colima': 'Colima', 'ciudad de mÃ©xico': 'Ciudad de MÃ©xico',
    'cdmx': 'Ciudad de MÃ©xico', 'durango': 'Durango', 'guanajuato': 'Guanajuato',
    'guerrero': 'Guerrero', 'hidalgo': 'Hidalgo', 'jalisco': 'Jalisco',
    'michoacÃ¡n': 'MichoacÃ¡n', 'morelos': 'Morelos', 'nayarit': 'Nayarit',
    'nuevo leÃ³n': 'Nuevo LeÃ³n', 'oaxaca': 'Oaxaca', 'puebla': 'Puebla',
    'querÃ©taro': 'QuerÃ©taro', 'quintana roo': 'Quintana Roo',
    'san luis potosÃ­': 'San Luis PotosÃ­', 'sinaloa': 'Sinaloa',
    'sonora': 'Sonora', 'tabasco': 'Tabasco', 'tamaulipas': 'Tamaulipas',
    'tlaxcala': 'Tlaxcala', 'veracruz': 'Veracruz', 'yucatÃ¡n': 'YucatÃ¡n',
    'zacatecas': 'Zacatecas',
    # Nacionalidades
    'mexicana': 'mexicana', 'mexicano': 'mexicano', 'estadounidense': 'estadounidense',
    'espaÃ±ola': 'espaÃ±ola', 'espaÃ±ol': 'espaÃ±ol', 'guatemalteca': 'guatemalteca',
    'hondureÃ±a': 'hondureÃ±a', 'salvadoreÃ±a': 'salvadoreÃ±a',
    'colombiana': 'colombiana', 'venezolana': 'venezolana',
    'argentina': 'argentina', 'brasileÃ±a': 'brasileÃ±a', 'chilena': 'chilena',
    'peruana': 'peruana', 'ecuatoriana': 'ecuatoriana',
    # Religiones
    'catÃ³lica': 'catÃ³lica', 'catÃ³lico': 'catÃ³lico', 'protestante': 'protestante',
    'evangÃ©lica': 'evangÃ©lica', 'testigo de jehovÃ¡': 'testigo de JehovÃ¡',
    'mormÃ³n': 'mormÃ³n', 'judÃ­a': 'judÃ­a', 'musulmana': 'musulmana',
    'budista': 'budista', 'atea': 'atea', 'agnÃ³stica': 'agnÃ³stica',
    'sin religiÃ³n': 'sin religiÃ³n', 'otra': 'otra',
    # Lenguas indÃ­genas
    'nÃ¡huatl': 'nÃ¡huatl', 'maya': 'maya', 'mixteco': 'mixteco',
    'zapoteco': 'zapoteco', 'tzeltal': 'tzeltal', 'tzotzil': 'tzotzil',
    'otomÃ­': 'otomÃ­', 'totonaco': 'totonaco', 'mazateco': 'mazateco',
    'chinanteco': 'chinanteco', 'mixe': 'mixe', 'triqui': 'triqui',
    'purÃ©pecha': 'purÃ©pecha', 'tarahumara': 'tarahumara', 'yaqui': 'yaqui',
    'seri': 'seri', 'kumiai': 'kumiai', 'cucapÃ¡': 'cucapÃ¡',
}

def normalizar_sintoma(texto):
    """Reemplaza la jerga por su término clínico DENTRO del texto, sin borrar el resto.

    Antes devolvía el término clínico y ya (si el campo mencionaba una jerga, perdía todo
    lo demás que se había dicho). Ahora cada frase del diccionario se sustituye en su
    lugar: "me duele la cabeza desde hace tres días" sigue diciendo desde cuándo.
    """
    if not texto:
        return texto
    resultado = texto
    for jerga, clinico in sorted(SINTOMAS_MEXICANOS.items(), key=lambda kv: len(kv[0]), reverse=True):
        patron = r'(?<![a-z0-9])' + re.escape(jerga) + r'(?![a-z0-9])'
        resultado = re.sub(patron, clinico, resultado, flags=re.IGNORECASE)
    return resultado

def extraer_campos(texto):
    campos = {}
    t = texto.lower().strip()
    if not t:
        return campos

    # CURP
    m = re.search(r'\b([A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d)\b', texto, re.IGNORECASE)
    if m:
        campos['curp'] = m.group(1).upper()

    # TelÃ©fono (10 dÃ­gitos, corrizos o agrupados, cerca de la palabra telÃ©fono/celular)
    def telefonoDe(fragmento):
        """Del fragmento dado, devuelve los 10 dÃ­gitos del telÃ©fono o None."""
        corridos = re.findall(r'\d[\d\s]{8,16}\d', fragmento)
        for corrido in corridos:
            d = re.sub(r'\D', '', corrido)
            if len(d) == 10:
                return d[:2] + ' ' + d[2:6] + ' ' + d[6:]
        return None

    m = re.search(r'(?:tel[Ã©e]fono|celular|n[Ãºu]mero|whatsapp)[^0-9]*(\d[\d\s]{8,16}\d)', t)
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
        m = re.search(r"correos?\s+(?:electr[oÃ³]nico|electronico)\s*(?:es|ser[iÃ­]a|son)?\s*[:;,]?\s*|correos?\s+(?:es|ser[iÃ­]a)\s*[:;,]?\s*|email\s*(?:es|ser[iÃ­]a)?\s*[:;,]?\s*", t)
        if not m:
            return None
        frag = t[m.end():].strip()
        frag = re.sub(r"gui[oÃ³]n\s+bajo|guion\s+bajo", "_", frag)
        frag = re.sub(r"gui[oÃ³]n\s+medio|guion\s+medio", "-", frag)
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

    # Fecha de nacimiento: no confundir una fecha de sÃ­ntomas o consulta con el nacimiento.
    marca_nacimiento = r'(?:fecha\s+(?:de\s+)?nacimiento|fecha\s+natal|cumplea(?:Ã±os|nos)|nac[iÃ­](?:[oÃ³])?|nacid[oa])'
    m = re.search(r'\b' + marca_nacimiento + r'[^.;\n]{0,70}?(\d{1,2})\s*(?:de\s+)?(' + '|'.join(MESES.keys()) + r')\s*(?:de\s+|del\s+)?(\d{4})', t)
    if m:
        d, mes, a = int(m.group(1)), MESES[m.group(2)], int(m.group(3))
        from datetime import date
        if 1 <= d <= 31 and 1 <= mes <= 12 and 1900 <= a <= date.today().year:
            campos['fecha_nacimiento'] = f"{a:04d}-{mes:02d}-{d:02d}"

    # Sexo
    if re.search(r'\b(femenin[oa]|mujer|niÃ±a|seÃ±ora|seÃ±orita|femenino)\b', t):
        campos['sexo'] = 'M'
    elif re.search(r'\b(masculin[oa]|hombre|niÃ±o|seÃ±or|masculino)\b', t):
        campos['sexo'] = 'H'

    # Grupo sanguÃ­neo
    m = re.search(r'\b(O|A|B|AB)\s*(positivo|negativo|\+|-)\b', t, re.IGNORECASE)
    if m:
        grupo = m.group(1).upper() + ('+' if m.group(2).lower() in ('positivo', '+') else '-')
        if grupo in ('O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'):
            campos['grupo_sanguineo'] = grupo

    # Nombre y apellidos (convenciÃ³n mexicana: nombre(s) + primer apellido + segundo apellido)
    def capitalizar_nombre(s):
        return ' '.join(p.capitalize() for p in s.split())
    m = re.search(r'(?:paciente|seÃ±or[a]?|recibo a|atiendo a|el joven|la joven|el niÃ±o|la niÃ±a|la seÃ±ora|el seÃ±or|me llamo|mi nombre es|soy)\s+([A-Za-zÃ¡Ã©Ã­Ã³ÃºÃ±ÃÃ‰ÃÃ“ÃšÃ‘]+(?:\s+[A-Za-zÃ¡Ã©Ã­Ã³ÃºÃ±ÃÃ‰ÃÃ“ÃšÃ‘]+){1,3})', texto, re.IGNORECASE)
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
    m = re.search(r'alÃ©rgic[oa]\s+(?:a\s+)?(?:el|la|los|las)?\s*(.+?)(?:\.|,|$)', t)
    if m:
        valor = m.group(1).strip()
        valor = re.sub(r'^(el|la|los|las)\s+', '', valor)
        # "penicilina y a la aspirina" â†’ "penicilina, aspirina"
        valor = re.sub(r'\s+y\s+a\s+(?:la|el|los|las)\s+', ', ', valor)
        valor = re.sub(r'\s+y\s+(?:al|a\s+el)\s+', ', ', valor)
        campos['alergias'] = valor.strip().capitalize()

    # Padecimientos crÃ³nicos
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
    m = re.search(r'(?:mi\s+direcci[Ã³o]n\s+es|mi\s+domicilio\s+es|vivo\s+en|vive\s+en|viviendo\s+en|habitamos\s+en|habito\s+en|domicilio\s*[:\s]|direcci[Ã³o]n\s*[:\s])\s*(.+?)(?:\.|$)', t)
    if m:
        campos['domicilio'] = capitalizar_nombre(m.group(1).strip())
    else:
        # "calle X" sueltos (sin "vivo en")
        m = re.search(r'(?:en\s+la\s+)?calle\s+(.+?)(?:\.|,|$)', t)
        if m:
            campos['domicilio'] = 'Calle ' + capitalizar_nombre(m.group(1).strip())

    # Municipio / alcaldÃ­a (hablado)
    m = re.search(r'(?:municipios?|alcald[iÃ­]a|delegaci[oÃ³]n)\s+(?:de\s+)?([^\.]{3,80})', t)
    if m:
        campos['municipio_estado'] = m.group(1).strip().rstrip(',').capitalize()

    # Contacto de emergencia

    # Contacto de emergencia
    m = re.search(r'(?:contacto de emergencia|contacto emergencia)[:\s]+(.+?)(?:\.|,|$)', t)
    if m:
        campos['contacto_emergencia'] = m.group(1).strip().capitalize()

    # TelÃ©fono de emergencia
    m = re.search(r'(?:telÃ©fono de emergencia|tel emergencia)[:\s]+(.+?)(?:\.|,|$)', t)
    if m:
        tel = telefonoDe(m.group(1))
        if tel:
            campos['telefono_emergencia'] = tel

    # Repetimos el patrÃ³n antiguo por compatibilidad con dictados formales ("municipio: CoyoacÃ¡n")
    m = re.search(r'municipio\s*:\s*(.+?)(?:\.|,|$)', t)
    if m and 'municipio_estado' not in campos:
        campos['municipio_estado'] = m.group(1).strip().capitalize()

    # Notas clÃ­nicas - secciones (expandido)
    secciones_clinicas = {
        'motivo_consulta': r'(?:motivo de consulta|m\.? de consulta)[:\s]+(.+?)(?:\.\s*(?:padecimiento|exploraciÃ³n|diagnÃ³stico|plan|nota)|$)',
        'padecimiento_actual': r'(?:padecimiento actual|p\.? actual)[:\s]+(.+?)(?:\.\s*(?:exploraciÃ³n|diagnÃ³stico|plan|nota)|$)',
        'exploracion_fisica': r'(?:exploraciÃ³n fÃ­sica|exploraciÃ³n)[:\s]+(.+?)(?:\.\s*(?:diagnÃ³stico|plan|nota)|$)',
        'diagnostico': r'(?:diagnÃ³stico)[:\s]+(.+?)(?:\.\s*(?:plan|nota|pronÃ³stico)|$)',
        'plan_tratamiento': r'(?:plan y tratamiento|plan)[:\s]+(.+?)(?:\.\s*(?:pronÃ³stico|nota)|$)',
        'nota_evolucion': r'(?:nota de evoluciÃ³n|evoluciÃ³n)[:\s]+(.+?)(?:\.\s*(?:plan|pronÃ³stico)|$)',
        'pronostico': r'(?:pronÃ³stico)[:\s]+(.+?)(?:\.|$)',
    }
    for campo, patron in secciones_clinicas.items():
        m = re.search(patron, t, re.IGNORECASE | re.DOTALL)
        if m:
            campos[campo] = m.group(1).strip().capitalize()

    # Signos vitales
    m = re.search(r'(?:tensiÃ³n arterial|ta|presiÃ³n)[:\s]+(\d{2,3})\s*/\s*(\d{2,3})', t)
    if m:
        campos['ta_sistolica'] = m.group(1)
        campos['ta_diastolica'] = m.group(2)
    m = re.search(r'(?:frecuencia cardÃ­aca|fc|pulso)[:\s]+(\d{2,3})', t)
    if m:
        campos['frecuencia_cardiaca'] = m.group(1)
    m = re.search(r'(?:temperatura|temp)[:\s]+(\d{2,3}\.?\d?)', t)
    if m:
        campos['temperatura'] = m.group(1)
    m = re.search(r'(?:frecuencia respiratoria|fr)[:\s]+(\d{1,2})', t)
    if m:
        campos['frecuencia_respiratoria'] = m.group(1)
    m = re.search(r'(?:saturaciÃ³n|sato2|spo2)[:\s]+(\d{2,3})', t)
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
    # NUEVO: DetecciÃ³n de secciÃ³n del expediente
    # ============================================================
    seccion = detectar_seccion(texto)
    campos['seccion'] = seccion['principal']
    campos['seccion_confianza'] = round(seccion['confianza'], 2)
    campos['secciones_sub'] = seccion['subsecciones']

    # ============================================================
    # NUEVO: IdentificaciÃ³n de hablante
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
# MODELO EN CACHÃ‰: se carga una sola vez al arrancar y se reutiliza en cada peticiÃ³n.
# ----------------------------------------------------------------------------
_modelo = None
_modelo_listo = threading.Event()
_modelo_error = ''
_lock_modelo = threading.Lock()


def _crear_modelo(dispositivo, tipo):
    """Carga el modelo desde la cachÃ© local.

    Sin `local_files_only` faster-whisper consulta HuggingFace al arrancar y, si la red
    va lenta o estÃ¡ cortada, se queda colgado sin responder (el .bat parecÃ­a Â«no hacer nadaÂ»).
    Solo si el modelo todavÃ­a no estÃ¡ descargado se permite ir a la red, una Ãºnica vez.
    """
    from faster_whisper import WhisperModel
    try:
        return WhisperModel(MODELO, device=dispositivo, compute_type=tipo, local_files_only=True)
    except Exception as e:
        texto = str(e).lower()
        if 'local' in texto or 'not found' in texto or 'no such file' in texto or 'cannot find' in texto:
            print("El modelo no estÃ¡ en la cachÃ© local; descargÃ¡ndolo una vezâ€¦", flush=True)
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
                print(f"CUDA no disponible ({e}), cayendo a CPU int8â€¦", flush=True)
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
    """Transcribe PCM 16 kHz mono float32 con Whisper en cachÃ©."""
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
    """Descarta segmentos de baja confianza y frases de relleno tÃ­picas de audio/video."""
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
        r"^(?:muchas gracias|gracias) por (?:su|tu) atenciÃ³n$",
        r"^(?:bienvenidos?|bienvenidas?) a (?:la|esta) sesiÃ³n(?: de hoy)?$",
        r"^(?:seguimos|continuamos) con (?:la )?presentaciÃ³n$",
        r"^(?:para mÃ¡s informaciÃ³n|mÃ¡s informaciÃ³n en) enlace$",
        r"^(?:agradecimientos? a los asociados de este video|gracias por ver este video)$",
        r"^(?:ja ){2,}ja$",
        r"^(?:ja){2,}$",
    )
    frases = re.split(r"(?<=[.!?])\s+|,\s+(?=(?:bienvenid|seguimos|continuamos|para mÃ¡s informaciÃ³n))", texto)
    utiles = []
    for frase in frases:
        plano = re.sub(r"https?://\S+|www\.\S+", " enlace ", frase.lower())
        plano = re.sub(r"[^a-zÃ¡Ã©Ã­Ã³ÃºÃ¼Ã±0-9]+", " ", plano).strip()
        if not plano or re.fullmatch(r"(?:enlace|jaja?|ja+)(?:\s+(?:enlace|jaja?|ja+))*", plano):
            continue
        if any(re.fullmatch(patron, plano) for patron in patrones_relleno):
            continue
        utiles.append(frase.strip())
    return " ".join(utiles).strip()


def transcribir_wav_bytes(datos):
    """Trozo WAV recibido en vivo (16 kHz mono, 16 bits) â†’ texto, sin tocar disco."""
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
        """Estado del servicio: sirve para comprobar rÃ¡pido si el transcriptor estÃ¡ listo."""
        listo = _modelo_listo.is_set() and _modelo is not None
        self._responder(200, {
            "ok": listo,
            "nombre": "Traductor Clinico",
            "modelo": "Traductor Clinico",
            "listo": listo,
            "error": _modelo_error,
        })

    def do_POST(self):
        content_length = int(self.headers.get('Content-Length', 0))
        if content_length == 0:
            self._responder(400, {"ok": False, "error": "No se recibiÃ³ audio."})
            return

        # /en-vivo: trozos WAV cortos mientras el mÃ©dico habla. Solo devuelve el texto
        # (sin extraer campos) para responder rÃ¡pido; los campos se extraen al final en PHP.
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
            "modelo": "traductor-clinico"
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
    # asÃ­ el puerto responde aunque el modelo tarde y nunca parece Â«muertoÂ».
    threading.Thread(target=obtener_modelo, daemon=True).start()
    server = ThreadingHTTPServer(('127.0.0.1', PUERTO), Handler)
    print(f"Cargando modelo {MODELO}â€¦ el servicio ya escucha en 127.0.0.1:{PUERTO}", flush=True)
    print("Deja esta ventana abierta mientras uses el dictado.", flush=True)
    server.serve_forever()
