<?php
namespace VocabSync;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'controllers' => [
        'invokables' => [
            'VocabSync\Controller\Admin\Index' => Controller\Admin\IndexController::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'vocab-sync' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/vocab-sync[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'VocabSync\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    // // In the Resources section, next to Vocabularies and Resource templates,
    // // because it is about the words used on resources, not a tool of the module.
    //
    // // "Above Vocabularies" cannot be done with config: the core entries are
    // // set first and a module's are always appended · see Module::init(),
    // // which reorders them while the config is merged.
    'navigation' => [
        'AdminResource' => [
            [
                'label' => 'Vocab Sync', // @translate
                'route' => 'admin/vocab-sync',
                'resource' => 'VocabSync\Controller\Admin\Index',
                'class' => 'vocab-sync',
            ],
        ],
    ],
    // // The data type name of each vocab is made on the fly by an abstract factory
    // // (as Custom Vocab does with customvocab:<id>), because Omeka ties one
    // // data type name to one property only, so each vocab needs its own name
    // // for each property to offer its own dropdown.
    'data_types' => [
        'abstract_factories' => [
            Service\DataType\VocabSyncDataTypeFactory::class,
        ],
    ],
];
