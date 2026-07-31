<?php

namespace App\Console\Commands;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * تولیدِ مستندِ OpenAPI از **جدولِ واقعیِ مسیرها**.
 *
 * ─── چرا تولیدی و نه دستی؟ ─────────────────────────────────────────────────
 * مستندِ دستی همیشه از کد عقب می‌افتد؛ کسی مسیری اضافه می‌کند و مستند
 * به‌روزرسانی نمی‌شود، و بعد از چند ماه مستند بیشتر گمراه می‌کند تا کمک.
 * اینجا منبع خودِ روتر است، پس چیزی که نوشته می‌شود همان چیزی است که واقعاً
 * وجود دارد.
 *
 * ─── چرا بدونِ پکیجِ بیرونی؟ ────────────────────────────────────────────────
 * ابزارهای آماده یا انبوهی annotation روی هر متد می‌خواهند (که خودش همان
 * مشکلِ همگام‌ماندن را دارد)، یا وابستگیِ سنگینی می‌آورند. این دستور کوچک
 * است، چیزی جز چیزی که داریم لازم ندارد، و قواعدِ اعتبارسنجی را مستقیم از
 * FormRequestها می‌خواند.
 *
 * @example php artisan openapi:generate
 */
class GenerateOpenApiSpec extends Command
{
    protected $signature = 'openapi:generate {--output=public/openapi.json}';

    protected $description = 'ساخت مستند OpenAPI از روی مسیرهای واقعی API';

    public function handle(): int
    {
        $paths = [];
        $counted = 0;

        // `getRoutes()` آرایه می‌دهد؛ خودِ کالکشن در اینترفیس iterable اعلام نشده
        foreach (Route::getRoutes()->getRoutes() as $route) {
            // فقط نسخه‌ی رسمی مستند می‌شود؛ نامِ مستعارِ بدونِ نسخه صرفاً
            // برای سازگاری است و نباید در قرارداد ظاهر شود.
            if (! Str::startsWith($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.preg_replace('/\{(\w+)\??\}/', '{$1}', $route->uri());

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation($route, $path);
                $counted++;
            }
        }

        ksort($paths);

        $spec = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('app.name').' — API',
                'version' => '1.0.0',
                'description' => "احراز هویت با نشست و کوکی انجام می‌شود، نه توکن.\n\n"
                    .'هر درخواستِ تغییردهنده باید هدرِ `X-CSRF-TOKEN` داشته باشد؛ '
                    ."توکن را از `GET /api/v1/csrf-token` بگیرید.\n\n"
                    .'شکلِ خطاها در همه‌ی مسیرها یکسان است (به `Error` نگاه کنید).',
            ],
            'servers' => [['url' => config('app.url')]],
            'components' => [
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'description' => 'شکلِ یکسانِ همه‌ی پاسخ‌های خطا.',
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'message' => ['type' => 'string', 'description' => 'پیامِ فارسیِ قابلِ نمایش'],
                            'code' => ['type' => 'string', 'description' => 'شناسه‌ی ماشین‌خوان', 'example' => 'validation_failed'],
                            'errors' => [
                                'type' => 'object',
                                'description' => 'خطای هر فیلد — فقط در ۴۲۲',
                                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                        'required' => ['success', 'message'],
                    ],
                ],
            ],
            'paths' => $paths,
        ];

        $output = base_path((string) $this->option('output'));
        File::ensureDirectoryExists(dirname($output));
        File::put($output, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info("مستند OpenAPI ساخته شد: {$output}");
        $this->line("  {$counted} عملیات روی ".count($paths).' مسیر');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(RoutingRoute $route, string $path): array
    {
        $middleware = $route->gatherMiddleware();
        $needsAuth = in_array('auth', $middleware, true)
            || collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'role:'));

        $operation = [
            'summary' => $route->getName() ?? $route->uri(),
            'tags' => [$this->tagFor($route->uri())],
            'parameters' => $this->pathParameters($path),
            'responses' => [
                '200' => ['description' => 'موفق'],
                '422' => $this->errorResponse('خطای اعتبارسنجی'),
                '429' => $this->errorResponse('عبور از محدودیتِ نرخ'),
            ],
        ];

        if ($needsAuth) {
            $operation['responses']['401'] = $this->errorResponse('وارد نشده‌اید');
            $operation['responses']['403'] = $this->errorResponse('دسترسی ندارید');
        }

        // نقشی که مسیر لازم دارد، از خودِ میدل‌ور خوانده می‌شود
        $roles = collect($middleware)
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'role:'))
            ->map(fn ($m) => substr($m, 5))
            ->implode('، ');

        if ($roles !== '') {
            $operation['description'] = "فقط برای نقشِ: {$roles}";
        }

        if ($body = $this->requestBody($route)) {
            $operation['requestBody'] = $body;
        }

        return $operation;
    }

    /**
     * بدنه‌ی درخواست، از روی قواعدِ FormRequest.
     *
     * فقط اکشن‌هایی که به FormRequest مهاجرت کرده‌اند بدنه‌ی مستند دارند؛
     * بقیه (که هنوز `$request->validate()` درجا دارند) در R9b مهاجرت
     * می‌کنند و همان لحظه خودبه‌خود اینجا ظاهر می‌شوند.
     *
     * @return array<string, mixed>|null
     */
    private function requestBody(RoutingRoute $route): ?array
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action);
        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            if (! class_exists($name) || ! is_subclass_of($name, BaseFormRequest::class)) {
                continue;
            }

            /** @var BaseFormRequest $request */
            $request = (new ReflectionClass($name))->newInstanceWithoutConstructor();
            $properties = [];
            $required = [];

            foreach ($request->rules() as $field => $rules) {
                $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
                $flat = array_map(fn ($r) => is_string($r) ? $r : '', $rules);

                $properties[$field] = [
                    'type' => match (true) {
                        in_array('boolean', $flat, true) => 'boolean',
                        in_array('integer', $flat, true), in_array('numeric', $flat, true) => 'number',
                        in_array('array', $flat, true) => 'array',
                        default => 'string',
                    },
                ];

                if ($label = ($request->attributes()[$field] ?? null)) {
                    $properties[$field]['description'] = $label;
                }

                if (in_array('required', $flat, true)) {
                    $required[] = $field;
                }
            }

            return [
                'required' => $required !== [],
                'content' => [
                    'application/json' => [
                        'schema' => array_filter([
                            'type' => 'object',
                            'properties' => $properties,
                            'required' => $required ?: null,
                        ]),
                    ],
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);

        return collect($matches[1])->map(fn (string $name) => [
            'name' => $name,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    /** گروه‌بندی بر اساس اولین بخشِ مسیر، تا Swagger UI خوانا بماند. */
    private function tagFor(string $uri): string
    {
        $segments = explode('/', $uri);

        return $segments[2] ?? 'general';
    }
}
