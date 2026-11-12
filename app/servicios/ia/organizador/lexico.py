"""Lexico espanol clinico construido con recursos locales; no descarga nada."""

from __future__ import annotations

import json
import re
import unicodedata
from collections import Counter
from functools import lru_cache
from pathlib import Path

from faker import Faker

RAIZ_PROYECTO = Path(__file__).resolve().parents[4]
RUTA_VOCABULARIO = RAIZ_PROYECTO / "app" / "servicios" / "ia" / "vocabulario_medico_es.txt"
RUTA_CACHE = RAIZ_PROYECTO / "privado" / "cache" / "organizador_clinico" / "lexico.json"

VERSION_LEXICO = 3

# Errores de dictado documentados. Se corrigen siempre, aunque el corpus del
# proyecto los haya aprendido como validos (dictado.php menciona "pansa" en un regex).
ERRORES_CONOCIDOS = {
    "pansa": "panza", "panssa": "panza",
    "corason": "corazón", "corazon": "corazón",
    "diabetis": "diabetes", "diavetes": "diabetes",
    "hipertencion": "hipertensión", "ipertension": "hipertensión",
    "hipertension": "hipertensión",
    "presion": "presión", "preción": "presión",
    "nauseas": "náuseas", "nauseaz": "náuseas",
    "estomago": "estómago", "estomagó": "estómago",
    "oido": "oído", "diagnostico": "diagnóstico", "diagnosticó": "diagnóstico",
    "sintoma": "síntoma", "sintomá": "síntoma",
    "cronico": "crónico", "croníco": "crónico",
    "alergico": "alérgico", "alergíco": "alérgico",
    "medico": "médico", "medíco": "médico",
    "clinica": "clínica", "clíníca": "clínica",
    "pildora": "píldora", "rigido": "rígido", "liquido": "líquido",
    "rapido": "rápido", "musculo": "músculo", "higado": "hígado",
    "rinon": "riñón", "pulmon": "pulmón", "torax": "tórax",
    "cardiaca": "cardíaca", "cardiaco": "cardíaco",
    "farmaco": "fármaco", "medicacion": "medicación",
    "exploracion": "exploración", "evolucion": "evolución",
    "diagnostica": "diagnóstica", "terapeutica": "terapéutica",
    "quirurgico": "quirúrgico", "cirugia": "cirugía",
}

_TOKEN = re.compile(r"[a-záéíóúüñ]+")
_ESTRUCTURAL = re.compile(r"^(seccion_|hablante_|.*_marcador$)")
# Palabras funcionales cortas que el filtro de longitud minima deja fuera del lexico.
_CORTAS = frozenset({
    "no", "me", "la", "el", "si", "te", "se", "de", "un", "mi", "tu", "su",
    "lo", "le", "ya", "ay", "ha", "he", "yo", "es", "en", "al", "del", "los",
    "las", "les", "nos", "mas", "muy", "que", "por", "con", "sin", "sus",
    "fue", "fui", "ser", "hay", "una", "dos", "tres", "este", "esta",
})
# Palabras donde el acento cambia el significado o la RAE ya prefiere la forma llana.
_AMBIGUAS = frozenset({
    "solo", "este", "esta", "estos", "estas", "ese", "esa", "esos", "esas",
    "aun", "mas", "mi", "tu", "el", "si", "te", "se", "de", "que", "como",
    "cuando", "donde", "cual", "quien", "porque", "sino", "si no",
})
# Identificadores y palabras de codigo que aparecen en comentarios y literales.
_CODIGO = frozenset({
    "array", "string", "int", "integer", "float", "bool", "boolean", "null",
    "true", "false", "void", "function", "return", "echo", "print", "printf",
    "isset", "empty", "unset", "foreach", "while", "switch", "case", "break",
    "continue", "class", "public", "private", "protected", "static", "const",
    "new", "try", "catch", "throw", "finally", "use", "namespace", "require",
    "include", "define", "defined", "global", "var", "let", "undefined",
    "typeof", "instanceof", "async", "await", "promise", "callback", "params",
    "args", "self", "this", "todo", "fixme", "hack", "note", "warning",
    "deprecated", "php", "html", "css", "json", "xml", "sql", "http", "https",
    "url", "api", "endpoint", "query", "select", "insert", "update", "delete",
    "where", "from", "join", "left", "inner", "order", "group", "limit",
    "table", "column", "row", "index", "primary", "foreign", "key", "value",
    "values", "data", "item", "items", "list", "map", "set", "get", "post",
    "put", "patch", "request", "response", "header", "body", "status", "code",
    "error", "exception", "message", "msg", "file", "path", "dir", "folder",
    "name", "type", "kind", "mode", "size", "count", "total", "max", "min",
    "sum", "avg", "default", "config", "settings", "options", "option",
    "param", "parameter", "input", "output", "field", "fields", "form",
    "button", "label", "div", "span", "style", "script", "src", "href",
    "test", "tests", "assert", "expect", "mock", "stub", "fixture", "user",
    "admin", "login", "logout", "password", "token", "session", "cache",
    "log", "logs", "debug", "info", "trace", "stack", "version", "build",
    "release", "commit", "branch", "merge", "none", "yes", "ids", "uuid",
})


