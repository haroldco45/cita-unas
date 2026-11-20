<?php
/* =====================================================================
   API · App de citas · Estudio de uñas
   PHP 8 + MySQL/MariaDB (Hostinger). Todas las horas en America/Bogota.
   Desarrollada por Vibras Positivas HM — Derechos de Autor Reservados

   Rutas (parámetro ?r=):
   Públicas
     GET  config                     ajustes, servicios activos, equipo, días bloqueados, ausencias, hora actual
     GET  ocupacion&desde=&hasta=    espacios ocupados por profesional (sin datos personales)
     POST reservar                   separa una cita; {profesional: id | null = cualquiera}
     POST mis                        citas de un WhatsApp (sin nombre ni teléfono)
     POST cancelar                   la clienta cancela con WhatsApp + código
   Equipo (encabezado Authorization: Bearer <token>)
     POST login / logout             el PIN dice si entra la dueña o una trabajadora
     GET  sesion                     quién está conectada
     GET  citas&desde=&hasta=&estado=&q=&profesional=   (una trabajadora solo ve las suyas)
     POST agendar                    cita creada desde la administración
     POST estado                     {id, estado}  (una trabajadora solo cambia las suyas)
     GET  config_admin               configuración completa
   Solo la dueña
     GET  clientas
     POST guardar_config             ajustes, horario, servicios, equipo y PIN
     POST bloqueo                    {fecha, bloquear:true|false}   (todo el negocio)
     POST ausencia                   {profesional, fecha, ausente:true|false}
     GET  respaldo                   todo en JSON
   ===================================================================== */

declare(strict_types=1);
date_default_timezone_set('America/Bogota');
ini_set('display_errors', '0');

$CFG = require __DIR__ . '/config.php';

