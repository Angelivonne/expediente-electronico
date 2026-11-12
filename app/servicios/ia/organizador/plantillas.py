"""Banco linguistico del piloto: valores, plantillas y etiquetas semanticas.

Todo el contenido es ficticio o de dominio general. No proviene de expedientes.
"""

from __future__ import annotations

NEGACION = ("afirmado", "negado")
TEMPORALIDAD = ("actual", "pasado", "habitual", "futuro", "familiar")
SUJETO = ("paciente", "familiar", "otro")
CERTEZA = ("confirmado", "referido", "dudoso")

TAREAS = {
    "negacion": NEGACION,
    "temporalidad": TEMPORALIDAD,
    "sujeto": SUJETO,
    "certeza": CERTEZA,
}

# Campos donde negar tiene sentido clinico. En fechas o identificadores no se niega nada.
CAMPOS_NEGABLES = frozenset({
    "motivo", "padecimiento", "padecimiento_actual", "exploracion", "resultados",
    "diagnostico", "alergias", "padecimientos_cronicos", "medicamentos_actuales",
    "antecedentes_heredofamiliares", "antecedentes_personales_patologicos",
    "antecedentes_personales_no_patologicos", "antecedentes_ginecoobstetricos",
    "habitos_sustancias", "inmunizaciones", "complicaciones", "hallazgos",
    "sangrado", "factores_riesgo", "causas_defuncion", "estado_mental",
})

# Campos que aceptan un modificador temporal al final sin romper la gramatica.
CAMPOS_NARRATIVOS = frozenset({
    "motivo", "motivo_consulta", "padecimiento", "padecimiento_actual",
    "exploracion", "resultados", "diagnostico", "nota", "plan", "pronostico",
    "interrogatorio", "estado_mental", "resumen", "criterios", "evolucion",
    "manejo", "pendientes", "recomendaciones", "factores_riesgo", "alergias",
    "padecimientos_cronicos", "medicamentos_actuales", "valoracion",
    "antecedentes_heredofamiliares", "antecedentes_personales_patologicos",
    "antecedentes_personales_no_patologicos", "antecedentes_ginecoobstetricos",
    "habitos_sustancias", "inmunizaciones", "complicaciones", "hallazgos",
    "tratamiento", "estudios", "observaciones", "sugerencias", "terapeutica",
})

MUNICIPIOS_EXTRA = (
    ("Veracruz", "Veracruz"), ("Cancun", "Quintana Roo"),
    ("Acapulco", "Guerrero"), ("Saltillo", "Coahuila"),
    ("Mexicali", "Baja California"), ("Chihuahua", "Chihuahua"),
    ("Villahermosa", "Tabasco"), ("Tlaxcala", "Tlaxcala"),
    ("Colima", "Colima"), ("Pachuca", "Hidalgo"),
    ("Cuernavaca", "Morelos"), ("Tepic", "Nayarit"),
    ("Zacatecas", "Zacatecas"), ("Chetumal", "Quintana Roo"),
    ("La Paz", "Baja California Sur"), ("Campeche", "Campeche"),
)