def sin_acentos(texto: str) -> str:
    descompuesto = unicodedata.normalize("NFKD", texto.casefold())
    return "".join(c for c in descompuesto if not unicodedata.combining(c))


def clave_fonetica(palabra: str) -> str:
    """Misma clave fonetica que receta_dictado.php:265, para no divergir del criterio ya probado."""
    p = sin_acentos(palabra)
    if not p:
        return ""
    for origen, destino in (
        ("ph", "f"), ("qu", "k"), ("ll", "y"), ("ñ", "n"),
        ("v", "b"), ("z", "s"), ("ge", "je"), ("gi", "ji"),
        ("ce", "se"), ("ci", "si"), ("x", "j"), ("h", ""),
    ):
        p = p.replace(origen, destino)
    p = p.replace("c", "k").replace("q", "k")
    p = re.sub(r"[^a-z]", "", p)
    return re.sub(r"(.)\1+", r"\1", p)


def _leer_vocabulario_medico() -> Counter[str]:
    if not RUTA_VOCABULARIO.is_file():
        return Counter()
    frecuencias: Counter[str] = Counter()
    for linea in RUTA_VOCABULARIO.read_text(encoding="utf-8").splitlines():
        entrada = linea.strip()
        if not entrada or entrada.startswith("#"):
            continue
        if _ESTRUCTURAL.match(sin_acentos(entrada)):
            continue
        for palabra in _TOKEN.findall(entrada.lower()):
            if len(palabra) >= 2:
                frecuencias[palabra] += 3
    return frecuencias


def _leer_faker() -> tuple[Counter[str], set[str]]:
    fake = Faker("es_MX")
    fake.seed_instance(0)
    generales: Counter[str] = Counter()
    nombres: set[str] = set()

    lorem = fake.provider("faker.providers.lorem")
    for palabra in getattr(lorem, "word_list", ()):
        generales[palabra.lower()] += 2

    persona = fake.provider("faker.providers.person")
    direccion = fake.provider("faker.providers.address")
    trabajo = fake.provider("faker.providers.job")
    for origen, atributos in (
        (persona, ("first_names", "last_names")),
        (direccion, ("states", "countries", "city_prefixes", "city_suffixes")),
        (trabajo, ("jobs",)),
    ):
        for atributo in atributos:
            for valor in getattr(origen, atributo, ()) or ():
                if not isinstance(valor, str):
                    continue
                for palabra in _TOKEN.findall(valor.lower()):
                    if len(palabra) >= 3:
                        nombres.add(palabra)
                        generales[palabra] += 1
    for idioma in getattr(persona, "language_names", ()) or ():
        for palabra in _TOKEN.findall(idioma.lower()):
            if len(palabra) >= 3:
                generales[palabra] += 1
    return generales, nombres


