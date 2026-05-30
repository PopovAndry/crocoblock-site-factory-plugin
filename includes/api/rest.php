<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'factory_register_rest_routes' );

function factory_register_rest_routes(): void {
	$namespace = 'factory/v1';

	$routes = [
		'/validate' => [
			'methods'  => 'POST',
			'callback' => 'factory_rest_validate',
		],
		'/summary' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_summary',
		],
		'/doctor' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_doctor',
		],
		'/runs' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_runs',
		],
		'/run/latest' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_latest_run',
		],
		'/run/(?P<file>run-[^/]+\.json)' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_run',
		],
		'/explain/latest' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_explain_latest',
		],
		'/index' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_index',
		],
		'/adapters' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_adapters',
		],
		'/capabilities' => [
			'methods'  => 'GET',
			'callback' => 'factory_rest_capabilities',
		],
		'/beta/real-estate/plan' => [
			'methods'  => [ 'GET', 'POST' ],
			'callback' => 'factory_rest_beta_real_estate_plan',
		],
		'/beta/real-estate/apply' => [
			'methods'  => 'POST',
			'callback' => 'factory_rest_beta_real_estate_apply',
		],
	];

	foreach ( $routes as $route => $args ) {
		register_rest_route(
			$namespace,
			$route,
			[
				'methods'             => $args['methods'],
				'callback'            => $args['callback'],
				'permission_callback' => 'factory_rest_require_manage_options',
			]
		);
	}
}

    function factory_rest_require_manage_options(): bool {
        return current_user_can( 'manage_options' );
    }

    function factory_rest_validate(): WP_REST_Response {

        $latest = factory_get_latest_run_name();

        if ( ! $latest ) {

            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'No runs found.',
                ],
                404
            );
        }

        $run = factory_get_run_manifest( $latest );

        if ( ! is_array( $run ) ) {

            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Invalid run manifest.',
                ],
                500
            );
        }

        $blueprint =
            $run['blueprint'] ?? [];

        $result =
            factory_validate_blueprint_state(
                $blueprint,
                false
            );

        return new WP_REST_Response(
            [
                'status' => $result['status'] ?? 'error',
                'checks' => $result['checks'] ?? [],
            ]
        );
    }

    function factory_rest_capabilities(): WP_REST_Response {
        $registry = new Factory_Adapter_Registry();
        $adapters = $registry->get_contract_report();

        return new WP_REST_Response(
            [
                'version' => '1.0',

                'ai'      => true,
                'docker'  => true,
                'wp_cli'  => true,

                'presets' => [
                    'job-board',
                    'real-estate',
                ],

                'commands' => [
                    'ai',
                    'apply',
                    'validate',
                    'fix',
                    'doctor',
                    'summary',
                    'runs',
                    'latest',
                    'run',
                    'explain',
                    'reset',
                ],

                'adapter_contract_ready' => factory_rest_adapters_contract_ready( $adapters ),

                'adapters' => [
                    'plugins',
                    'theme',
                    'taxonomy',
                    'wp_core',
                    'jetengine',
                    'listing',
                    'render',
                    'single',
                    'content',
                ],
            ]
        );
    }

    function factory_rest_adapters(): WP_REST_Response {
        $registry = new Factory_Adapter_Registry();

        return new WP_REST_Response(
            [
                'status'   => 'ok',
                'adapters' => $registry->get_contract_report(),
            ]
        );
    }

    function factory_rest_adapters_contract_ready( array $adapters ): bool {
        foreach ( $adapters as $adapter ) {
            if ( empty( $adapter['contract_ready'] ) ) {
                return false;
            }
        }

        return true;
    }

    function factory_rest_beta_real_estate_plan( WP_REST_Request $request ): WP_REST_Response {
        try {
            $base_blueprint = factory_rest_load_real_estate_blueprint();
            $prompt_context = factory_rest_get_real_estate_prompt_context( $request, $base_blueprint, 'Dashboard preview: real-estate' );
            $blueprint    = factory_rest_apply_real_estate_preset_variables( $base_blueprint, $prompt_context['applied_variables'] );
            $prompt       = $prompt_context['prompt'];
            $plan         = factory_rest_build_plan( $blueprint );
            $dependencies = factory_rest_get_real_estate_dependency_status();
            $product_plan = factory_rest_build_real_estate_product_plan( $blueprint, $plan, $dependencies, $prompt, $prompt_context );

            return new WP_REST_Response(
                [
                    'status'            => 'ok',
                    'preset'            => 'real-estate',
                    'prompt'            => $prompt,
                    'preset_variables'  => $prompt_context['preset_variables'],
                    'applied_variables' => $prompt_context['applied_variables'],
                    'prompt_notes'      => $prompt_context['notes'],
                    'plan'              => $plan,
                    'dependencies'      => $dependencies,
                    'product_plan'      => $product_plan,
                ]
            );
        } catch ( Throwable $e ) {
            return factory_rest_beta_error_response( $e->getMessage() );
        }
    }

    function factory_rest_beta_real_estate_apply( WP_REST_Request $request ): WP_REST_Response {
        try {
            $base_blueprint = factory_rest_load_real_estate_blueprint();
            $prompt_context = factory_rest_get_real_estate_prompt_context( $request, $base_blueprint, 'Dashboard apply: real-estate' );
            $blueprint    = factory_rest_apply_real_estate_preset_variables( $base_blueprint, $prompt_context['applied_variables'] );
            $prompt       = $prompt_context['prompt'];
            $dependencies = factory_rest_get_real_estate_dependency_status();

            if ( empty( $dependencies['ready'] ) ) {
                return factory_rest_beta_error_response(
                    'Real Estate dependencies are missing or inactive.',
                    409,
                    [
                        'dependencies' => $dependencies,
                    ]
                );
            }

            if ( function_exists( 'factory_reset_diff_report' ) ) {
                factory_reset_diff_report();
            }

            $execution = factory_apply_blueprint( $blueprint );
            $plan      = factory_rest_build_plan( $blueprint );
            $report    = factory_validate_blueprint_state( $blueprint, false );

            $manifest_path = factory_save_run_manifest(
                $prompt,
                'real-estate',
                $blueprint,
                $plan,
                $report,
                $report['status'] ?? 'error',
                $execution,
                [
                    'prompt_context' => $prompt_context,
                ]
            );

            $results = function_exists( 'factory_build_manifest_results' )
                ? factory_build_manifest_results( $report )
                : [
                    'summary' => [
                        'ok'      => 0,
                        'warning' => 0,
                        'error'   => 0,
                    ],
                ];

            return new WP_REST_Response(
                [
                    'status'            => $report['status'] ?? 'error',
                    'message'           => 'Real Estate preset applied.',
                    'preset'            => 'real-estate',
                    'prompt'            => $prompt,
                    'preset_variables'  => $prompt_context['preset_variables'],
                    'applied_variables' => $prompt_context['applied_variables'],
                    'prompt_notes'      => $prompt_context['notes'],
                    'file'              => basename( $manifest_path ),
                    'plan_summary'      => $plan['summary'] ?? [],
                    'execution_count'   => count( $execution ),
                    'validation_count'  => count( $report['checks'] ?? [] ),
                    'results_summary'   => $results['summary'] ?? [],
                ]
            );
        } catch ( Throwable $e ) {
            return factory_rest_beta_error_response( $e->getMessage() );
        }
    }

    function factory_rest_load_real_estate_blueprint(): array {
        $manager = new Factory_Blueprint_Preset_Manager();

        return $manager->load_preset( 'real-estate' );
    }

    function factory_rest_get_beta_prompt( WP_REST_Request $request, string $fallback ): string {
        $prompt = $request->get_param( 'prompt' );

        if ( is_array( $prompt ) || is_object( $prompt ) ) {
            $prompt = '';
        }

        $prompt = is_string( $prompt ) || is_numeric( $prompt ) ? (string) $prompt : '';
        $prompt = function_exists( 'wp_unslash' ) ? wp_unslash( $prompt ) : $prompt;
        $prompt = function_exists( 'sanitize_textarea_field' )
            ? sanitize_textarea_field( $prompt )
            : trim( wp_strip_all_tags( $prompt ) );
        $prompt = trim( $prompt );

        return '' !== $prompt ? $prompt : $fallback;
    }

    function factory_rest_get_real_estate_prompt_context( WP_REST_Request $request, array $blueprint, string $fallback_prompt ): array {
        $defaults = factory_rest_get_real_estate_variable_defaults( $blueprint );
        $received = $request->get_param( 'preset_variables' );
        $allowed  = factory_rest_get_real_estate_variable_schema();
        $sanitized = [];
        $applied   = [];
        $notes     = [
            'Prepared Real Estate preset is used as the base.',
            'Only whitelisted copy fields are overlaid.',
            'No schema, filters, forms, property data, media, or page topology changes are applied.',
        ];

        if ( ! is_array( $received ) ) {
            $received = [];
        }

        foreach ( $received as $key => $value ) {
            if ( ! isset( $allowed[ $key ] ) ) {
                $notes[] = "Ignored unsupported preset variable: {$key}";
            }
        }

        foreach ( $allowed as $key => $schema ) {
            $default = $defaults[ $key ] ?? '';
            $value   = $received[ $key ] ?? '';
            $value   = factory_rest_sanitize_preset_variable( $value, $schema );

            if ( '' === $value ) {
                $value = $default;
                $notes[] = "Used preset default for {$key}.";
            }

            $sanitized[ $key ] = $value;
            $applied[ $key ]   = $value;
        }

        return [
            'prompt'            => factory_rest_get_beta_prompt( $request, $fallback_prompt ),
            'preset_variables'  => $sanitized,
            'applied_variables' => $applied,
            'notes'             => array_values( array_unique( $notes ) ),
        ];
    }

    function factory_rest_get_real_estate_variable_schema(): array {
        return [
            'agency_name'   => [
                'max'       => 80,
                'sanitizer' => 'text',
            ],
            'hero_title'    => [
                'max'       => 120,
                'sanitizer' => 'text',
            ],
            'hero_subtitle' => [
                'max'       => 240,
                'sanitizer' => 'textarea',
            ],
            'contact_title' => [
                'max'       => 120,
                'sanitizer' => 'text',
            ],
            'contact_intro' => [
                'max'       => 400,
                'sanitizer' => 'textarea',
            ],
        ];
    }

    function factory_rest_sanitize_preset_variable( $value, array $schema ): string {
        if ( is_array( $value ) || is_object( $value ) ) {
            return '';
        }

        $value = is_string( $value ) || is_numeric( $value ) ? (string) $value : '';
        $value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
        $value = 'textarea' === ( $schema['sanitizer'] ?? 'text' ) && function_exists( 'sanitize_textarea_field' )
            ? sanitize_textarea_field( $value )
            : sanitize_text_field( $value );
        $value = trim( $value );
        $max   = max( 1, (int) ( $schema['max'] ?? 120 ) );

        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $max );
        }

        return substr( $value, 0, $max );
    }

    function factory_rest_get_real_estate_variable_defaults( array $blueprint ): array {
        $home         = is_array( $blueprint['pages']['home'] ?? null ) ? $blueprint['pages']['home'] : [];
        $contact      = is_array( $blueprint['pages']['contact'] ?? null ) ? $blueprint['pages']['contact'] : [];
        $hero_section = factory_rest_find_real_estate_home_section( $home, 'hero' );

        return [
            'agency_name'   => (string) ( $blueprint['site']['name'] ?? $home['title'] ?? 'Kyiv Turquoise Realty' ),
            'hero_title'    => (string) ( $hero_section['title'] ?? $home['title'] ?? 'Kyiv Turquoise Realty' ),
            'hero_subtitle' => (string) ( $hero_section['subtitle'] ?? 'Find apartments, houses, and commercial spaces in Kyiv' ),
            'contact_title' => (string) ( $contact['title'] ?? 'Contact Kyiv Turquoise Realty' ),
            'contact_intro' => (string) ( $contact['text'] ?? 'Schedule a viewing or request more details about Kyiv properties.' ),
        ];
    }

    function factory_rest_find_real_estate_home_section( array $home, string $type ): array {
        foreach ( $home['sections'] ?? [] as $section ) {
            if ( is_array( $section ) && $type === ( $section['type'] ?? '' ) ) {
                return $section;
            }
        }

        return [];
    }

    function factory_rest_apply_real_estate_preset_variables( array $blueprint, array $variables ): array {
        if ( isset( $variables['agency_name'] ) ) {
            $blueprint['site']['name'] = $variables['agency_name'];
            $blueprint['pages']['home']['title'] = $variables['agency_name'];
        }

        foreach ( $blueprint['pages']['home']['sections'] ?? [] as $index => $section ) {
            if ( ! is_array( $section ) || 'hero' !== ( $section['type'] ?? '' ) ) {
                continue;
            }

            if ( isset( $variables['hero_title'] ) ) {
                $blueprint['pages']['home']['sections'][ $index ]['title'] = $variables['hero_title'];
            }

            if ( isset( $variables['hero_subtitle'] ) ) {
                $blueprint['pages']['home']['sections'][ $index ]['subtitle'] = $variables['hero_subtitle'];
            }

            break;
        }

        if ( isset( $variables['contact_title'] ) ) {
            $blueprint['pages']['contact']['title'] = $variables['contact_title'];
        }

        if ( isset( $variables['contact_intro'] ) ) {
            $blueprint['pages']['contact']['text'] = $variables['contact_intro'];
        }

        return $blueprint;
    }

    function factory_rest_build_plan( array $blueprint ): array {
        $dry_run = new Factory_Dry_Run_Command();
        $items   = $dry_run->get_plan_items( $blueprint );

        return [
            'version' => 1,
            'summary' => factory_rest_plan_summary( $items ),
            'items'   => $items,
        ];
    }

    function factory_rest_plan_summary( array $items ): array {
        $summary = [
            'create'  => 0,
            'update'  => 0,
            'skip'    => 0,
            'warning' => 0,
            'error'   => 0,
        ];

        foreach ( $items as $item ) {
            $action = $item['action'] ?? 'skip';

            if ( isset( $summary[ $action ] ) ) {
                $summary[ $action ]++;
            }
        }

        return $summary;
    }

    function factory_rest_build_real_estate_product_plan(
        array $blueprint,
        array $plan,
        array $dependencies,
        string $prompt = '',
        array $prompt_context = []
    ): array {
        $property_count = isset( $blueprint['content']['property'] ) && is_array( $blueprint['content']['property'] )
            ? count( $blueprint['content']['property'] )
            : 0;

        $asset_pools = $blueprint['site']['assets']['property_images'] ?? [];
        $asset_labels = [];

        if ( is_array( $asset_pools ) ) {
            foreach ( $asset_pools as $type => $sources ) {
                if ( ! is_string( $type ) || '' === trim( $type ) ) {
                    continue;
                }

                $count = is_array( $sources ) ? count( $sources ) : ( is_string( $sources ) && '' !== trim( $sources ) ? 1 : 0 );
                $asset_labels[] = sprintf( '%s image pool (%d)', $type, $count );
            }
        }

        $summary = $plan['summary'] ?? [];
        $dependency_items = [];

        foreach ( [ 'jet_engine' => 'JetEngine', 'kava' => 'Kava theme' ] as $key => $label ) {
            $dependency = $dependencies[ $key ] ?? [];
            $active = ! empty( $dependency['active'] );
            $installed = ! empty( $dependency['installed'] );

            $dependency_items[] = $active
                ? "{$label} active"
                : ( $installed ? "{$label} installed but inactive" : "{$label} missing" );
        }

        $jetformbuilder = $dependencies['jetformbuilder'] ?? [];
        $dependency_items[] = ! empty( $jetformbuilder['available'] )
            ? 'JetFormBuilder available for Request Viewing form'
            : 'JetFormBuilder optional: Request Viewing fallback will be used';

        $dependency_status = ! empty( $dependencies['ready'] )
            ? 'ready'
            : 'warning';
        $applied_variables = is_array( $prompt_context['applied_variables'] ?? null )
            ? $prompt_context['applied_variables']
            : [];
        $prompt_notes = is_array( $prompt_context['notes'] ?? null )
            ? $prompt_context['notes']
            : [];
        $variable_items = [];

        foreach ( $applied_variables as $key => $value ) {
            $label = ucwords( str_replace( '_', ' ', (string) $key ) );
            $variable_items[] = "{$label}: {$value}";
        }

        return [
            'title'    => 'Real Estate Demo Plan',
            'mode'     => 'Prepared Real Estate preset with safe copy variables',
            'summary'  => 'Generate a Kyiv real estate website with catalog, properties, images, filters, single pages, contact page, and validation proof. The prompt is captured in the run manifest; only explicit safe copy variables are overlaid onto the prepared preset.',
            'sections' => [
                [
                    'label'  => 'Prompt context',
                    'status' => 'ready',
                    'items'  => [
                        '' !== $prompt ? "Captured prompt: {$prompt}" : 'No custom prompt supplied',
                        'Prompt is recorded for this beta run',
                        'Free-prose prompt parsing is not enabled in Prompt Testing v1',
                    ],
                ],
                [
                    'label'  => 'Applied safe variables',
                    'status' => 'ready',
                    'items'  => empty( $variable_items )
                        ? [ 'No safe preset variables supplied' ]
                        : array_merge(
                            $variable_items,
                            $prompt_notes
                        ),
                ],
                [
                    'label'  => 'Guardrails',
                    'status' => 'ready',
                    'items'  => [
                        'No CPT, taxonomy, meta, filter, form, query, listing, media, or property content schema changes',
                        'No property count, district, taxonomy term, image, native filter, or form schema changes',
                        'Prepared Real Estate preset remains the deterministic base',
                    ],
                ],
                [
                    'label'  => 'Site structure',
                    'status' => 'ready',
                    'items'  => [
                        'Home page',
                        'Properties catalog',
                        'Contact page',
                        'Navigation menu',
                    ],
                ],
                [
                    'label'  => 'Data model',
                    'status' => 'ready',
                    'items'  => [
                        'Property CPT',
                        'Purpose taxonomy',
                        'Property Type taxonomy',
                        'District taxonomy',
                        'Price/address/bedrooms/bathrooms/size fields',
                    ],
                ],
                [
                    'label'  => 'Content',
                    'status' => 'ready',
                    'items'  => [
                        "{$property_count} Kyiv properties",
                        'Sale and rent listings',
                        'Apartment, house, and commercial types',
                    ],
                ],
                [
                    'label'  => 'Media',
                    'status' => empty( $asset_labels ) ? 'warning' : 'ready',
                    'items'  => empty( $asset_labels )
                        ? [ 'Property image pools not configured' ]
                        : $asset_labels,
                ],
                [
                    'label'  => 'Frontend features',
                    'status' => 'ready',
                    'items'  => [
                        'Catalog cards',
                        'GET filters',
                        'Single property pages',
                        'Contact agency CTA',
                        'Request Viewing fallback/form section',
                    ],
                ],
                [
                    'label'  => 'Dependencies',
                    'status' => $dependency_status,
                    'items'  => $dependency_items,
                ],
                [
                    'label'  => 'Proof',
                    'status' => 'ready',
                    'items'  => [
                        'Execution trace',
                        'Validation checks',
                        'Run manifest',
                        sprintf(
                            'Current dry-run: %d create / %d update / %d unchanged',
                            (int) ( $summary['create'] ?? 0 ),
                            (int) ( $summary['update'] ?? 0 ),
                            (int) ( $summary['skip'] ?? 0 )
                        ),
                    ],
                ],
            ],
        ];
    }

    function factory_rest_get_real_estate_dependency_status(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins              = function_exists( 'get_plugins' ) ? get_plugins() : [];
        $jetengine_installed  = false;
        $jetengine_active     = false;
        $jfb_installed        = false;
        $jfb_active           = false;
        $kava_theme           = wp_get_theme( 'kava' );
        $kava_installed       = $kava_theme && $kava_theme->exists();
        $current_theme        = wp_get_theme();
        $kava_active          = $current_theme && 'kava' === $current_theme->get_stylesheet();

        foreach ( $plugins as $file => $data ) {
            if ( str_starts_with( $file, 'jet-engine/' ) ) {
                $jetengine_installed = true;

                if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
                    $jetengine_active = true;
                }
            }

            if ( 'jet-form-builder/jet-form-builder.php' === $file || str_starts_with( $file, 'jet-form-builder/' ) ) {
                $jfb_installed = true;

                if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
                    $jfb_active = true;
                }
            }
        }

        $jfb_available = $jfb_active
            || function_exists( 'jet_form_builder' )
            || defined( 'JET_FORM_BUILDER_VERSION' );

        if ( $jfb_available && function_exists( 'post_type_exists' ) ) {
            $jfb_available = post_type_exists( 'jet-form-builder' );
        }

        return [
            'ready'      => $jetengine_active && $kava_active,
            'jet_engine' => [
                'installed' => $jetengine_installed,
                'active'    => $jetengine_active,
                'status'    => $jetengine_active ? 'ok' : ( $jetengine_installed ? 'warning' : 'error' ),
            ],
            'kava'       => [
                'installed' => $kava_installed,
                'active'    => $kava_active,
                'status'    => $kava_active ? 'ok' : ( $kava_installed ? 'warning' : 'error' ),
            ],
            'jetformbuilder' => [
                'installed' => $jfb_installed,
                'active'    => $jfb_active,
                'available' => $jfb_available,
                'optional'  => true,
                'status'    => $jfb_available ? 'ok' : 'warning',
                'fallback'  => ! $jfb_available,
            ],
        ];
    }

    function factory_rest_beta_error_response( string $message, int $status = 500, array $extra = [] ): WP_REST_Response {
        return new WP_REST_Response(
            array_merge(
                [
                    'status'  => 'error',
                    'message' => $message,
                ],
                $extra
            ),
            $status
        );
    }

    function factory_rest_index(): WP_REST_Response {

        return new WP_REST_Response(
            [
                'name'        => 'Crocoblock Site Factory API',
                'version'     => '1.0',
                'status'      => 'active',
                'endpoints'   => [
                '/summary',
                '/doctor',
                '/runs',
                '/run/latest',
                '/run/{file}',
                '/explain/latest',
                '/index',
                '/capabilities',
                '/adapters',
                '/beta/real-estate/plan',
                '/beta/real-estate/apply',
                ],
                'description' => 'Runtime inspection and orchestration API for Factory.',
            ]
        );
    }

    function factory_rest_explain_latest(): WP_REST_Response {

        $latest = factory_get_latest_run_name();

        if ( ! $latest ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'No runs found.',
                ],
                404
            );
        }

        $run = factory_get_run_manifest( $latest );

        if ( ! is_array( $run ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Invalid run manifest.',
                ],
                500
            );
        }

        $blueprint = $run['blueprint'] ?? [];

        $response = [
            'site'         => $blueprint['site']['name'] ?? '',
            'cpt'          => [],
            'taxonomies'   => [],
            'listings'     => [],
            'archive'      => '',
            'demo_content' => [],
        ];

        foreach ( $blueprint['cpt'] ?? [] as $cpt ) {

            $response['cpt'][] = [
                'slug' => $cpt['slug'] ?? '',
                'meta' => array_map(
                    static fn( $field ) => $field['key'] ?? '',
                    $cpt['meta'] ?? []
                ),
            ];
        }

        foreach ( $blueprint['taxonomies'] ?? [] as $taxonomy ) {

            $response['taxonomies'][] =
                $taxonomy['slug'] ?? '';
        }

        foreach ( $blueprint['listings'] ?? [] as $listing ) {

            $response['listings'][] =
                $listing['title'] ?? '';
        }

        $archive =
            $blueprint['pages']['archive']['slug']
            ?? '';

        if ( $archive ) {
            $response['archive'] =
                '/' . trim( $archive, '/' ) . '/';
        }

        foreach (
            $blueprint['content'] ?? []
            as $items
        ) {

            foreach ( $items as $item ) {

                $response['demo_content'][] =
                    $item['title'] ?? '';
            }
        }

        return new WP_REST_Response( $response );
    }

