# Organizador local de dictados (piloto sintetico)

El piloto entrena una red neuronal compacta en CPU y guarda datos de prueba,
metricas y pesos en rutas locales ignoradas por Git. No consulta la base de
datos, no lee expedientes y no modifica el endpoint de dictado ni guarda
resultados en la aplicacion.

## Preparacion y entrenamiento

Usa el mismo Python 3.12 del servicio local:

```powershell
python -m pip install --index-url https://download.pytorch.org/whl/cpu --extra-index-url https://pypi.org/simple -r app/servicios/ia/organizador/requirements.txt
python -m app.servicios.ia.organizador.entrenar --ejemplos-por-tipo 1280 --epocas 8 --semilla 20261224
```

El generador usa Faker con locale `es_MX`; correos de prueba terminan en
`example.invalid`. Incluye narraciones largas ficticias, variaciones de primera
y tercera persona, las entidades federativas mexicanas y nacionalidades de
distintas regiones. Las preguntas sin respuesta se guardan como ejemplos de
abstencion, nunca como hechos para entrenamiento. La particion es 70/15/15 por
etiqueta y la ejecucion es reproducible con la semilla. El entrenamiento y la
inferencia usan CPU para no competir por memoria con el servicio de transcripcion.

## Inferencia de prueba

```powershell
python -m app.servicios.ia.organizador.clasificar "Acude porque presenta dolor abdominal simulado."
python -m app.servicios.ia.organizador.clasificar --tipo consulta "El diagnostico de ejemplo es una condicion simulada."
```

La salida conserva cada fragmento original y lo propone como campo; los casos
bajo umbral y las preguntas quedan sin clasificar. Las predicciones nunca se guardan
automaticamente. Para usarlo con dictados reales haria falta una evaluacion
separada, consentimiento y revision clinica. Las metricas sinteticas no
representan precision clinica.
