# Guía de diseño WIN (referencia: win.pe/internet-fibra-optica)

Extraído de los estilos computados de la página (oct 2026). Usar como base visual para ProviWIN.

## Tipografía
| Uso | Fuente | Pesos |
|---|---|---|
| Cuerpo / textos / títulos generales | **MADE Tommy Soft** (`MADETommySoft`) | 300, 400, 500, 700, 800, 900 |
| Números grandes, MBPS, botones CTA | **Panton** | 400, 600, 700, 800, 900 (Black para CTA) |
| Secundaria (Google Fonts, cargada pero poco usada) | Exo | 300–800 |
| Iconos | Font Awesome 5 | — |

Fuentes servidas en `https://win.pe/fonts/` (`MADETommySoftRegular.woff2`, `PantonDemoBlack.woff2`, etc.). Alternativa libre si no se tienen: **Nunito / Quicksand** (≈ Tommy Soft) y **Montserrat 900** (≈ Panton Black).

### Escala de tamaños
| Clase WIN | Tamaño | Uso |
|---|---|---|
| `text-bxl` | 62px | Velocidad del plan ("1000") — Panton 900 |
| `text-huge` | 42px / lh 1.2 | Titular de sección, MAYÚSCULAS, bold |
| `text-vbig` | 36px | Precio entero ("S/ 69") |
| `text-xbig` | 32px | Subtítulo naranja ("PLANES WIN INTERNET") |
| `text-big` | 28px | "MBPS" — Panton 900 |
| `text-medium` | 21px | Decimales del precio, "x1 mes" |
| `text-normal` | 18px | Títulos de tarjeta (500), botones |
| base | 15px / lh 22.5px (1.5) | Cuerpo |
| `text-xsmall` | 12px | "Precio regular" (gris) |
| `text-tiny` | 11px | Features con icono en tarjetas |

Estilo de titulares: **MAYÚSCULAS, bold, centrados**, mezclando negro + naranja por líneas
(ej. "¡CÁMBIATE A UNO DE / **NUESTROS PLANES** (naranja) / DE INTERNET 100% FIBRA ÓPTICA!").

## Colores
| Token | Valor | Uso |
|---|---|---|
| `--win-orange` | `#FF5A0A` | Color de marca: cabeceras de tarjeta, títulos, bordes, "x1 mes" |
| `--win-orange-dark` | `#D8450B` | Hover / variantes |
| `--win-orange-soft` | `#FFEFE6` | Fondos suaves naranja |
| `--win-yellow` | `#FFC200` | Etiqueta "Plan recomendado" |
| `--win-green` | `#1BD741` | WhatsApp |
| `--win-black` | `#000000` | Botones CTA, footer |
| `--win-ink` | `#141414` | Texto principal |
| `--win-brown` | `#301D19` | Texto body / precios (`text-brown`) |
| `--win-gray` | `#7C7E80` | Texto secundario |
| `--win-bg-light` | `#F4F4F4` / `#F6F6F6` | Fondos de sección |
| `--win-border` | `#D6D6D6` | Bordes neutros |

## Componentes

**Tarjeta de plan** (`box-caja-planes`)
- Fondo blanco, `border: 1px solid #FF5A0A`, `border-radius: 30px`.
- Cabecera naranja `#FF5A0A`, radius `30px 30px 0 0`, padding `24px 8px 8px`, texto blanco:
  - "Internet 100% Fibra Óptica" 18px/500
  - "1000" Panton 900 62px + "MBPS" Panton 900 28px.
- Precio centrado: "S/ 69" 36px marrón + ".50" 21px + "x1 mes" 21px naranja; debajo "Precio regular: S/139" 12px gris.
- Badge "Plan recomendado": fondo `#FFC200`, texto negro 21px, radius 10px, padding 4px 0, encima de la tarjeta.
- Features: iconos + texto 11px ("100% fibra óptica", "Internet ilimitado y simétrico", "Mayor velocidad").
- Mostradas en slider (slick) horizontal.

**Botón CTA** ("¡Quiero este plan!")
- Fondo negro, texto blanco, **Panton 900 18px**, `border-radius: 10px`, padding `8px 12px`.

**Tabs / selector de planes**
- Contenedor con `border: 2px solid #FF5A0A`, `border-radius: 30px` (pill).
- Selects: fondo blanco, borde 1px naranja, radius 20px, texto naranja 18px, padding `16px 48px 16px 16px`.

**Botones de contacto**
- WhatsApp: verde `#1BD741`, icono WhatsApp, texto blanco bold en MAYÚSCULAS, esquinas redondeadas.
- Llamada: blanco con borde naranja, icono teléfono naranja, número en naranja.
- Flotantes fijos abajo a la derecha (WhatsApp + Llamada).
- Bloque "¡Pide aquí…": dos paneles grandes redondeados (~30px) naranja | verde, con persona al centro y botones pill blancos.

**Header / navegación**
- Transparente sobre el banner; logo "win" blanco; links blancos MAYÚSCULAS 15px, padding `25px 18px`.
- Arriba a la derecha: botón WhatsApp verde + "Canal de venta 017073000" con icono.

**Hero / banner**
- Slider a ancho completo (~587px alto) con imágenes de fondo naranja/oscuras, flechas laterales circulares.

**Footer**
- Fondo negro, texto blanco, links 15–18px.

## Layout
- Bootstrap 5.3 (grid, utilidades `py-5`, `mb-3`…).
- Contenedor `max-width: 960px` (en desktop crece por breakpoints Bootstrap).
- Secciones con `padding: 48px 0`; todo centrado.
- Radios generosos (10 / 20 / 30px) → estética "soft" y amigable.
- Animación `scale-anim` (pulso suave) en botones de contacto.

## Variables CSS listas para usar
```css
:root{
  --win-orange:#FF5A0A; --win-orange-dark:#D8450B; --win-orange-soft:#FFEFE6;
  --win-yellow:#FFC200; --win-green:#1BD741;
  --win-black:#000; --win-ink:#141414; --win-brown:#301D19; --win-gray:#7C7E80;
  --win-bg-light:#F4F4F4; --win-border:#D6D6D6;
  --win-font:'MADETommySoft','Nunito',sans-serif;
  --win-font-display:'Panton','Montserrat',sans-serif;
  --win-radius-sm:10px; --win-radius-md:20px; --win-radius-lg:30px;
}
body{font-family:var(--win-font);font-size:15px;line-height:1.5;color:var(--win-brown);}
```
