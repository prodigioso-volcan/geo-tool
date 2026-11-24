# Meta Descriptions IA

Plugin de WordPress que revisa el texto alternativo de las imágenes, propone uno mejor con IA y lo aplica solo cuando una persona lo aprueba.

Muchas imágenes acaban sin texto alternativo, o con el nombre del archivo o la palabra «imagen». Eso deja fuera a quien usa un lector de pantalla y perjudica el posicionamiento. Este plugin lo detecta, lo corrige con ayuda de la IA y te dice de un vistazo si todo está en verde.

![Panel con el estado, la cobertura y las comprobaciones](docs/img/panel.jpg)

## Qué hace

- **Revisa sin coste.** Analiza cada imagen de la biblioteca de medios y cada uso en entradas y páginas. Esta revisión no usa IA.
- **Propone con IA.** Pide una descripción al proveedor que tengas conectado en WordPress: OpenAI, Anthropic o Google.
- **No cambia nada por su cuenta.** Cada propuesta pasa por una persona, que la aprueba, la edita, la ignora o marca la imagen como decorativa.
- **Aplica donde hace falta.** El texto aprobado se escribe en la biblioteca y también en el contenido ya publicado. Se puede deshacer.
- **Controla el gasto.** Tiene un límite diario de peticiones y no genera el histórico hasta que alguien lo lanza.
- **Informa del estado.** Un panel, un widget en el Escritorio y una prueba en Salud del sitio.

## Requisitos

- WordPress 7.0 o superior.
- PHP 8.1 o superior.
- Un proveedor de IA conectado en **Ajustes > Conectores**, con su clave de API.
- WP-Cron en funcionamiento (o un cron del servidor), porque el trabajo se hace en segundo plano.

> **La clave de API no es la suscripción.** Una suscripción a ChatGPT, Claude o Gemini no sirve: ningún proveedor permite hoy usarla desde un plugin alojado en tu web. Hace falta una clave de API, que se factura por uso. Describir una imagen suele costar una fracción de céntimo.

## Instalación

1. Descarga el zip de la [última versión](../../releases/latest).
2. En WordPress, ve a **Plugins > Añadir nuevo > Subir plugin**, elige el zip y actívalo.
3. Se abrirá el asistente de configuración.

