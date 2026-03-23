<?php
declare(strict_types=1);

namespace ExeLearning;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Regex;
use Laminas\Router\Http\Segment;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\ApiController::class => Controller\ApiControllerFactory::class,
            Controller\EditorController::class => Controller\EditorControllerFactory::class,
            Controller\ContentController::class => Controller\ContentControllerFactory::class,
        ],
        'aliases' => [
            'ExeLearning\Controller\Editor' => Controller\EditorController::class,
            'ExeLearning\Controller\Api' => Controller\ApiController::class,
            'ExeLearning\Controller\Content' => Controller\ContentController::class,
        ],
    ],

    'service_manager' => [
        'factories' => [
            Service\ElpFileService::class => Service\ElpFileServiceFactory::class,
        ],
    ],

    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],

    'file_renderers' => [
        'factories' => [
            'exelearning_renderer' => Media\FileRenderer\ExeLearningRendererFactory::class,
        ],
        'aliases' => [
            // MIME types
            'application/zip' => 'exelearning_renderer',
            'application/x-zip-compressed' => 'exelearning_renderer',
            'application/octet-stream' => 'exelearning_renderer',
            // File extensions
            'elpx' => 'exelearning_renderer',
            'zip' => 'exelearning_renderer',
        ],
    ],

    'router' => [
        'routes' => [
            // Secure content delivery route - serves extracted eXeLearning content with security headers
            // Uses Regex route to properly capture file paths with multiple slashes
            'exelearning-content' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/exelearning/content/(?<hash>[a-f0-9]{40})(?:/(?<file>.*))?',
                    'spec' => '/exelearning/content/%hash%/%file%',
                    'defaults' => [
                        '__NAMESPACE__' => 'ExeLearning\Controller',
                        'controller' => 'Content',
                        'action' => 'serve',
                        'file' => 'index.html',
                    ],
                ],
            ],
            'exelearning-api' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/api/exelearning',
                    'defaults' => [
                        '__NAMESPACE__' => 'ExeLearning\Controller',
                        'controller' => 'Api',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'save' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/save/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'save',
                            ],
                        ],
                    ],
                    'elp-data' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/elp-data/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'getData',
                            ],
                        ],
                    ],
                    'teacher-mode' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/teacher-mode/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                'action' => 'setTeacherMode',
                            ],
                        ],
                    ],
                    'install-editor' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/install-editor',
                            'defaults' => [
                                'action' => 'installEditor',
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'exelearning-editor' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/exelearning/editor/:action[/:id]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'ExeLearning\Controller',
                                'controller' => 'Editor',
                                'action' => 'index',
                            ],
                        ],
                    ],
                    'exelearning-install' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/exelearning/install-editor',
                            'defaults' => [
                                '__NAMESPACE__' => 'ExeLearning\Controller',
                                'controller' => 'Editor',
                                'action' => 'installEditor',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],

    'exelearning' => [
        'settings' => [
            'exelearning_viewer_height' => 600,
            'exelearning_show_edit_button' => true,
        ],
    ],
];
