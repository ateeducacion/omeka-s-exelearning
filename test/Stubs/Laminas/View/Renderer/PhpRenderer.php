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

    public function __construct()
    {
        $this->headScript = new class {
            /** @var array<int, string> */
            public array $files = [];
            /** @var array<int, string> Inline scripts, RECORDED: a stub that discards them
             *  cannot answer "was the relay initialised, and once?" — which is exactly the
             *  question the external-media migration made worth asking. */
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
}