Si prefieres generar el zip desde el código, mira [Desarrollo](#desarrollo).

## Puesta en marcha

El asistente tiene cinco pasos:

1. **Requisitos.** Comprueba que hay un proveedor de IA conectado. Si no lo hay, te lleva a **Ajustes > Conectores**: instala el conector de tu proveedor y pega la clave de API.
2. **Prueba.** Describe una imagen de tu biblioteca para confirmar que todo funciona. No guarda nada.
3. **Preferencias.** Eliges el límite diario de peticiones y si las imágenes nuevas reciben propuesta automáticamente.
4. **Primera revisión.** Analiza la biblioteca y el contenido, y te dice cuántas imágenes necesitan texto.
5. **Listo.** Puedes lanzar la generación de propuestas o ir al panel.

Hasta que termines el asistente, el plugin no genera propuestas. Lo único que se envía antes es la imagen de la prueba del paso 2, y solo cuando pulsas el botón.

## Uso

Todo está en **Medios > Textos alternativos IA**.

### Panel

Muestra un estado global y cuatro comprobaciones.

| Estado | Significa |
|---|---|
| **Todo en verde** | Todas las imágenes tienen un texto correcto, o alguien decidió que no lo necesitan. |
| **Necesita atención** | Hay imágenes por generar, por revisar o aplicándose, o se alcanzó el límite diario. |
| **Hay que actuar** | Falla el conector de IA, hay errores sin resolver o las tareas en segundo plano no se ejecutan. |

Desde el panel se lanza la generación de propuestas con el botón **Generar propuestas**. Antes de empezar pide confirmación y recuerda que cada imagen es una petición de pago.

### Revisión

![Cola de revisión con las propuestas editables](docs/img/revision.jpg)

Cada imagen muestra su texto actual, el problema detectado, en cuántas páginas se usa y la propuesta, que puedes editar.

| Acción | Qué hace |
|---|---|
| **Aprobar** | Aplica el texto que ves en pantalla a la biblioteca y a las páginas. |
| **Regenerar** | Pide otra propuesta a la IA. |
| **Decorativa** | Deja la imagen sin texto alternativo a propósito. Cuenta como correcta. |
| **Ignorar** | No toca nada y deja de señalar la imagen. |
| **Deshacer** | Devuelve el texto anterior a la biblioteca y a las páginas. |

Puedes seleccionar varias y usar **Aprobar seleccionadas**.

Cuando el texto de la biblioteca ya es bueno y solo falta en alguna página, el plugin propone copiar ese mismo texto. En ese caso no usa IA.

### Ajustes

- Límite diario de peticiones de IA (200 por defecto).
- Generación automática para imágenes nuevas.
- Revisar todo de nuevo y repetir el asistente.

## Qué considera un texto deficiente

| Problema | Regla |
|---|---|
| Falta | Está vacío. |
| Nombre de archivo | Es el nombre del archivo o lo parece (`IMG_2034`, `captura-de-pantalla-2026`). |
| Genérico | Es una palabra como «imagen», «foto», «logo» o «sin título». |
| Demasiado corto | Menos de 5 caracteres. |
| Demasiado largo | Más de 150 caracteres. |
| Repetido | El mismo texto en 5 o más imágenes. |

## Qué cambia en tu web y qué no

Al aprobar un texto, el plugin:

- Actualiza el texto alternativo de la imagen en la biblioteca de medios.
- Actualiza el atributo `alt` en las entradas, páginas, patrones sincronizados y plantillas donde aparece esa imagen. En los bloques que guardan ese texto también en sus atributos (como Fondo), actualiza los dos sitios. Cada página guarda una revisión de WordPress.
- Solo llega al contenido que podría editar quien aprueba: un editor no modifica plantillas; un administrador sí.

En una página solo sustituye el texto si está vacío, si es una copia del texto antiguo de la biblioteca o si incumple las reglas. **Un texto bueno escrito para esa página se respeta.**

No toca el título, la leyenda ni la descripción de la imagen.

## Privacidad y costes

- Para describir una imagen se envía al proveedor de IA que hayas conectado una copia reducida (1024 px de lado mayor como máximo), el nombre del archivo, su título, su texto alternativo actual y el título de una página donde se usa. No se envía nada a nadie más.
- El plugin nunca guarda ni muestra tu clave de API. La gestiona WordPress en **Ajustes > Conectores**.
- Las imágenes se vuelven a codificar antes de enviarse, sin sus metadatos (por ejemplo, la posición GPS de la cámara).
- Las propuestas nunca contienen enlaces ni direcciones de correo, aunque aparezcan en la imagen.
- Solo generan propuesta automática las imágenes que sube alguien con permiso para revisar.
- El límite diario cuenta todas las peticiones, incluidos los reintentos. Al alcanzarlo, el plugin se detiene y continúa al día siguiente.
- Tres errores seguidos del proveedor detienen la cola, para que una clave caducada no consuma el límite.

## Límites conocidos

- **Constructores visuales.** Las imágenes colocadas con Elementor se actualizan en la biblioteca, pero no dentro de esas páginas. El panel indica cuántas hay. Otros constructores y los campos personalizados no se detectan.
- **Formatos.** Se revisan JPEG, PNG, WebP y GIF. No se revisan SVG ni archivos que no sean imágenes.
- **Un idioma por sitio.** Los textos se escriben en el idioma del sitio. No hay soporte para webs multilingües.
- **Multisitio.** Funciona sitio a sitio, sin administración de red.

## Preguntas frecuentes

**¿Puedo elegir el modelo de IA?**
Sí, con el filtro `mdia_model_preference`. Los modelos pequeños (Claude Haiku, GPT mini, Gemini Flash) dan buen resultado y cuestan menos.

```php
add_filter( 'mdia_model_preference', fn() => array( 'claude-haiku-5-5' ) );
```

**¿Puedo usar Amazon Bedrock?**
El plugin funciona con cualquier proveedor registrado en el cliente de IA de WordPress. Se ha probado con Claude Haiku 5.5 en Bedrock mediante un conector de la comunidad, que no está incluido ni mantenido aquí.

**Las tareas en segundo plano aparecen en rojo.**
El plugin depende de WP-Cron. Comprueba que funciona en tu web o configura un cron del servidor.

**¿Qué pasa al desinstalarlo?**
Se borran sus tablas y ajustes. Los textos alternativos ya aplicados se conservan.

## Para desarrolladores

### Filtros

| Filtro | Para qué sirve |
|---|---|
| `mdia_model_preference` | Modelos preferidos, por orden. |
| `mdia_system_prompt` | Instrucciones que recibe la IA. |
| `mdia_audit_config` | Umbrales y palabras genéricas de la revisión. |
| `mdia_indexed_post_types` | Tipos de contenido que se revisan y reescriben. |
| `mdia_unmanaged_attachment_ids` | Imágenes que una página usa en un sitio que el plugin no puede reescribir. |
| `mdia_max_source_bytes` / `mdia_max_source_pixels` | Tamaño máximo de imagen que se analiza. |
| `mdia_provider` | Sustituye el proveedor de IA (se usa en las pruebas). |

### Permisos

- `mdia_manage`: ajustes, asistente y lanzar la generación. Lo tienen los administradores.
- `mdia_review`: revisar y aprobar. Lo tienen administradores y editores.

### Desarrollo

Solo hace falta Docker. PHP, Composer, Node, WP-CLI y las pruebas corren en contenedores.

```bash
cp .env.example .env
```

Cambia las contraseñas de `.env` y arranca:

```bash
docker compose up -d
```

```bash
bin/composer install
```

```bash
bin/npm install
```

```bash
bin/npm run build
```

La primera vez hay que instalar WordPress y activar el plugin:

```bash
bin/wp core install --url=http://localhost:8787 --title="Local" --admin_user=admin --admin_password=CAMBIA --admin_email=admin@example.test --skip-email
```

```bash
bin/wp plugin install ai-provider-for-openai ai-provider-for-anthropic ai-provider-for-google --activate
```

```bash
bin/wp plugin activate meta-descriptions-ia
```

La web queda en http://localhost:8787 y el correo de prueba en http://localhost:8797.

| Comando | Qué hace |
|---|---|
| `bin/test-all` | Pruebas unitarias y de integración. |
| `bin/test unit` · `bin/test integration` | Cada suite por separado. |
| `bin/npm run build` | Compila la interfaz en `plugin/build/`. |
| `bin/wp …` | WP-CLI. |
| `bin/seed [proposals]` | Crea imágenes y entradas de demostración. |
| `bin/i18n` | Regenera los archivos de idioma. |
| `bin/build-zip` | Genera `dist/meta-descriptions-ia-{versión}.zip`. |
| `bin/check-zip` | Instala ese zip en un WordPress limpio y desechable. |

El servicio `cron` de `docker-compose.yml` ejecuta las tareas en segundo plano cada 20 segundos.

### Estructura

```
plugin/                 el plugin
  src/                  código PHP, espacio de nombres MDIA\
  assets/src/           interfaz de administración (React)
  languages/            traducciones (es_ES incluida)
  tests/                pruebas y datos de demostración
bin/                    envoltorios de Docker
docker/                 archivos solo del entorno local
docs/                   imágenes del README
```

Las cadenas del código están en inglés. Tras añadir o cambiar cadenas, ejecuta `bin/i18n`, traduce las nuevas en `plugin/languages/meta-descriptions-ia-es_ES.po` y vuelve a ejecutarlo.

## Seguridad

Si encuentras un problema de seguridad, no abras una incidencia pública. Mira [SECURITY.md](SECURITY.md).

## Licencia

[GPL-2.0-or-later](LICENSE). Hecho por [Prodigioso Volcán](https://www.prodigiosovolcan.com).