# Valores en forma de sintagma nominal: encajan tanto en "Niega {v}" como en "{cue}: {v}".
VALORES_EXTENDIDOS = {
    "motivo": [
        "dolor abdominal", "dolor de cabeza", "fiebre", "tos seca", "dolor de garganta",
        "mareo", "nauseas", "vomito", "diarrea", "dolor lumbar", "dolor toracico",
        "falta de aire", "palpitaciones", "ardor al orinar", "dolor de oido",
        "revision preventiva", "control de tratamiento", "seguimiento de padecimiento",
        "dolor en la rodilla", "comezon en la piel", "acidez estomacal", "estrenimiento",
        "dolor menstrual", "vision borrosa", "zumbido de oidos", "cansancio",
    ],
    "modalidad_consulta": [
        "general", "seguimiento", "primera vez", "urgencia", "subsecuente",
        "domiciliaria", "telefonica", "de especialidad", "preventiva",
    ],
    "padecimiento": [
        "dolor abdominal de tres dias de evolucion", "tos seca desde ayer",
        "fiebre de 38 grados desde hace dos dias", "dolor de cabeza intermitente",
        "nauseas y vomito desde esta manana", "mareo al levantarse",
        "dolor lumbar que empeora al caminar", "ardor al orinar desde hace una semana",
        "falta de aire al subir escaleras", "palpitaciones en reposo",
        "dolor de garganta al tragar", "diarrea de dos dias de evolucion",
        "comezon generalizada en la piel", "acidez despues de comer",
        "dolor en la rodilla derecha al flexionar", "cansancio de varias semanas",
    ],
    "padecimiento_actual": [
        "dolor abdominal persistente", "cuadro febril de dos dias",
        "tos productiva de una semana", "cefalea intensa de inicio subito",
        "mareo e inestabilidad al caminar", "disnea de medianos esfuerzos",
        "dolor toracico opresivo", "nauseas sin vomito", "diarrea acuosa",
        "dolor articular en ambas rodillas", "lesion cutanea con comezon",
    ],
    "exploracion": [
        "abdomen blando sin dolor a la palpacion", "abdomen doloroso en fosa iliaca derecha",
        "campos pulmonares bien ventilados", "estertores en base pulmonar derecha",
        "ruidos cardiacos ritmicos sin soplos", "soplo sistolico en foco mitral",
        "faringe hiperemica sin exudado", "edema en miembros inferiores",
        "fuerza muscular conservada", "reflejos osteotendinosos normales",
        "piel integra sin lesiones", "lesion eritematosa en antebrazo",
        "orientado en tiempo lugar y persona", "somnoliento pero responsivo",
        "signos de deshidratacion leve", "sin datos de alarma a la exploracion",
    ],
    "resultados": [
        "biometria hematica dentro de parametros", "leucocitos elevados",
        "glucosa en ayuno de 110", "hemoglobina de 13.5", "plaquetas normales",
        "radiografia de torax sin consolidaciones", "infiltrado basal derecho",
        "examen general de orina con bacterias", "creatinina de 0.9",
        "perfil tiroideo normal", "electrocardiograma en ritmo sinusal",
        "ultrasonido abdominal sin hallazgos", "sin alteraciones relevantes",
    ],
    "diagnostico": [
        "gastritis", "faringitis aguda", "infeccion de vias urinarias",
        "hipertension arterial sistemica", "diabetes mellitus tipo 2",
        "asma bronquial", "rinitis alergica", "dermatitis de contacto",
        "lumbalgia mecanica", "migrana", "gastroenteritis aguda",
        "amigdalitis", "bronquitis aguda", "anemia leve", "hipotiroidismo",
        "artrosis de rodilla", "conjuntivitis", "sinusitis", "reflujo gastroesofagico",
    ],
    "plan": [
        "tratamiento sintomatico y vigilancia", "antibiotico por siete dias",
        "control en dos semanas", "estudios de laboratorio y gabinete",
        "hidratacion oral y dieta blanda", "analgesia y reposo relativo",
        "referencia a especialidad", "ajuste de dosis del medicamento actual",
        "medidas higienico dieteticas", "seguimiento por consulta externa",
    ],
    "pronostico": [
        "reservado a la evolucion", "favorable", "bueno para la vida y la funcion",
        "malo para la funcion", "pendiente de evaluacion", "estable",
    ],
    "nota": [
        "paciente estable sin datos de alarma", "refiere mejoria parcial",
        "persiste el dolor pero de menor intensidad", "tolera la via oral",
        "sin fiebre en las ultimas veinticuatro horas", "ambulatorio",
        "se explica el plan y acepta", "evoluciona favorablemente",
    ],
    "interrogatorio": [
        "inicio subito del dolor", "sintomas progresivos de una semana",
        "antecedente de contacto con enfermo", "viaje reciente al extranjero",
        "consumo de alimentos en la calle", "traumatismo previo",
        "sintomas que ceden con el reposo", "cuadro similar previo",
    ],
    "estado_mental": [
        "alerta y orientado", "somnoliento", "confuso", "agitado", "ansioso",
        "consciente", "desorientado en tiempo", "apatico",
        "estado neurologico normal", "sin alteraciones del estado mental",
    ],
    "destino": [
        "domicilio", "hospitalizacion", "observacion en urgencias",
        "unidad de cuidados intensivos", "consulta externa", "quirofano",
        "otro establecimiento", "servicio de medicina interna",
    ],
    "servicio_consultado": [
        "cardiologia", "neurologia", "gastroenterologia", "neumologia",
        "traumatologia", "dermatologia", "endocrinologia", "nefrologia",
        "ginecologia", "urologia", "oftalmologia", "otorrinolaringologia",
        "cirugia general", "medicina interna", "pediatria", "psiquiatria",
    ],
    "servicio_solicitante": [
        "medicina general", "urgencias", "medicina interna", "cirugia general",
        "pediatria", "ginecologia", "consulta externa", "unidad de cuidados intensivos",
    ],
    "servicio_destino": [
        "medicina interna", "cirugia general", "urgencias", "cardiologia",
        "unidad de cuidados intensivos", "pediatria", "traumatologia",
    ],
    "resumen": [
        "paciente con cuadro febril de tres dias que cede con antibiotico",
        "estancia hospitalaria sin complicaciones", "evolucion favorable",
        "ingreso por dolor abdominal y manejo conservador",
        "cuadro respiratorio con mejoria progresiva", "control metabolico adecuado",
    ],
    "criterios": [
        "criterios clinicos y de laboratorio compatibles", "dos criterios mayores",
        "hallazgos de imagen concordantes", "sin criterios de gravedad",
        "cumple criterios de ingreso", "valoracion integral del cuadro",
    ],
    "plan_estudios": [
        "biometria hematica y quimica sanguinea", "radiografia de torax",
        "examen general de orina", "ultrasonido abdominal", "electrocardiograma",
        "tomografia de craneo", "perfil tiroideo", "hemoglobina glucosilada",
        "estudios de gabinete pendientes", "ninguno por el momento",
    ],
    "sugerencias": [
        "completar esquema antibiotico", "control en una semana",
        "valoracion por especialista", "medidas generales de higiene",
        "evitar automedicacion", "acudir a urgencias si hay datos de alarma",
    ],
    "tipo_envio": [
        "referencia", "contrarreferencia", "traslado", "interconsulta",
        "envio a segundo nivel", "envio a tercer nivel", "alta con referencia",
    ],
    "establecimiento": [
        "unidad de medicina familiar", "hospital general", "clinica de especialidades",
        "centro de salud", "hospital regional", "unidad de primer nivel",
    ],
    "terapeutica": [
        "antibiotico y analgesico", "hidratacion parenteral", "oxigeno suplementario",
        "broncodilatador inhalado", "antihipertensivo", "insulina",
        "manejo sintomatico", "ninguna durante el traslado",
    ],
    "motivo_egreso": [
        "mejoria clinica", "alta voluntaria", "referencia a otro nivel",
        "defuncion", "traslado a otra unidad", "estabilidad clinica",
        "termino del tratamiento",
    ],
    "servicio": [
        "medicina interna", "cirugia general", "pediatria", "ginecologia",
        "urgencias", "unidad de cuidados intensivos", "traumatologia",
        "neumologia", "cardiologia", "consulta externa",
    ],
    "causas_defuncion": [
        "no aplica", "choque septico", "insuficiencia respiratoria aguda",
        "infarto agudo al miocardio", "evento vascular cerebral",
    ],
    "manejo": [
        "antibioticoterapia y vigilancia", "hidratacion y analgesia",
        "oxigenoterapia", "control de glucosa", "curaciones diarias",
        "terapia fisica", "manejo conservador", "tratamiento quirurgico",
    ],
    "pendientes": [
        "resultado de cultivo", "valoracion por cardiologia", "estudio de imagen",
        "ajuste de dosis", "control de laboratorio", "ningun pendiente",
        "biopsia pendiente", "seguimiento por consulta externa",
    ],
    "recomendaciones": [
        "reposo relativo por tres dias", "dieta baja en sal", "hidratacion abundante",
        "control en dos semanas", "acudir a urgencias si hay fiebre",
        "completar el antibiotico", "evitar esfuerzos", "medicion de presion diaria",
    ],
    "factores_riesgo": [
        "tabaquismo", "obesidad", "sedentarismo", "hipertension mal controlada",
        "diabetes de larga evolucion", "antecedente familiar de cancer",
        "dislipidemia", "consumo de alcohol", "ninguno identificado",
    ],
    "medicamento": [
        "paracetamol", "ibuprofeno", "amoxicilina", "metformina", "losartan",
        "omeprazol", "salbutamol", "atorvastatina", "enalapril", "metamizol",
        "azitromicina", "diclofenaco", "loratadina", "insulina glargina",
        "levotiroxina", "aspirina", "clopidogrel", "furosemida",
    ],
    "presentacion": [
        "tabletas de 500 mg", "capsulas de 250 mg", "jarabe de 100 ml",
        "solucion inyectable", "tabletas de 850 mg", "inhalador",
        "crema al dos por ciento", "gotas oftalmicas", "suspension oral",
        "tabletas de 100 mg", "ampolletas de 1 g",
    ],
    "cantidad": [
        "una caja con veinte tabletas", "dos cajas", "un frasco", "diez tabletas",
        "treinta capsulas", "un inhalador", "tres ampolletas",
        "una caja con catorce tabletas", "cinco cajas",
    ],
    "dosis": [
        "una tableta", "dos tabletas", "media tableta", "una capsula",
        "cinco mililitros", "diez mililitros", "una ampolleta", "dos inhalaciones",
        "una tableta de 500 mg", "un mililitro",
    ],
    "via": [
        "oral", "intravenosa", "intramuscular", "subcutanea", "inhalatoria",
        "topica", "rectal", "oftalmica", "nasal", "transdermica",
    ],
    "periodicidad": [
        "cada ocho horas", "cada doce horas", "cada veinticuatro horas",
        "cada seis horas", "una vez al dia", "dos veces al dia",
        "tres veces al dia", "cada semana", "en dosis unica", "segun necesidad",
    ],
    "duracion": [
        "tres dias", "cinco dias", "siete dias", "diez dias", "catorce dias",
        "un mes", "tres meses", "tratamiento continuo", "dosis unica",
        "hasta nueva valoracion",
    ],
    "estado_tratamiento": [
        "activo", "suspendido", "completado", "en curso", "pendiente de surtir",
        "modificado", "cancelado",
    ],
    "indicaciones": [
        "tomar con alimentos", "no suspender el tratamiento", "evitar el alcohol",
        "tomar en ayunas", "agitar antes de usar", "no manejar maquinaria",
        "completar el esquema aunque haya mejoria", "conservar en refrigeracion",
        "acudir si presenta reaccion adversa", "no exceder la dosis indicada",
    ],
    "estudio": [
        "biometria hematica", "quimica sanguinea", "examen general de orina",
        "radiografia de torax", "ultrasonido abdominal", "electrocardiograma",
        "tomografia de craneo", "resonancia magnetica", "perfil tiroideo",
        "hemoglobina glucosilada", "endoscopia", "espirometria", "cultivo faringeo",
        "panel lipidico", "ecocardiograma", "mastografia",
    ],
    "categoria": [
        "laboratorio", "gabinete", "imagen", "histopatologia", "microbiologia",
        "funcional", "endoscopico", "citologia",
    ],
    "realizado_en": [
        "laboratorio de la unidad", "servicio de radiologia", "unidad de imagen",
        "laboratorio externo", "servicio de endoscopia", "banco de sangre",
    ],
    "resultado": [
        "dentro de parametros normales", "leucocitosis", "glucosa elevada",
        "hemoglobina baja", "sin hallazgos patologicos", "infiltrado basal",
        "resultado pendiente", "alteracion leve sin significado clinico",
        "positivo para bacterias", "negativo",
    ],
    "fuera_rango": [
        "glucosa por encima del rango", "leucocitos elevados", "hemoglobina baja",
        "creatinina elevada", "ninguno", "potasio bajo", "sodio elevado",
        "plaquetas por debajo del rango",
    ],
    "interpretacion": [
        "compatible con proceso infeccioso", "sin alteraciones significativas",
        "sugiere proceso inflamatorio", "hallazgos normales para la edad",
        "requiere correlacion clinica", "resultado no concluyente",
        "compatible con anemia leve", "estudio dentro de la normalidad",
    ],
    "motivo_ingreso": [
        "dolor abdominal agudo", "cuadro febril", "disnea de reposo",
        "descontrol metabolico", "traumatismo", "cirugia programada",
        "observacion por dolor toracico", "infeccion de vias urinarias",
    ],
    "cama": ["12", "7", "23", "5", "101", "34", "8", "19", "2", "45"],
    "diagnostico_ingreso": [
        "dolor abdominal en estudio", "neumonia adquirida en la comunidad",
        "descompensacion metabolica", "fractura de femur", "colecistitis aguda",
        "infarto agudo al miocardio", "gastroenteritis", "crisis asmatica",
    ],
    "diagnostico_egreso": [
        "gastritis aguda", "neumonia resuelta", "diabetes controlada",
        "fractura consolidada", "colecistitis postoperada", "cuadro febril resuelto",
        "hipertension controlada", "asma estable",
    ],
    "evolucion": [
        "favorable", "estable", "con mejoria progresiva", "sin cambios",
        "desfavorable", "con complicaciones leves", "tolerando la via oral",
        "febril las primeras cuarenta y ocho horas", "ambulatorio desde el tercer dia",
    ],
    "estado_egreso": [
        "estable", "mejorado", "sin datos de alarma", "con tratamiento ambulatorio",
        "para seguimiento externo", "grave", "fallecido",
    ],
    "procedimiento": [
        "colecistectomia laparoscopica", "apendicectomia", "hernioplastia inguinal",
        "cesarea", "histerectomia", "artroscopia de rodilla", "cateterismo cardiaco",
        "endoscopia digestiva alta", "biopsia de piel", "curacion quirurgica",
        "amigdalectomia", "osteosintesis de femur",
    ],
    "descripcion": [
        "extraccion de la vesicula por via laparoscopica",
        "reseccion del apendice por via abierta", "reparacion de hernia con malla",
        "extraccion del producto por via abdominal",
        "exploracion articular con camara", "toma de muestra de tejido",
    ],
    "lugar": [
        "quirofano uno", "sala de procedimientos", "unidad de endoscopia",
        "quirofano de urgencias", "sala de curaciones", "consultorio de cirugia menor",
    ],
    "riesgos": [
        "sangrado", "infeccion", "dolor postoperatorio", "reaccion a la anestesia",
        "lesion de organos vecinos", "trombosis", "necesidad de reintervencion",
    ],
    "beneficios": [
        "resolucion del cuadro", "alivio del dolor", "mejoria de la funcion",
        "prevencion de complicaciones", "diagnostico definitivo",
    ],
    "alternativas": [
        "manejo conservador", "tratamiento medico", "otra tecnica quirurgica",
        "vigilancia sin intervencion", "no realizar el procedimiento",
    ],
    "contingencias": [
        "autorizada", "no autorizada", "pendiente de valoracion",
        "autorizada solo para urgencias", "se explican y aceptan",
    ],
    "parentesco": [
        "madre", "padre", "hijo", "hija", "hermano", "hermana", "conyuge",
        "abuela", "abuelo", "tio", "tia", "tutor legal", "representante legal",
        "si mismo",
    ],
    "tipo_incapacidad": [
        "enfermedad general", "riesgo de trabajo", "maternidad",
        "accidente de trabajo", "enfermedad profesional",
    ],
    "observaciones": [
        "sin observaciones adicionales", "paciente comprende el plan",
        "se entrega receta impresa", "requiere apoyo familiar",
        "pendiente resultado de laboratorio", "se explican signos de alarma",
    ],
    "tipo_intervencion": [
        "electiva", "de urgencia", "programada", "ambulatoria", "mayor", "menor",
        "diferida", "de alta especialidad",
    ],
    "riesgo_quirurgico": [
        "bajo", "moderado", "alto", "muy alto", "ASA uno", "ASA dos", "ASA tres",
        "pendiente de valoracion",
    ],
    "plan_quirurgico": [
        "abordaje laparoscopico", "abordaje abierto", "cirugia ambulatoria",
        "osteosintesis con placa", "reseccion y anastomosis",
        "exploracion quirurgica", "manejo en dos tiempos",
    ],
    "cuidados": [
        "ayuno de ocho horas", "aseo quirurgico", "signos vitales cada cuatro horas",
        "curacion diaria", "movilizacion temprana", "vigilancia de sangrado",
        "hidratacion parenteral", "control del dolor", "profilaxis antibiotica",
    ],
    "sangrado": [
        "minimo", "escaso", "moderado", "abundante", "sin sangrado relevante",
        "cincuenta mililitros", "doscientos mililitros", "quinientos mililitros",
    ],
    "hallazgos": [
        "vesicula con calculos", "apendice inflamado", "hernia de tres centimetros",
        "sin hallazgos patologicos", "tumoracion de dos centimetros",
        "adherencias", "liquido libre en cavidad", "fractura desplazada",
    ],
    "tecnica": [
        "laparoscopica", "abierta", "endoscopica", "percutanea", "microscopica",
        "robotica", "con uso de malla", "con osteosintesis",
    ],
    "complicaciones": [
        "ninguna", "sangrado leve", "infeccion de herida", "fiebre postoperatoria",
        "nauseas por anestesia", "retencion urinaria", "dehiscencia de herida",
        "trombosis venosa profunda", "neumonia intrahospitalaria",
    ],
    "estado_final": [
        "estable", "critico", "grave", "mejorado", "fallecido", "en recuperacion",
        "para traslado a piso", "sedado y conectado a ventilador",
    ],
    "valoracion": [
        "paciente consciente y orientado", "dolor controlado", "herida limpia",
        "tolera la dieta", "deambula con apoyo", "sin datos de infeccion",
        "piel integra", "elimina sin dificultad", "ansiedad leve",
    ],
    "medicamentos": [
        "paracetamol intravenoso", "metamizol", "antibiotico profilactico",
        "solucion fisiologica", "omeprazol", "analgesico de rescate",
        "antiemetico", "insulina rapida",
    ],
    "tratamiento": [
        "antibioticoterapia", "analgesia", "hidratacion", "broncodilatadores",
        "antihipertensivos", "hipoglucemiantes orales", "terapia fisica",
        "curaciones", "oxigenoterapia",
    ],
    "estudios": [
        "biometria hematica de control", "radiografia de torax", "quimica sanguinea",
        "ultrasonido", "electrocardiograma", "tomografia", "ninguno durante la estancia",
    ],
    "sexo": ["femenino", "masculino", "otro", "no especificado"],
    "estado_civil": [
        "soltera", "soltero", "casada", "casado", "divorciada", "divorciado",
        "viuda", "viudo", "union libre", "separada",
    ],
    "ocupacion": [
        "docente", "comerciante", "estudiante", "agricultor", "enfermera",
        "medico", "contador", "abogada", "ingeniero", "chofer", "cocinera",
        "jubilado", "ama de casa", "albanil", "secretaria", "desempleado",
    ],
    "escolaridad": [
        "primaria concluida", "primaria incompleta", "secundaria concluida",
        "bachillerato concluido", "licenciatura concluida", "posgrado",
        "sin escolaridad", "educacion tecnica",
    ],
    "afiliacion_institucional": [
        "IMSS", "ISSSTE", "ninguna", "seguro popular", "privado", "bienestar",
        "pemex", "sedena", "issste estatal",
    ],
    "grupo_etnico": [
        "mestizo", "indigena", "no especificado", "afrodescendiente",
        "nahua", "maya", "zapoteco", "mixteco",
    ],
    "lengua_materna": [
        "espanol", "maya", "nahuatl", "ingles", "zapoteco", "mixteco",
        "otomi", "totonaco", "tseltal", "tsotsil", "purhepecha",
    ],
    "grupo_sanguineo": [
        "O positivo", "O negativo", "A positivo", "A negativo", "B positivo",
        "B negativo", "AB positivo", "AB negativo", "desconocido",
    ],
    "alergias": [
        "penicilina", "sulfas", "aspirina", "ibuprofeno", "latex", "polen",
        "acaros", "alimentos marinos", "ninguna conocida", "contrastes yodados",
        "anestesicos locales", "huevo",
    ],
    "padecimientos_cronicos": [
        "diabetes mellitus tipo 2", "hipertension arterial", "asma",
        "hipotiroidismo", "artrosis", "enfermedad renal cronica", "EPOC",
        "obesidad", "dislipidemia", "ninguno", "artritis reumatoide",
    ],
    "medicamentos_actuales": [
        "metformina", "losartan", "levotiroxina", "salbutamol inhalado",
        "omeprazol", "atorvastatina", "aspirina", "insulina", "enalapril",
        "ninguno", "paracetamol ocasional",
    ],
    "motivo_consulta": [
        "dolor abdominal", "control de diabetes", "revision general",
        "dolor de cabeza", "fiebre", "seguimiento de hipertension",
        "renovacion de receta", "dolor de espalda", "tos persistente",
    ],
    "antecedentes_heredofamiliares": [
        "madre con diabetes", "padre con hipertension", "hermano con asma",
        "abuela con cancer de mama", "padre fallecido por infarto",
        "madre con hipotiroidismo", "sin antecedentes familiares de importancia",
        "ambos padres vivos y sanos", "tio con enfermedad renal",
    ],
    "antecedentes_personales_patologicos": [
        "varicela en la infancia", "apendicectomia previa", "fractura de brazo",
        "hospitalizacion por neumonia", "transfusion sanguinea", "cirugia cardiaca",
        "hepatitis en la juventud", "sin antecedentes patologicos de importancia",
        "alergia a penicilina",
    ],
    "antecedentes_personales_no_patologicos": [
        "vivienda urbana con servicios basicos", "hacinamiento",
        "convive con animales", "agua potable disponible", "drenaje conectado",
        "cocina con humo de lena", "realiza actividad fisica regular",
        "sedentarismo", "dieta alta en grasas", "higiene adecuada",
    ],
    "inmunizaciones": [
        "esquema completo para la edad", "influenza anual", "hepatitis B completa",
        "tetanos reciente", "neumococo", "esquema incompleto", "no recuerda",
        "sin vacunas registradas", "refuerzo pendiente",
    ],
    "antecedentes_ginecoobstetricos": [
        "menarca a los doce anos", "dos gestas un parto", "cesarea previa",
        "menopausia a los cincuenta", "ciclos regulares", "sin embarazos",
        "aborto previo", "usa metodo anticonceptivo", "no aplica",
        "ultima menstruacion hace dos semanas",
    ],
    "habitos_sustancias": [
        "tabaquismo de diez cigarros diarios", "alcoholismo ocasional",
        "consumo de marihuana", "niega tabaquismo y alcohol",
        "exfumador desde hace cinco anos", "consume cafe a diario",
        "sin consumo de sustancias", "alcoholismo social los fines de semana",
    ],
}

