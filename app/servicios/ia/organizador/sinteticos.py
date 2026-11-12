"""Generacion local de ejemplos ficticios; no lee ni consulta la base clinica."""

from __future__ import annotations

import random
import re
from datetime import date, timedelta

from faker import Faker

from .esquema import DOCUMENTOS, ETIQUETAS

ENTIDADES_FEDERATIVAS = (
    "Aguascalientes", "Baja California", "Baja California Sur", "Campeche",
    "Coahuila", "Colima", "Chiapas", "Chihuahua", "Ciudad de Mexico",
    "Durango", "Guanajuato", "Guerrero", "Hidalgo", "Jalisco", "Estado de Mexico",
    "Michoacan", "Morelos", "Nayarit", "Nuevo Leon", "Oaxaca", "Puebla",
    "Queretaro", "Quintana Roo", "San Luis Potosi", "Sinaloa", "Sonora",
    "Tabasco", "Tamaulipas", "Tlaxcala", "Veracruz", "Yucatan", "Zacatecas",
)

NACIONALIDADES = (
    "mexicana", "estadounidense", "canadiense", "guatemalteca", "hondurena",
    "salvadorena", "nicaraguense", "costarricense", "panamena", "cubana",
    "colombiana", "venezolana", "ecuatoriana", "peruana", "boliviana",
    "chilena", "argentina", "paraguaya", "uruguaya", "brasilena", "espanola",
    "portuguesa", "francesa", "italiana", "alemana", "britanica", "china",
    "japonesa", "india", "australiana", "marroqui", "sudafricana",
    "beliceña", "dominicana", "haitiana", "puertorriqueña", "rusa",
    "ucraniana", "surcoreana", "danesa", "sueca", "noruega", "islandesa",
    "neerlandesa", "holandesa", "belga", "suiza", "austriaca", "griega",
    "polaca", "rumana", "turca", "egipcia", "nigeriana", "keniana",
    "filipina", "tailandesa", "indonesia", "vietnamita", "pakistaní",
    "bangladesí", "israelí", "palestina", "siria", "iraquí", "iraní",
    "saudí", "senegalesa", "etíope", "ghanesa", "libanesa", "jordana",
    "malasia", "singapurense", "neozelandesa",
)

MUNICIPIOS_ESTADOS = (
    ("Guadalajara", "Jalisco"), ("Monterrey", "Nuevo Leon"),
    ("Leon", "Guanajuato"), ("Merida", "Yucatan"),
    ("Puebla", "Puebla"), ("Queretaro", "Queretaro"),
    ("Durango", "Durango"), ("Morelia", "Michoacan"),
    ("Hermosillo", "Sonora"), ("Tuxtla Gutierrez", "Chiapas"),
    ("Oaxaca de Juarez", "Oaxaca"), ("Tijuana", "Baja California"),
    ("Gomez Farias", "Tamaulipas"), ("San Luis Potosi", "San Luis Potosi"),
    ("Aguascalientes", "Aguascalientes"), ("Toluca", "Estado de Mexico"),
    ("Campeche", "Campeche"), ("Culiacan", "Sinaloa"),
)

