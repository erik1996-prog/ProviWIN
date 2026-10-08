<?php
// 02_BACKEND/src/routes/api/observaciones_pdf.php - Reporte PDF de observaciones (lo genera el script Python del sistema de origen).
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/observation_records.php';
Auth::requireLogin();
$user=Auth::getLoggedInUser();
if (empty($user['is_win_directo']) && empty($user['partner_id'])) { http_response_code(403); exit('Acceso denegado'); }
if (empty($user['is_win_directo']) && !empty($user['partner_id'])) { http_response_code(403); exit('La descarga PDF no está habilitada para supervisores del partner.'); }
$filters=observationRegisterFilters($_GET);
$rows=fetchObservationRecords(Database::getConnection(),$user,$filters);
$payload=['base_path'=>SHARED_FILES_PATH,'filters'=>$filters,'rows'=>$rows];
$token=bin2hex(random_bytes(10));
$jsonPath=sys_get_temp_dir().DIRECTORY_SEPARATOR.'win_obs_'.$token.'.json';
$pdfPath=sys_get_temp_dir().DIRECTORY_SEPARATOR.'win_obs_'.$token.'.pdf';
file_put_contents($jsonPath,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
// Intérprete de Python configurable (PYTHON_BIN en .env); se mantiene la ruta usada hasta ahora como respaldo.
$python=getenv('PYTHON_BIN') ?: 'C:\\Users\\ERIKJ\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
$script=SHARED_FILES_PATH.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'render_observations_pdf.py';
$command='"'.$python.'" "'.$script.'" "'.$jsonPath.'" "'.$pdfPath.'" 2>&1';
exec($command,$output,$code);
@unlink($jsonPath);
if($code!==0||!is_file($pdfPath)){
    // El detalle técnico va al log; el usuario solo ve el código de seguimiento.
    win_log_error('pdf_generation_failed', 'No se pudo generar el PDF de observaciones', ['exit_code'=>$code, 'python'=>$python, 'output'=>mb_substr(implode("\n",$output),0,4000)]);
    http_response_code(500);header('Content-Type:text/plain;charset=utf-8');
    echo 'No se pudo generar el PDF. Código de seguimiento: '.sgs_error_code();@unlink($pdfPath);exit;
}
$pdf=file_get_contents($pdfPath);@unlink($pdfPath);
$filename='observaciones_'.date('Ymd_His').'.pdf';
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.strlen($pdf));echo $pdf;
