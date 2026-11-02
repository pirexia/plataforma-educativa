<?php

/**
 * Servidor S3 MÍNIMO y FALSO para las pruebas e2e contra la API real cuando no hay MinIO (la imagen `docker.io/minio/minio`
 * ya no se puede descargar, `funcional.md §22.1` de `REQ-FAM-UNIT`). No es código de aplicación ni valida firmas: implementa,
 * en estilo de ruta (`AWS_USE_PATH_STYLE_ENDPOINT=true`), solo lo que usa `PrivateObjectStore` a través del *driver* S3 de
 * Laravel — `PutObject`, `GetObject`, `HeadObject`, `DeleteObject`, `ListObjectsV2` y la creación de un *bucket* — contra un
 * directorio local. Sirve las URL firmadas (`GET` con `X-Amz-*`) sin comprobar la firma ni su caducidad: **la comprobación de
 * la caducidad y de la firma NO se prueba con esto**.
 *
 * SOLO DESARROLLO y datos sintéticos (`ADR-030`). Uso (ver `apps/web/e2e/README` y `docs/modulos/REQ-PRL/operacion.md`): `php -S 0.0.0.0:9000 tests/Support/e2e-fake-s3.php`
 * dentro de un contenedor de la red de desarrollo con nombre `minio`. Los datos viven en `FAKE_S3_DIR` (por defecto
 * `/tmp/fake-s3`); solo datos sintéticos (`ADR-030`, `REQ-SEED-005`).
 */
if (PHP_SAPI !== 'cli-server') {
    exit(1);
}

// Solo desarrollo y datos sintéticos: no valida firmas, así que se niega a atender fuera de loopback y de redes privadas
// (la red de contenedores de desarrollo). Publicar el puerto solo en 127.0.0.1 (`-p 127.0.0.1:9000:9000`).
$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
    http_response_code(403);
    exit;
}

$root = rtrim((string) (getenv('FAKE_S3_DIR') ?: '/tmp/fake-s3'), '/');
$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

$segments = explode('/', ltrim($path, '/'), 2);
$bucket = $segments[0];
$key = $segments[1] ?? '';

$fail = static function (int $status, string $code) use ($method): never {
    http_response_code($status);

    if ($method !== 'HEAD') {
        header('Content-Type: application/xml');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error><Code>{$code}</Code><Message>{$code}</Message></Error>";
    }

    exit;
};

if ($bucket === '' || preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/', $bucket) !== 1 || str_contains($key, '..')) {
    $fail(400, 'InvalidBucketName');
}

$dir = "{$root}/{$bucket}";
$file = $dir.'/'.$key;
$meta = $file.'.__meta';

if ($key === '') {
    if ($method === 'PUT') {
        @mkdir($dir, 0775, true);
        http_response_code(200);
        exit;
    }

    if (! is_dir($dir)) {
        $fail(404, 'NoSuchBucket');
    }

    if ($method === 'HEAD') {
        http_response_code(200);
        exit;
    }

    if ($method === 'GET') {
        $prefix = (string) ($query['prefix'] ?? '');
        $items = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            $name = substr($entry->getPathname(), strlen($dir) + 1);

            if (str_ends_with($name, '.__meta') || ! str_starts_with($name, $prefix)) {
                continue;
            }

            $items[] = '<Contents><Key>'.htmlspecialchars($name, ENT_XML1).'</Key><LastModified>'.gmdate('Y-m-d\TH:i:s.000\Z', $entry->getMTime()).'</LastModified><ETag>"'.md5_file($entry->getPathname()).'"</ETag><Size>'.$entry->getSize().'</Size><StorageClass>STANDARD</StorageClass></Contents>';
        }

        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><Name>'.htmlspecialchars($bucket, ENT_XML1).'</Name><Prefix>'.htmlspecialchars($prefix, ENT_XML1).'</Prefix><KeyCount>'.count($items).'</KeyCount><MaxKeys>1000</MaxKeys><IsTruncated>false</IsTruncated>'.implode('', $items).'</ListBucketResult>';
        exit;
    }

    $fail(405, 'MethodNotAllowed');
}

switch ($method) {
    case 'PUT':
        if (! is_dir($dir)) {
            $fail(404, 'NoSuchBucket');
        }

        @mkdir(dirname($file), 0775, true);
        $body = (string) file_get_contents('php://input');
        file_put_contents($file, $body);
        file_put_contents($meta, (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'));
        header('ETag: "'.md5($body).'"');
        http_response_code(200);
        exit;

    case 'GET':
    case 'HEAD':
        if (! is_file($file)) {
            $fail(404, 'NoSuchKey');
        }

        header('Content-Type: '.(is_file($meta) ? (string) file_get_contents($meta) : 'application/octet-stream'));
        header('Content-Length: '.filesize($file));
        header('ETag: "'.md5_file($file).'"');
        header('Last-Modified: '.gmdate('D, d M Y H:i:s \G\M\T', (int) filemtime($file)));
        http_response_code(200);

        if ($method === 'GET') {
            readfile($file);
        }

        exit;

    case 'DELETE':
        @unlink($file);
        @unlink($meta);
        http_response_code(204);
        exit;
}

$fail(405, 'MethodNotAllowed');
