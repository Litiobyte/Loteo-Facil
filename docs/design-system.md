# Loteo Fácil — Ficha técnica de identidad visual y UI

**Versión:** 1.0  
**Producto:** Loteo Fácil  
**Aplicación:** plataforma web SaaS para administración de loteos y comunidades rurales en Chile  
**Documento:** referencia de diseño para desarrolladores, diseñadores y asistentes de IA

> **Objetivo:** mantener una experiencia visual consistente, profesional, simple y confiable. La identidad representa lotes organizados, control administrativo y transparencia de la comunidad.
>
> **Nota sobre precisión:** los colores HEX de esta guía son una **paleta de referencia adoptada para implementación** a partir de la propuesta visual. No constituyen una medición colorimétrica exacta del PNG generado. Si se consigue un logo maestro vectorial, sus valores deben prevalecer.

## 1. Identidad y logo

### 1.1 Composición

- **Isotipo:** terreno estilizado en perspectiva, dividido en una cuadrícula de lotes verdes mediante separadores blancos. Un lote inferior izquierdo azul y una marca de verificación blanca a la derecha.
- **Logotipo:** `Loteo Fácil`, con `Loteo` en azul oscuro y `Fácil` en verde oscuro.
- **Descriptor:** `ORDEN • TRANSPARENCIA • COMUNIDAD`, en mayúsculas, azul oscuro y con separadores verdes.
- **Personalidad:** tecnológica, cercana, ordenada, accesible y profesional. Evitar una estética de constructora o inmobiliaria tradicional.

### 1.2 Variantes y archivos

| Variante | Uso recomendado | Nombre de archivo sugerido |
|---|---|---|
| Logo horizontal completo, transparente | Login, encabezados amplios, footer, documentos | `logo-full-transparent.png` |
| Isotipo transparente | Menú compacto, favicon, avatar | `icon-transparent.png` |
| Versión monocromática | Impresión a una tinta | `logo-monochrome.svg` |
| Isotipo sobre fondo sólido | Icono de aplicación y PWA | `app-icon.png` |

**Importante:** los nombres son una convención sugerida. Ajustar a las rutas reales del proyecto; no asumir que todos los archivos existen.

### 1.3 Reglas de uso

1. Conservar las proporciones originales; nunca estirar, comprimir, rotar ni recolorear de manera arbitraria.
2. No agregar degradados nuevos, sombras, bordes, brillos ni efectos tridimensionales al logo.
3. Usar el logo completo donde haya espacio suficiente y el isotipo en superficies pequeñas.
4. Mantener un área libre alrededor del logo de al menos **25 % de la altura del isotipo** (regla práctica de implementación).
5. Priorizar fondos blancos o muy claros. Para fondos oscuros usar una variante diseñada para ese contraste, nunca invertir colores automáticamente si compromete la identidad.
6. Si el descriptor se vuelve ilegible, usar una versión sin descriptor en lugar de reducirlo excesivamente.

### 1.4 Tamaños recomendados

| Elemento | Tamaño sugerido |
|---|---|
| Logo horizontal en navbar desktop | 150–200 px de ancho, sin forzar altura |
| Logo horizontal en login | 200–280 px de ancho |
| Isotipo en menú compacto | 32–40 px |
| Favicon | Exportaciones 16×16, 32×32 y 48×48 px |
| Icono PWA | Exportaciones 192×192 y 512×512 px |

Se deben revisar manualmente los detalles del isotipo al tamaño final, especialmente el check y las divisiones blancas.

## 2. Paleta cromática

### 2.1 Colores de marca

| Token | Nombre | HEX | Aplicación |
|---|---|---|---|
| `brand.green` | Verde principal | `#1E6432` | Logotipo «Fácil», identidad, estados afirmativos de marca |
| `brand.blue` | Azul principal | `#0B2E57` | Logotipo «Loteo», títulos institucionales, navegación |
| `brand.white` | Blanco | `#FFFFFF` | Fondo del logo, superficies y espacios negativos |
| `neutral.charcoal` | Gris oscuro | `#333333` | Texto secundario de marca y aplicaciones monocromáticas |

### 2.2 Colores de interfaz sugeridos

Estos tokens amplían la paleta para uso en la plataforma; **no forman parte necesariamente del logo**.

