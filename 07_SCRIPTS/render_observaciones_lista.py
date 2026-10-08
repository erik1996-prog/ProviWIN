# 07_SCRIPTS/render_observaciones_lista.py - PDF con la relación (listado) de observaciones filtradas.
# Lo llama 02_BACKEND/src/routes/api/observaciones_export.php: python render_observaciones_lista.py entrada.json salida.pdf
# Entrada: {"titulo", "filtros": [texto...], "generado", "usuario", "columnas": [...], "anchos_mm": [...], "filas": [[...]], "resumen": [[estado, n]...]}
import json, sys
from xml.sax.saxutils import escape
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4, landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle

src, out = sys.argv[1], sys.argv[2]
with open(src, 'r', encoding='utf-8') as fh:
    data = json.load(fh)

ORANGE = colors.HexColor('#FF681F'); INK = colors.HexColor('#202731'); MUTED = colors.HexColor('#667085')
LINE = colors.HexColor('#DDE1E6'); ZEBRA = colors.HexColor('#F6F7F9')
cell = ParagraphStyle('cell', fontName='Helvetica', fontSize=7, leading=8.6, textColor=INK)
head = ParagraphStyle('head', fontName='Helvetica-Bold', fontSize=7, leading=8.6, textColor=colors.white)
meta = ParagraphStyle('meta', fontName='Helvetica', fontSize=8, leading=11, textColor=MUTED)
page_w, page_h = landscape(A4)
title = data.get('titulo') or 'Relación de observaciones'

def decorate(canvas, doc):
    canvas.saveState()
    canvas.setFillColor(INK); canvas.rect(0, page_h - 18 * mm, page_w, 18 * mm, fill=1, stroke=0)
    canvas.setFillColor(ORANGE); canvas.rect(0, page_h - 19.2 * mm, page_w, 1.2 * mm, fill=1, stroke=0)
    canvas.setFillColor(colors.white); canvas.setFont('Helvetica-Bold', 13); canvas.drawString(12 * mm, page_h - 11.5 * mm, title)
    canvas.setFont('Helvetica', 8); canvas.drawRightString(page_w - 12 * mm, page_h - 11.5 * mm, 'SGS · Sistema de Gestión de Supervisión')
    canvas.setFillColor(MUTED); canvas.setFont('Helvetica', 7)
    canvas.drawString(12 * mm, 6 * mm, f"Generado el {data.get('generado', '')} por {data.get('usuario', '')}")
    canvas.drawRightString(page_w - 12 * mm, 6 * mm, f'Página {doc.page}')
    canvas.restoreState()

def p(value, style=cell):
    return Paragraph(escape(str(value if value is not None else '')).replace('\n', '<br/>'), style)

story = []
filtros = data.get('filtros') or ['Sin filtros: todas las observaciones']
story.append(Paragraph('<b>Filtros aplicados:</b> ' + escape(' · '.join(filtros)), meta))
resumen = data.get('resumen') or []
total = sum(n for _, n in resumen)
partes = [f'<b>Total:</b> {total}'] + [f'{escape(str(e))}: {n}' for e, n in resumen]
story.append(Paragraph(' &nbsp;·&nbsp; '.join(partes), meta))
story.append(Spacer(1, 4 * mm))

cols = data['columnas']; widths = [w * mm for w in data['anchos_mm']]
rows = [[p(c, head) for c in cols]] + [[p(v) for v in fila] for fila in data.get('filas', [])]
if len(rows) == 1:
    rows.append([p('No hay observaciones para los filtros seleccionados.')] + [p('') for _ in cols[1:]])
table = Table(rows, colWidths=widths, repeatRows=1)
style = [
    ('BACKGROUND', (0, 0), (-1, 0), INK), ('VALIGN', (0, 0), (-1, -1), 'TOP'),
    ('LINEBELOW', (0, 0), (-1, -1), 0.4, LINE), ('LINEBELOW', (0, 0), (-1, 0), 1.2, ORANGE),
    ('LEFTPADDING', (0, 0), (-1, -1), 3), ('RIGHTPADDING', (0, 0), (-1, -1), 3),
    ('TOPPADDING', (0, 0), (-1, -1), 3), ('BOTTOMPADDING', (0, 0), (-1, -1), 3),
]
for i in range(2, len(rows), 2):
    style.append(('BACKGROUND', (0, i), (-1, i), ZEBRA))
table.setStyle(TableStyle(style))
story.append(table)

doc = SimpleDocTemplate(out, pagesize=landscape(A4), leftMargin=12 * mm, rightMargin=12 * mm, topMargin=24 * mm, bottomMargin=12 * mm,
                        title=title, author='SGS')
doc.build(story, onFirstPage=decorate, onLaterPages=decorate)
