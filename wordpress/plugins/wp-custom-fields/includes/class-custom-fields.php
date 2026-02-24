<?php

declare(strict_types=1);

namespace WpCustomFields;

if (! defined('ABSPATH')) {
    exit;
}

class CustomFields
{
    /**
     * Meta key → config mapping.
     * Alle Felder fuer register_post_meta().
     */
    private const FIELDS = [
        'hero_headline' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'heroHeadline',
            'graphql_type'      => 'String',
            'description'       => 'Hero section headline',
        ],
        'hero_subline' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'heroSubline',
            'graphql_type'      => 'String',
            'description'       => 'Hero section subline',
        ],
        'hero_cta_text' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'heroCtaText',
            'graphql_type'      => 'String',
            'description'       => 'Hero CTA button text',
        ],
        'hero_cta_link' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'graphql_field'     => 'heroCtaLink',
            'graphql_type'      => 'String',
            'description'       => 'Hero CTA button URL',
        ],
        'hero_background_image' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'graphql_field'     => 'heroBackgroundImage',
            'graphql_type'      => 'String',
            'description'       => 'Hero background image URL',
        ],
        'seo_meta_description' => [
            'post_types'        => ['page', 'post'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'seoMetaDescription',
            'graphql_type'      => 'String',
            'description'       => 'SEO meta description',
        ],
    ];

    /**
     * Registriert alle Custom Fields via register_post_meta().
     * Hook: init
     */
    public static function register_post_meta_fields(): void
    {
        foreach (self::FIELDS as $meta_key => $config) {
            foreach ($config['post_types'] as $post_type) {
                register_post_meta($post_type, $meta_key, [
                    'type'              => $config['type'],
                    'single'            => true,
                    'show_in_rest'      => true,
                    'show_in_graphql'   => true,
                    'sanitize_callback' => $config['sanitize_callback'],
                    'auth_callback'     => fn() => current_user_can('edit_posts'),
                ]);
            }
        }
    }

    /**
     * Registriert GraphQL-Felder via register_graphql_field().
     * Hook: graphql_register_types
     */
    public static function register_graphql_fields(): void
    {
        foreach (self::FIELDS as $meta_key => $config) {
            $graphql_types = array_map(
                fn(string $pt) => $pt === 'post' ? 'Post' : 'Page',
                $config['post_types']
            );

            // Deduplizieren (seo_meta_description gilt fuer page UND post)
            $graphql_types = array_unique($graphql_types);

            foreach ($graphql_types as $graphql_type) {
                register_graphql_field($graphql_type, $config['graphql_field'], [
                    'type'        => $config['graphql_type'],
                    'description' => $config['description'],
                    'resolve'     => function ($object) use ($meta_key): ?string {
                        $value = get_post_meta($object->databaseId, $meta_key, true);
                        return $value !== '' ? $value : null;
                    },
                ]);
            }
        }
    }
}