| Token | HEX | Uso |
|---|---|---|
| `surface.page` | `#F7F9F8` | Fondo general de páginas |
| `surface.card` | `#FFFFFF` | Paneles, formularios y tablas |
| `surface.subtle` | `#EDF4EF` | Fondos verdes tenues |
| `text.primary` | `#172B24` | Texto principal |
| `text.secondary` | `#5D6B63` | Descripciones y metadatos |
| `border.default` | `#DCE5DF` | Bordes y divisores |
| `action.primary` | `#1E6432` | Acción principal |
| `action.primaryHover` | `#174F28` | Hover de acción principal |
| `focus.ring` | `#0B2E57` | Foco de teclado |
| `status.success` | `#1E6432` | Confirmaciones, conciliaciones completas |
| `status.warning` | `#936000` | Advertencias, pendientes |
| `status.danger` | `#B42318` | Errores, acciones destructivas |
| `status.info` | `#0B2E57` | Información y avisos neutrales |

**Regla semántica:** el color nunca debe ser el único indicador de estado; combinarlo con texto y, cuando corresponda, iconos.

### 2.3 Variables CSS propuestas

```css
:root {
  --lf-green: #1e6432;
  --lf-blue: #0b2e57;
  --lf-white: #ffffff;
  --lf-charcoal: #333333;

  --lf-bg: #f7f9f8;
  --lf-surface: #ffffff;
  --lf-surface-subtle: #edf4ef;
  --lf-text: #172b24;
  --lf-text-muted: #5d6b63;
  --lf-border: #dce5df;

  --lf-primary: #1e6432;
  --lf-primary-hover: #174f28;
  --lf-focus: #0b2e57;
  --lf-success: #1e6432;
  --lf-warning: #936000;
  --lf-danger: #b42318;
  --lf-info: #0b2e57;

  --lf-radius-sm: 6px;
  --lf-radius-md: 10px;
  --lf-radius-lg: 14px;
  --lf-shadow-card: 0 2px 10px rgb(11 46 87 / 5%);
}
```

## 3. Tipografía

**Familia sugerida:** Montserrat, con `sans-serif` como fallback.

| Elemento | Peso | Tamaño base sugerido |
|---|---|---|
| Título de página | 600–700 | 24–30 px |
| Título de sección | 600 | 18–22 px |
| Título de tarjeta | 600 | 16–18 px |
| Texto normal | 400 | 14–16 px |
| Etiqueta de formulario | 500–600 | 14 px |
| Ayuda / metadatos | 400 | 12–14 px |

- Usar interlineado de 1.4–1.6 en texto corrido.
- Evitar mayúsculas sostenidas en párrafos y elementos largos de navegación.
- Priorizar legibilidad en pantallas pequeñas y tablas financieras.
- Las cifras monetarias deben alinearse a la derecha y usar números tabulares cuando estén disponibles.

## 4. Componentes de interfaz

### 4.1 Botones

- **Primario:** fondo verde `#1E6432`, texto blanco, estados hover y focus visibles.
- **Secundario:** fondo blanco, borde suave, texto azul oscuro.
- **Destructivo:** rojo reservado para eliminación o reversión crítica, con confirmación si corresponde.
- Altura sugerida: **40–44 px**; esquinas de **8–10 px**.
- Solo una acción primaria destacada por bloque o formulario, salvo excepciones justificadas.

### 4.2 Formularios

- Etiquetas visibles sobre cada campo; no depender exclusivamente de placeholders.
- Altura sugerida de controles: **40–44 px**.
- Explicar errores bajo el campo e indicar cómo resolverlos.
- Identificar campos obligatorios sin depender únicamente del color.
- Para CLP, formatear importes consistentemente como `$ 125.000` (sin decimales salvo que el flujo lo requiera).
- Fechas en interfaz chilena: `DD/MM/AAAA`; almacenar fechas y horas en formatos apropiados para el backend.

### 4.3 Tablas y reportes

- Cabeceras claras, separación visual discreta y filas legibles.
- Alinear texto a la izquierda; montos y cifras a la derecha.
- Mostrar totales y subtotales con jerarquía visual, evitando saturar de colores.
- Mantener filtros y acciones importantes accesibles en móvil; usar scroll horizontal cuando la tabla no pueda simplificarse.
- Distinguir visualmente **cobros, recaudaciones, gastos y egresos**, sin mezclar sus significados.

### 4.4 Tarjetas y secciones

