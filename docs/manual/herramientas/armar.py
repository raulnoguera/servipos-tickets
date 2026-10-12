#!/usr/bin/env python3
"""Arma el manual en PDF: une las partes, calcula el índice con sus páginas y agrega portada, cabecera y pie.

    python3 herramientas/armar.py [versión del sistema]

Sin argumento, la versión se lee del sitio en producción. Requiere macOS (usa herramientas/pdf).
"""
import html
import json
import re
import subprocess
import sys
import unicodedata
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
PDF = RAIZ / 'herramientas' / 'pdf'
TITULO = 'Manual de usuario · Sistema de Monitoreo de Tickets ATM'
SALIDA = RAIZ / 'Manual-ServiPos-Tickets.pdf'
MARGENES = ['60', '64', '54']  # superior, inferior, lateral (puntos)


def ejecutar(*args):
    return subprocess.run([str(PDF), *map(str, args)], check=True, capture_output=True, text=True).stdout


def version():
    if len(sys.argv) > 1:
        return sys.argv[1]
    salida = subprocess.run(['curl', '-fsS', '--max-time', '15', 'https://intra.servipos2019.com/build/version.json'], check=True, capture_output=True, text=True).stdout
    return json.loads(salida)['build']


def plano(texto):
    """Texto sin espacios, acentos ni mayúsculas: el PDF corta líneas y transforma letras."""
    texto = unicodedata.normalize('NFKD', html.unescape(re.sub(r'<[^>]+>', '', texto)))
    return re.sub(r'[^a-z0-9]', '', ''.join(c for c in texto if not unicodedata.combining(c)).lower())


def titulos(cuerpo):
    """Capítulos (h1) y secciones numeradas (h2) en orden de aparición."""
    lista = []
    for nivel, contenido in re.findall(r'<h([12])[^>]*>(.*?)</h\1>', cuerpo, flags=re.S):
        texto = re.sub(r'<span class="cap">.*?</span>', '', contenido)
        texto = html.unescape(re.sub(r'<[^>]+>', '', texto)).strip()
        if texto == 'Contenido' or (nivel == '2' and not re.match(r'\d+\.\d+ ', texto)):
            continue
        lista.append((int(nivel), texto))
    return lista


def tablas_juntas(cuerpo):
    """Marca las tablas cortas para que no se partan entre dos páginas."""
    def marcar(m):
        if m.group(0).count('<tr') > 17:
            return m.group(0)
        inicio = m.group(1)
        inicio = inicio.replace('class="', 'class="junta ', 1) if 'class="' in inicio else inicio.replace('<table', '<table class="junta"', 1)
        return inicio + m.group(2)
    return re.sub(r'(<table[^>]*>)(.*?</table>)', marcar, cuerpo, flags=re.S)


VACIAS = {'img', 'br', 'hr', 'meta', 'link', 'input', 'col'}


def bloques(cuerpo):
    """Divide el HTML en sus elementos de primer nivel: [etiqueta o None, texto]."""
    lista, nivel, desde, etiqueta = [], 0, 0, None
    for m in re.finditer(r'<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>', cuerpo):
        cierre, nombre = m.group(1), m.group(2).lower()
        if nombre in VACIAS:
            continue
        if not cierre:
            if nivel == 0:
                lista.append([None, cuerpo[desde:m.start()]])
                desde, etiqueta = m.start(), m.group(0)
            nivel += 1
        else:
            nivel -= 1
            if nivel == 0:
                lista.append([etiqueta, cuerpo[desde:m.end()]])
                desde = m.end()
    lista.append([None, cuerpo[desde:]])
    return lista


def agrupar(cuerpo):
    """Une cada título con el bloque que le sigue si ese bloque no se parte (figura, recuadro, tabla corta)."""
    def entero(etiqueta):
        return etiqueta is not None and (re.match(r'<(figure|div)\b', etiqueta) or 'junta' in etiqueta)

    lista, salida, i = bloques(cuerpo), [], 0
    while i < len(lista):
        etiqueta, texto = lista[i]
        if etiqueta and re.match(r'<h[23]\b', etiqueta):
            j, grupo, unido = i + 1, [texto], False
            while j < len(lista):
                siguiente, contenido = lista[j]
                grupo.append(contenido)
                j += 1
                if siguiente is None or re.match(r'<h3\b', siguiente):  # espacios, o un h3 tras el h2: sigue buscando
                    continue
                unido = bool(entero(siguiente))
                break
            if unido:
                salida.append('<div class="grupo">' + ''.join(grupo) + '</div>')
                i = j
                continue
        elif etiqueta and re.match(r'<div class="(moviles|lado)', etiqueta):
            texto = '<div class="grupo">' + texto + '</div>'
        salida.append(texto)
        i += 1
    return ''.join(salida)


def indice(lista, paginas):
    filas = []
    for (nivel, texto), pagina in zip(lista, paginas):
        filas.append(f'<li class="n{nivel}"><span>{html.escape(texto)}</span><span class="pt"></span><span class="pg">{pagina}</span></li>')
    return '<ol>\n' + '\n'.join(filas) + '\n</ol>'


def paginas_de(lista, pdf):
    """Página del cuerpo donde aparece cada título, buscando siempre hacia adelante."""
    hojas = [plano(t) for t in re.split(r'=====PAGINA \d+=====', ejecutar('paginas', pdf))[1:]]
    actual = next(i for i, hoja in enumerate(hojas) if 'aquienvadirigido' in hoja)  # primera hoja tras el índice
    resultado = []
    for _, texto in lista:
        clave = plano(texto)
        encontrada = next((i for i in range(actual, len(hojas)) if clave in hojas[i]), None)
        if encontrada is None:
            sys.exit(f'No se encontró el título en el PDF: {texto}')
        actual = encontrada
        resultado.append(encontrada + 1)
    return resultado


def main():
    partes = ''.join(p.read_text(encoding='utf-8') for p in sorted((RAIZ / 'partes').glob('*.html')))
    sistema = version()
    partes = partes.replace('<!--VERSION-->', sistema)
    partes = agrupar(tablas_juntas(partes))
    lista = titulos(partes)
    cabeza = f'<!doctype html>\n<html lang="es"><head><meta charset="utf-8"><title>{TITULO}</title><link rel="stylesheet" href="estilos.css"></head>\n<body>\n'
    manual, cuerpo = RAIZ / 'manual.html', RAIZ / 'cuerpo.pdf'

    paginas = ['00'] * len(lista)
    for vuelta in range(4):
        manual.write_text(cabeza + partes.replace('<!--INDICE-->', indice(lista, paginas)) + '\n</body></html>\n', encoding='utf-8')
        ejecutar('render', manual, cuerpo, *MARGENES)
        nuevas = paginas_de(lista, cuerpo)
        if nuevas == paginas:
            break
        paginas = nuevas
    else:
        sys.exit('El índice no se estabilizó.')

    ejecutar('render', RAIZ / 'portada.html', RAIZ / 'portada.pdf', '0', '0', '0')
    marcadores = RAIZ / 'marcadores.tsv'
    marcadores.write_text('1\t1\tContenido\n' + ''.join(f'{n}\t{p}\t{t}\n' for (n, t), p in zip(lista, paginas)), encoding='utf-8')
    print(ejecutar('armar', RAIZ / 'portada.pdf', cuerpo, SALIDA, TITULO, marcadores).strip())
    marcadores.unlink()
    print(f'{len(lista)} títulos en el índice · versión del sistema {sistema}')


if __name__ == '__main__':
    main()