FRASES = {
    "motivo": ["Acude porque presenta {v}.", "Solicita atencion por {v}.", "El motivo principal es {v}."],
    "modalidad_consulta": ["La atencion corresponde a {v}.", "Se trata de una consulta {v}."],
    "fecha": ["La fecha de atencion es {v}.", "El documento se registra el {v}."],
    "hora": ["La hora registrada es {v}.", "La atencion inicia a las {v}."],
    "padecimiento": ["Refiere que {v}.", "El padecimiento actual consiste en {v}."],
    "exploracion": ["A la exploracion se encuentra {v}.", "La exploracion fisica muestra {v}."],
    "resultados": ["Los resultados relevantes indican {v}.", "En los estudios se observa {v}."],
    "diagnostico": ["La impresion diagnostica es {v}.", "Se establece como diagnostico {v}."],
    "nota": ["En la evolucion se documenta {v}.", "La nota de evolucion refiere {v}."],
    "plan": ["El plan de manejo contempla {v}.", "Como tratamiento se indica {v}."],
    "pronostico": ["El pronostico se describe como {v}.", "La evolucion esperada es {v}."],
    "interrogatorio": ["Durante el interrogatorio, la persona refiere {v}.", "El relato de la atencion incluye {v}."],
    "estado_mental": ["En el estado mental se observa {v}.", "La valoracion neurologica describe {v}."],
    "destino": ["El destino al terminar la atencion es {v}.", "La persona queda en {v}."],
    "servicio_consultado": ["Se solicita valoracion por {v}.", "El servicio consultado es {v}."],
    "servicio_solicitante": ["La solicitud proviene de {v}.", "El servicio solicitante corresponde a {v}."],
    "resumen": ["El resumen clinico consigna {v}.", "Como resumen de la estancia se registra {v}."],
    "criterios": ["Los criterios diagnosticos incluyen {v}.", "La valoracion considera {v}."],
    "plan_estudios": ["El plan de estudios contempla {v}.", "Se solicitan los estudios siguientes: {v}."],
    "sugerencias": ["Como sugerencia diagnostica y terapeutica se anota {v}.", "Se proponen las siguientes medidas: {v}."],
    "tipo_envio": ["Se realiza un envio de tipo {v}.", "El tipo de traslado es {v}."],
    "establecimiento": ["El establecimiento de origen es {v}.", "La institucion que envia es {v}."],
    "servicio_destino": ["El servicio receptor es {v}.", "La persona se dirige al area de {v}."],
    "terapeutica": ["La terapeutica empleada fue {v}.", "Durante la atencion se administro {v}."],
    "motivo_egreso": ["El egreso ocurre por {v}.", "El motivo del alta es {v}."],
    "ingreso": ["La fecha de ingreso fue {v}.", "La hospitalizacion comenzo el {v}."],
    "servicio": ["La atencion se brinda en {v}.", "El servicio hospitalario es {v}."],
    "causas_defuncion": ["Las causas documentadas son {v}.", "Se registran como causas {v}."],
    "manejo": ["El manejo durante la estancia consistio en {v}.", "La atencion recibida incluyo {v}."],
    "pendientes": ["Quedan pendientes {v}.", "Los problemas por resolver son {v}."],
    "recomendaciones": ["Al egreso se recomiendan {v}.", "Las indicaciones posteriores son {v}."],
    "factores_riesgo": ["Se da seguimiento a {v}.", "El factor de riesgo identificado es {v}."],
    "medicamento": ["Se prescribe {v}.", "El farmaco indicado es {v}."],
    "presentacion": ["La presentacion es {v}.", "Se utiliza la forma farmaceutica {v}."],
    "cantidad": ["La cantidad a surtir es {v}.", "Se requieren {v} unidades."],
    "dosis": ["La dosis indicada es {v}.", "Se administra una cantidad de {v}."],
    "via": ["La via de administracion es {v}.", "Se administra por via {v}."],
    "periodicidad": ["La frecuencia indicada es {v}.", "Se administra {v}."],
    "duracion": ["El tratamiento dura {v}.", "La duracion prescrita es {v}."],
    "fecha_inicio": ["El tratamiento inicia el {v}.", "La fecha de inicio es {v}."],
    "fecha_fin": ["El tratamiento concluye el {v}.", "La fecha final prevista es {v}."],
    "estado_tratamiento": ["El estado del tratamiento queda como {v}.", "La prescripcion se marca {v}."],
    "indicaciones": ["Las indicaciones para la persona son {v}.", "Se explica que {v}."],
    "cedula_prescriptor": ["La cedula profesional registrada es {v}.", "Quien prescribe cuenta con la cedula {v}."],
    "institucion": ["La institucion que expidio el titulo es {v}.", "El titulo profesional fue expedido por {v}."],
    "estudio": ["El estudio realizado es {v}.", "Se documenta el estudio de {v}."],
    "categoria": ["El estudio corresponde a {v}.", "La categoria del estudio es {v}."],
    "realizado_en": ["El estudio se realizo en {v}.", "La unidad que lo realizo es {v}."],
    "solicitante": ["El profesional solicitante es {v}.", "El estudio fue solicitado por {v}."],
    "resultado": ["El resultado obtenido es {v}.", "El reporte informa {v}."],
    "fuera_rango": ["Los valores fuera de rango son {v}.", "Se observan fuera de rango {v}."],
    "interpretacion": ["La interpretacion del resultado es {v}.", "El reporte se interpreta como {v}."],
    "motivo_ingreso": ["La hospitalizacion se debe a {v}.", "El motivo de ingreso es {v}."],
    "cama": ["La cama asignada es {v}.", "Se ubica en la cama {v}."],
    "diagnostico_ingreso": ["El diagnostico al ingreso es {v}.", "Ingresa con el diagnostico de {v}."],
    "evolucion": ["Durante la estancia evoluciona con {v}.", "La evolucion documentada es {v}."],
    "egreso": ["La fecha de alta es {v}.", "El egreso se registra el {v}."],
    "estado_egreso": ["Al egreso se encuentra {v}.", "La condicion de salida es {v}."],
    "diagnostico_egreso": ["El diagnostico final es {v}.", "Al alta se registra {v}."],
    "procedimiento": ["El procedimiento documentado es {v}.", "Se realiza {v}."],
    "descripcion": ["El procedimiento consiste en {v}.", "Se explica que el acto autorizado es {v}."],
    "lugar": ["El procedimiento se realiza en {v}.", "El lugar del acto es {v}."],
    "riesgos": ["Entre los riesgos explicados se encuentran {v}.", "Se informan los riesgos siguientes: {v}."],
    "beneficios": ["Los beneficios esperados incluyen {v}.", "Se explican como beneficios {v}."],
    "alternativas": ["Las alternativas explicadas son {v}.", "Se comentan otras opciones: {v}."],
    "contingencias": ["La autorizacion de contingencias queda {v}.", "La respuesta sobre atencion de urgencias es {v}."],
    "otorga": ["El consentimiento lo otorga {v}.", "La autorizacion proviene de {v}."],
    "otorgante": ["El nombre de quien otorga es {v}.", "La persona autorizante se identifica como {v}."],
    "parentesco": ["El parentesco con la persona es {v}.", "La relacion del representante es {v}."],
    "testigos": ["Los testigos son {v}.", "Firman como testigos {v}."],
    "dias": ["Se indican {v} dias de incapacidad.", "La incapacidad es por {v} dias."],
    "tipo_incapacidad": ["La incapacidad corresponde a {v}.", "El tipo de incapacidad es {v}."],
    "observaciones": ["Como observacion se registra {v}.", "Se deja constancia de {v}."],
    "fecha_cirugia": ["La cirugia esta programada para {v}.", "La fecha de la intervencion es {v}."],
    "tipo_intervencion": ["El tipo de intervencion planeada es {v}.", "Se programa una intervencion {v}."],
    "riesgo_quirurgico": ["El riesgo quirurgico se clasifica como {v}.", "La valoracion de riesgo operatorio indica {v}."],
    "plan_quirurgico": ["El plan quirurgico contempla {v}.", "La estrategia operatoria es {v}."],
    "cuidados": ["Los cuidados indicados son {v}.", "Se realizan los siguientes cuidados: {v}."],
    "inicio": ["La operacion inicia a las {v}.", "La hora de inicio es {v}."],
    "fin": ["La operacion termina a las {v}.", "La hora de termino es {v}."],
    "sangrado": ["El sangrado estimado es {v}.", "La perdida sanguinea se registra en {v}."],
    "hallazgos": ["Los hallazgos observados son {v}.", "Durante la intervencion se encuentra {v}."],
    "tecnica": ["La tecnica quirurgica empleada es {v}.", "Se utiliza la tecnica {v}."],
    "complicaciones": ["Las complicaciones documentadas son {v}.", "Durante la atencion se presentan {v}."],
    "estado_final": ["El estado final de la persona es {v}.", "Al terminar, la condicion es {v}."],
    "valoracion": ["La valoracion de enfermeria registra {v}.", "En la valoracion se observa {v}."],
    "medicamentos": ["Los medicamentos administrados fueron {v}.", "Se registra la administracion de {v}."],
    "padecimiento_actual": ["El problema clinico actual consiste en {v}.", "El padecimiento actual es {v}."],
    "tratamiento": ["Los tratamientos recibidos incluyen {v}.", "Se documenta la terapia {v}."],
    "estudios": ["Los estudios realizados incluyen {v}.", "Se cuenta con estudios de {v}."],
    "nombre": ["El nombre de la persona es {v}.", "Se identifica como {v}."],
    "primer_apellido": ["El primer apellido es {v}.", "Su apellido paterno es {v}."],
    "segundo_apellido": ["El segundo apellido es {v}.", "Su apellido materno es {v}."],
    "fecha_nacimiento": ["La fecha de nacimiento es {v}.", "Nacio el {v}."],
    "sexo": ["El sexo registrado es {v}.", "La persona se identifica con sexo {v}."],
    "curp": ["La CURP de prueba es {v}.", "Se registra la clave ficticia {v}."],
    "entidad_nacimiento": ["Nacio en {v}.", "La entidad de nacimiento es {v}."],
    "nacionalidad": ["La nacionalidad registrada es {v}.", "Es de nacionalidad {v}."],
    "telefono": ["El telefono de contacto ficticio es {v}.", "Se proporciona el numero de prueba {v}."],
    "correo": ["El correo de prueba es {v}.", "Se registra el correo ficticio {v}."],
    "domicilio": ["El domicilio ficticio es {v}.", "La direccion de prueba corresponde a {v}."],
    "municipio_estado": ["Reside en {v}.", "El municipio y estado registrados son {v}."],
    "contacto_emergencia": ["El contacto de emergencia ficticio es {v}.", "Para emergencias se contacta a {v}."],
    "telefono_emergencia": ["El telefono de emergencia de prueba es {v}.", "El numero del contacto ficticio es {v}."],
    "alergias": ["Las alergias referidas son {v}.", "En alergias se registra {v}."],
    "grupo_sanguineo": ["El grupo sanguineo registrado es {v}.", "El tipo de sangre de prueba es {v}."],
    "padecimientos_cronicos": ["Los padecimientos cronicos ficticios son {v}.", "Como antecedente cronico de prueba se anota {v}."],
    "medicamentos_actuales": ["La medicacion habitual ficticia es {v}.", "Como medicamento actual de prueba se menciona {v}."],
    "motivo_consulta": ["La razon de consulta es {v}.", "Solicita atencion por {v}."],
    "estado_civil": ["El estado civil registrado es {v}.", "Refiere estado civil {v}."],
    "ocupacion": ["Actualmente trabaja como {v}.", "Su ocupacion es {v}."],
    "escolaridad": ["Su escolaridad es {v}.", "Cuenta con estudios de {v}."],
    "afiliacion_institucional": ["Su afiliacion institucional es {v}.", "Cuenta con seguro de salud {v}."],
    "tutor_representante": ["Su representante ficticio es {v}.", "Se registra como tutor de prueba a {v}."],
    "grupo_etnico": ["Refiere pertenecer al grupo {v}.", "El grupo etnico referido es {v}."],
    "lengua_materna": ["Su lengua materna es {v}.", "Refiere hablar {v} como primer idioma."],
    "antecedentes_heredofamiliares": ["En antecedentes familiares ficticios, {v}.", "Su familia refiere el antecedente de prueba: {v}."],
    "antecedentes_personales_patologicos": ["Como antecedente personal de prueba, {v}.", "Refiere de manera ficticia {v}."],
    "antecedentes_personales_no_patologicos": ["En habitos no patologicos ficticios, {v}.", "Respecto a sus habitos, el ejemplo registra {v}."],
    "inmunizaciones": ["Refiere esquema de vacunacion ficticio {v}.", "En inmunizaciones de prueba se registra {v}."],
    "antecedentes_ginecoobstetricos": ["En antecedentes ginecoobstetricos ficticios, {v}.", "Refiere como dato ginecoobstetrico simulado {v}."],
    "habitos_sustancias": ["Respecto al consumo, refiere de forma ficticia {v}.", "En habitos se registra para la prueba {v}."],
    "entidad_nacimiento": ["Nacio en {v}, Mexico.", "Su entidad de nacimiento registrada es {v}."],
    "nacionalidad": ["Su nacionalidad es {v}.", "Se registra nacionalidad {v}."],
    "municipio_estado": ["Actualmente reside en {v}.", "Su municipio y estado de residencia son {v}."],
    "domicilio": ["Su domicilio ficticio corresponde a {v}.", "Reside en la direccion sintetica {v}."],
    "signos.sistolica": ["La presion sistolica es {v}.", "Se registra presion arterial sistolica de {v}."],
    "signos.diastolica": ["La presion diastolica es {v}.", "Se registra presion arterial diastolica de {v}."],
    "signos.fc": ["La frecuencia cardiaca es {v}.", "Se registra pulso de {v}."],
    "signos.fr": ["La frecuencia respiratoria es {v}.", "Se registran {v} respiraciones por minuto."],
    "signos.temperatura": ["La temperatura corporal es {v}.", "Se registra temperatura de {v} grados."],
    "signos.spo2": ["La saturacion de oxigeno es {v}.", "Se registra una oximetria de {v} por ciento."],
    "signos.glucosa": ["La glucosa capilar es {v}.", "Se registra glucosa de {v}."],
    "signos.peso": ["El peso corporal es {v}.", "Se registra un peso de {v} kilogramos."],
    "signos.talla": ["La talla es {v}.", "Se registra una estatura de {v} centimetros."],
}

