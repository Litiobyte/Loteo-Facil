{{-- Tokens del sistema de diseño (docs/design-system.md, sección 2.3).
    Single source de las variables --lf-*; se inyecta en <head> de ambos paneles
    vía PanelsRenderHook::HEAD_END. Consumir con var(--lf-*) y fallback solo en
    estilos legacy. No añadir HEX nuevos aquí sin actualizar docs/design-system.md. --}}
<style>
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

    /* Logo horizontal en login: 200–280 px de ancho (docs/design-system.md 1.4). */
    .fi-simple-layout .fi-logo {
        width: 15rem;
        height: auto !important;
    }
</style>
