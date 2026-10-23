<?php
declare(strict_types=1);

namespace ClassicFlix\Services;

use ClassicFlix\Application;
use ClassicFlix\Support\HttpException;
use ClassicFlix\Support\ValidationException;

final class AuthService
{
    private array|false|null $resolved = false;

    public function __construct(private Application $app) {}

    public function user(): ?array
    {
        if ($this->resolved === false) {
            $id = (int) ($_SESSION['user_id'] ?? 0);
            $this->resolved = $id > 0 ? $this->app->users()->findById($id) : null;
            if ($this->resolved !== null) { unset($this->resolved['contrasena']); }
        }
        return $this->resolved;
    }

    public function requireUser(): array
    {
        return $this->user() ?? throw new HttpException(401, 'Inicia sesión para acceder a esta función.');
    }

    public function requireAdmin(): array
    {
        $user = $this->requireUser();
        if ($user['rol'] !== 'admin') { throw new HttpException(403, 'Esta sección es exclusiva para administración.'); }
        return $user;
    }

    public function register(array $data): void
    {
        $errors = [];
        $name = trim($data['nombre'] ?? '');
        $email = mb_strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) { $errors['nombre'] = 'Escribe un nombre entre 2 y 100 caracteres.'; }
        if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Escribe un correo válido de hasta 100 caracteres.'; }
        if (mb_strlen($password) < 8) { $errors['password'] = 'Utiliza al menos 8 caracteres para la contraseña.'; }
        elseif (strlen($password) > 72) { $errors['password'] = 'La contraseña es demasiado larga. Utiliza menos caracteres o símbolos.'; }
        if ($password !== ($data['password_confirmation'] ?? '')) { $errors['password_confirmation'] = 'Las contraseñas no coinciden.'; }
        if ($errors) { throw new ValidationException($errors); }
        if ($this->app->users()->findByEmail($email)) { throw new ValidationException(['email' => 'Este correo ya tiene una cuenta.']); }
        try {
            $id = $this->app->users()->create($name, $email, password_hash($password, PASSWORD_DEFAULT), 'usuario');
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') { throw new ValidationException(['email' => 'Este correo ya tiene una cuenta.']); }
            throw $e;
        }
        $this->establish($id);
    }

    public function login(string $email, string $password): void
    {
        $attempt = $_SESSION['login_attempt'] ?? ['count' => 0, 'since' => time()];
        if (time() - $attempt['since'] > 300) { $attempt = ['count' => 0, 'since' => time()]; }
        if ($attempt['count'] >= 10) { throw new HttpException(429, 'Espera unos minutos antes de volver a intentarlo.'); }
        $user = $this->app->users()->findByEmail(mb_strtolower(trim($email)));
        // El hash de reserva mantiene el trabajo de verificación aun si el correo no existe.
        $valid = password_verify($password, $user['contrasena'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid) {
            $attempt['count']++;
            $_SESSION['login_attempt'] = $attempt;
            throw new ValidationException(['email' => 'El correo o la contraseña no son correctos.']);
        }
        unset($_SESSION['login_attempt']);
        $this->establish((int) $user['id']);
    }

    private function establish(int $id): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        $_SESSION['user_id'] = $id;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        unset($_SESSION['playback_tokens']);
        $this->resolved = false;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
        $this->resolved = null;
    }
}