VALORES = {
    "motivo": ["dolor abdominal simulado", "revision preventiva ficticia", "molestia de garganta de prueba"],
    "modalidad_consulta": ["general", "seguimiento", "primera vez"],
    "padecimiento": ["molestia leve de prueba desde ayer", "tos ficticia sin otros datos", "dolor de ejemplo intermitente"],
    "exploracion": ["sin hallazgos de prueba", "dato simulado pendiente de confirmar", "hallazgo ficticio no concluyente"],
    "resultados": ["resultado ficticio dentro del ejemplo", "dato simulado sin interpretacion", "valor de prueba pendiente"],
    "diagnostico": ["condicion de prueba no clinica", "etiqueta diagnostica simulada", "dato de entrenamiento sin validez medica"],
    "plan": ["seguimiento de ejemplo sin indicacion real", "plan ficticio pendiente de revision", "dato simulado para clasificacion"],
    "pronostico": ["dato de entrenamiento", "pendiente de evaluacion ficticia", "no aplica en este ejemplo"],
    "destino": ["observacion de ejemplo", "domicilio ficticio", "servicio simulado"],
    "servicio": ["servicio de prueba", "consulta simulada", "unidad ficticia"],
    "riesgos": ["riesgo hipotetico A", "escenario de prueba B", "posibilidad ficticia C"],
    "beneficios": ["beneficio hipotetico A", "escenario de prueba B", "beneficio ficticio C"],
    "estado_civil": ["soltera", "casado", "divorciada", "viudo", "union libre"],
    "ocupacion": ["docente", "comerciante", "estudiante", "profesional de prueba"],
    "escolaridad": ["primaria concluida", "secundaria concluida", "bachillerato concluido", "licenciatura concluida"],
    "afiliacion_institucional": ["IMSS", "ISSSTE", "ninguna"],
    "grupo_etnico": ["mestizo", "no especificado", "dato ficticio"],
    "lengua_materna": ["espanol", "maya", "nahuatl", "ingles"],
    "antecedentes_heredofamiliares": [
        "su madre refiere un antecedente simulado de asma",
        "un familiar ficticio tuvo diabetes de ejemplo",
        "no se reportan antecedentes familiares en este ejemplo",
    ],
    "antecedentes_personales_patologicos": [
        "niega enfermedades cronicas en este escenario ficticio",
        "refiere antecedente simulado de hipotiroidismo",
        "sin antecedentes patologicos documentados para la prueba",
    ],
    "antecedentes_personales_no_patologicos": [
        "realiza caminatas de ejemplo y niega tabaquismo",
        "consume agua de manera ficticia y no refiere drogas",
        "habita una vivienda urbana simulada con servicios basicos",
    ],
    "inmunizaciones": [
        "vacunacion ficticia contra influenza en octubre",
        "esquema de prueba referido como completo",
        "dato simulado pendiente de comprobante",
    ],
    "antecedentes_ginecoobstetricos": [
        "antecedente ginecoobstetrico simulado sin validez medica",
        "dato de prueba pendiente de confirmacion",
        "sin datos aplicables en este ejemplo sintetico",
    ],
    "habitos_sustancias": [
        "niega tabaquismo y drogas en el escenario de prueba",
        "refiere consumo ocasional ficticio de bebidas alcoholicas",
        "no se registra consumo de sustancias en este ejemplo",
    ],
}


