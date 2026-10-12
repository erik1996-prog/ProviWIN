<?php
// 02_BACKEND/src/routes/api/observaciones.php - API REST para Registro de Observaciones, Fotos y SLA de Partner (3 Días)
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/uploads.php';
require_once __DIR__ . '/../../config/notifications.php';

Auth::requireLogin();
$user = Auth::getLoggedInUser();
$pdo = Database::getConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
Auth::requireCsrf();
if (!Auth::can(Auth::PERM_OBSERVATION_VIEW, $user)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'No tiene acceso al módulo de observaciones.']);
    exit;
}

// 1. Obtener Categorías y Subopciones Maestras
if ($action === 'catalogo') {
    $stmtCats = $pdo->query("SELECT * FROM categorias_observacion ORDER BY id ASC");
    $categorias = $stmtCats->fetchAll();

    $stmtSubs = $pdo->query("SELECT * FROM subopciones_observacion ORDER BY categoria_id ASC, id ASC");
    $subopciones = $stmtSubs->fetchAll();

    echo json_encode([
        'status' => 'success',
        'categorias' => $categorias,
        'subopciones' => $subopciones
    ]);
    exit;
}

// 2. Ejecutar actualización automática de SLA (Transición a Penalidad Directa si supera 3 días)
$pdo->exec("
    UPDATE observaciones_supervision 
    SET estado_respuesta = 'Penalidad Directa' 
    WHERE estado_respuesta = 'Pendiente' AND fecha_limite_respuesta < NOW()
");

// 3. Registrar Observación con Carga de Hasta 3 Fotos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    if (!Auth::hasRole([Auth::ROLE_SUPERVISOR, Auth::ROLE_PARTNER, Auth::ROLE_ADMINISTRADOR, Auth::ROLE_GERENTE, Auth::ROLE_COORDINADOR])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tiene permisos para registrar observaciones.']);
        exit;
    }
    $supervisionId = intval($_POST['supervision_id'] ?? 0);
    $gravedad = $_POST['gravedad'] ?? 'Leve';
    $categoriaId = intval($_POST['categoria_id'] ?? 0);
    $subopcionId = intval($_POST['subopcion_id'] ?? 0);
    $detalle = trim($_POST['detalle_observacion'] ?? '');
    $subsanacionInmediata = false;
    $plazoDias = 3;

    if ($supervisionId <= 0 || $categoriaId <= 0 || $subopcionId <= 0 || empty($detalle)) {
        echo json_encode(['status' => 'error', 'message' => 'Complete la categoría, subopción, gravedad y detalle de la observación.']);
        exit;
    }

    $stmtSeverity = $pdo->prepare('SELECT gravedad FROM subopciones_observacion WHERE id=:id AND categoria_id=:categoria LIMIT 1');
    $stmtSeverity->execute([':id'=>$subopcionId, ':categoria'=>$categoriaId]);
    $catalogSeverity = $stmtSeverity->fetchColumn();
    if (!$catalogSeverity) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'La tipificación seleccionada no es válida para esa categoría.']);
        exit;
    }
    $gravedad = (string)$catalogSeverity;
    $penalidadDirecta = $gravedad === 'Muy Grave';

    $stmtSupervision = $pdo->prepare("SELECT supervisor_id,partner_id FROM supervisiones WHERE id = :id LIMIT 1");
    $stmtSupervision->execute([':id' => $supervisionId]);
    $supervision = $stmtSupervision->fetch();
    $ownerId = $supervision['supervisor_id'] ?? 0;
    if (!$ownerId || (!Auth::isAdministrative() && (int)$ownerId !== (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tiene acceso a la supervisión indicada.']);
        exit;
    }

    // Procesar Carga de Fotos (hasta 3)
    $fotoRutas = [null, null, null];
    $fotoComentarios = [trim((string)($_POST['foto_comentario_1'] ?? '')),trim((string)($_POST['foto_comentario_2'] ?? '')),trim((string)($_POST['foto_comentario_3'] ?? ''))];
    for ($i = 1; $i <= 3; $i++) {
        $fileKey = "foto_" . $i;
        if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
            try {
                $validated = validateUploadedFile($_FILES[$fileKey], [
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
                ], 8 * 1024 * 1024);
                $nombreArchivo = randomUploadName('obs_' . $supervisionId . '_' . $i . '_', $validated['extension']);
                $destino = UPLOAD_DIR . $nombreArchivo;
                if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $destino)) {
                    $fotoRutas[$i - 1] = UPLOAD_URL . $nombreArchivo;
                }
            } catch (RuntimeException $e) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }
    foreach ($fotoRutas as $index=>$ruta) {
        if ($ruta && $fotoComentarios[$index]==='') { echo json_encode(['status'=>'error','message'=>'Ingrese un comentario para cada fotografía.']); exit; }
    }

    $fechaLimite = $penalidadDirecta
        ? date('Y-m-d H:i:s')
        : ($subsanacionInmediata
        ? date('Y-m-d 23:59:59')
        : observationBusinessDeadline($plazoDias));
    $estadoInicial = $penalidadDirecta ? 'Penalidad Directa' : 'Pendiente';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO observaciones_supervision (
                supervision_id, creado_por, gravedad, categoria_id, subopcion_id,
                detalle_observacion, foto_1, foto_comentario_1, foto_2, foto_comentario_2, foto_3, foto_comentario_3,
                fecha_registro, fecha_limite_respuesta, plazo_subsanacion_dias, subsanacion_inmediata, estado_respuesta
            ) VALUES (
                :sup_id, :creado_por, :grav, :cat_id, :sub_id,
                :det, :f1, :fc1, :f2, :fc2, :f3, :fc3,
                NOW(), :f_lim, :plazo, :inmediata, :estado
            )
        ");

        $stmt->execute([
            ':sup_id' => $supervisionId,
            ':creado_por' => (int)$user['id'],
            ':grav' => $gravedad,
            ':cat_id' => $categoriaId,
            ':sub_id' => $subopcionId,
            ':det' => $detalle,
            ':f1' => $fotoRutas[0],
            ':fc1' => $fotoComentarios[0] ?: null,
            ':f2' => $fotoRutas[1],
            ':fc2' => $fotoComentarios[1] ?: null,
            ':f3' => $fotoRutas[2],
            ':fc3' => $fotoComentarios[2] ?: null,
            ':f_lim' => $fechaLimite,
            ':plazo' => $plazoDias,
            ':inmediata' => $subsanacionInmediata ? 1 : 0
            ,':estado' => $estadoInicial
        ]);
        $observacionId = (int)$pdo->lastInsertId();
        notifyPartnerObservation($pdo, $observacionId, $supervisionId, (int)($supervision['partner_id'] ?? 0), $plazoDias, $subsanacionInmediata, $penalidadDirecta);

        echo json_encode([
            'status' => 'success',
            'message' => $penalidadDirecta ? 'Observación Muy Grave registrada con Penalidad Directa.' : ($subsanacionInmediata ? 'Observación registrada con subsanación inmediata.' : "Observación registrada con plazo de {$plazoDias} día(s) hábiles."),
            'fecha_limite' => $fechaLimite
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error al registrar observación: ' . $e->getMessage()]);
        exit;
    }
}

