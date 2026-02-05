# Studies Simplifying Access (CO360)

## Uso rápido

### Crear un estudio
1. En WordPress, ve a **Estudios** > **Añadir estudio**.
2. Completa el título y configura en el metabox:
   - Prefijo o Regex del código.
   - Modo de código (único o listado).
   - Bloqueo por email (opcional).
   - URL/formulario de inscripción si aplica.
3. Marca **Estudio activo**.

### Configurar ajustes globales
1. Ve a **Studies Simplifying Access** > **Ajustes**.
2. Define:
   - URL de registro.
   - URL de inscripción global.
   - URL de login personalizada (opcional).
   - TTL del token (minutos).

### Autorrelleno CRD por estudio (única configuración)
1. En **Estudios**, edita el estudio correspondiente.
2. En el metabox **CRD – Autorrelleno**, añade una fila por cada formulario CRD del estudio e indica:
   - Form ID del formulario CRD.
   - Field IDs de investigator_code, center (combinado), y code_used (center_code es opcional).
3. En Formidable, marca esos campos como **Read-only** si quieres que sean visibles pero no editables.
4. Añade también un campo hidden `study_id` en cada CRD para guardar automáticamente el estudio activo en cada envío.
5. Evita marcar esos campos como **Disabled**, **Admin Only** o esconderlos con lógica condicional, para que se rendericen correctamente en CRD.

### Dashboard de Estudios (admin)
- Menú: **Studies Simplifying Access > Dashboard**.
- Selecciona un estudio para ver KPIs, centros e investigadores recientes.
- Si falta `study_id_field_id` en los mapeos CRD, el KPI de CRDs mostrará un aviso de configuración.

### Shortcodes disponibles
- `[acceso_estudio study_id="123" title="Acceso" button_text="Acceder" require_code="1"]`
- `[co360_ssa_form_context]`
- `[co360_ssa_enrollment study_id="123" form_id="456"]`
- `[co360_ssa_stats study_id="123" days="30" chart="line" show_totals="1"]`
- `[co360_ssa_login title="Login" show_labels="1" show_remember="1"]`
- `[co360_ssa_crd_submission_number form_id="4"]` (si omites `form_id`, intenta detectarlo por contexto CRD)

### Flujo recomendado
1. Página de acceso con `[acceso_estudio]` para capturar email+codigo.
2. Redirección automática a la página de inscripción con token.
3. En la página de inscripción:
   - Agrega `[co360_ssa_form_context]` dentro del formulario (Formidable).
   - Usa `[co360_ssa_enrollment]` para mostrar el formulario y validar contexto.
4. Opcional: página de login con `[co360_ssa_login]`.

## Debug
- `?ssa_debug=1` muestra notices ligeros.
- `?ssa_debug=2` detiene redirecciones y muestra panel de diagnóstico.


## Changelog
- **1.0.1**: Añadido Dashboard de Estudios (admin), selector por estudio con KPIs/tablas, y cache temporal con invalidación en inscripciones/CRDs.