def _valor(campo: str, fake: Faker, rng: random.Random) -> str:
    if campo == "nombre":
        return fake.first_name()
    if campo in ("otorgante", "contacto_emergencia", "testigos", "solicitante"):
        return fake.name()
    if campo in ("primer_apellido", "segundo_apellido"):
        return fake.last_name()
    if campo == "correo":
        local = re.sub(r"[^a-z0-9._-]", "", fake.user_name().lower())[:24] or "prueba"
        return f"{local}@example.invalid"
    if campo in ("telefono", "telefono_emergencia"):
        return f"555{rng.randint(1000000, 9999999)}"
    if campo == "curp":
        return f"SIMU{rng.randint(1000000000, 9999999999)}"
    if campo == "municipio_estado":
        municipio, estado = rng.choice(MUNICIPIOS_ESTADOS)
        return f"{municipio}, {estado}"
    if campo == "domicilio":
        return f"calle ficticia {rng.randint(1, 999)}, colonia de prueba, CP {rng.randint(10000, 99999)}"
    if campo == "entidad_nacimiento":
        return rng.choice(ENTIDADES_FEDERATIVAS)
    if campo == "nacionalidad":
        return rng.choice(NACIONALIDADES)
    if campo in ("fecha", "fecha_inicio", "fecha_fin", "fecha_nacimiento", "ingreso", "egreso", "fecha_cirugia"):
        return (date(2020, 1, 1) + timedelta(days=rng.randint(0, 2000))).isoformat()
    if campo in ("hora", "inicio", "fin"):
        return f"{rng.randint(0, 23):02}:{rng.choice([0, 15, 30, 45]):02}"
    if campo.startswith("signos."):
        rangos = {
            "signos.sistolica": (90, 150), "signos.diastolica": (55, 95),
            "signos.fc": (55, 110), "signos.fr": (10, 28),
            "signos.temperatura": (35, 40), "signos.spo2": (88, 100),
            "signos.glucosa": (65, 220), "signos.peso": (40, 130),
            "signos.talla": (140, 200),
        }
        minimo, maximo = rangos[campo]
        return str(rng.randint(minimo, maximo))
    if campo in VALORES:
        return rng.choice(VALORES[campo])
    opciones = VALORES.get("padecimiento", ["informacion ficticia", "dato simulado", "ejemplo no clinico"])
    return rng.choice(opciones)


