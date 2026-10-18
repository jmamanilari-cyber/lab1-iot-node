<?php
declare(strict_types=1);

namespace Lab2;

final class ApiController
{
    public static function handle(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($path === '/api/v1/telemetry') {
            // Preserve the tested C1 controller, authentication and service unchanged.
            TelemetryController::handle();
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $resourceId = null;
            if ($path === '/api/v1/devices') { $route = 'devices'; $method = 'GET'; }
            elseif ($path === '/api/v1/events') { $route = 'events'; $method = 'GET'; }
            elseif (preg_match('~\A/api/v1/devices/([^/]+)/(telemetry|config)\z~', (string) $path, $match)) {
                $resourceId = $match[1]; $route = $match[2]; $method = $route === 'config' ? 'PUT' : 'GET';
            } else { throw new ApiException(404, 'not_found', 'Ruta no disponible.'); }
            if ($_SERVER['REQUEST_METHOD'] !== $method) {
                header('Allow: ' . $method);
                throw new ApiException(405, 'method_not_allowed', 'Metodo no permitido.');
            }
            $pdo = Database::connect();
            $principal = RestAuth::authenticate($pdo);
            if ($route !== 'telemetry') { RestAuth::requireAdmin($principal); }
            $id = $resourceId === null ? null : ApiInput::integer($resourceId, 'id', 1, PHP_INT_MAX);
            if ($id !== null) { RestAuth::requireDeviceAccess($principal, $id); }
            $repository = new ManagementRepository($pdo);
            $meta = ['api_version' => 'v1'];
            if ($route === 'config') {
                [$key, $json] = ApiInput::configuration();
                $data = $repository->putConfiguration($id, $key, $json);
            } else {
                $allowed = ['limit', 'offset'];
                if ($route === 'telemetry') { $allowed = [...$allowed, 'from', 'to', 'time_field']; }
                if ($route === 'events') { $allowed = [...$allowed, 'from', 'to', 'device_id', 'type', 'severity']; }
                $query = ApiInput::query($allowed);
                [$limit, $offset] = ApiInput::pagination($query);
                [$from, $to] = ApiInput::dates($query);
                if ($route === 'devices') { $data = $repository->devices($limit, $offset); }
                elseif ($route === 'telemetry') {
                    $field = $query['time_field'] ?? 'received_at';
                    if (!in_array($field, ['received_at', 'timestamp'], true)) { ApiInput::invalid('time_field', 'Use received_at o timestamp.'); }
                    $data = $repository->history($id, $field, $from, $to, $limit, $offset);
                } else {
                    $filters = [];
                    if (isset($query['device_id'])) { $filters['device_id'] = ApiInput::integer($query['device_id'], 'device_id', 1, PHP_INT_MAX); }
                    if (isset($query['type'])) {
                        if (!is_string($query['type']) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $query['type'])) { ApiInput::invalid('type', 'Tipo invalido.'); }
                        $filters['type'] = $query['type'];
                    }
                    if (isset($query['severity'])) {
                        if (!in_array($query['severity'], ['info', 'warning', 'error'], true)) { ApiInput::invalid('severity', 'Severidad invalida.'); }
                        $filters['severity'] = $query['severity'];
                    }
                    $data = $repository->events($filters, $from, $to, $limit, $offset);
                }
                $more = count($data) > $limit;
                if ($more) { array_pop($data); }
                $meta += ['limit' => $limit, 'offset' => $offset, 'count' => count($data), 'has_more' => $more];
            }
            http_response_code(200);
            $body = ['ok' => true, 'data' => $data, 'meta' => $meta];
        } catch (ApiException $error) {
            http_response_code($error->status);
            if ($error->status === 401) { header('WWW-Authenticate: Bearer'); }
            $body = ['ok' => false, 'error' => ['code' => $error->errorCode, 'message' => $error->getMessage(),
                'details' => (object) $error->details], 'meta' => ['api_version' => 'v1']];
        } catch (\Throwable) {
            http_response_code(500);
            $body = ['ok' => false, 'error' => ['code' => 'internal_error', 'message' => 'Error interno.'], 'meta' => ['api_version' => 'v1']];
        }
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