function factory_rest_summary(): WP_REST_Response {

	$latest = factory_get_latest_run_name();

	if ( ! $latest ) {
		return new WP_REST_Response(
			[
				'status'  => 'error',
				'message' => 'No runs found.',
			],
			404
		);
	}

	$run = factory_get_run_manifest( $latest );

	if ( ! is_array( $run ) ) {
		return new WP_REST_Response(
			[
				'status'  => 'error',
				'message' => 'Invalid run manifest.',
			],
			500
		);
	}

	$blueprint = $run['blueprint'] ?? [];

	$current = factory_validate_blueprint_state(
		$blueprint,
		false
	);

	$state = ( $current['status'] ?? 'error' ) === 'ok'
		? 'IN SYNC'
		: 'DRIFT';

	$cpt_count = count( $blueprint['cpt'] ?? [] );

	$taxonomy_count = count(
		$blueprint['taxonomies'] ?? []
	);

	$listing_count = count(
		$blueprint['listings'] ?? []
	);

	$content_count = 0;

	foreach ( $blueprint['content'] ?? [] as $items ) {
		if ( is_array( $items ) ) {
			$content_count += count( $items );
		}
	}

	return new WP_REST_Response(
		[
			'status'         => $state,
			'latest_run'     => $latest,
			'site'           => $blueprint['site']['name'] ?? '-',
			'cpt_count'      => $cpt_count,
			'taxonomy_count' => $taxonomy_count,
			'listing_count'  => $listing_count,
			'content_count'  => $content_count,
			'doctor'         => $state === 'IN SYNC'
				? 'healthy'
				: 'issues detected',
		]
	);
}

