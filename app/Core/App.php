<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\SettingsService;
use App\Support\Menu;
use App\Support\Present;
use Throwable;

/**
 * HTTP kernel: /api/* goes to controllers (JSON), everything else renders a page view.
 */
final class App
{
    public function __construct(private Router $router, private array $pages)
    {
    }

    public static function create(): self
    {
        $router = new Router();
        (require BASE_PATH . '/app/routes.php')($router);
        $pages = require BASE_PATH . '/app/pages.php';
        return new self($router, $pages);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function pages(): array
    {
        return $this->pages;
    }

    public function handle(Request $request): Response
    {
        Request::setCurrent($request);
        Auth::reset();

        if ($request->isApi() && $request->method === 'OPTIONS') {
            $response = new Response(204, [], '');
            return $this->secureHeaders($response, $request);
        }

        try {
            $response = $request->isApi() ? $this->api($request) : $this->page($request);
        } catch (HttpException $e) {
            $response = $request->isApi() ? Response::fromException($e) : $this->errorPage($e->status, $e->getMessage());
        } catch (Throwable $e) {
            if (Db::isDuplicateKey($e)) {
                $http = HttpException::conflict('DUPLICATE', 'Já existe um registro com estes dados.');
            } else {
                Log::error($e->getMessage(), [
                    'type' => get_class($e),
                    'at' => $e->getFile() . ':' . $e->getLine(),
                    'path' => $request->method . ' ' . $request->path,
                    'user' => Auth::id(),
                ]);
                $http = new HttpException(
                    500,
                    'SERVER_ERROR',
                    Config::get('app.debug')
                        ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
                        : 'Erro inesperado. Tente novamente; se persistir, contate o administrador.'
                );
            }
            $response = $request->isApi() ? Response::fromException($http) : $this->errorPage($http->status, $http->getMessage());
        }

        return $this->secureHeaders($response, $request);
    }

    // -----------------------------------------------------------------
    // API
    // -----------------------------------------------------------------
    private function api(Request $request): Response
    {
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            throw HttpException::notFound('Endpoint não encontrado.');
        }
        $options = $match['route']['options'];
        $request->params = $match['params'];

        Session::start();
        Auth::boot();

        if (empty($options['public'])) {
            if (!Auth::check()) {
                throw HttpException::unauthenticated();
            }
            if ((int) Auth::user()['must_change_password'] === 1 && empty($options['password_change_ok'])) {
                throw HttpException::forbidden('Troque sua senha para continuar.', 'PASSWORD_CHANGE_REQUIRED');
            }
            if (isset($options['perm'])) {
                Auth::authorize($options['perm']);
            }
        }

        if (!$request->isSafeMethod() && ($options['csrf'] ?? true)) {
            Csrf::verify($request);
        }

        [$class, $method] = $match['route']['handler'];
        $call = static fn (): Response => (new $class())->{$method}($request);

        return empty($options['idempotent']) ? $call() : Idempotency::run($request, $call);
    }

