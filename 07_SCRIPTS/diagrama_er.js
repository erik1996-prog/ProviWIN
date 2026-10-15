// 07_SCRIPTS/diagrama_er.js - Dibuja el diagrama de relaciones de la base (SVG), agrupado por módulos.
// Lo llama 07_SCRIPTS/generar_diagrama.php (que arma el JSON con la estructura). Uso: node diagrama_er.js estructura.json salida.svg
const fs = require('fs');
const [, , inFile, outFile] = process.argv;
const DB = JSON.parse(fs.readFileSync(inFile, 'utf8'));

const LANES = [
  { nombre: 'Usuarios, accesos y turnos', color: '#2563EB', tablas: ['usuarios', 'usuario_permisos', 'usuario_departamentos_acceso', 'asistencia_supervisores', 'asistencia_eventos', 'login_attempts'] },
  { nombre: 'Supervisión', color: '#FF5A0A', tablas: ['supervisiones', 'supervision_estado_historial', 'supervision_coordenadas_historial', 'tipificaciones_cancelacion'] },
  { nombre: 'Partners, cuadrillas y ubicación', color: '#0F9D58', tablas: ['partners', 'tecnicos', 'vehiculos', 'ubigeo'] },
  { nombre: 'Observaciones y checklist', color: '#B45309', tablas: ['observaciones_supervision', 'categorias_observacion', 'subopciones_observacion', 'notificaciones', 'checklists_tecnicos', 'checklist_respuestas'] },
  { nombre: 'Sistema', color: '#6B7280', tablas: ['sgs_migracion'] },
];
const enLane = {}; LANES.forEach((l, i) => l.tablas.forEach(t => (enLane[t] = i)));
Object.keys(DB.tablas).filter(t => enLane[t] === undefined).forEach(t => { LANES[4].tablas.push(t); enLane[t] = 4; });

// Referencias "quién lo hizo" (auditoría): se dibujan tenues
const AUDITORIA = /^(creado_por|cambiado_por|otorgado_por|asignado_por|resuelto_por|evaluado_por|respondido_por|emisor_id|receptor_id)$/i;
const fkDe = {}; DB.relaciones.forEach(r => ((fkDe[r.t] ||= {})[r.c] = r));