// 4. Respuesta del Partner a las observaciones de un checklist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'responder_checklist_partner') {
    $esRepresentantePartner = empty($user['is_win_directo']) && !empty($user['partner_id']);
    if (!$esRepresentantePartner && $user['rol'] !== Auth::ROLE_ADMINISTRADOR) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Solo el partner asignado puede responder este checklist.']); exit;
    }
    $supervisionId=(int)($_POST['supervision_id']??0); $respuestaGeneral=trim((string)($_POST['respuesta_general']??''));
    $stmt=$pdo->prepare("SELECT ct.*,s.partner_id,s.supervisor_id,s.departamento AS departamento_orden,COALESCE(ct.fecha_limite_respuesta,CASE WEEKDAY(ct.fecha_registro) WHEN 0 THEN DATE_ADD(ct.fecha_registro,INTERVAL 3 DAY) WHEN 1 THEN DATE_ADD(ct.fecha_registro,INTERVAL 3 DAY) WHEN 2 THEN DATE_ADD(ct.fecha_registro,INTERVAL 3 DAY) WHEN 3 THEN DATE_ADD(ct.fecha_registro,INTERVAL 4 DAY) WHEN 4 THEN DATE_ADD(ct.fecha_registro,INTERVAL 4 DAY) WHEN 5 THEN DATE_ADD(ct.fecha_registro,INTERVAL 4 DAY) ELSE DATE_ADD(ct.fecha_registro,INTERVAL 3 DAY) END) fecha_limite FROM checklists_tecnicos ct JOIN supervisiones s ON s.id=ct.supervision_id WHERE ct.supervision_id=:id ORDER BY ct.id DESC LIMIT 1");
    $stmt->execute([':id'=>$supervisionId]);$checklist=$stmt->fetch();$checklistId=(int)($checklist['id']??0);
    if(!$checklist){http_response_code(404);echo json_encode(['status'=>'error','message'=>'Checklist no encontrado.']);exit;}
    if($esRepresentantePartner&&(int)$checklist['partner_id']!==(int)$user['partner_id']){http_response_code(403);echo json_encode(['status'=>'error','message'=>'Este checklist no pertenece a su partner.']);exit;}
    if($esRepresentantePartner&&!sgsUserInZone((int)$user['id'],$checklist['departamento_orden']??'')){http_response_code(403);echo json_encode(['status'=>'error','message'=>'Este checklist no pertenece a su zona de trabajo.']);exit;}
    if(!in_array($checklist['estado_respuesta'],['Pendiente','Reabierto'],true)){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Este checklist no está habilitado para recibir descargos.']);exit;}
    if(strtotime($checklist['fecha_limite'])<time()){http_response_code(422);echo json_encode(['status'=>'error','message'=>'El plazo de levantamiento ya venció.']);exit;}
    if($respuestaGeneral===''){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Ingrese el comentario general del levantamiento.']);exit;}
    $itemsStmt=$pdo->prepare("SELECT id,descargo_foto FROM checklist_respuestas WHERE checklist_id=:id AND ((observacion IS NOT NULL AND TRIM(observacion)<>'') OR foto_evidencia IS NOT NULL)");$itemsStmt->execute([':id'=>$checklistId]);$itemRows=$itemsStmt->fetchAll();$itemIds=array_map('intval',array_column($itemRows,'id'));$existingPhotos=[];foreach($itemRows as $itemRow)$existingPhotos[(int)$itemRow['id']]=$itemRow['descargo_foto'];
    if(!$itemIds){http_response_code(422);echo json_encode(['status'=>'error','message'=>'El checklist no tiene observaciones pendientes.']);exit;}
    $updates=[];
    foreach($itemIds as $itemId){$comment=trim((string)($_POST['comentario_item'][$itemId]??''));$fileKey='foto_item_'.$itemId;$photo=$existingPhotos[$itemId]??null;if($comment===''){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Ingrese el comentario de cada descargo.']);exit;}if(isset($_FILES[$fileKey])&&$_FILES[$fileKey]['error']===UPLOAD_ERR_OK){try{$validated=validateUploadedFile($_FILES[$fileKey],['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'],8*1024*1024);$name=randomUploadName('checklist_descargo_'.$itemId.'_',$validated['extension']);if(!move_uploaded_file($_FILES[$fileKey]['tmp_name'],UPLOAD_DIR.$name))throw new RuntimeException('No se pudo guardar una evidencia.');$photo=UPLOAD_URL.$name;}catch(RuntimeException $e){http_response_code(422);echo json_encode(['status'=>'error','message'=>$e->getMessage()]);exit;}}if(!$photo){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Adjunte una foto para cada descargo.']);exit;}$updates[]=[$itemId,$photo,$comment];}
    $pdo->beginTransaction();try{$update=$pdo->prepare("UPDATE checklist_respuestas SET descargo_foto=:foto,descargo_comentario=:comentario WHERE id=:id AND checklist_id=:checklist");foreach($updates as [$itemId,$photo,$comment])$update->execute([':foto'=>$photo,':comentario'=>$comment,':id'=>$itemId,':checklist'=>$checklistId]);$pdo->prepare("UPDATE checklists_tecnicos SET respuesta_general_partner=:respuesta,fecha_respuesta_partner=NOW(),respondido_por_partner=:respondido_por,estado_respuesta='En revisión' WHERE id=:id")->execute([':respuesta'=>$respuestaGeneral,':respondido_por'=>(int)$user['id'],':id'=>$checklistId]);$notify=$pdo->prepare("INSERT INTO notificaciones(usuario_id,titulo,mensaje,url_destino) VALUES(:uid,'Checklist respondido por el partner',:mensaje,'index.php?page=registro_observaciones')");$notify->execute([':uid'=>$checklist['supervisor_id'],':mensaje'=>'Se recibió el levantamiento del checklist de '.$checklist['tecnico_nombre'].'.']);$pdo->commit();echo json_encode(['status'=>'success','message'=>'El levantamiento fue enviado a revisión.']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}exit;
}