    // -----------------------------------------------------------------
    // Pages (server-rendered shells; data is loaded from /api by the page JS)
    // -----------------------------------------------------------------
    private function page(Request $request): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            throw new HttpException(405, 'METHOD_NOT_ALLOWED', 'Método não permitido.');
        }
        Session::start();
        Auth::boot();

        if ($request->path === '/') {
            return Response::redirect(url(Auth::check() ? '/dashboard' : '/login'));
        }

        $found = $this->matchPage($request->path);
        if ($found === null) {
            return $this->errorPage(404, 'Página não encontrada.');
        }
        [$page, $params] = $found;
        $request->params = $params;

        if (!empty($page['guest'])) {
            if (Auth::check()) {
                return Response::redirect(url('/dashboard'));
            }
        } else {
            if (!Auth::check()) {
                return Response::redirect(url('/login') . '?next=' . rawurlencode($request->path));
            }
            if ((int) Auth::user()['must_change_password'] === 1 && $request->path !== '/perfil') {
                return Response::redirect(url('/perfil') . '?trocar-senha=1');
            }
            if (isset($page['perm']) && !Auth::can($page['perm'])) {
                return $this->errorPage(403, 'Você não tem permissão para acessar esta página.');
            }
            if (!empty($page['deny_cd']) && Auth::isCdOperations()) {
                return $this->errorPage(403, 'Você não tem permissão para acessar esta página.');
            }
            if (!empty($page['deny_roles']) && in_array(Auth::roleSlug(), (array) $page['deny_roles'], true)) {
                return $this->errorPage(403, 'Você não tem permissão para acessar esta página.');
            }
        }

        return Response::html(View::render($page['view'], $this->viewData($request, $page, $params)));
    }

    /** @return array{0: array, 1: array}|null */
    private function matchPage(string $path): ?array
    {
        foreach ($this->pages as $page) {
            if (preg_match(Router::compile($page['path']), $path, $m)) {
                return [$page, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
            }
        }
        return null;
    }

    /**
     * Everything a view receives in $app. Documented in docs/api-contract.md §Views.
     */
    private function viewData(Request $request, array $page, array $params): array
    {
        $user = Auth::user();
        return [
            'user' => $user ? Present::user($user) : null,
            'permissions' => Auth::permissions(),
            'csrf' => Csrf::token(),
            'settings' => SettingsService::branding(),
            'menu' => $user ? Menu::build($request->path) : [],
            'page' => [
                'path' => $request->path,
                'view' => $page['view'],
                'title' => $page['title'] ?? '',
                'params' => $params,
                'query' => $request->query,
            ],
            'base_url' => Config::basePath(),
        ];
    }

    private function errorPage(int $status, string $message): Response
    {
        $view = "pages/errors/{$status}";
        $app = [
            'user' => Auth::user() ? Present::user(Auth::user()) : null,
            'permissions' => Auth::permissions(),
            'csrf' => Session::get('csrf') ? Csrf::token() : '',
            'settings' => SettingsService::branding(),
            'menu' => Auth::user() ? Menu::build('') : [],
            'page' => ['path' => '', 'view' => $view, 'title' => 'Erro ' . $status, 'params' => [], 'query' => []],
            'base_url' => Config::basePath(),
            'error' => ['status' => $status, 'message' => $message],
        ];
        try {
            $html = View::render(View::exists($view) ? $view : 'pages/errors/generic', $app);
        } catch (Throwable) {
            $html = '<h1>Erro ' . $status . '</h1><p>' . e($message) . '</p>';
        }
        return Response::html($html, $status);
    }

    private function secureHeaders(Response $response, Request $request): Response
    {
        $h = &$response->headers;
        $h['X-Content-Type-Options'] = 'nosniff';
        $h['X-Frame-Options'] = 'SAMEORIGIN';
        $h['Referrer-Policy'] = 'same-origin';
        $h['Permissions-Policy'] = 'camera=(self), microphone=(), geolocation=()';

        // Dynamic CORS handling for API & local dev
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $allowedOrigins = array_filter(array_map('trim', explode(',', (string) Config::get('cors.allowed_origins', ''))));
            $isLocal = preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/i', $origin);
            if ($isLocal || in_array($origin, $allowedOrigins, true)) {
                $h['Access-Control-Allow-Origin'] = $origin;
                $h['Access-Control-Allow-Credentials'] = 'true';
                $h['Access-Control-Allow-Methods'] = 'GET, POST, PUT, DELETE, PATCH, OPTIONS';
                $h['Access-Control-Allow-Headers'] = 'Content-Type, X-CSRF-Token, Authorization, Idempotency-Key, X-Requested-With';
                $h['Vary'] = isset($h['Vary']) ? $h['Vary'] . ', Origin' : 'Origin';
            }
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $h['Strict-Transport-Security'] = 'max-age=31536000';
        }
        if ($request->isApi()) {
            $h['Cache-Control'] ??= 'no-store';
        } elseif (str_starts_with($h['Content-Type'] ?? '', 'text/html')) {
            $h['Cache-Control'] = 'no-store';
            $h['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval'; font-src 'self' data:; connect-src 'self'; "
                . "media-src 'self' blob: mediastream:; worker-src 'self' blob:; "
                . "frame-ancestors 'self'; base-uri 'self'; form-action 'self' mailto:";
        }
        return $response;
    }
}