def _leer_corpus_proyecto() -> Counter[str]:
    """Prosa clinica real del sistema: literales de cadena y comentarios, nunca codigo."""
    frecuencias: Counter[str] = Counter()
    literal = re.compile(r"'([^'\\]{6,})'|\"([^\"\\]{6,})\"")
    comentario = re.compile(r"//[^\n]*|/\*.*?\*/", re.S)
    directorios = ("app", "paginas", "docs", "pacientes")
    for directorio in directorios:
        base = RAIZ_PROYECTO / directorio
        if not base.is_dir():
            continue
        for ruta in base.rglob("*.php"):
            try:
                contenido = ruta.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            fragmentos = [a or b for a, b in literal.findall(contenido)]
            fragmentos.extend(comentario.findall(contenido))
            for fragmento in fragmentos:
                if len(fragmento.split()) < 2:
                    continue
                for palabra in _TOKEN.findall(fragmento.lower()):
                    if len(palabra) >= 3 and palabra not in _CODIGO:
                        frecuencias[palabra] += 1
        for ruta in base.rglob("*.md"):
            try:
                contenido = ruta.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            for palabra in _TOKEN.findall(contenido.lower()):
                if len(palabra) >= 3 and palabra not in _CODIGO:
                    frecuencias[palabra] += 1
    return frecuencias


def construir() -> dict[str, object]:
    medicas = _leer_vocabulario_medico()
    generales, nombres = _leer_faker()
    frecuencias = generales + medicas + _leer_corpus_proyecto()

    from .esquema import DOCUMENTOS, ETIQUETAS

    for campo in {c for grupo in DOCUMENTOS.values() for c in grupo}:
        for palabra in _TOKEN.findall(campo.replace("_", " ").replace(".", " ")):
            frecuencias[palabra] += 1
    for etiquetas in ETIQUETAS.values():
        for etiqueta in etiquetas:
            for palabra in _TOKEN.findall(etiqueta):
                frecuencias[palabra] += 1

    palabras = {p for p in frecuencias if len(p) >= 2}
    return {
        "version": VERSION_LEXICO,
        "frecuencias": dict(sorted(frecuencias.items())),
        "nombres": sorted(nombres),
        "palabras": sorted(palabras),
    }


