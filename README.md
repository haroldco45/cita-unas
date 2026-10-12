# Citas · Estudio de uñas

App para que las clientas de un estudio de uñas (manos y pies) separen su cita en línea. La app muestra solo las horas en que cabe el servicio completo. Si el día está lleno, sugiere los días más cercanos con cupo. La dueña administra la agenda, el histórico, las clientas y la configuración desde la misma app, con PIN.

Desarrollada por **Vibras Positivas HM** — Derechos de Autor Reservados.

## Cómo está armada

| Parte | Dónde vive | Qué hace |
|---|---|---|
| `index.html` | GitHub Pages (haroldco45/citas-unas) y Netlify | La app que ven la clienta y la dueña |
| `api/index.php` | Hostinger, `citas.vibraspositivashm.com/api/` | Recibe y valida las reservas, guarda en MySQL |
| `api/schema.sql` | Se ejecuta una vez en phpMyAdmin | Crea las tablas y carga los servicios iniciales |
| `api/config.php` | **Solo en Hostinger, nunca en GitHub** | Clave de la base, PIN inicial, sitios permitidos |
| `api/.htaccess` | Hostinger | Bloquea `config.php` y `schema.sql`, y deja pasar la sesión de administración |

Todas las horas se manejan en hora de Colombia (America/Bogota, UTC-5).

### Tablas

- `ajustes`: nombre, slogan, WhatsApp, reglas de agenda, horario y almuerzo. El PIN se guarda cifrado.
- `servicios`: catálogo con duración en minutos y precio (vacío = por definir).
- `bloqueos`: días sin atención.
- `clientas`: una por WhatsApp, con la fecha de autorización de datos (Ley 1581 de 2012).
- `citas`: el histórico completo. Las citas nunca se borran, solo cambian de estado.
- `cita_servicios`: copia del nombre, duración y precio de cada servicio al momento de la cita.
- `sesiones` e `intentos`: sesiones de administración y control de abusos.

### Protecciones

- El servidor vuelve a revisar cada reserva (horario, almuerzo, días bloqueados, anticipación y choques) con un candado por fecha. Dos clientas no pueden quedarse con la misma hora aunque reserven en el mismo segundo.
- La parte pública solo ve los espacios ocupados, nunca nombres ni teléfonos.
- En «Mis citas» se consulta con el WhatsApp, pero para cancelar se necesita el código de la cita.
- Máximo 2 citas pendientes por WhatsApp.
- Después de 5 intentos de PIN equivocado, la administración se bloquea 15 minutos.
- La API solo responde a los sitios listados en `config.php`.

## Instalación en Hostinger

1. **Subdominio.** En hPanel, en Dominios > Subdominios, crear `citas.vibraspositivashm.com` y activarle el SSL gratuito.
2. **Base de datos.** En hPanel, en Bases de datos > Bases de datos MySQL, crear la base y el usuario. Anotar el nombre, el usuario y la clave.
3. **Tablas.** Abrir phpMyAdmin, seleccionar la base, ir a la pestaña SQL y pegar el contenido de `api/schema.sql`.
4. **Configuración.** Editar `api/config.php` con el nombre, usuario y clave de la base. Cambiar `pin_inicial` por el PIN que va a usar la dueña.
5. **Subir la API.** Con el Administrador de archivos, crear la carpeta `api` dentro de la carpeta del subdominio y subir `index.php`, `config.php` y `.htaccess`.
6. **Probar.** Abrir `https://citas.vibraspositivashm.com/api/index.php?r=config`. Debe mostrar el nombre del negocio y los servicios.

## Publicación de la app

1. En `index.html`, revisar que `API_URL` apunte a `https://citas.vibraspositivashm.com/api/index.php`.
2. Subir `index.html` y este `README.md` al repositorio `haroldco45/citas-unas` y activar GitHub Pages. Netlify se actualiza solo desde el repositorio.
3. Si la app se publica en otra dirección, agregarla a `origenes` en `config.php`.

## Primer uso de la dueña

1. Entrar con «Acceso administración» al pie de la página, con el PIN inicial.
2. En Configuración: poner el nombre del negocio, el slogan, el WhatsApp, el horario y los precios. Cambiar el PIN.
3. Desde ese momento el PIN queda guardado cifrado en la base, y `pin_inicial` ya no sirve.

## Modo demostración

Si la app se abre como archivo, desde la vista previa de Claude o con `?demo` al final de la dirección, funciona sin servidor. Los datos se guardan en ese navegador y trae citas de ejemplo. El PIN de demostración es `1234`.

## Pendiente

- Logo, slogan y precios de la clienta.
- `manifest.json`, `sw.js`, íconos e imagen `og-image.png` de 1200×630 cuando esté el logo.