/* ---------- CORS ---------- */
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origen && in_array(rtrim($origen, '/'), $CFG['origenes'], true)) {
  header('Access-Control-Allow-Origin: ' . $origen);
  header('Vary: Origin');
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Max-Age: 86400');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* ---------- Utilidades ---------- */
function responder($datos, int $codigo = 200): never {
  http_response_code($codigo);
  echo json_encode($datos, JSON_UNESCAPED_UNICODE);
  exit;
}
function fallar(string $mensaje, int $codigo = 400, string $clave = 'error'): never {
  responder(['ok' => false, 'error' => $clave, 'mensaje' => $mensaje], $codigo);
}
function entrada(): array {
  $j = json_decode(file_get_contents('php://input') ?: '[]', true);
  return is_array($j) ? $j : [];
}
function ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }
function ahora(): array {
  $d = new DateTime('now', new DateTimeZone('America/Bogota'));
  return ['fecha' => $d->format('Y-m-d'), 'min' => (int)$d->format('G') * 60 + (int)$d->format('i'), 'iso' => $d->format(DATE_ATOM)];
}
function fechaValida($f): bool {
  if (!is_string($f) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return false;
  [$y, $m, $d] = array_map('intval', explode('-', $f));
  return checkdate($m, $d, $y);
}
function sumarDias(string $f, int $n): string { return (new DateTime($f))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d'); }
function diaSemana(string $f): int { return (int)(new DateTime($f))->format('w'); }
function telValido($t): ?string { $t = preg_replace('/\D/', '', (string)$t); return preg_match('/^3\d{9}$/', $t) ? $t : null; }
function limpiar($s, int $max): string { return mb_substr(trim(strip_tags((string)$s)), 0, $max); }
function hora12(int $min): string { $h = intdiv($min, 60); $m = $min % 60; return (($h + 11) % 12 + 1) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ($h >= 12 ? ' p. m.' : ' a. m.'); }

/* ---------- Base de datos ---------- */
try {
  $db = new PDO(
    "mysql:host={$CFG['db_host']};dbname={$CFG['db_name']};charset=utf8mb4",
    $CFG['db_user'], $CFG['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
  );
  $db->exec("SET time_zone = '-05:00'");
} catch (Throwable $e) {
  fallar('No hay conexión con la base de datos. Revisa config.php.', 500, 'sin_db');
}
function q(string $sql, array $p = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }

/* ---------- Ajustes ---------- */
function ajustes(): array {
  $a = [];
  foreach (q('SELECT clave, valor FROM ajustes')->fetchAll() as $r) $a[$r['clave']] = $r['valor'];
  return [
    'negocio'       => $a['negocio'] ?? 'Tu estudio de uñas',
    'slogan'        => $a['slogan'] ?? '',
    'whatsapp'      => $a['whatsapp'] ?? '',
    'paso'          => (int)($a['paso'] ?? 30),
    'anticipacion'  => (int)($a['anticipacion'] ?? 60),
    'diasVista'     => (int)($a['diasVista'] ?? 21),
    'horasCancelar' => (int)($a['horasCancelar'] ?? 3),
    'horario'       => json_decode($a['horario'] ?? '{}', true) ?: [],
    'almuerzo'      => json_decode($a['almuerzo'] ?? '{"on":false,"ini":0,"fin":0}', true),
    '_pin_hash'     => $a['pin_hash'] ?? null,
  ];
}
function guardarAjuste(string $clave, string $valor): void {
  q('INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$clave, $valor]);
}
function servicios(bool $soloActivos): array {
  $rows = q('SELECT id, nombre, categoria, duracion, precio, activo FROM servicios' . ($soloActivos ? ' WHERE activo = 1' : '') . ' ORDER BY orden, nombre')->fetchAll();
  return array_map(fn($s) => ['id' => $s['id'], 'nombre' => $s['nombre'], 'cat' => $s['categoria'], 'dur' => (int)$s['duracion'],
    'precio' => $s['precio'] === null ? null : (int)$s['precio'], 'activo' => (bool)$s['activo']], $rows);
}
function bloqueos(): array {
  return array_column(q('SELECT fecha FROM bloqueos WHERE fecha >= ? ORDER BY fecha', [sumarDias(ahora()['fecha'], -400)])->fetchAll(), 'fecha');
}

/* ---------- Equipo ---------- */
function diasLista(string $d): array { return array_values(array_unique(array_filter(array_map('intval', explode(',', $d)), fn($x) => $x >= 0 && $x <= 6))); }
function profesionales(bool $soloActivas, bool $paraDuena = false): array {
  $rows = q('SELECT id, nombre, rol, pin_hash, dias, activa FROM profesionales' . ($soloActivas ? ' WHERE activa = 1' : '') . ' ORDER BY orden, id')->fetchAll();
  return array_map(function ($p) use ($paraDuena) {
    $o = ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'rol' => $p['rol'], 'dias' => diasLista($p['dias']), 'activa' => (bool)$p['activa']];
    if ($paraDuena) $o['tienePin'] = $p['rol'] === 'duena' || $p['pin_hash'] !== null;
    return $o;
  }, $rows);
}
function profesional(int $id): ?array {
  $p = q('SELECT id, nombre, rol, dias, activa FROM profesionales WHERE id = ?', [$id])->fetch();
  return $p ? ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'rol' => $p['rol'], 'dias' => diasLista($p['dias']), 'activa' => (bool)$p['activa']] : null;
}
function idDuena(): int { return (int)(q("SELECT id FROM profesionales WHERE rol = 'duena' ORDER BY id LIMIT 1")->fetch()['id'] ?? 1); }
function ausencias(): array {
  return array_map(fn($a) => ['profesional' => (int)$a['profesional_id'], 'fecha' => $a['fecha']],
    q('SELECT profesional_id, fecha FROM ausencias WHERE fecha >= ? ORDER BY fecha', [sumarDias(ahora()['fecha'], -400)])->fetchAll());
}
/* ¿Ese PIN ya lo usa otra persona? (los PIN tienen que ser distintos porque el PIN dice quién entra) */
function pinEnUso(string $pin, ?int $menosProf, bool $menosDuena): bool {
  global $CFG;
  if (!$menosDuena) {
    $h = ajustes()['_pin_hash'];
    if ($h ? password_verify($pin, $h) : hash_equals((string)$CFG['pin_inicial'], $pin)) return true;
  }
  foreach (q("SELECT id, pin_hash FROM profesionales WHERE rol = 'trabajadora' AND pin_hash IS NOT NULL")->fetchAll() as $t)
    if ((int)$t['id'] !== $menosProf && password_verify($pin, $t['pin_hash'])) return true;
  return false;
}

/* ---------- Límites anti-abuso ---------- */
function contarIntentos(string $tipo, int $minutos): int {
  return (int)q('SELECT COUNT(*) c FROM intentos WHERE tipo = ? AND ip = ? AND fecha > (NOW() - INTERVAL ? MINUTE)', [$tipo, ip(), $minutos])->fetch()['c'];
}
function anotarIntento(string $tipo): void {
  q('INSERT INTO intentos (tipo, ip) VALUES (?, ?)', [$tipo, ip()]);
  if (random_int(1, 50) === 1) q('DELETE FROM intentos WHERE fecha < (NOW() - INTERVAL 2 DAY)');
}

/* ---------- Sesión de administración ---------- */
function tokenRecibido(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  if (!$h && function_exists('apache_request_headers')) { $hs = apache_request_headers(); $h = $hs['Authorization'] ?? $hs['authorization'] ?? ''; }
  return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m) ? strtolower($m[1]) : null;
}
/* La sesión dice quién entró: la dueña (rol admin) o una trabajadora (solo su agenda) */
function sesion(): ?array {
  $t = tokenRecibido(); if (!$t) return null;
  $s = q('SELECT s.rol, s.profesional_id, p.nombre, p.activa FROM sesiones s LEFT JOIN profesionales p ON p.id = s.profesional_id
          WHERE s.token_hash = ? AND s.expira > NOW()', [hash('sha256', $t)])->fetch();
  if (!$s) return null;
  if ($s['rol'] === 'trabajadora' && (!$s['profesional_id'] || !$s['activa'])) return null;   // trabajadora desactivada = sin acceso
  return ['rol' => $s['rol'], 'profesional' => $s['profesional_id'] ? (int)$s['profesional_id'] : idDuena(), 'nombre' => $s['nombre'] ?? 'Dueña'];
}
function exigirSesion(): array { $s = sesion(); if (!$s) fallar('La sesión se cerró. Vuelve a entrar con tu PIN.', 401, 'sin_sesion'); return $s; }
function exigirDuena(): array { $s = exigirSesion(); if ($s['rol'] !== 'admin') fallar('Esto solo lo puede hacer la dueña.', 403, 'sin_permiso'); return $s; }

/* ---------- Disponibilidad (misma regla que la app) ---------- */
const ACTIVAS = "('pendiente','confirmada','atendida')";

function revisarEspacio(array $aj, string $fecha, int $inicio, int $dur, bool $porAdmin, int $pid, ?int $excluirId = null): ?string {
  $now = ahora();
  if ($fecha < $now['fecha']) return 'Esa fecha ya pasó.';
  if (!$porAdmin && $fecha > sumarDias($now['fecha'], max(1, $aj['diasVista']) - 1)) return 'Esa fecha todavía no está abierta para reservar.';
  if (q('SELECT 1 FROM bloqueos WHERE fecha = ?', [$fecha])->fetch()) return 'Ese día no hay atención.';
  $h = $aj['horario'][(string)diaSemana($fecha)] ?? null;
  if (!$h || empty($h['on'])) return 'Ese día no hay atención.';
  $ini = (int)$h['ini']; $fin = (int)$h['fin']; $paso = max(5, $aj['paso']);
  if ($inicio < $ini || $inicio + $dur > $fin) return 'Esa hora está por fuera del horario de atención.';
  if (!$porAdmin && (($inicio - $ini) % $paso) !== 0) return 'Esa hora no es válida.';
  if ($fecha === $now['fecha'] && $inicio < $now['min'] + ($porAdmin ? 0 : $aj['anticipacion'])) return 'Esa hora ya no se puede separar.';
  $al = $aj['almuerzo'];
  if (!empty($al['on']) && $inicio < (int)$al['fin'] && $inicio + $dur > (int)$al['ini']) return 'Esa hora se cruza con el almuerzo.';
  $p = profesional($pid);
  if (!$p || !$p['activa']) return 'Esa persona ya no está atendiendo.';
  if (!in_array(diaSemana($fecha), $p['dias'], true)) return $p['nombre'] . ' no trabaja ese día.';
  if (q('SELECT 1 FROM ausencias WHERE profesional_id = ? AND fecha = ?', [$pid, $fecha])->fetch()) return $p['nombre'] . ' no viene ese día.';
  $choque = q('SELECT 1 FROM citas WHERE fecha = ? AND profesional_id = ? AND estado IN ' . ACTIVAS . ' AND inicio < ? AND fin > ?' . ($excluirId ? ' AND id <> ?' : '') . ' LIMIT 1',
    $excluirId ? [$fecha, $pid, $inicio + $dur, $inicio, $excluirId] : [$fecha, $pid, $inicio + $dur, $inicio])->fetch();
  if ($choque) return 'ocupado';
  return null;
}

/* «Cualquiera»: la primera que esté libre, empezando por la que tenga menos citas ese día */
function asignarProfesional(array $aj, string $fecha, int $inicio, int $dur, bool $porAdmin): array {
  $cands = q("SELECT p.id, (SELECT COUNT(*) FROM citas c WHERE c.profesional_id = p.id AND c.fecha = ? AND c.estado IN " . ACTIVAS . ") n
              FROM profesionales p WHERE p.activa = 1 ORDER BY n, p.orden, p.id", [$fecha])->fetchAll();
  $motivos = [];
  foreach ($cands as $c) {
    $m = revisarEspacio($aj, $fecha, $inicio, $dur, $porAdmin, (int)$c['id']);
    if ($m === null) return [(int)$c['id'], null];
    $motivos[] = $m;
  }
  if (!$motivos) return [null, 'No hay nadie del equipo atendiendo.'];
  if (in_array('ocupado', $motivos, true)) return [null, 'ocupado'];          // alguien trabaja ese día, pero a esa hora ya está tomada
  $unicos = array_values(array_unique($motivos));
  return [null, count($unicos) === 1 ? $unicos[0] : 'Esa hora no está disponible. Escoge otra.'];
}

function codigoNuevo(): string {
  $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  do { $c = ''; for ($i = 0; $i < 5; $i++) $c .= $a[random_int(0, strlen($a) - 1)]; }
  while (q('SELECT 1 FROM citas WHERE codigo = ?', [$c])->fetch());
  return $c;
}

/* Lee citas con sus servicios y clienta, en la forma que usa la app */
function leerCitas(string $where, array $p, bool $conDatos): array {
  $rows = q("SELECT c.*, cl.nombre AS cl_nombre, cl.telefono AS cl_tel, pr.nombre AS pr_nombre FROM citas c JOIN clientas cl ON cl.id = c.clienta_id
              LEFT JOIN profesionales pr ON pr.id = c.profesional_id WHERE $where ORDER BY c.fecha DESC, c.inicio DESC", $p)->fetchAll();
  if (!$rows) return [];
  $ids = array_column($rows, 'id');
  $marcas = implode(',', array_fill(0, count($ids), '?'));
  $svc = [];
  foreach (q("SELECT cita_id, servicio_id, nombre, duracion, precio FROM cita_servicios WHERE cita_id IN ($marcas) ORDER BY id", $ids)->fetchAll() as $s)
    $svc[$s['cita_id']][] = ['id' => $s['servicio_id'], 'nombre' => $s['nombre'], 'dur' => (int)$s['duracion'], 'precio' => $s['precio'] === null ? null : (int)$s['precio']];
  return array_map(function ($c) use ($svc, $conDatos) {
    $o = ['id' => (string)$c['id'], 'codigo' => $c['codigo'], 'fecha' => $c['fecha'], 'inicio' => (int)$c['inicio'], 'fin' => (int)$c['fin'],
      'duracion' => (int)$c['duracion'], 'servicios' => $svc[$c['id']] ?? [], 'total' => $c['total'] === null ? null : (int)$c['total'],
      'estado' => $c['estado'], 'origen' => $c['origen'], 'creada' => $c['creada'],
      'profesional' => $c['profesional_id'] === null ? null : (int)$c['profesional_id'], 'profesionalNombre' => $c['pr_nombre'] ?? ''];
    if ($conDatos) { $o['cliente'] = ['nombre' => $c['cl_nombre'], 'tel' => $c['cl_tel']]; $o['nota'] = $c['nota'] ?? ''; $o['actualizada'] = $c['actualizada']; }
    return $o;
  }, $rows);
}

/* Crea una cita dentro de un candado por fecha para que nadie tome la misma hora al mismo tiempo */
function crearCita(array $in, bool $porAdmin, ?array $ses = null): array {
  global $db;
  $aj = ajustes();
  $fecha = $in['fecha'] ?? ''; $inicio = filter_var($in['inicio'] ?? null, FILTER_VALIDATE_INT);
  $nombre = limpiar($in['nombre'] ?? '', 60); $tel = telValido($in['tel'] ?? '');
  $nota = limpiar($in['nota'] ?? '', 240);
  if (!fechaValida($fecha) || $inicio === false || $inicio === null) fallar('Escoge un día y una hora.');
  if (mb_strlen($nombre) < 2) fallar('Escribe tu nombre.');
  if (!$tel) fallar('Escribe un celular de 10 dígitos que empiece por 3.');
  if (!$porAdmin && empty($in['autoriza'])) fallar('Necesitamos tu autorización para guardar la cita.');

  $ids = array_values(array_unique(array_filter((array)($in['servicios'] ?? []), 'is_string')));
  if (!$ids || count($ids) > 8) fallar('Escoge al menos un servicio.');
  $marcas = implode(',', array_fill(0, count($ids), '?'));
  $svcs = q("SELECT id, nombre, duracion, precio FROM servicios WHERE activo = 1 AND id IN ($marcas) ORDER BY orden", $ids)->fetchAll();
  if (count($svcs) !== count($ids)) fallar('Uno de los servicios ya no está disponible. Vuelve a escogerlos.', 409, 'servicio');
  $dur = array_sum(array_map(fn($s) => (int)$s['duracion'], $svcs));
  $total = in_array(null, array_column($svcs, 'precio'), true) ? null : array_sum(array_map(fn($s) => (int)$s['precio'], $svcs));

  if (!$porAdmin) {
    $futuras = (int)q('SELECT COUNT(*) c FROM citas c JOIN clientas cl ON cl.id = c.clienta_id WHERE cl.telefono = ? AND c.estado IN (\'pendiente\',\'confirmada\') AND c.fecha >= ?',
      [$tel, ahora()['fecha']])->fetch()['c'];
    if ($futuras >= 2) fallar('Ya tienes 2 citas pendientes con este número. Si necesitas otra, escríbenos por WhatsApp.', 429, 'limite');
  }

  // Con quién: una trabajadora agenda solo en su propia agenda; si no se escoge, la app asigna
  $pid = filter_var($in['profesional'] ?? null, FILTER_VALIDATE_INT) ?: null;
  if ($ses && $ses['rol'] === 'trabajadora') $pid = $ses['profesional'];

  $candado = 'citas_unas_' . $fecha;
  if ((int)q('SELECT GET_LOCK(?, 8) l', [$candado])->fetch()['l'] !== 1) fallar('Hay mucha gente reservando. Intenta de nuevo en un momento.', 503, 'ocupado_servidor');
  try {
    $db->beginTransaction();
    if ($pid) $motivo = revisarEspacio($aj, $fecha, (int)$inicio, $dur, $porAdmin, $pid);
    else [$pid, $motivo] = asignarProfesional($aj, $fecha, (int)$inicio, $dur, $porAdmin);
    if ($motivo === 'ocupado') { $db->rollBack(); fallar('Esa hora se acaba de ocupar. Escoge otra.', 409, 'ocupado'); }
    if ($motivo) { $db->rollBack(); fallar($motivo, 409, 'no_disponible'); }

    q('INSERT INTO clientas (telefono, nombre, autoriza_datos) VALUES (?, ?, ' . ($porAdmin ? 'NULL' : 'NOW()') . ')
       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)' . ($porAdmin ? '' : ', autoriza_datos = NOW()'), [$tel, $nombre]);
    $clientaId = (int)q('SELECT id FROM clientas WHERE telefono = ?', [$tel])->fetch()['id'];
    $codigo = codigoNuevo();
    q('INSERT INTO citas (codigo, clienta_id, profesional_id, fecha, inicio, fin, duracion, total, estado, origen, nota) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$codigo, $clientaId, $pid, $fecha, $inicio, $inicio + $dur, $dur, $total, $porAdmin ? 'confirmada' : 'pendiente', $porAdmin ? 'admin' : 'cliente', $nota ?: null]);
    $citaId = (int)$db->lastInsertId();
    foreach ($svcs as $s) q('INSERT INTO cita_servicios (cita_id, servicio_id, nombre, duracion, precio) VALUES (?,?,?,?,?)', [$citaId, $s['id'], $s['nombre'], $s['duracion'], $s['precio']]);
    $db->commit();
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    q('SELECT RELEASE_LOCK(?)', [$candado]);
    if ($e instanceof PDOException) fallar('No se pudo guardar la cita. Intenta de nuevo.', 500, 'db');
    throw $e;
  }
  q('SELECT RELEASE_LOCK(?)', [$candado]);
  return leerCitas('c.id = ?', [$citaId], $porAdmin)[0];
}

/* =====================================================================
   Rutas
   ===================================================================== */
$r = $_GET['r'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$soloPost = fn() => $metodo === 'POST' || fallar('Usa POST.', 405);

try {
switch ($r) {

  case 'config': {
    $aj = ajustes(); unset($aj['_pin_hash']);
    $eq = array_map(fn($p) => ['id' => $p['id'], 'nombre' => $p['nombre'], 'dias' => $p['dias']], profesionales(true));
    responder($aj + ['servicios' => servicios(true), 'profesionales' => $eq, 'bloqueos' => bloqueos(), 'ausencias' => ausencias(), 'ahora' => ahora()]);
  }

  case 'ocupacion': {
    $hoy = ahora()['fecha'];
    $desde = fechaValida($_GET['desde'] ?? '') ? $_GET['desde'] : $hoy;
    $hasta = fechaValida($_GET['hasta'] ?? '') ? $_GET['hasta'] : sumarDias($hoy, 60);
    if ($hasta > sumarDias($desde, 90)) $hasta = sumarDias($desde, 90);
    $rows = q('SELECT id, profesional_id, fecha, inicio, fin, estado FROM citas WHERE fecha BETWEEN ? AND ? AND estado IN ' . ACTIVAS . ' ORDER BY fecha, inicio', [$desde, $hasta])->fetchAll();
    responder(array_map(fn($c) => ['id' => 'o' . $c['id'], 'profesional' => $c['profesional_id'] === null ? null : (int)$c['profesional_id'],
      'fecha' => $c['fecha'], 'inicio' => (int)$c['inicio'], 'fin' => (int)$c['fin'], 'estado' => $c['estado']], $rows));
  }

  case 'reservar': {
    $soloPost();
    $in = entrada();
    if (!empty($in['web'])) fallar('Solicitud no válida.', 400);              // campo trampa para robots
    if (contarIntentos('reserva', 60) >= 12) fallar('Demasiadas reservas desde esta conexión. Intenta más tarde.', 429, 'limite');
    anotarIntento('reserva');
    responder(['ok' => true, 'cita' => crearCita($in, false)]);
  }

  case 'mis': {
    $soloPost();
    if (contarIntentos('mis', 60) >= 30) fallar('Demasiadas consultas. Intenta más tarde.', 429, 'limite');
    anotarIntento('mis');
    $tel = telValido(entrada()['tel'] ?? ''); if (!$tel) fallar('Escribe un celular de 10 dígitos.');
    // El código no se devuelve: para cancelar hay que tenerlo
    responder(array_map(function ($c) { unset($c['codigo']); return $c; }, leerCitas('cl.telefono = ?', [$tel], false)));
  }

  case 'cancelar': {
    $soloPost();
    $in = entrada();
    if (contarIntentos('cancelar', 60) >= 15) fallar('Demasiados intentos. Intenta más tarde.', 429, 'limite');
    anotarIntento('cancelar');
    $tel = telValido($in['tel'] ?? ''); $codigo = strtoupper(limpiar($in['codigo'] ?? '', 5));
    if (!$tel || strlen($codigo) !== 5) fallar('Faltan el WhatsApp o el código de la cita.');
    $c = q('SELECT c.id, c.fecha, c.inicio, c.estado FROM citas c JOIN clientas cl ON cl.id = c.clienta_id WHERE c.codigo = ? AND cl.telefono = ?', [$codigo, $tel])->fetch();
    if (!$c) fallar('No encontramos esa cita con ese número.', 404, 'no_existe');
    if (!in_array($c['estado'], ['pendiente', 'confirmada'], true)) fallar('Esa cita ya no se puede cancelar.', 409);
    $aj = ajustes(); $now = ahora();
    $minHasta = (int)((new DateTime($c['fecha']))->diff(new DateTime($now['fecha']))->days) * 1440 + (int)$c['inicio'] - $now['min'];
    if ($c['fecha'] < $now['fecha'] || $minHasta < $aj['horasCancelar'] * 60)
      fallar("Faltan menos de {$aj['horasCancelar']} horas. Para cancelar escríbenos por WhatsApp.", 409, 'tarde');
    q("UPDATE citas SET estado = 'cancelada', cancelada_por = 'cliente' WHERE id = ?", [$c['id']]);
    responder(['ok' => true]);
  }

  /* ---------------- Administración ---------------- */

  case 'login': {
    $soloPost();
    if (contarIntentos('pin', 15) >= 5) fallar('Demasiados intentos con PIN equivocado. Espera 15 minutos.', 429, 'limite');
    $pin = preg_replace('/\D/', '', (string)(entrada()['pin'] ?? ''));
    if ($pin === '') { anotarIntento('pin'); fallar('PIN incorrecto.', 401, 'pin'); }
    $aj = ajustes();
    $rol = null; $pid = null; $nombre = '';
    // Primero la dueña
    if ($aj['_pin_hash'] ? password_verify($pin, $aj['_pin_hash']) : hash_equals((string)$CFG['pin_inicial'], $pin)) {
      if (!$aj['_pin_hash']) guardarAjuste('pin_hash', password_hash($pin, PASSWORD_DEFAULT));
      $rol = 'admin'; $pid = idDuena(); $nombre = profesional($pid)['nombre'] ?? 'Dueña';
    } else {
      // Luego cada trabajadora activa con PIN
      foreach (q("SELECT id, nombre, pin_hash FROM profesionales WHERE rol = 'trabajadora' AND activa = 1 AND pin_hash IS NOT NULL")->fetchAll() as $t)
        if (password_verify($pin, $t['pin_hash'])) { $rol = 'trabajadora'; $pid = (int)$t['id']; $nombre = $t['nombre']; break; }
    }
    if (!$rol) { anotarIntento('pin'); fallar('PIN incorrecto.', 401, 'pin'); }
    $token = bin2hex(random_bytes(32));
    q('INSERT INTO sesiones (token_hash, rol, profesional_id, expira) VALUES (?, ?, ?, NOW() + INTERVAL ? HOUR)', [hash('sha256', $token), $rol, $pid, (int)$CFG['horas_sesion']]);
    q('DELETE FROM sesiones WHERE expira < NOW()');
    responder(['ok' => true, 'token' => $token, 'rol' => $rol, 'profesional' => ['id' => $pid, 'nombre' => $nombre], 'pinInicial' => $rol === 'admin' && !$aj['_pin_hash']]);
  }

  case 'logout': {
    $t = tokenRecibido(); if ($t) q('DELETE FROM sesiones WHERE token_hash = ?', [hash('sha256', $t)]);
    responder(['ok' => true]);
  }

  case 'sesion': {
    $s = exigirSesion();
    responder(['ok' => true, 'rol' => $s['rol'], 'profesional' => ['id' => $s['profesional'], 'nombre' => $s['nombre']]]);
  }

  case 'citas': {
    $ses = exigirSesion();
    $w = ['1=1']; $p = [];
    // Una trabajadora solo ve sus citas; la dueña puede filtrar por persona
    if ($ses['rol'] === 'trabajadora') { $w[] = 'c.profesional_id = ?'; $p[] = $ses['profesional']; }
    elseif ($f = filter_var($_GET['profesional'] ?? null, FILTER_VALIDATE_INT)) { $w[] = 'c.profesional_id = ?'; $p[] = $f; }
    if (fechaValida($_GET['desde'] ?? '')) { $w[] = 'c.fecha >= ?'; $p[] = $_GET['desde']; }
    if (fechaValida($_GET['hasta'] ?? '')) { $w[] = 'c.fecha <= ?'; $p[] = $_GET['hasta']; }
    if (in_array($_GET['estado'] ?? '', ['pendiente','confirmada','atendida','noasistio','cancelada'], true)) { $w[] = 'c.estado = ?'; $p[] = $_GET['estado']; }
    $texto = limpiar($_GET['q'] ?? '', 60);
    if ($texto !== '') {
      $w[] = '(cl.nombre LIKE ? OR cl.telefono LIKE ? OR c.codigo = ? OR EXISTS (SELECT 1 FROM cita_servicios s WHERE s.cita_id = c.id AND s.nombre LIKE ?))';
      array_push($p, "%$texto%", "%$texto%", strtoupper($texto), "%$texto%");
    }
    responder(leerCitas(implode(' AND ', $w), $p, true));
  }

  case 'agendar': {
    $soloPost(); $ses = exigirSesion();
    responder(['ok' => true, 'cita' => crearCita(entrada(), true, $ses)]);
  }

  case 'estado': {
    $soloPost(); $ses = exigirSesion();
    $in = entrada(); $id = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT); $estado = $in['estado'] ?? '';
    if (!$id || !in_array($estado, ['pendiente','confirmada','atendida','noasistio','cancelada'], true)) fallar('Datos incompletos.');
    $c = q('SELECT id, profesional_id, fecha, inicio, duracion, estado FROM citas WHERE id = ?', [$id])->fetch();
    if (!$c) fallar('Esa cita no existe.', 404);
    if ($ses['rol'] === 'trabajadora' && (int)$c['profesional_id'] !== $ses['profesional']) fallar('Esa cita es de otra persona del equipo.', 403, 'sin_permiso');
    // Si se reactiva una cita cancelada, el espacio tiene que seguir libre
    if (in_array($c['estado'], ['cancelada','noasistio'], true) && in_array($estado, ['pendiente','confirmada','atendida'], true)) {
      $choque = q('SELECT 1 FROM citas WHERE fecha = ? AND profesional_id <=> ? AND estado IN ' . ACTIVAS . ' AND inicio < ? AND fin > ? AND id <> ?',
        [$c['fecha'], $c['profesional_id'], (int)$c['inicio'] + (int)$c['duracion'], $c['inicio'], $id])->fetch();
      if ($choque) fallar('Ese espacio ya lo tomó otra clienta. No se puede reactivar esta cita.', 409, 'ocupado');
    }
    q('UPDATE citas SET estado = ?, cancelada_por = ? WHERE id = ?', [$estado, $estado === 'cancelada' ? 'admin' : null, $id]);
    responder(['ok' => true, 'cita' => leerCitas('c.id = ?', [$id], true)[0]]);
  }

  case 'clientas': {
    exigirDuena();
    $rows = q("SELECT cl.nombre, cl.telefono AS tel,
        COUNT(c.id) total,
        SUM(c.estado = 'atendida') atendidas,
        SUM(c.estado = 'noasistio') noasistio,
        SUM(c.estado = 'cancelada') cancel,
        MAX(CASE WHEN c.estado = 'atendida' THEN c.fecha END) ultima,
        COALESCE(SUM(CASE WHEN c.estado = 'atendida' THEN c.total END), 0) gastado
      FROM clientas cl LEFT JOIN citas c ON c.clienta_id = cl.id
      GROUP BY cl.id ORDER BY atendidas DESC, cl.nombre")->fetchAll();
    responder(array_map(fn($x) => ['nombre' => $x['nombre'], 'tel' => $x['tel'], 'total' => (int)$x['total'], 'atendidas' => (int)$x['atendidas'],
      'noasistio' => (int)$x['noasistio'], 'cancel' => (int)$x['cancel'], 'ultima' => $x['ultima'] ?? '', 'gastado' => (int)$x['gastado']], $rows));
  }

  case 'config_admin': {
    $ses = exigirSesion();
    $aj = ajustes(); unset($aj['_pin_hash']);
    responder($aj + ['servicios' => servicios(false), 'profesionales' => profesionales(false, $ses['rol'] === 'admin'),
      'bloqueos' => bloqueos(), 'ausencias' => ausencias(), 'ahora' => ahora()]);
  }

  case 'guardar_config': {
    $soloPost(); exigirDuena();
    $in = entrada();
    $db->beginTransaction();
    if (isset($in['negocio'])) guardarAjuste('negocio', limpiar($in['negocio'], 50) ?: 'Tu estudio de uñas');
    if (isset($in['slogan'])) guardarAjuste('slogan', limpiar($in['slogan'], 90));
    if (isset($in['whatsapp'])) { $w = preg_replace('/\D/', '', (string)$in['whatsapp']); if ($w !== '' && !telValido($w)) { $db->rollBack(); fallar('El WhatsApp debe tener 10 dígitos y empezar por 3.'); } guardarAjuste('whatsapp', $w); }
    if (isset($in['paso'])) guardarAjuste('paso', (string)(in_array((int)$in['paso'], [15, 30, 60], true) ? (int)$in['paso'] : 30));
    if (isset($in['anticipacion'])) guardarAjuste('anticipacion', (string)max(0, min(2880, (int)$in['anticipacion'])));
    if (isset($in['diasVista'])) guardarAjuste('diasVista', (string)max(3, min(60, (int)$in['diasVista'])));
    if (isset($in['horasCancelar'])) guardarAjuste('horasCancelar', (string)max(0, min(72, (int)$in['horasCancelar'])));
    if (isset($in['horario']) && is_array($in['horario'])) {
      $h = [];
      for ($d = 0; $d < 7; $d++) { $x = $in['horario'][$d] ?? $in['horario'][(string)$d] ?? [];
        $ini = max(0, min(1439, (int)($x['ini'] ?? 480))); $fin = max($ini, min(1440, (int)($x['fin'] ?? 1080)));
        $h[(string)$d] = ['on' => !empty($x['on']), 'ini' => $ini, 'fin' => $fin]; }
      guardarAjuste('horario', json_encode($h));
    }
    if (isset($in['almuerzo']) && is_array($in['almuerzo'])) {
      $a = $in['almuerzo']; $ini = max(0, min(1439, (int)($a['ini'] ?? 720)));
      guardarAjuste('almuerzo', json_encode(['on' => !empty($a['on']), 'ini' => $ini, 'fin' => max($ini, min(1440, (int)($a['fin'] ?? 780)))]));
    }
    if (isset($in['servicios']) && is_array($in['servicios'])) {
      $vistos = [];
      foreach (array_values($in['servicios']) as $i => $s) {
        $id = preg_replace('/[^a-z0-9\-]/i', '', (string)($s['id'] ?? '')) ?: 's-' . bin2hex(random_bytes(4));
        $id = substr($id, 0, 40); $vistos[] = $id;
        $precio = ($s['precio'] ?? null) === null || $s['precio'] === '' ? null : max(0, (int)$s['precio']);
        q('INSERT INTO servicios (id, nombre, categoria, duracion, precio, activo, orden) VALUES (?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), categoria = VALUES(categoria), duracion = VALUES(duracion), precio = VALUES(precio), activo = VALUES(activo), orden = VALUES(orden)',
          [$id, limpiar($s['nombre'] ?? 'Servicio', 80) ?: 'Servicio', limpiar($s['cat'] ?? 'Manos', 30) ?: 'Manos', max(10, min(480, (int)($s['dur'] ?? 30))), $precio, empty($s['activo']) ? 0 : 1, $i + 1]);
      }
      // Los servicios que ya no vienen se desactivan (no se borran: el histórico los usa)
      if ($vistos) { $m = implode(',', array_fill(0, count($vistos), '?')); q("UPDATE servicios SET activo = 0 WHERE id NOT IN ($m)", $vistos); }
    }
    if (isset($in['bloqueos']) && is_array($in['bloqueos'])) {
      $fs = array_values(array_unique(array_filter($in['bloqueos'], 'fechaValida')));
      q('DELETE FROM bloqueos');
      foreach ($fs as $f) q('INSERT INTO bloqueos (fecha) VALUES (?)', [$f]);
    }
    if (!empty($in['pinNuevo'])) {
      $pin = preg_replace('/\D/', '', (string)$in['pinNuevo']);
      if (strlen($pin) < 4 || strlen($pin) > 8) { $db->rollBack(); fallar('El PIN debe tener entre 4 y 8 números.'); }
      if (pinEnUso($pin, null, true)) { $db->rollBack(); fallar('Ese PIN ya lo tiene alguien del equipo. Escoge otro.', 409, 'pin_repetido'); }
      guardarAjuste('pin_hash', password_hash($pin, PASSWORD_DEFAULT));
    }
    // Equipo: nombre, días, activa y PIN de cada trabajadora. Nadie se borra (el histórico las usa); se desactivan.
    if (isset($in['profesionales']) && is_array($in['profesionales'])) {
      $orden = 0;
      foreach (array_values($in['profesionales']) as $p) {
        $orden++;
        $nombre = limpiar($p['nombre'] ?? '', 40);
        if ($nombre === '') { $db->rollBack(); fallar('Cada persona del equipo necesita un nombre.'); }
        $dias = implode(',', array_values(array_unique(array_filter(array_map('intval', (array)($p['dias'] ?? [])), fn($x) => $x >= 0 && $x <= 6))));
        $id = filter_var($p['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $actual = $id ? profesional($id) : null;
        if ($id && !$actual) { $db->rollBack(); fallar('Una persona del equipo ya no existe. Recarga la página.'); }
        $esDuena = $actual && $actual['rol'] === 'duena';
        $activa = $esDuena ? 1 : (empty($p['activa']) ? 0 : 1);   // la dueña no se puede desactivar
        if ($actual) q('UPDATE profesionales SET nombre = ?, dias = ?, activa = ?, orden = ? WHERE id = ?', [$nombre, $dias, $activa, $orden, $id]);
        else { q("INSERT INTO profesionales (nombre, rol, dias, activa, orden) VALUES (?, 'trabajadora', ?, ?, ?)", [$nombre, $dias, $activa, $orden]); $id = (int)$db->lastInsertId(); }
        if (!$esDuena && !empty($p['pinNuevo'])) {
          $pin = preg_replace('/\D/', '', (string)$p['pinNuevo']);
          if (strlen($pin) < 4 || strlen($pin) > 8) { $db->rollBack(); fallar("El PIN de $nombre debe tener entre 4 y 8 números."); }
          if (pinEnUso($pin, $id, false)) { $db->rollBack(); fallar("Ese PIN ya lo tiene otra persona. Escoge otro para $nombre.", 409, 'pin_repetido'); }
          q('UPDATE profesionales SET pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $id]);
          q("DELETE FROM sesiones WHERE rol = 'trabajadora' AND profesional_id = ?", [$id]);   // con PIN nuevo, vuelve a entrar
        }
        if (!$activa) q("DELETE FROM sesiones WHERE rol = 'trabajadora' AND profesional_id = ?", [$id]);
      }
    }
    $db->commit();
    $aj = ajustes(); unset($aj['_pin_hash']);
    responder(['ok' => true, 'config' => $aj + ['servicios' => servicios(false), 'profesionales' => profesionales(false, true),
      'bloqueos' => bloqueos(), 'ausencias' => ausencias(), 'ahora' => ahora()]]);
  }

  case 'bloqueo': {
    $soloPost(); exigirDuena();
    $in = entrada(); $f = $in['fecha'] ?? '';
    if (!fechaValida($f)) fallar('Fecha no válida.');
    if (!empty($in['bloquear'])) q('INSERT IGNORE INTO bloqueos (fecha) VALUES (?)', [$f]); else q('DELETE FROM bloqueos WHERE fecha = ?', [$f]);
    responder(['ok' => true, 'bloqueos' => bloqueos()]);
  }

  case 'ausencia': {
    $soloPost(); exigirDuena();
    $in = entrada(); $f = $in['fecha'] ?? ''; $pid = filter_var($in['profesional'] ?? null, FILTER_VALIDATE_INT);
    if (!fechaValida($f) || !$pid || !profesional($pid)) fallar('Datos incompletos.');
    if (!empty($in['ausente'])) q('INSERT IGNORE INTO ausencias (profesional_id, fecha) VALUES (?, ?)', [$pid, $f]);
    else q('DELETE FROM ausencias WHERE profesional_id = ? AND fecha = ?', [$pid, $f]);
    responder(['ok' => true, 'ausencias' => ausencias()]);
  }

  case 'respaldo': {
    exigirDuena();
    $aj = ajustes(); unset($aj['_pin_hash']);
    responder(['version' => 2, 'exportado' => ahora()['iso'], 'config' => $aj + ['servicios' => servicios(false), 'profesionales' => profesionales(false),
      'bloqueos' => bloqueos(), 'ausencias' => ausencias()], 'citas' => leerCitas('1=1', [], true)]);
  }

  default:
    fallar('Ruta no encontrada.', 404, 'ruta');
}
} catch (Throwable $e) {
  error_log('[citas-unas] ' . $e->getMessage());
  fallar('Error del servidor. Intenta de nuevo.', 500, 'servidor');
}
