import unittest
from pathlib import Path

from .clasificar import clasificar
from .entrenar import DIRECTORIO_DATOS, DIRECTORIO_MODELOS, _crear_documentos
from .esquema import DOCUMENTOS
from .modelo import Organizador, codificar
from .sinteticos import generar_ejemplos, generar_preguntas_sin_respuesta


class PruebasOrganizadorSintetico(unittest.TestCase):
    def test_artifacts_se_guardan_en_rutas_locales_ignoradas(self) -> None:
        raiz = Path(__file__).resolve().parents[4]
        self.assertEqual(DIRECTORIO_MODELOS, raiz / "models" / "organizador_clinico")
        self.assertEqual(
            DIRECTORIO_DATOS,
            raiz / "privado" / "cache" / "organizador_clinico",
        )

    def test_generacion_reproducible_y_con_esquema_valido(self) -> None:
        primero = generar_ejemplos(60, 4815)
        segundo = generar_ejemplos(60, 4815)
        self.assertEqual(primero, segundo)
        self.assertTrue(all(
            ejemplo["campo"] in DOCUMENTOS[ejemplo["tipo"]]
            for ejemplo in primero
        ))
        correos = [
            ejemplo["texto"].split("@", 1)[-1].split()[0].rstrip(".")
            for ejemplo in primero if "@" in ejemplo["texto"]
        ]
        self.assertTrue(all(correo.endswith("example.invalid") for correo in correos))

    def test_registro_incluye_campos_del_formulario_y_dialogos_largos(self) -> None:
        ejemplos = generar_ejemplos(120, 7421)
        registro = DOCUMENTOS["registro_paciente"]
        campos_generados = {
            ejemplo["campo"] for ejemplo in ejemplos
            if ejemplo["tipo"] == "registro_paciente"
        }
        self.assertEqual(campos_generados, set(registro))
        documentos = _crear_documentos(ejemplos, 7422, 4)
        dialogos = [
            documento for documento in documentos
            if documento["sintetico"] == "dialogo-largo-faker-es_MX"
        ]
        self.assertEqual(len(dialogos), 4)
        self.assertTrue(all(
            len(documento["texto"].split(". ")) >= len(registro)
            for documento in dialogos
        ))

    def test_preguntas_sinteticas_se_mantienen_fuera_de_datos_clasificables(self) -> None:
        preguntas = generar_preguntas_sin_respuesta(13)
        self.assertTrue(preguntas)
        self.assertTrue(all(
            pregunta["tipo_registro"] == "pregunta_sin_respuesta"
            and "?" in pregunta["texto"]
            for pregunta in preguntas
        ))

    def test_caracteristicas_estables_y_condicionadas_por_tipo(self) -> None:
        texto = "Motivo de atención simulado"
        self.assertEqual(codificar(texto, "consulta"), codificar(texto, "consulta"))
        self.assertNotEqual(codificar(texto, "consulta"), codificar(texto, "urgencias"))

    def test_clasificacion_conserva_texto_y_exige_revision(self) -> None:
        tipos = list(DOCUMENTOS)
        campos = sorted({campo for grupo in DOCUMENTOS.values() for campo in grupo})
        modelo = Organizador(len(tipos), len(campos))
        texto = "Dolor ficticio de prueba. Diagnostico simulado."
        resultado = clasificar(
            texto, modelo, tipos, campos,
            umbral_tipo=0.0, umbral_campo=0.0, tipo_forzado="consulta",
        )
        self.assertEqual(resultado["tipo_documento"], "consulta")
        self.assertTrue(resultado["requiere_revision_profesional"])
        self.assertTrue(resultado["modelo_entrenado_solo_con_datos_sinteticos"])
        self.assertEqual(
            [propuesta["texto_original"] for propuesta in resultado["campos"]],
            ["Dolor ficticio de prueba.", "Diagnostico simulado."],
        )
        self.assertTrue(all(
            propuesta["campo"] in DOCUMENTOS["consulta"]
            for propuesta in resultado["campos"]
        ))

    def test_preguntas_no_se_convierten_en_datos_y_respuestas_se_separan(self) -> None:
        tipos = list(DOCUMENTOS)
        campos = sorted({campo for grupo in DOCUMENTOS.values() for campo in grupo})
        modelo = Organizador(len(tipos), len(campos))
        pregunta = "¿Te duele la panza desde ayer?"
        resultado = clasificar(
            pregunta, modelo, tipos, campos,
            umbral_tipo=0.0, umbral_campo=0.0, tipo_forzado="consulta",
        )
        self.assertEqual(resultado["campos"], [])
        self.assertEqual(resultado["sin_clasificar"], [pregunta])

        texto = "¿Te duele la panza? Sí, me duele la panza desde ayer."
        resultado = clasificar(
            texto, modelo, tipos, campos,
            umbral_tipo=0.0, umbral_campo=0.0, tipo_forzado="consulta",
        )
        self.assertEqual(resultado["sin_clasificar"], ["¿Te duele la panza?"])
        self.assertEqual(
            [propuesta["texto_original"] for propuesta in resultado["campos"]],
            ["Sí, me duele la panza desde ayer."],
        )


if __name__ == "__main__":
    unittest.main()
