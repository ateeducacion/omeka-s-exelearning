<?php

declare(strict_types=1);

namespace Laminas\View\Renderer;

class PhpRenderer
{
    /** @var object */
    private $headScript;
    /** @var object */
    private $headLink;
    /** @var array */
    private $urls = [];

    /**
     * View variables. The real renderer proxies undefined property access to a
     * Variables container, which is how view scripts reach $this->item and
     * $this->resource; __get/__set below reproduce that without relying on
     * dynamic properties (deprecated since PHP 8.2).
     *
     * @var array<string, mixed>
     */
    private array $vars = [];

    /** @var array<int, array{name: string, vars: array}> Recorded partial() calls. */
    public array $partials = [];

    /** @var string */
    public string $basePath = '';

    public function __construct()
    {
        $this->headScript = new class {
            /** @var array<int, string> */
            public array $files = [];
            /** @var array<int, string> */
            public array $scripts = [];
            public function appendFile(string $url, $type = null): self
            {
                $this->files[] = $url;
                return $this;
            }
            public function appendScript(string $script): self
            {
                $this->scripts[] = $script;
                return $this;
            }
        };

        $this->headLink = new class {
            /** @var array<int, string> */
            public array $stylesheets = [];
            public function appendStylesheet(string $url): self
            {
                $this->stylesheets[] = $url;
                return $this;
            }
        };
    }

    public function headScript()
    {
        return $this->headScript;
    }

    public function inlineScript()
    {
        return $this->headScript();
    }

    public function headLink()
    {
        return $this->headLink;
    }

    public function headStyle()
    {
        return new class {
            /** @var array<int, string> */
            public array $styles = [];
            public function appendStyle(string $style): self
            {
                $this->styles[] = $style;
                return $this;
            }
        };
    }

    public function assetUrl(string $path, ?string $module = null): string
    {
        $prefix = $module ? "/modules/{$module}/" : "/assets/";
        return $prefix . ltrim($path, '/');
    }

    public function url(string $route, array $params = [], array $options = []): string
    {
        // Generate a mock URL
        $url = '/' . $route;
        foreach ($params as $key => $value) {
            $url .= '/' . $value;
        }
        return $url;
    }

    public function plugin(string $name)
    {
        if ($name === 'setting') {
            return function (string $key, $default = null) {
                return $default;
            };
        }
        return null;
    }

    public function translate(string $message): string
    {
        return $message;
    }

    public function escapeHtmlAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function getHelperPluginManager()
    {
        return new class {
            public function get(string $name)
            {
                throw new \Exception('No helper found: ' . $name);
            }
        };
    }

    /**
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->vars[$name] ?? null;
    }

    /**
     * @param mixed $value
     */
    public function __set(string $name, $value): void
    {
        $this->vars[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . $path;
    }

    public function escapeJs(string $value): string
    {
        return addcslashes($value, "\0..\37'\"\\\/");
    }

    /**
     * Records the call and returns a marker instead of rendering: the view
     * scripts under view/ are not on the include path in unit tests.
     *
     * @param array<string, mixed> $vars
     */
    public function partial(string $name, array $vars = []): string
    {
        $this->partials[] = ['name' => $name, 'vars' => $vars];
        return '<!--partial:' . $name . '-->';
    }

    /**
     * @param mixed $form
     */
    public function formCollection($form, bool $wrap = true): string
    {
        return '<!--formCollection-->';
    }
}