function factory_rest_doctor(): WP_REST_Response {

	$latest = factory_get_latest_run_name();

	if ( ! $latest ) {
		return new WP_REST_Response(
			[
				'status'  => 'error',
				'message' => 'No runs found.',
			],
			404
		);
	}

	$run = factory_get_run_manifest( $latest );

	if ( ! is_array( $run ) ) {
		return new WP_REST_Response(
			[
				'status'  => 'error',
				'message' => 'Invalid run manifest.',
			],
			500
		);
	}

	$blueprint = $run['blueprint'] ?? [];

	$current = factory_validate_blueprint_state(
		$blueprint,
		false
	);

	$issues = [];

	foreach ( $current['checks'] ?? [] as $check ) {
		if ( ( $check['status'] ?? '' ) === 'ok' ) {
			continue;
		}

		$issues[] = [
			'status'  => $check['status'] ?? 'error',
			'message' => $check['message'] ?? '',
		];
	}

	return new WP_REST_Response(
		[
			'status'     => $current['status'] ?? 'error',
			'latest_run' => $latest,
			'prompt'     => $run['prompt'] ?? '',
			'issues'     => $issues,
		]
	);
}

    function factory_rest_latest_run(): WP_REST_Response {

        $latest = factory_get_latest_run_name();

        if ( ! $latest ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'No runs found.',
                ],
                404
            );
        }

        $run = factory_get_run_manifest( $latest );

        if ( ! is_array( $run ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Invalid run manifest.',
                ],
                500
            );
        }

        return new WP_REST_Response(
            [
                'status' => 'ok',
                'run'    => factory_rest_enrich_run_manifest( $run, $latest ),
            ]
        );
    }

    function factory_rest_run( WP_REST_Request $request ): WP_REST_Response {

        $file = (string) $request->get_param( 'file' );

        if ( ! factory_rest_is_safe_run_file( $file ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Invalid run file.',
                ],
                400
            );
        }

        $run = factory_get_run_manifest( $file );

        if ( ! is_array( $run ) ) {
            return new WP_REST_Response(
                [
                    'status'  => 'error',
                    'message' => 'Run file not found or invalid.',
                ],
                404
            );
        }

        return new WP_REST_Response(
            [
                'status' => 'ok',
                'run'    => factory_rest_enrich_run_manifest( $run, $file ),
            ]
        );
    }

    function factory_rest_is_safe_run_file( string $file ): bool {
        if ( '' === $file ) {
            return false;
        }

        if (
            str_contains( $file, '/' ) ||
            str_contains( $file, '\\' ) ||
            str_contains( $file, '..' )
        ) {
            return false;
        }

        if ( basename( $file ) !== $file ) {
            return false;
        }

        return 1 === preg_match( '/^run-[A-Za-z0-9_.-]+\.json$/', $file );
    }

    function factory_rest_enrich_run_manifest( array $run, string $file ): array {
        $run['file'] = $file;

        if ( ! isset( $run['plan'] ) || ! is_array( $run['plan'] ) ) {
            $run['plan'] = [];
        }

        if ( ! isset( $run['plan']['version'] ) || null === $run['plan']['version'] ) {
            $run['plan']['version'] = 1;
        }

        if ( ! isset( $run['plan']['summary'] ) || ! is_array( $run['plan']['summary'] ) ) {
            $run['plan']['summary'] = [];
        }

        if ( ! isset( $run['plan']['items'] ) || ! is_array( $run['plan']['items'] ) ) {
            $run['plan']['items'] = [];
        }

        if ( ! isset( $run['execution'] ) || ! is_array( $run['execution'] ) ) {
            $run['execution'] = [];
        }

        if ( ! isset( $run['execution']['version'] ) || null === $run['execution']['version'] ) {
            $run['execution']['version'] = 1;
        }

        if ( ! isset( $run['execution']['items'] ) || ! is_array( $run['execution']['items'] ) ) {
            $run['execution']['items'] = [];
        }

        $run['execution']['count'] = count( $run['execution']['items'] );

        if ( ! isset( $run['results'] ) || ! is_array( $run['results'] ) ) {
            $run['results'] = [];
        }

        if ( ! isset( $run['results']['version'] ) || null === $run['results']['version'] ) {
            $run['results']['version'] = 1;
        }

        if ( ! isset( $run['results']['source'] ) || null === $run['results']['source'] ) {
            $run['results']['source'] = '';
        }

        if ( ! isset( $run['results']['summary'] ) || ! is_array( $run['results']['summary'] ) ) {
            $run['results']['summary'] = [];
        }

        if ( ! isset( $run['results']['items'] ) || ! is_array( $run['results']['items'] ) ) {
            $run['results']['items'] = [];
        }

        if ( ! isset( $run['validation'] ) || ! is_array( $run['validation'] ) ) {
            $run['validation'] = [];
        }

        if ( ! isset( $run['validation']['status'] ) || null === $run['validation']['status'] ) {
            $run['validation']['status'] = '';
        }

        if ( ! isset( $run['validation']['checks'] ) || ! is_array( $run['validation']['checks'] ) ) {
            $run['validation']['checks'] = [];
        }

        $run['validation']['count'] = count( $run['validation']['checks'] );

        return $run;
    }