class Lexico:
    def __init__(self, datos: dict[str, object]) -> None:
        self.frecuencias: dict[str, int] = datos["frecuencias"]
        self.palabras: frozenset[str] = frozenset(datos["palabras"])
        self.nombres: frozenset[str] = frozenset(datos["nombres"])
        self.sin_acento: dict[str, list[str]] = {}
        self.fonetica: dict[str, list[str]] = {}
        self.borrados: dict[str, list[str]] = {}
        for palabra in sorted(self.palabras):
            plana = sin_acentos(palabra)
            self.sin_acento.setdefault(plana, []).append(palabra)
            self.fonetica.setdefault(clave_fonetica(palabra), []).append(palabra)
            for indice in range(len(plana)):
                self.borrados.setdefault(plana[:indice] + plana[indice + 1:], []).append(palabra)
        for grupo in self.sin_acento.values():
            grupo.sort(key=lambda p: -self.frecuencias.get(p, 0))
        self.canonica: dict[str, str] = {}
        for plana, grupo in self.sin_acento.items():
            if plana in _AMBIGUAS or len(grupo) < 2:
                continue
            acentuadas = [p for p in grupo if p != sin_acentos(p)]
            if acentuadas:
                self.canonica[plana] = acentuadas[0]

    def __len__(self) -> int:
        return len(self.palabras)

    def conocida(self, palabra: str) -> bool:
        return palabra in self.palabras

    def restaurar_acento(self, palabra: str) -> str | None:
        plana = sin_acentos(palabra)
        mejor = self.canonica.get(plana)
        if mejor is None or mejor == palabra:
            return None
        return mejor

    def candidatas(self, palabra: str) -> set[str]:
        plana = sin_acentos(palabra)
        encontradas = set(self.borrados.get(plana, ()))
        for indice in range(len(plana)):
            encontradas.update(self.borrados.get(plana[:indice] + plana[indice + 1:], ()))
        encontradas.update(self.fonetica.get(clave_fonetica(palabra), ()))
        encontradas.discard(palabra)
        # "corazon" y "corazón" son el mismo lexema: compite una sola forma, la canonica.
        por_lexema: dict[str, str] = {}
        for candidata in encontradas:
            lexema = sin_acentos(candidata)
            actual = por_lexema.get(lexema)
            if actual is None or self._preferir(candidata, actual):
                por_lexema[lexema] = candidata
        return set(por_lexema.values())

    def _preferir(self, nueva: str, actual: str) -> bool:
        acento_nueva = nueva != sin_acentos(nueva)
        acento_actual = actual != sin_acentos(actual)
        if acento_nueva != acento_actual:
            return acento_nueva
        return self.frecuencias.get(nueva, 0) > self.frecuencias.get(actual, 0)

    def separar_pegadas(self, palabra: str) -> tuple[str, str] | None:
        """Parte una palabra desconocida en dos conocidas, solo si la particion es unica.

        Importa clinicamente: "notengo diabetes" esconde la negacion dentro del token.
        """
        if len(palabra) < 6 or palabra in self.palabras or palabra in self.nombres:
            return None
        if not palabra.isalpha():
            return None
        validas: list[tuple[str, str]] = []
        for corte in range(2, len(palabra) - 1):
            izquierda, derecha = palabra[:corte], palabra[corte:]
            if len(derecha) < 2:
                continue
            if izquierda not in self.palabras and izquierda not in _CORTAS:
                continue
            if derecha not in self.palabras and derecha not in _CORTAS:
                continue
            validas.append((izquierda, derecha))
        if len(validas) != 1:
            return None
        return validas[0]

    def corregir_palabra(self, palabra: str) -> tuple[str, str] | None:
        """Devuelve (correccion, motivo) o None si no hay evidencia suficiente."""
        if len(palabra) < 4 or not palabra.isalpha() or palabra in self.nombres:
            return None

        conocida = ERRORES_CONOCIDOS.get(palabra)
        if conocida is not None and conocida != palabra:
            return conocida, "error_conocido"

        acento = self.restaurar_acento(palabra)
        if acento is not None:
            return acento, "acento"
        if palabra in self.palabras:
            return None

        candidatas = self.candidatas(palabra)
        if not candidatas:
            return None
        plana = sin_acentos(palabra)
        fonetica = clave_fonetica(palabra)
        maxima = 2 if len(palabra) >= 8 else 1

        puntuadas: list[tuple[float, str]] = []
        for candidata in candidatas:
            if candidata in self.nombres and candidata not in self.frecuencias:
                continue
            if abs(len(candidata) - len(palabra)) > 2:
                continue
            distancia = _distancia(plana, sin_acentos(candidata))
            if distancia > maxima:
                continue
            misma_fonetica = clave_fonetica(candidata) == fonetica
            if distancia == maxima and not misma_fonetica:
                continue
            frecuencia = self.frecuencias.get(candidata, 0)
            puntuadas.append((distancia - (0.6 if misma_fonetica else 0) - min(frecuencia, 30) / 100, candidata))
        if not puntuadas:
            return None
        puntuadas.sort()
        mejor, segunda = puntuadas[0], (puntuadas[1] if len(puntuadas) > 1 else None)
        if segunda is not None and segunda[0] - mejor[0] < 0.15:
            return None
        return mejor[1], "fonetica" if clave_fonetica(mejor[1]) == fonetica else "ortografia"


def _distancia(a: str, b: str) -> int:
    if a == b:
        return 0
    if len(a) < len(b):
        a, b = b, a
    anterior = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        actual = [i]
        for j, cb in enumerate(b, 1):
            actual.append(min(anterior[j] + 1, actual[j - 1] + 1, anterior[j - 1] + (ca != cb)))
        anterior = actual
    return anterior[-1]


@lru_cache(maxsize=1)
def cargar() -> Lexico:
    if RUTA_CACHE.is_file():
        datos = json.loads(RUTA_CACHE.read_text(encoding="utf-8"))
        if datos.get("version") == VERSION_LEXICO:
            return Lexico(datos)
    datos = construir()
    RUTA_CACHE.parent.mkdir(parents=True, exist_ok=True)
    RUTA_CACHE.write_text(json.dumps(datos, ensure_ascii=False), encoding="utf-8")
    return Lexico(datos)


if __name__ == "__main__":
    lexico = cargar()
    print(f"palabras={len(lexico)} nombres={len(lexico.nombres)} cache={RUTA_CACHE}")
    for prueba in ("pansa", "estomago", "corason", "abdomen", "metformina", "cabeza", "asdfgh"):
        print(prueba, "->", lexico.corregir_palabra(prueba))