// 5. Evaluación del supervisor directo al levantamiento del checklist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'evaluar_checklist') {
    $input=json_decode(file_get_contents('php://input'),true)??[];$supervisionId=(int)($input['supervision_id']??0);$approve=!empty($input['aprobar']);$comment=$approve?'Válido':trim((string)($input['comentario']??''));
    if(!$approve&&$comment===''){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Indique por qué no se valida la observación.']);exit;}
    $stmt=$pdo->prepare("SELECT ct.id,ct.supervisor_id,ct.estado_respuesta,ct.tecnico_nombre,s.partner_id FROM checklists_tecnicos ct JOIN supervisiones s ON s.id=ct.supervision_id WHERE ct.supervision_id=:sid ORDER BY ct.id DESC LIMIT 1");$stmt->execute([':sid'=>$supervisionId]);$checklist=$stmt->fetch();
    if(!$checklist){http_response_code(404);echo json_encode(['status'=>'error','message'=>'Checklist no encontrado.']);exit;}
    $isAdmin=$user['rol']===Auth::ROLE_ADMINISTRADOR;$isDirect=($user['rol']===Auth::ROLE_SUPERVISOR&&!empty($user['is_win_directo'])&&(int)$checklist['supervisor_id']===(int)$user['id']);
    if(!$isAdmin&&!$isDirect){http_response_code(403);echo json_encode(['status'=>'error','message'=>'Solo el supervisor directo responsable puede evaluar este levantamiento.']);exit;}
    if($checklist['estado_respuesta']!=='En revisión'){http_response_code(422);echo json_encode(['status'=>'error','message'=>'El checklist no tiene un levantamiento pendiente de evaluación.']);exit;}
    $newState=$approve?'Validado':'Penalizado';$pdo->prepare("UPDATE checklists_tecnicos SET estado_respuesta=:estado,sustentacion_evaluacion=:comentario,fecha_evaluacion=NOW(),evaluado_por=:usuario WHERE id=:id")->execute([':estado'=>$newState,':comentario'=>$comment,':usuario'=>$user['id'],':id'=>$checklist['id']]);
    $partnerUsers=$pdo->prepare("SELECT id FROM usuarios WHERE partner_id=:partner AND rol='Partner' AND activo=1");$partnerUsers->execute([':partner'=>$checklist['partner_id']]);$notify=$pdo->prepare("INSERT INTO notificaciones(usuario_id,titulo,mensaje,url_destino) VALUES(:uid,:titulo,:mensaje,'index.php?page=registro_observaciones')");foreach($partnerUsers->fetchAll(PDO::FETCH_COLUMN) as $partnerUserId)$notify->execute([':uid'=>$partnerUserId,':titulo'=>$approve?'Descargo validado':'Observación penalizada',':mensaje'=>'Resultado para '.$checklist['tecnico_nombre'].': '.$newState.'.']);echo json_encode(['status'=>'success','message'=>$approve?'El descargo fue validado.':'El descargo no fue aceptado y la observación pasó a Penalizado.']);exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reabrir_checklist') {
    $input=json_decode(file_get_contents('php://input'),true)??[];$supervisionId=(int)($input['supervision_id']??0);$stmt=$pdo->prepare("SELECT ct.id,ct.supervisor_id,ct.estado_respuesta,ct.tecnico_nombre,s.partner_id FROM checklists_tecnicos ct JOIN supervisiones s ON s.id=ct.supervision_id WHERE ct.supervision_id=:sid ORDER BY ct.id DESC LIMIT 1");$stmt->execute([':sid'=>$supervisionId]);$checklist=$stmt->fetch();
    if(!$checklist){http_response_code(404);echo json_encode(['status'=>'error','message'=>'Checklist no encontrado.']);exit;}$isAdmin=$user['rol']===Auth::ROLE_ADMINISTRADOR;$isDirect=$user['rol']===Auth::ROLE_SUPERVISOR&&!empty($user['is_win_directo'])&&(int)$checklist['supervisor_id']===(int)$user['id'];if(!$isAdmin&&!$isDirect){http_response_code(403);echo json_encode(['status'=>'error','message'=>'No tiene permiso para reabrir esta observación.']);exit;}if($checklist['estado_respuesta']!=='Penalizado'){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Solo se pueden reabrir observaciones penalizadas.']);exit;}
    $pdo->prepare("UPDATE checklists_tecnicos SET estado_respuesta='Reabierto',fecha_limite_respuesta=CASE WEEKDAY(NOW()) WHEN 0 THEN DATE_ADD(NOW(),INTERVAL 3 DAY) WHEN 1 THEN DATE_ADD(NOW(),INTERVAL 3 DAY) WHEN 2 THEN DATE_ADD(NOW(),INTERVAL 3 DAY) WHEN 3 THEN DATE_ADD(NOW(),INTERVAL 4 DAY) WHEN 4 THEN DATE_ADD(NOW(),INTERVAL 4 DAY) WHEN 5 THEN DATE_ADD(NOW(),INTERVAL 4 DAY) ELSE DATE_ADD(NOW(),INTERVAL 3 DAY) END,sustentacion_evaluacion=NULL,fecha_evaluacion=NULL,evaluado_por=NULL WHERE id=:id")->execute([':id'=>$checklist['id']]);$partnerUsers=$pdo->prepare("SELECT id FROM usuarios WHERE partner_id=:partner AND rol='Partner' AND activo=1");$partnerUsers->execute([':partner'=>$checklist['partner_id']]);$notify=$pdo->prepare("INSERT INTO notificaciones(usuario_id,titulo,mensaje,url_destino) VALUES(:uid,'Observación reabierta',:mensaje,'index.php?page=registro_observaciones')");foreach($partnerUsers->fetchAll(PDO::FETCH_COLUMN) as $partnerUserId)$notify->execute([':uid'=>$partnerUserId,':mensaje'=>'Puede actualizar los descargos de '.$checklist['tecnico_nombre'].' dentro del nuevo plazo.']);echo json_encode(['status'=>'success','message'=>'La observación fue reabierta por 3 días hábiles.']);exit;
}

// 6. Respuesta del Partner a una Observación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'responder_partner') {
    $esRepresentantePartner = empty($user['is_win_directo']) && !empty($user['partner_id']);
    if (!$esRepresentantePartner && $user['rol'] !== Auth::ROLE_ADMINISTRADOR) {
        echo json_encode(['status' => 'error', 'message' => 'Solo los representantes del Partner pueden responder observaciones.']);
        exit;
    }

    $input = $_POST;
    if (!$input) $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $obsId = intval($input['observacion_id'] ?? 0);
    $respuesta = trim($input['respuesta_partner'] ?? '');

    if ($obsId <= 0 || empty($respuesta)) {
        echo json_encode(['status' => 'error', 'message' => 'Ingrese el texto de sustento/descargo.']);
        exit;
    }

    // Verificar estado actual
    $stmt = $pdo->prepare("SELECT o.*, s.partner_id, s.supervisor_id, s.departamento AS departamento_orden FROM observaciones_supervision o JOIN supervisiones s ON s.id = o.supervision_id WHERE o.id = :id");
    $stmt->execute([':id' => $obsId]);
    $obs = $stmt->fetch();

    if (!$obs) {
        echo json_encode(['status' => 'error', 'message' => 'Observación no encontrada.']);
        exit;
    }

    if ($esRepresentantePartner && (int)$obs['partner_id'] !== (int)$user['partner_id']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Esta observación no pertenece a su empresa.']);
        exit;
    }
    if ($esRepresentantePartner && !sgsUserInZone((int)$user['id'], $obs['departamento_orden'] ?? '')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Esta observación no pertenece a su zona de trabajo.']);
        exit;
    }

    if ($obs['estado_respuesta'] === 'Penalidad Directa') {
        echo json_encode(['status' => 'error', 'message' => 'El plazo de subsanación venció. Esta observación ya se encuentra en Penalidad Directa.']);
        exit;
    }
    if (strtotime((string)$obs['fecha_limite_respuesta']) < time()) {
        $pdo->prepare("UPDATE observaciones_supervision SET estado_respuesta='Penalidad Directa' WHERE id=:id")->execute([':id'=>$obsId]);
        echo json_encode(['status'=>'error','message'=>'El plazo de regularización venció. La observación pasó a Penalidad Directa.']);
        exit;
    }

    if (!in_array($obs['estado_respuesta'], ['Pendiente', 'Levantamiento rechazado', 'Apelación Rechazada'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'La observación ya tiene un descargo en revisión o fue cerrada.']);
        exit;
    }

    $fotosDescargo = [];
    $comentariosDescargo = [];
    for ($i = 1; $i <= 3; $i++) {
        $key = 'descargo_foto_' . $i;
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Adjunte las 3 fotos obligatorias del levantamiento.']);
            exit;
        }
        $comentarioFoto=trim((string)($input['descargo_comentario_'.$i] ?? ''));
        if($comentarioFoto===''){echo json_encode(['status'=>'error','message'=>'Ingrese el comentario de levantamiento para cada foto.']);exit;}
        $comentariosDescargo[]=$comentarioFoto;
        try {
            $validated = validateUploadedFile($_FILES[$key], [
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
            ], 8 * 1024 * 1024);
            $nombreArchivo = randomUploadName('descargo_' . $obsId . '_' . $i . '_', $validated['extension']);
            if (!move_uploaded_file($_FILES[$key]['tmp_name'], UPLOAD_DIR . $nombreArchivo)) {
                throw new RuntimeException('No se pudo guardar una fotografía del descargo.');
            }
            $fotosDescargo[] = UPLOAD_URL . $nombreArchivo;
        } catch (RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    $stmtUpd = $pdo->prepare("
        UPDATE observaciones_supervision SET 
            respuesta_partner = :resp,
            descargo_foto_1 = :foto1,
            descargo_comentario_1 = :comentario1,
            descargo_foto_2 = :foto2,
            descargo_comentario_2 = :comentario2,
            descargo_foto_3 = :foto3,
            descargo_comentario_3 = :comentario3,
            fecha_respuesta_partner = NOW(),
            respondido_por_partner = :respondido_por,
            evaluacion_supervisor = NULL,
            sustentacion_evaluacion = NULL,
            fecha_evaluacion = NULL,
            evaluado_por = NULL,
            estado_respuesta = 'En revisión'
        WHERE id = :id
    ");
    $stmtUpd->execute([':resp' => $respuesta, ':foto1'=>$fotosDescargo[0], ':comentario1'=>$comentariosDescargo[0], ':foto2'=>$fotosDescargo[1], ':comentario2'=>$comentariosDescargo[1], ':foto3'=>$fotosDescargo[2], ':comentario3'=>$comentariosDescargo[2], ':respondido_por'=>(int)$user['id'], ':id' => $obsId]);
    $stmtNotify = $pdo->prepare("INSERT INTO notificaciones (usuario_id,observacion_id,titulo,mensaje,url_destino) VALUES (:uid,:oid,'Descargo pendiente de revisión','El partner envió un descargo con tres evidencias fotográficas.','index.php?page=registro_observaciones')");
    $stmtNotify->execute([':uid'=>(int)$obs['supervisor_id'],':oid'=>$obsId]);

    echo json_encode(['status' => 'success', 'message' => 'Descargo enviado con 3 fotos. Quedó pendiente de revisión por WIN.']);
    exit;
}

// 5. Evaluación del Supervisor sobre la Apelación del Partner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'actualizar_plazo') {
    if (!Auth::hasRole([Auth::ROLE_SUPERVISOR, Auth::ROLE_ADMINISTRADOR, Auth::ROLE_COORDINADOR])) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'No tiene permisos para actualizar el plazo.']); exit;
    }
    $input=json_decode(file_get_contents('php://input'),true)??$_POST;
    $obsId=(int)($input['observacion_id']??0); $fecha=trim((string)($input['fecha_limite_respuesta']??''));
    $date=DateTime::createFromFormat('Y-m-d\TH:i',$fecha);
    if(!$date){http_response_code(422);echo json_encode(['status'=>'error','message'=>'Seleccione una fecha y hora válidas.']);exit;}
    $stmt=$pdo->prepare('SELECT s.supervisor_id,s.asignado_por,o.estado_respuesta FROM observaciones_supervision o JOIN supervisiones s ON s.id=o.supervision_id WHERE o.id=:id LIMIT 1');
    $stmt->execute([':id'=>$obsId]);$owner=$stmt->fetch();
    if(!$owner||(!Auth::isAdministrative()&&(int)$owner['supervisor_id']!==(int)$user['id']&&(int)$owner['asignado_por']!==(int)$user['id'])){http_response_code(403);echo json_encode(['status'=>'error','message'=>'No puede modificar el plazo de una observación ajena.']);exit;}
    // El administrador puede ampliar el plazo aunque ya haya pasado a "Penalidad Directa" (2026-10-11):
    // si la nueva fecha es futura, la observación vuelve a "Pendiente" para que el partner pueda responder.
    $esAdmin = Auth::hasRole([Auth::ROLE_ADMINISTRADOR]);
    $cerrada = in_array($owner['estado_respuesta'],['Levantamiento validado','Penalidad Directa'],true);
    if($owner['estado_respuesta']==='Levantamiento validado' || ($cerrada && !$esAdmin)){http_response_code(422);echo json_encode(['status'=>'error','message'=>'La observación ya está cerrada.']);exit;}
    $reabre = $esAdmin && $owner['estado_respuesta']==='Penalidad Directa' && $date > new DateTime();
    $pdo->prepare('UPDATE observaciones_supervision SET fecha_limite_respuesta=:fecha' . ($reabre ? ", estado_respuesta='Pendiente'" : '') . ' WHERE id=:id')
        ->execute([':fecha'=>$date->format('Y-m-d H:i:s'),':id'=>$obsId]);
    win_log_error('audit_observation_deadline', 'Plazo de observación actualizado', ['observacion_id'=>$obsId, 'nuevo_plazo'=>$date->format('Y-m-d H:i'), 'estado_anterior'=>$owner['estado_respuesta'], 'reabierta'=>$reabre]);
    echo json_encode(['status'=>'success','message'=>$reabre ? 'Plazo ampliado. La observación volvió a Pendiente para que el partner responda.' : 'Fecha de levantamiento actualizada.']);exit;
}

