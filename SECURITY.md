# Seguridad

## Cómo avisar de un problema

No abras una incidencia pública. Usa el aviso privado de GitHub: en este repositorio, pestaña **Security > Report a vulnerability**.

Cuenta qué has encontrado, cómo reproducirlo y qué versión del plugin y de WordPress usas. Responderemos en cuanto podamos y te avisaremos cuando esté corregido.

## Versiones con soporte

Se corrige la última versión publicada.

## Qué protege el plugin

- **Claves de API.** No las lee, guarda ni muestra. Las gestiona WordPress en Ajustes > Conectores.
- **Permisos.** Toda la API exige iniciar sesión y la capacidad `mdia_review` (revisar) o `mdia_manage` (ajustes y generación).
- **Cambios en el contenido.** Nada se escribe sin que una persona lo apruebe. Solo se modifica el texto alternativo (el atributo `alt` y, en los bloques que lo duplican, su atributo de bloque), siempre escapado. Una aprobación no llega a contenido que quien aprueba no podría editar.
- **Gasto.** Límite diario de peticiones, generación del histórico solo a petición y parada automática tras tres errores seguidos del proveedor.
- **Datos enviados.** Solo al proveedor de IA conectado: una copia reducida de la imagen, sin metadatos, y un contexto mínimo (nombre de archivo, título, texto actual y título de una página publicada donde se usa). Nada antes de completar la configuración.
- **Respuestas de la IA.** Se tratan siempre como texto plano: sin etiquetas, enlaces, correos ni caracteres invisibles, y con revisión humana.
- **Errores del proveedor.** El detalle solo lo ven los administradores.