const BOX_W = 300, ROW_H = 19, HEAD_H = 34, PAD_B = 8, LANE_GAP = 130, BOX_GAP = 26, TOP = 150, LEFT = 50, MAX_OTRAS = 3;
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const tipo = ty => String(ty).replace(/\(.*$/, '').replace(/ unsigned/, '');

// Filas visibles: PK, FK y hasta 3 columnas más; el resto como "+ N columnas"
const boxes = {};
LANES.forEach((lane, li) => {
  let y = TOP;
  const x = LEFT + li * (BOX_W + LANE_GAP);
  lane.tablas.forEach(t => {
    const tb = DB.tablas[t]; if (!tb) return;
    const claves = tb.columnas.filter(c => c[3] === 'PRI' || fkDe[t]?.[c[0]]);
    const otras = tb.columnas.filter(c => !(c[3] === 'PRI' || fkDe[t]?.[c[0]]));
    const filas = [...claves, ...otras.slice(0, MAX_OTRAS)];
    const resto = otras.length - Math.min(otras.length, MAX_OTRAS);
    const h = HEAD_H + (filas.length + (resto > 0 ? 1 : 0)) * ROW_H + PAD_B;
    boxes[t] = { t, x, y, w: BOX_W, h, filas, resto, lane: li, color: lane.color, filasN: tb.filas };
    y += h + BOX_GAP;
  });
  lane.altura = y;
});
const W = LEFT * 2 + LANES.length * BOX_W + (LANES.length - 1) * LANE_GAP;
const H = Math.max(...LANES.map(l => l.altura)) + 40;
const rowY = (b, col) => { const i = b.filas.findIndex(f => f[0] === col); return b.y + HEAD_H + (i < 0 ? 0 : i) * ROW_H + ROW_H / 2; };

// Líneas: del FK (hijo) a la PK (padre)
const edges = [];
DB.relaciones.forEach(r => {
  const c = boxes[r.t], p = boxes[r.rt]; if (!c || !p) return;
  const y1 = rowY(c, r.c), y2 = rowY(p, r.rc);
  const aud = AUDITORIA.test(r.c);
  let d, ex, ey, dir;
  if (c.lane === p.lane) { // misma columna: rodeo por la derecha
    const x1 = c.x + c.w, x2 = p.x + p.w, off = 46 + (Math.abs(y1 - y2) / 40);
    d = `M${x1},${y1} C${x1 + off},${y1} ${x2 + off},${y2} ${x2 + 8},${y2}`; ex = x2; ey = y2; dir = -1;
  } else if (c.lane > p.lane) { // padre a la izquierda
    const x1 = c.x, x2 = p.x + p.w, k = Math.max(60, (x1 - x2) * 0.45);
    d = `M${x1},${y1} C${x1 - k},${y1} ${x2 + k},${y2} ${x2 + 8},${y2}`; ex = x2; ey = y2; dir = -1;
  } else { // padre a la derecha
    const x1 = c.x + c.w, x2 = p.x, k = Math.max(60, (x2 - x1) * 0.45);
    d = `M${x1},${y1} C${x1 + k},${y1} ${x2 - k},${y2} ${x2 - 8},${y2}`; ex = x2; ey = y2; dir = 1;
  }
  edges.push({ d, aud, color: p.color, ex, ey, dir, y1, cx: c.lane === p.lane ? c.x + c.w : (c.lane > p.lane ? c.x : c.x + c.w), cdir: c.lane === p.lane ? 1 : (c.lane > p.lane ? -1 : 1) });
});

let svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" font-family="Segoe UI, Nunito, Arial, sans-serif">
<rect width="100%" height="100%" fill="#F7F5F3"/>
<text x="${LEFT}" y="56" font-size="30" font-weight="800" fill="#1A1614">Base de datos de SGS · relaciones entre tablas</text>
<text x="${LEFT}" y="86" font-size="15" fill="#5C5552">${Object.keys(DB.tablas).length} tablas · ${DB.relaciones.length} relaciones · generado ${esc(DB.generado)}</text>
<g font-size="13" fill="#3A2C27">
  <line x1="${W - 560}" y1="52" x2="${W - 520}" y2="52" stroke="#FF5A0A" stroke-width="2.2"/><text x="${W - 512}" y="56">Relación de negocio (color del módulo de destino)</text>
  <line x1="${W - 560}" y1="78" x2="${W - 520}" y2="78" stroke="#A8A29E" stroke-width="1.2" stroke-dasharray="5 4"/><text x="${W - 512}" y="82">Auditoría: quién creó o cambió el registro</text>
  <text x="${W - 560}" y="108"><tspan font-weight="800" fill="#C2410C">PK</tspan> clave principal · <tspan font-weight="800" fill="#2563EB">FK</tspan> apunta a otra tabla · ● = muchos</text>
</g>\n`;
// Encabezados de columnas (módulos)
LANES.forEach((l, li) => {
  const x = LEFT + li * (BOX_W + LANE_GAP);
  svg += `<rect x="${x}" y="${TOP - 36}" width="${BOX_W}" height="24" rx="12" fill="${l.color}" opacity="0.12"/><text x="${x + BOX_W / 2}" y="${TOP - 19}" text-anchor="middle" font-size="12.5" font-weight="800" fill="${l.color}" letter-spacing="0.5">${esc(l.nombre.toUpperCase())}</text>\n`;
});
// Líneas debajo de las cajas (auditoría primero, luego negocio)
[...edges.filter(e => e.aud), ...edges.filter(e => !e.aud)].forEach(e => {
  svg += e.aud
    ? `<path d="${e.d}" fill="none" stroke="#B9B2AD" stroke-width="1.1" stroke-dasharray="5 4" opacity="0.8"/>\n`
    : `<path d="${e.d}" fill="none" stroke="${e.color}" stroke-width="2" opacity="0.75"/>\n`;
  const col = e.aud ? '#B9B2AD' : e.color;
  // Lado "uno" (padre): barra; lado "muchos" (hijo): punto
  svg += `<line x1="${e.ex + e.dir * 8}" y1="${e.ey - 6}" x2="${e.ex + e.dir * 8}" y2="${e.ey + 6}" stroke="${col}" stroke-width="2"/>`;
  svg += `<circle cx="${e.cx + e.cdir * 4}" cy="${e.y1}" r="3.6" fill="${col}"/>\n`;
});
// Cajas
Object.values(boxes).forEach(b => {
  const sgs = DB.tablas[b.t].sgs;
  svg += `<g>
  <rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="10" fill="#FFFFFF" stroke="#E2D9D3"/>
  <path d="M${b.x},${b.y + 10} a10,10 0 0 1 10,-10 h${b.w - 20} a10,10 0 0 1 10,10 v${HEAD_H - 10} h-${b.w} z" fill="${b.color}"/>
  <text x="${b.x + 12}" y="${b.y + 22}" font-size="${b.t.length > 26 ? 12.5 : 14}" font-weight="800" fill="#FFFFFF">${esc(b.t)}</text>
  <text x="${b.x + b.w - 10}" y="${b.y + 22}" font-size="11" font-weight="700" fill="#FFFFFF" text-anchor="end" opacity="0.92">${b.filasN.toLocaleString('es-PE')} reg.${sgs || b.t.length > 24 ? '' : ' · original'}</text>\n`;
  b.filas.forEach((f, i) => {
    const y = b.y + HEAD_H + i * ROW_H + 14;
    const pk = f[3] === 'PRI', fk = fkDe[b.t]?.[f[0]];
    if (i % 2 === 1) svg += `<rect x="${b.x + 1}" y="${y - 13}" width="${b.w - 2}" height="${ROW_H}" fill="#FAF8F6"/>`;
    svg += `<text x="${b.x + 12}" y="${y}" font-size="11" font-weight="800" fill="${pk ? '#C2410C' : '#2563EB'}">${pk ? 'PK' : fk ? 'FK' : ''}</text>`;
    svg += `<text x="${b.x + 38}" y="${y}" font-size="12" font-family="Consolas, monospace" fill="#1A1614" font-weight="${pk || fk ? 700 : 400}">${esc(f[0].length > 24 ? f[0].slice(0, 23) + '…' : f[0])}</text>`;
    svg += `<text x="${b.x + b.w - 10}" y="${y}" font-size="10.5" font-family="Consolas, monospace" fill="#8A817C" text-anchor="end">${esc(tipo(f[1]))}</text>\n`;
  });
  if (b.resto > 0) svg += `<text x="${b.x + 38}" y="${b.y + HEAD_H + b.filas.length * ROW_H + 14}" font-size="11.5" fill="#8A817C" font-style="italic">+ ${b.resto} columnas más</text>\n`;
  svg += `</g>\n`;
});
svg += `</svg>\n`;
fs.writeFileSync(outFile, svg);
console.log(`SVG ${W}x${H}, ${Object.keys(boxes).length} tablas, ${edges.length} relaciones (${edges.filter(e => e.aud).length} de auditoría)`);