// Eliminar una observación (solo administrador, 2026-10-11). Antes de borrarla se guarda una copia completa
// en el registro de auditoría (08_LOGS) para poder recuperarla si fuera necesario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'eliminar') {
    if (!Auth::hasRole([Auth::ROLE_ADMINISTRADOR])) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'Solo el administrador puede eliminar observaciones.']); exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $obsId = (int)($input['observacion_id'] ?? 0);
    $motivo = trim((string)($input['motivo'] ?? ''));
    if ($obsId <= 0 || mb_strlen($motivo) < 5) {
        http_response_code(422); echo json_encode(['status'=>'error','message'=>'Indique el motivo de la eliminación (al menos 5 caracteres).']); exit;
    }
    $stmt = $pdo->prepare('SELECT * FROM observaciones_supervision WHERE id = :id');
    $stmt->execute([':id' => $obsId]);
    $copia = $stmt->fetch();
    if (!$copia) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'La observación ya no existe.']); exit; }
    $pdo->prepare('DELETE FROM observaciones_supervision WHERE id = :id')->execute([':id' => $obsId]); // sus notificaciones se borran solas (ON DELETE CASCADE)
    win_log_error('audit_observation_deleted', 'Observación eliminada por el administrador', ['observacion_id' => $obsId, 'motivo' => $motivo, 'copia' => $copia]);
    echo json_encode(['status'=>'success','message'=>'Observación eliminada. Quedó una copia en el registro de auditoría.']);
    exit;
}

