<?php
declare(strict_types=1);

namespace Lab2;

final class TelemetryController
{
    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($path !== '/api/v1/telemetry') {
                throw new ApiException(404, 'not_found', 'Ruta no disponible.');
            }
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Allow: POST');
                throw new ApiException(405, 'method_not_allowed', 'Utilice POST.');
            }
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (!preg_match('/\ABearer ([a-f0-9]{64})\z/i', $auth, $match)) {
                throw new ApiException(401, 'unauthorized', 'Token ausente o invalido.');
            }
            $pdo = Database::connect();
            $device = (new DeviceRepository($pdo))->authenticate($match[1]);
            if (!$device) {
                throw new ApiException(401, 'unauthorized', 'Token ausente o invalido.');
            }
            $raw = file_get_contents('php://input', false, null, 0, 16385);
            if ($raw === false || strlen($raw) > 16384) {
                throw new ApiException(422, 'validation_error', 'Cuerpo excede 16384 bytes.');
            }
            try {
                $payload = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiException(400, 'malformed_json', 'JSON malformado o demasiado profundo.');
            }
            if (!$payload instanceof \stdClass) {
                throw new ApiException(422, 'validation_error', 'El cuerpo debe ser un objeto JSON.');
            }
            $result = (new TelemetryService($pdo))->receive($payload, $device['id'], $device['node_id']);
            http_response_code($result['created'] ? 201 : 200);
            $body = ['ok' => true, 'data' => $result['data'], 'meta' => ['api_version' => 'v1', 'created' => $result['created']]];
        } catch (ApiException $error) {
            http_response_code($error->status);
            if ($error->status === 401) {
                header('WWW-Authenticate: Bearer');
            }
            $body = ['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage(),
                'details' => (object) $error->details], 'meta' => ['api_version' => 'v1']];
        } catch (\Throwable) {
            http_response_code(500);
            $body = ['ok' => false, 'error' => ['code' => 'internal_error', 'message' => 'Error interno.'],
                'meta' => ['api_version' => 'v1']];
        }
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