def _texto_por_perspectiva(campo: str, valor: str, rng: random.Random) -> str:
    if campo in ("motivo", "motivo_consulta"):
        frases = (
            "Acude por dolor abdominal simulado.",
            "El motivo de consulta es una molestia ficticia.",
            "Solicita atencion por un sintoma gastrointestinal de ejemplo.",
        )
        return rng.choice(frases)
    if campo in ("padecimiento", "padecimiento_actual"):
        frases = (
            "Me duele la panza desde hace dos dias.",
            "Me duele la pansa desde ayer.",
            "Refiere dolor de panza desde hace tres dias.",
            "La paciente comenta dolor abdominal de un dia de evolucion.",
            "El paciente refiere molestia abdominal ficticia.",
        )
        return rng.choice(frases)
    if campo == "nombre":
        return rng.choice((f"Me llamo {valor}.", f"El nombre de la persona es {valor}."))
    if campo == "primer_apellido":
        return rng.choice((f"Mi apellido paterno es {valor}.", f"El primer apellido es {valor}."))
    if campo == "segundo_apellido":
        return rng.choice((f"Mi apellido materno es {valor}.", f"El segundo apellido es {valor}."))
    if campo in ("padecimientos_cronicos", "antecedentes_personales_patologicos"):
        return rng.choice((
            f"Tengo {valor}.",
            f"La persona refiere {valor}.",
            f"Como antecedente personal, se registra {valor}.",
        ))
    if campo == "alergias":
        return rng.choice((
            f"Soy alergico a {valor}.",
            f"La paciente refiere alergia a {valor}.",
            f"En alergias se registra {valor}.",
        ))
    if campo == "medicamentos_actuales":
        return rng.choice((
            f"Actualmente tomo {valor}.",
            f"La persona utiliza {valor}.",
            f"Se registra como medicacion habitual ficticia {valor}.",
        ))
    if campo == "fecha_nacimiento":
        return rng.choice((
            f"Naci el {valor}.",
            f"La fecha de nacimiento registrada es {valor}.",
        ))
    if campo == "entidad_nacimiento":
        return f"Nacio en {valor}, Mexico."
    if campo == "nacionalidad":
        return rng.choice((f"Soy de nacionalidad {valor}.", f"La persona es {valor}."))
    if campo == "municipio_estado":
        return rng.choice((f"Vivo en {valor}.", f"Actualmente reside en {valor}."))
    clave = campo.rsplit(".", 1)[-1]
    plantilla = rng.choice(FRASES.get(
        campo,
        FRASES.get(clave, ("Se documenta {v}.",)),
    ))
    return plantilla.format(v=valor)