# Plantillas nuevas por campo: primera persona, tercera y forma impersonal.
PLANTILLAS = {
    "motivo": (
        "Acude por {v}.", "Solicita atencion por {v}.", "El motivo de consulta es {v}.",
        "Viene por {v}.", "Refiere {v} como motivo.", "Consulta por presentar {v}.",
        "Lo que le trae es {v}.", "Motivo de atencion: {v}.", "La persona acude por {v}.",
    ),
    "motivo_consulta": (
        "Acude por {v}.", "La razon de consulta es {v}.", "Solicita atencion por {v}.",
        "Viene a consulta por {v}.", "El motivo principal es {v}.",
    ),
    "padecimiento": (
        "Me duele la panza desde hace dos dias.", "Refiere {v}.",
        "El padecimiento actual consiste en {v}.", "La paciente comenta {v}.",
        "El paciente refiere {v}.", "Padece {v}.", "Presenta {v}.",
        "Comienza con {v}.", "Cuadro clinico de {v}.", "Manifiesta {v}.",
        "Desde hace dias presenta {v}.", "Refiere molestia por {v}.",
    ),
    "padecimiento_actual": (
        "El problema clinico actual es {v}.", "Presenta {v}.", "Refiere {v}.",
        "El padecimiento actual consiste en {v}.", "Actualmente cursa con {v}.",
    ),
    "exploracion": (
        "A la exploracion se encuentra {v}.", "La exploracion fisica muestra {v}.",
        "Exploro y encuentro {v}.", "A la revision fisica: {v}.",
        "Se objetiva {v}.", "En la exploracion: {v}.",
    ),
    "diagnostico": (
        "La impresion diagnostica es {v}.", "Se establece como diagnostico {v}.",
        "El diagnostico es {v}.", "Diagnostico: {v}.", "Se diagnostica {v}.",
        "Considero {v} como diagnostico.", "Impresion clinica: {v}.",
        "El cuadro es compatible con {v}.",
    ),
    "plan": (
        "El plan de manejo contempla {v}.", "Como tratamiento se indica {v}.",
        "Plan: {v}.", "Se indica {v}.", "El manejo sera {v}.",
        "Procedo a {v}.", "Se acuerda {v}.", "Conducta a seguir: {v}.",
    ),
    "pronostico": (
        "El pronostico es {v}.", "Pronostico: {v}.", "La evolucion esperada es {v}.",
        "Se considera pronostico {v}.",
    ),
    "alergias": (
        "Soy alergico a {v}.", "La paciente refiere alergia a {v}.",
        "En alergias se registra {v}.", "Refiere alergia a {v}.",
        "Alergias: {v}.", "Presenta alergia a {v}.", "Es alergica a {v}.",
    ),
    "medicamentos_actuales": (
        "Actualmente tomo {v}.", "La persona utiliza {v}.", "Medicacion habitual: {v}.",
        "Toma {v} de forma habitual.", "Refiere uso de {v}.",
        "Como medicamento actual se registra {v}.",
    ),
    "padecimientos_cronicos": (
        "Tengo {v}.", "Padece {v} desde hace anos.", "Antecedente cronico: {v}.",
        "Refiere {v} como padecimiento cronico.", "Es portador de {v}.",
    ),
    "nombre": (
        "Me llamo {v}.", "El nombre de la persona es {v}.", "Mi nombre es {v}.",
        "Se identifica como {v}.", "Nombre: {v}.",
    ),
    "primer_apellido": (
        "Mi apellido paterno es {v}.", "El primer apellido es {v}.",
        "Apellido paterno: {v}.", "Su apellido paterno es {v}.",
    ),
    "segundo_apellido": (
        "Mi apellido materno es {v}.", "El segundo apellido es {v}.",
        "Apellido materno: {v}.", "Su apellido materno es {v}.",
    ),
    "fecha_nacimiento": (
        "Naci el {v}.", "La fecha de nacimiento es {v}.", "Fecha de nacimiento: {v}.",
        "Nacio el {v}.",
    ),
    "entidad_nacimiento": (
        "Naci en {v}.", "Nacio en {v}, Mexico.", "La entidad de nacimiento es {v}.",
        "Es originario de {v}.", "Entidad de nacimiento: {v}.",
    ),
    "nacionalidad": (
        "Soy de nacionalidad {v}.", "La persona es {v}.", "Nacionalidad: {v}.",
        "Su nacionalidad es {v}.", "Es de nacionalidad {v}.",
    ),
    "municipio_estado": (
        "Vivo en {v}.", "Actualmente reside en {v}.", "Municipio y estado: {v}.",
        "Reside en {v}.", "Su lugar de residencia es {v}.",
    ),
    "domicilio": (
        "Vivo en {v}.", "El domicilio es {v}.", "Domicilio: {v}.",
        "Reside en {v}.", "Su direccion es {v}.",
    ),
    "telefono": (
        "Mi telefono es {v}.", "El telefono de contacto es {v}.", "Telefono: {v}.",
        "Se le puede localizar en el {v}.",
    ),
    "correo": (
        "Mi correo es {v}.", "El correo electronico es {v}.", "Correo: {v}.",
        "Se registra el correo {v}.",
    ),
    "sexo": (
        "Sexo: {v}.", "El sexo registrado es {v}.",
        "La persona se identifica con sexo {v}.", "Sexo de la persona: {v}.",
    ),
    "curp": (
        "Mi CURP es {v}.", "La CURP registrada es {v}.", "CURP: {v}.",
        "Clave unica de registro: {v}.",
    ),
    "signos.sistolica": (
        "La presion sistolica es {v}.", "Presion arterial sistolica de {v}.",
        "Sistolica: {v}.", "Se registra sistolica de {v}.",
    ),
    "signos.diastolica": (
        "La presion diastolica es {v}.", "Presion arterial diastolica de {v}.",
        "Diastolica: {v}.", "Se registra diastolica de {v}.",
    ),
    "signos.fc": (
        "La frecuencia cardiaca es {v}.", "Pulso de {v} latidos por minuto.",
        "Frecuencia cardiaca: {v}.", "FC de {v}.",
    ),
    "signos.fr": (
        "La frecuencia respiratoria es {v}.", "{v} respiraciones por minuto.",
        "Frecuencia respiratoria: {v}.", "FR de {v}.",
    ),
    "signos.temperatura": (
        "La temperatura corporal es {v}.", "Temperatura de {v} grados.",
        "Temperatura: {v}.", "Se registra temperatura de {v}.",
    ),
    "signos.spo2": (
        "La saturacion de oxigeno es {v}.", "Oximetria de {v} por ciento.",
        "Saturacion: {v}.", "SpO2 de {v}.",
    ),
    "signos.glucosa": (
        "La glucosa capilar es {v}.", "Glucosa de {v}.", "Glucemia: {v}.",
        "Se registra glucosa de {v}.",
    ),
    "signos.peso": (
        "El peso corporal es {v}.", "Peso de {v} kilogramos.", "Peso: {v}.",
        "La persona pesa {v} kilos.",
    ),
    "signos.talla": (
        "La talla es {v}.", "Estatura de {v} centimetros.", "Talla: {v}.",
        "Mide {v} centimetros.",
    ),
}