// 5. Evaluación del Supervisor sobre la Apelación del Partner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'evaluar_supervisor') {
    if (!Auth::hasRole([Auth::ROLE_SUPERVISOR, Auth::ROLE_ADMINISTRADOR, Auth::ROLE_COORDINADOR])) {
        echo json_encode(['status' => 'error', 'message' => 'No tiene permisos para evaluar apelaciones.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $obsId = intval($input['observacion_id'] ?? 0);
    $aprobado = !empty($input['aprobar_apelacion']); // true o false
    $sustento = trim($input['sustentacion_evaluacion'] ?? '');

    $stmtOwner = $pdo->prepare("SELECT s.supervisor_id,s.asignado_por,o.estado_respuesta FROM observaciones_supervision o JOIN supervisiones s ON s.id = o.supervision_id WHERE o.id = :id LIMIT 1");
    $stmtOwner->execute([':id' => $obsId]);
    $owner = $stmtOwner->fetch();
    if (!$owner || (!Auth::isAdministrative() && (int)$owner['supervisor_id'] !== (int)$user['id'] && (int)$owner['asignado_por'] !== (int)$user['id'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No puede evaluar una observación ajena.']);
        exit;
    }
    if ($owner['estado_respuesta'] !== 'En revisión') {
        echo json_encode(['status' => 'error', 'message' => 'La observación no tiene un descargo pendiente de revisión.']);
        exit;
    }

    if ($obsId <= 0 || $sustento === '') {
        echo json_encode(['status' => 'error', 'message' => 'Ingrese el comentario de la revisión.']);
        exit;
    }

    $nuevoEstado = $aprobado ? 'Levantamiento validado' : 'Levantamiento rechazado';

    $stmtUpd = $pdo->prepare("
        UPDATE observaciones_supervision SET 
            evaluacion_supervisor = :eval,
            sustentacion_evaluacion = :sust,
            fecha_evaluacion = NOW(),
            evaluado_por = :evaluado_por,
            estado_respuesta = :est
        WHERE id = :id
    ");
    $stmtUpd->execute([
        ':eval' => $nuevoEstado,
        ':sust' => $sustento,
        ':evaluado_por' => (int)$user['id'],
        ':est' => $nuevoEstado,
        ':id' => $obsId
    ]);

    echo json_encode(['status' => 'success', 'message' => $aprobado ? 'Se validó el levantamiento de la observación.' : 'Se rechazó el levantamiento de la observación.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
