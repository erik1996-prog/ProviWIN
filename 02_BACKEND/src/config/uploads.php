<?php

function validateUploadedFile(array $file, array $allowedMimeToExtension, int $maxBytes): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('La carga del archivo no es válida.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
        throw new RuntimeException('El archivo excede el tamaño permitido.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedMimeToExtension[$mime])) {
        throw new RuntimeException('El contenido del archivo no corresponde a un formato permitido.');
    }
    return ['mime' => $mime, 'extension' => $allowedMimeToExtension[$mime]];
}

function randomUploadName(string $prefix, string $extension): string {
    return $prefix . bin2hex(random_bytes(16)) . '.' . $extension;
}