function factory_rest_runs( WP_REST_Request $request ): WP_REST_Response {

	$registry = factory_get_runs_registry();

	if ( empty( $registry ) ) {
		return new WP_REST_Response(
			[
				'status'  => 'error',
				'message' => 'Run registry not found.',
				'runs'    => [],
			],
			404
		);
	}

	$runs = $registry['runs'] ?? [];

	if ( $request->get_param( 'latest' ) ) {
		$latest = $registry['latest'] ?? '';

		$runs = array_values(
			array_filter(
				$runs,
				static fn( $run ) => ( $run['file'] ?? '' ) === $latest
			)
		);
	}

	if ( $request->get_param( 'failed' ) ) {
		$runs = array_values(
			array_filter(
				$runs,
				static fn( $run ) => ( $run['status'] ?? '' ) !== 'ok'
			)
		);
	}

	$limit = (int) $request->get_param( 'limit' );

	if ( $limit > 0 ) {
		$runs = array_slice(
			$runs,
			0,
			$limit
		);
	}

	$rows = [];

	foreach ( $runs as $run ) {
		$plan_summary = $run['plan_summary'] ?? [];

		if ( ! is_array( $plan_summary ) ) {
			$plan_summary = [];
		}

		$results_summary = $run['results_summary'] ?? [];

		if ( ! is_array( $results_summary ) ) {
			$results_summary = [];
		}

		$rows[] = [
			'file'             => $run['file'] ?? '',
			'timestamp'        => $run['timestamp'] ?? '',
			'status'           => $run['status'] ?? '',
			'preset'           => $run['preset'] ?? '',
			'prompt'           => $run['prompt'] ?? '',
			'plan_summary'     => $plan_summary,
			'execution_count'  => isset( $run['execution_count'] ) ? (int) $run['execution_count'] : 0,
			'validation_count' => isset( $run['validation_count'] ) ? (int) $run['validation_count'] : 0,
			'results_summary'  => $results_summary,
		];
	}

	return new WP_REST_Response(
		[
			'status' => 'ok',
			'latest' => $registry['latest'] ?? null,
			'runs'   => $rows,
		]
	);
}

if ( did_action( 'rest_api_init' ) ) {
	factory_register_rest_routes();
}