# Patrones que funcionan con cualquier campo usando su etiqueta del esquema.
PATRONES_GENERICOS = (
    "{cue}: {v}.", "En {cue} se registra {v}.", "Sobre {cue}, se anota {v}.",
    "Respecto a {cue}, {v}.", "En el apartado de {cue}, {v}.",
    "Se documenta en {cue} que {v}.", "Para {cue} se consigna {v}.",
    "El dato de {cue} es {v}.", "En cuanto a {cue}, {v}.",
    "Se asienta en {cue}: {v}.", "Corresponde a {cue} el dato {v}.",
    "En el rubro de {cue} figura {v}.", "Se registra {cue} como {v}.",
    "Anoto en {cue} {v}.", "El campo de {cue} contiene {v}.",
    "Referente a {cue}: {v}.", "Se consigna {cue}, {v}.",
    "Dentro de {cue} se observa {v}.", "La {cue} registrada es {v}.",
    "Como {cue} se documenta {v}.", "Se informa {cue}: {v}.",
    "En la seccion de {cue}, {v}.", "Consta en {cue} {v}.",
    "Se hace constar {cue}: {v}.",
)

NEGACIONES = (
    "Niega {v}.", "No presenta {v}.", "No refiere {v}.", "Sin {v}.",
    "Descarta {v}.", "No hay {v}.", "Nunca ha presentado {v}.",
    "La persona niega {v}.", "El paciente niega {v}.", "La paciente niega {v}.",
    "No padece {v}.", "Se niega {v}.", "No se observa {v}.",
    "No cuenta con {v}.", "Niego {v}.", "No tengo {v}.",
)