def generar_ejemplos(por_documento: int, semilla: int) -> list[dict[str, str]]:
    rng = random.Random(semilla)
    fake = Faker("es_MX")
    fake.seed_instance(semilla)
    ejemplos: list[dict[str, str]] = []
    for documento, campos in DOCUMENTOS.items():
        for indice in range(por_documento):
            campo = campos[indice % len(campos)]
            clave = campo.rsplit(".", 1)[-1]
            cue = rng.choice(ETIQUETAS.get(
                campo,
                ETIQUETAS.get(clave, (clave.replace("_", " "),)),
            ))
            valor = _valor(campo, fake, rng)
            ejemplos.append({
                "tipo": documento,
                "campo": campo,
                "texto": (
                    _texto_por_perspectiva(campo, valor, rng)
                    if rng.random() < 0.65
                    else f"Sobre {cue}, se registra {valor}."
                ),
                "sintetico": "faker-es_MX",
            })
    rng.shuffle(ejemplos)
    return ejemplos


def generar_preguntas_sin_respuesta(semilla: int) -> list[dict[str, str]]:
    rng = random.Random(semilla)
    preguntas = [
        "¿Te duele la panza desde ayer?",
        "¿Tienes alergia a algun medicamento?",
        "¿Tomas algun tratamiento actualmente?",
        "¿Naciste en Jalisco o en otro estado?",
        "¿Tu familiar tiene antecedentes de diabetes?",
    ]
    rng.shuffle(preguntas)
    return [
        {
            "tipo_registro": "pregunta_sin_respuesta",
            "texto": pregunta,
            "sintetico": "pregunta-sintetica-es_MX",
        }
        for pregunta in preguntas
    ]


if __name__ == "__main__":
    for ejemplo in generar_ejemplos(2, 42):
        print(ejemplo)