- Fondo blanco, borde `#DCE5DF`, radio `10–14 px`.
- Sombra muy tenue u opcional; evitar tarjetas flotantes excesivamente decorativas.
- Espaciado interno sugerido: **16–24 px**.
- Mantener títulos y acciones en posiciones coherentes entre módulos.

### 4.5 Estados financieros

- **Pagado / conciliado:** verde, con etiqueta textual.
- **Pendiente:** color advertencia, con etiqueta textual.
- **Vencido:** rojo, con etiqueta textual.
- **Saldo a favor:** informativo, distinto de deuda pendiente.
- Nunca comunicar estados financieros únicamente mediante color o iconos.

## 5. Retícula y espaciado

Usar una escala de espaciado basada en múltiplos de 4 px:

`4 · 8 · 12 · 16 · 24 · 32 · 48`

- Móvil: priorizar una columna y controles con área táctil adecuada.
- Desktop: usar grillas responsivas sin perder la relación entre datos y acciones.
- Evitar encabezados excesivamente altos o espacios vacíos que obliguen a desplazarse innecesariamente.

## 6. Accesibilidad

- Cumplir como objetivo **WCAG 2.2 AA**.
- Contraste mínimo recomendado: **4.5:1** para texto normal y **3:1** para texto grande y componentes relevantes, según el criterio aplicable.
- Asegurar navegación completa mediante teclado y foco visible.
- Proporcionar nombres accesibles a iconos interactivos y alternativas textuales a gráficos.
- No depender únicamente de color, posición o iconografía para transmitir información crítica.
- Verificar combinaciones finales de color con una herramienta de contraste antes de producción.

## 7. Convenciones de contenido

- Interfaz en **español de Chile**.
- Preferir lenguaje claro para administradores y propietarios no técnicos.
- Usar los términos del dominio con consistencia:
  - **Lotes**: terrenos administrados.
  - **Propietarios**: personas vinculadas a uno o más lotes.
  - **Gastos**: obligaciones o conceptos de gasto de la comunidad.
  - **Cobros**: cargos distribuidos a propietarios conforme a las reglas del loteo.
  - **Recaudaciones**: dinero recibido de propietarios.
  - **Egresos**: salidas de dinero registradas por la administración.
- Usar mensajes directos, por ejemplo: `Recaudación registrada` o `No se pudo guardar el egreso`.

## 8. Reglas de consistencia para nuevas pantallas

Al diseñar o implementar una pantalla:

1. Reutilizar componentes del sistema visual existente y del framework de interfaz antes de crear variantes nuevas.
2. Aplicar tokens semánticos; evitar códigos HEX dispersos en plantillas.
3. Respetar la jerarquía de título, descripción, filtros, contenido y acciones.
4. Mantener la experiencia responsive y accesible.
5. Validar casos sin datos, carga, error, éxito y permisos insuficientes.
6. No introducir otra paleta ni otra tipografía sin una decisión explícita de diseño.
7. Preservar el funcionamiento y la semántica del negocio: los ajustes visuales no deben alterar cálculos financieros ni reglas de acceso.

## 9. Integración sugerida en el proyecto

Guardar este documento en:

```text
docs/design-system.md
```

Y los archivos de marca en una ruta pública del proyecto:

```text
public/branding/
├── logo-full-transparent.png
├── icon-transparent.png
├── app-icon.png
└── logo-monochrome.svg
```

**Estado de implementación en este repo (2026-11):**

- `docs/design-system.md`: este documento (fuente del diseño adoptado).
- `public/branding/logo-full-transparent.png`: logo horizontal (2172×724, fondo transparente), entregado por el usuario.
- `public/branding/icon-transparent.png`: isotipo cuadrado (1254×1254, fondo transparente), entregado por el usuario; usado como favicon de ambos paneles.
- Pendiente: exportaciones de favicon 16/32/48 px, icono PWA 192/512 px, versión monocromática SVG (`logo-monochrome.svg`) e isotipo optimizado para el menú compacto (32–40 px).
- Tokens CSS (`--lf-*`): single source en `resources/views/filament/branding/design-tokens.blade.php`, inyectado vía render hook en ambos paneles Filament; también declarado en `resources/css/app.css` para el frontend público.

Estas rutas son **propuestas de organización**, no una descripción de los archivos existentes.

---

**Criterio rector:** Loteo Fácil debe sentirse como una herramienta de gestión confiable y fácil de usar, no como una inmobiliaria. Las decisiones visuales deben priorizar claridad operativa, transparencia y eficiencia.