TEMPORALES = {
    "actual": (
        "desde ayer", "desde hace dos dias", "en este momento", "actualmente",
        "de tres dias de evolucion", "desde esta manana", "al dia de hoy",
        "desde hace una semana", "en la actualidad",
    ),
    "pasado": (
        "hace varios anos", "en la infancia", "durante el ano pasado",
        "previamente", "hace tiempo", "en el pasado", "anos atras",
    ),
    "habitual": (
        "de manera habitual", "con frecuencia", "cada semana", "todos los dias",
        "de forma recurrente", "habitualmente", "de manera repetida",
    ),
    "futuro": (
        "se programa para despues", "queda pendiente", "se valorara posteriormente",
        "para la proxima consulta", "en el seguimiento",
    ),
}

SUJETOS_FAMILIAR = (
    "Su madre", "Su padre", "Su hermano", "Su hermana",
    "Un familiar", "Su abuela", "Su hijo",
)

CERTEZAS = {
    "confirmado": ("Se registra", "Se documenta", "Se confirma", "Consta"),
    "referido": ("Refiere", "Comenta", "Manifiesta", "Menciona"),
    "dudoso": ("Posible", "Aparente", "Probable", "Presunto"),
}

PREFIJOS_REFERIDO = (
    "Refiere", "Me ", "Soy ", "Tengo ", "Comenta", "Mi ", "Naci", "Vivo",
    "Actualmente tomo", "Padece", "Manifiesta", "Es alergica", "Niego", "No tengo",
    "No refiere", "Niega",
)

MARCADORES_FICTICIOS = ("dato ficticio", "ejemplo de prueba", "sin validez clinica")