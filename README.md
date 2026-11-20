# Citas · Estudio de uñas

App para que las clientas de un estudio de uñas (manos y pies) separen su cita en línea. La app muestra solo las horas en que cabe el servicio completo. Si el día está lleno, sugiere los días más cercanos con cupo. La clienta puede escoger con quién quiere la cita o dejar que la app se la asigne a quien esté libre. La dueña administra todo desde una dirección aparte, con PIN, y cada trabajadora entra a la misma dirección con su propio PIN para ver solo su agenda.

Desarrollada por **Vibras Positivas HM** — Derechos de Autor Reservados.

## Cómo está armada

| Parte | Dónde vive | Qué hace |
|---|---|---|
| `index.html` | GitHub Pages (haroldco45/cita-unas) y Netlify | La app de las clientas: separar y cancelar citas. No tiene ninguna entrada a la administración |
| `admin.html` | GitHub Pages y Netlify | La administración de la dueña: abre directo en la pantalla del PIN |
| `api/index.php` | Hostinger, `citas.vibraspositivashm.com/api/` (copia en este repo) | Recibe y valida las reservas, guarda en MySQL |
| `api/migracion-equipo.sql` | Se ejecuta una vez en phpMyAdmin, en bases creadas antes del equipo | Agrega el equipo sin borrar las citas |
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
- `profesionales`: la dueña y sus trabajadoras, con los días que trabaja cada una y el PIN cifrado de cada trabajadora.
- `ausencias`: días puntuales en que una persona no viene.
- `sesiones` e `intentos`: sesiones (de la dueña o de una trabajadora) y control de abusos.

### Protecciones

- El servidor vuelve a revisar cada reserva (horario, almuerzo, días bloqueados, anticipación y choques) con un candado por fecha. Dos clientas no pueden quedarse con la misma hora aunque reserven en el mismo segundo.
- La parte pública solo ve los espacios ocupados, nunca nombres ni teléfonos.
- En «Mis citas» se consulta con el WhatsApp, pero para cancelar se necesita el código de la cita.
- Máximo 2 citas pendientes por WhatsApp.
- Después de 5 intentos de PIN equivocado, la administración se bloquea 15 minutos.
- La API solo responde a los sitios listados en `config.php`.
- Cada cita queda con quién la atiende. Dos clientas pueden tener la misma hora con personas distintas, pero nunca con la misma.
- Una trabajadora solo ve y cambia sus propias citas. Clientas, Configuración y Respaldo son solo de la dueña.
- Cada persona tiene un PIN distinto. Al desactivar a una trabajadora o cambiarle el PIN, su sesión se cierra.

## Instalación en Hostinger

1. **Subdominio.** En hPanel, en Dominios > Subdominios, crear `citas.vibraspositivashm.com` y activarle el SSL gratuito.
2. **Base de datos.** En hPanel, en Bases de datos > Bases de datos MySQL, crear la base y el usuario. Anotar el nombre, el usuario y la clave.
3. **Tablas.** Abrir phpMyAdmin, seleccionar la base, ir a la pestaña SQL y pegar el contenido de `api/schema.sql`.
4. **Configuración.** Editar `api/config.php` con el nombre, usuario y clave de la base. Cambiar `pin_inicial` por el PIN que va a usar la dueña.
5. **Subir la API.** Con el Administrador de archivos, crear la carpeta `api` dentro de la carpeta del subdominio y subir `index.php`, `config.php` y `.htaccess`.
6. **Probar.** Abrir `https://citas.vibraspositivashm.com/api/index.php?r=config`. Debe mostrar el nombre del negocio y los servicios.

## Publicación de la app

1. En `index.html`, revisar que `API_URL` apunte a `https://citas.vibraspositivashm.com/api/index.php`.
2. Subir `index.html`, `admin.html` y este `README.md` al repositorio `haroldco45/cita-unas` y activar GitHub Pages. Netlify se actualiza solo desde el repositorio.
3. Si la app se publica en otra dirección, agregarla a `origenes` en `config.php`.

## Direcciones

| Para quién | Dirección |
|---|---|
| Clientas | `https://cita-unas.netlify.app` |
| Dueña (administración) | `https://cita-unas.netlify.app/admin` |

La dirección de administración no se comparte con las clientas. Aunque alguien la encuentre, sin el PIN no puede ver nada: la API exige la sesión de la dueña para cada dato de administración.

`admin.html` es una copia de `index.html` que solo cambia `data-app="admin"` en la etiqueta `<html>`, el título y la línea `noindex`. Cada vez que se cambie `index.html`, hay que volver a generar `admin.html` igual, para que las dos queden con el mismo código.

## Equipo de trabajo

**Si la base ya existía** (instalación anterior al equipo):

1. Subir el `api/index.php` nuevo a Hostinger, encima del anterior.
2. En phpMyAdmin, pestaña SQL, ejecutar **una sola vez** `api/migracion-equipo.sql`. Las citas que ya existían quedan a nombre de la dueña.

**En la app**, la dueña entra a `/admin` y en Configuración → Equipo:

- Le pone su nombre a la primera fila (la dueña) y marca los días que trabaja.
- Con «Agregar trabajadora» crea a cada una: nombre, días y PIN.
- Toca «Guardar cambios».

Si una trabajadora se va, se le quita «Activa»: deja de recibir citas y no puede entrar, pero su histórico se conserva. Si un día no viene, la dueña la escoge en la Agenda y toca «no viene este día».

## Primer uso de la dueña

1. Entrar a `https://cita-unas.netlify.app/admin` con el PIN inicial.
2. En Configuración: poner el nombre del negocio, el slogan, el WhatsApp, el horario y los precios. Cambiar el PIN.
3. Desde ese momento el PIN queda guardado cifrado en la base, y `pin_inicial` ya no sirve.

## Modo demostración

Si la app se abre como archivo, desde la vista previa de Claude o con `?demo` al final de la dirección (`/?demo` para clientas, `/admin?demo` para la dueña), funciona sin servidor. Los datos se guardan en ese navegador y trae citas de ejemplo. Los PIN de demostración son `1234` (dueña), `1111` (Ana) y `2222` (Luz).

## Pendiente

- Logo, slogan y precios de la clienta.
- `manifest.json`, `sw.js`, íconos e imagen `og-image.png` de 1200×630 cuando esté el logo.
