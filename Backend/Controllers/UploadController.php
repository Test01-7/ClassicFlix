<?php
declare(strict_types=1);

namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\Csrf;
use ClassicFlix\Support\Http;
use ClassicFlix\Support\HttpException;

final class UploadController
{
    public function __construct(private readonly Application $app) {}

    public function handle(string $action): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpException(405, 'Esta operación requiere POST.');
        }
        $user = $this->app->auth()->requireAdmin();
        Csrf::validate();
        $input = $_POST;
        $id = (string) ($input['upload_id'] ?? '');
        $result = match ($action) {
            'iniciar' => $this->app->uploads()->initiate($input, (int) $user['id']),
            'parte' => $this->app->uploads()->part($id, (int) ($input['part_number'] ?? 0), (int) $user['id']),
            'completar' => $this->app->uploads()->complete($id, (int) $user['id'], $this->parts($input)),
            'cancelar' => $this->app->uploads()->cancel($id, (int) $user['id']),
            default => throw new HttpException(404, 'La operación no existe.'),
        };
        Http::json($result);
    }

    private function parts(array $input): array
    {
        $parts = json_decode((string) ($input['parts'] ?? '[]'), true);
        if (!is_array($parts)) { throw new HttpException(422, 'La lista de partes no es válida.'); }
        return $parts;
    }
}
