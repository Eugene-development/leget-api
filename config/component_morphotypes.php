<?php

declare(strict_types=1);

/**
 * Морфотипы версий компонентов: «шаблон → страница → тип → версия → конструкция».
 *
 * ФАЙЛ СГЕНЕРИРОВАН — не редактируйте руками, правки затрёт следующая генерация.
 * Источник: scripts/build-component-morphotypes.mjs
 * Пересборка: node scripts/build-component-morphotypes.mjs
 *
 * morph — что блок собой представляет по устройству, независимо от того, подо
 * что его использует тенант. Грамматика и полная таблица:
 * docs/architecture/component-morphotypes.md
 *
 * roles — назначения, которые эта конструкция способна исполнить. Это
 * ВОЗМОЖНОСТИ, а не выбор: что тенант выбрал на самом деле, лежит в
 * page_components.role_slug. Каждый slug обязан существовать в
 * config/component_roles.php — сеятель проверяет и падает, если нет.
 *
 * Версии, которых нет в каталоге (layout-компоненты объявлены не всеми
 * версиями), сеятель молча пропускает и считает отдельной строкой отчёта.
 */
return [
    // Promo-1
    1 => [
        '/' => [
            'HeroMain' => [
                1 => [
                    'morph' => 'overlay(photo) : actions + logos.rail.n.logo.carousel', // 1.1.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner', 'brands', 'partners', 'clients'],
                ],
                2 => [
                    'morph' => 'split(photo).picker : cards.grid.3-6.icon + logos.row.n.logo', // 1.1.1.2
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
                3 => [
                    'morph' => 'split(photo).picker : logos.row.n.logo', // 1.1.1.3
                    'roles' => ['brands', 'partners', 'clients'],
                ],
                4 => [
                    'morph' => 'split(photo).picker : logos.row.n.logo', // 1.1.1.4
                    'roles' => ['brands', 'partners', 'clients'],
                ],
            ],
            'Message' => [
                1 => [
                    'morph' => 'plain : text + cards.grid.2-4.photo', // 1.1.2.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro', 'benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
                2 => [
                    'morph' => 'split : text + cards.grid.2-4.photo', // 1.1.2.2
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro', 'benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'PromoOffer' => [
                1 => [
                    'morph' => 'split(photo) : list.row.3-6.icon', // 1.1.3.1
                    'roles' => ['stats', 'contacts', 'badges'],
                ],
                2 => [
                    'morph' => 'split(photo) : list.row.3-6.icon', // 1.1.3.2
                    'roles' => ['stats', 'contacts', 'badges'],
                ],
            ],
            'Equipment' => [
                1 => [
                    'morph' => 'plain : cards.mosaic.=5.photo', // 1.1.4.1
                    'roles' => ['gallery', 'categories', 'products', 'testimonials'],
                ],
                2 => [
                    'morph' => 'plain : cards.mosaic.=5.photo', // 1.1.4.2
                    'roles' => ['gallery', 'categories', 'products', 'testimonials'],
                ],
            ],
            'Stage' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.1.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
                2 => [
                    'morph' => 'aside : list.stack.3-6.icon/ord', // 1.1.5.2
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Incentives' => [
                1 => [
                    'morph' => 'split : cards.mosaic.=4.photo', // 1.1.6.1
                    'roles' => ['gallery', 'categories', 'products', 'testimonials'],
                ],
                2 => [
                    'morph' => 'split(photo).tabs : list.stack.=4.none', // 1.1.6.2
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
                3 => [
                    'morph' => 'plain : list.stack.=4.photo', // 1.1.6.3
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Direction' => [
                1 => [
                    'morph' => 'plain : tiles.grid.=2.photo', // 1.1.7.1
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
                2 => [
                    'morph' => 'plain : tiles.grid.=2.photo', // 1.1.7.2
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
            ],
            'Brands' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.logo + logos.grid.n.logo', // 1.1.8.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
                2 => [
                    'morph' => 'plain : cards.grid.3-6.logo + logos.grid.n.logo', // 1.1.8.2
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'PartnerOffers' => [
                1 => [
                    'morph' => 'plain : tiles.grid.=2.photo', // 1.1.9.1
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
            ],
        ],
        '/about' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 1.2.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'Statistics' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.metric', // 1.2.2.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'Mission' => [
                1 => [
                    'morph' => 'split(photo) : text', // 1.2.3.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'Values' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon/ord', // 1.2.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'WhyUs' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 1.2.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'AboutCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.2.6.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'Text' => [
                1 => [
                    'morph' => 'plain : text', // 1.2.7.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
        ],
        '/contact' => [
            'ContactForm' => [
                1 => [
                    'morph' => 'plain : fields.stack.3-4.none.submit', // 1.3.1.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
        ],
        '/actions' => [
            'Hero' => [
                1 => [
                    'morph' => 'split : list.rail.n.none.carousel', // 1.4.1.1
                    'roles' => ['badges', 'promo'],
                ],
            ],
            'ActionsCards' => [
                1 => [
                    'morph' => 'plain : cards.grid.4-8.icon', // 1.4.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ActionsCardsExtra' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.4.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ActionsBanner' => [
                1 => [
                    'morph' => 'plain : media(photo)', // 1.4.4.1
                    'roles' => ['banner', 'gallery', 'map', 'video'],
                ],
            ],
            'ActionsCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.4.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
                2 => [
                    'morph' => 'split : actions', // 1.4.5.2
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/contacts' => [
            'ContactsHero' => [
                1 => [
                    'morph' => 'plain : text', // 1.5.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
                2 => [
                    'morph' => 'plain : text', // 1.5.1.2
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'ContactChannels' => [
                1 => [
                    'morph' => 'plain : cards.grid.=3.icon', // 1.5.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ContactAddress' => [
                1 => [
                    'morph' => 'split(map) : list.stack.=3.icon', // 1.5.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'ContactMessengers' => [
                1 => [
                    'morph' => 'split : actions', // 1.5.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'ContactCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.5.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
                2 => [
                    'morph' => 'split : actions', // 1.5.5.2
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/partnership' => [
            'PartnershipHero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 1.6.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'WhoWeInvite' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 1.6.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ForManufacturers' => [
                1 => [
                    'morph' => 'split : list.stack.3-6.icon + list.grid.3-6.metric', // 1.6.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Benefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon/ord', // 1.6.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'HowToStart' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 1.6.5.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'PartnershipCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.6.6.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/testimonials' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 1.7.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'TestimonialsGrid' => [
                1 => [
                    'morph' => 'plain : cards.mosaic.n.none', // 1.7.2.1
                    'roles' => ['gallery', 'categories', 'products', 'testimonials'],
                ],
            ],
            'TestimonialsSummary' => [
                1 => [
                    'morph' => 'split : list.grid.2-4.metric', // 1.7.3.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
        ],
        '/installment' => [
            'InstallmentHero' => [
                1 => [
                    'morph' => 'plain : text', // 1.8.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'InstallmentPlans' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.metric', // 1.8.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'InstallmentRequirements' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon/ord', // 1.8.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'InstallmentSteps' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 1.8.4.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'InstallmentBanks' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none', // 1.8.5.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'InstallmentFAQ' => [
                1 => [
                    'morph' => 'plain : list.stack.3-6.none/ord', // 1.8.6.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'InstallmentCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.8.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/guarantees' => [
            'GuaranteesHero' => [
                1 => [
                    'morph' => 'plain : text', // 1.9.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'GuaranteeTerms' => [
                1 => [
                    'morph' => 'plain : cards.grid.4-8.icon', // 1.9.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'WhatsCovered' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.2-4.icon', // 1.9.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'HowToApply' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 1.9.4.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'GuaranteesCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.9.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/yandex-direct' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : actions', // 1.10.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'USP' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 1.10.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Advantages' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.metric + list.stack.4-8.icon', // 1.10.3.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'Steps' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.icon/ord', // 1.10.4.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'SocialProof' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 1.10.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Offer' => [
                1 => [
                    'morph' => 'split : list.stack.3-6.icon', // 1.10.6.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.10.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/consultation' => [
            'ConsultationHero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 1.11.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'ConsultationFeatures' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.11.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ConsultationWhy' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.3-6.metric', // 1.11.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'ConsultationCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.11.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/design-project' => [
            'DesignProjectHero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 1.12.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'DesignProjectFeatures' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.12.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'DesignProjectWhy' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.3-6.metric', // 1.12.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'DesignProjectCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.12.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/measurement' => [
            'MeasurementHero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 1.13.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'MeasurementFeatures' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.13.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'MeasurementWhy' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.3-6.metric', // 1.13.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'MeasurementCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.13.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/furniture-project' => [
            'FurnitureProjectHero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 1.14.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'FurnitureProjectFeatures' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.14.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'FurnitureProjectWhy' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.3-6.metric', // 1.14.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'FurnitureProjectCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.14.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/assembly' => [
            'AssemblyHero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 1.15.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'AssemblyFeatures' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.15.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'AssemblyWhy' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.3-6.metric', // 1.15.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'AssemblyCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.15.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/mebel' => [
            'MebelSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.16.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'MebelHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.16.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'MebelBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.16.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
                2 => [
                    'morph' => 'plain : list.stack.3-6.icon', // 1.16.3.2
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'MebelSolutions' => [
                1 => [
                    'morph' => 'plain : tiles.grid.2-4.photo', // 1.16.4.1
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
            ],
            'MebelProcess' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 1.16.5.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
                2 => [
                    'morph' => 'plain : list.stack.2-4.none/ord', // 1.16.5.2
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'MebelCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.16.6.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/mebel/{category}' => [
            'MebelSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.17.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'MebelCategoryHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.17.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'MebelProjectsGrid' => [
                1 => [
                    'morph' => 'plain : cards.grid.n.photo.pager', // 1.17.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'MebelBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.17.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
                2 => [
                    'morph' => 'plain : list.stack.3-6.icon', // 1.17.4.2
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'MebelCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.17.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/mebel/{category}/{project}' => [
            'MebelSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.18.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'MebelProjectHero' => [
                1 => [
                    'morph' => 'split(photo).picker : fields.stack.3-6.none.submit', // 1.18.2.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
            'MebelProjectDescription' => [
                1 => [
                    'morph' => 'plain : text', // 1.18.3.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'MebelProjectSimilar' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.photo', // 1.18.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'MebelCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.18.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/stoleshnica' => [
            'StoleshnicaSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.19.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'StoleshnicaHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.19.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'StoleshnicaMaterials' => [
                1 => [
                    'morph' => 'plain : table.stack.n.none', // 1.19.3.1
                    'roles' => ['specs', 'pricing', 'compare'],
                ],
            ],
            'StoleshnicaBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.19.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'StoleshnicaSolutions' => [
                1 => [
                    'morph' => 'plain : tiles.grid.2-4.photo', // 1.19.5.1
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
            ],
            'StoleshnicaServices' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 1.19.6.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'StoleshnicaCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.19.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/bytovaya-tehnika' => [
            'ByttehnikaSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.20.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'ByttehnikaHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.20.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'ByttehnikaBrands' => [
                1 => [
                    'morph' => 'plain : logos.grid.4-12.logo', // 1.20.3.1
                    'roles' => ['brands', 'partners', 'clients', 'banks'],
                ],
            ],
            'ByttehnikaBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.20.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ByttehnikaCategories' => [
                1 => [
                    'morph' => 'plain : tiles.grid.2-4.photo', // 1.20.5.1
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
            ],
            'ByttehnikaComplex' => [
                1 => [
                    'morph' => 'plain : list.stack.3-6.icon', // 1.20.6.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'ByttehnikaCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.20.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/santehnika' => [
            'SantehnikaSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.21.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'SantehnikaHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.21.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'SantehnikaBrands' => [
                1 => [
                    'morph' => 'plain : logos.grid.4-12.logo', // 1.21.3.1
                    'roles' => ['brands', 'partners', 'clients', 'banks'],
                ],
            ],
            'SantehnikaSinkTypes' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.21.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'SantehnikaCategories' => [
                1 => [
                    'morph' => 'plain : tiles.grid.2-4.photo', // 1.21.5.1
                    'roles' => ['categories', 'directions', 'promo', 'gallery'],
                ],
            ],
            'SantehnikaBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.21.6.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'SantehnikaComplex' => [
                1 => [
                    'morph' => 'plain : list.stack.3-6.icon', // 1.21.7.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'SantehnikaCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.21.8.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/furnitura' => [
            'FurnituraSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.22.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'FurnituraHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.22.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'FurnituraShops' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo + logos.row.n.logo', // 1.22.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'FurnituraCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.22.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/plitka' => [
            'PliitkaSidebar' => [
                1 => [
                    'morph' => 'dock : list.stack.n.none', // 1.23.1.1
                    'roles' => ['sidebar', 'catalog-nav'],
                ],
            ],
            'PliitkaHero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 1.23.2.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'PliitkaBrands' => [
                1 => [
                    'morph' => 'plain : logos.grid.4-12.logo', // 1.23.3.1
                    'roles' => ['brands', 'partners', 'clients', 'banks'],
                ],
            ],
            'PliitkaBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 1.23.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'PliitkaCategories' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 1.23.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'PliitkaComplex' => [
                1 => [
                    'morph' => 'plain : list.stack.3-6.icon', // 1.23.6.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'PliitkaCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 1.23.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/404' => [
            'NotFound' => [
                1 => [
                    'morph' => 'plain : actions', // 1.25.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '__global__' => [
            'Banner' => [
                1 => [
                    'morph' => 'bar : list.row.n.logo', // 1.Б.1.1
                    'roles' => ['header', 'promo-bar'],
                ],
                2 => [
                    'morph' => 'bar : list.row.n.logo', // 1.Б.1.2
                    'roles' => ['header', 'promo-bar'],
                ],
            ],
            'Header' => [
                1 => [
                    'morph' => 'bar : list.row.n.none.accordion', // 1.М.1.1
                    'roles' => ['header', 'promo-bar'],
                ],
                2 => [
                    'morph' => 'bar : list.row.n.none.accordion', // 1.М.1.2
                    'roles' => ['header', 'promo-bar'],
                ],
                3 => [
                    'morph' => 'bar : list.row.n.none.accordion', // 1.М.1.3
                    'roles' => ['header', 'promo-bar'],
                ],
            ],
            'Footer' => [
                1 => [
                    'morph' => 'plain : list.grid.n.none + fields.row.=1.none.submit', // 1.Ф.1.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
                2 => [
                    'morph' => 'split : list.grid.n.none', // 1.Ф.1.2
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
                3 => [
                    'morph' => 'split : list.grid.n.none', // 1.Ф.1.3
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
        ],
    ],
    // Promo-2
    2 => [
        '/' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(video) : actions', // 2.1.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'Styles' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo', // 2.1.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Advantages' => [
                1 => [
                    'morph' => 'split : list.grid.2-6.metric + list.stack.3-6.icon', // 2.1.3.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'Details' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.4-8.icon', // 2.1.4.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.1.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/about' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.2.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'LeaderSection' => [
                1 => [
                    'morph' => 'split(photo) : text', // 2.2.2.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'Mission' => [
                1 => [
                    'morph' => 'split : text + list.stack.2-4.metric', // 2.2.3.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro', 'steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Factory' => [
                1 => [
                    'morph' => 'plain : media(photo)', // 2.2.4.1
                    'roles' => ['banner', 'gallery', 'map', 'video'],
                ],
            ],
            'Video' => [
                1 => [
                    'morph' => 'plain : media(video)', // 2.2.5.1
                    'roles' => ['banner', 'gallery', 'map', 'video'],
                ],
            ],
            'Principles' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.none/ord', // 2.2.6.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'AboutCTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.2.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/news' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 2.3.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'NewsList' => [
                1 => [
                    'morph' => 'plain : list.stack.n.none.filter', // 2.3.2.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'NewsCTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.3.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/styles' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 2.4.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'StylesGrid' => [
                1 => [
                    'morph' => 'plain : cards.grid.n.photo', // 2.4.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'StylesCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 2.4.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/facades' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.5.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'FacadesCatalog' => [
                1 => [
                    'morph' => 'plain : cards.stack.3-6.photo', // 2.5.2.1
                    'roles' => ['products', 'services', 'process', 'catalog'],
                ],
            ],
            'FacadesCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 2.5.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/furniture' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.6.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'FurnitureIntro' => [
                1 => [
                    'morph' => 'plain : text', // 2.6.2.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'BrandsSection' => [
                1 => [
                    'morph' => 'plain : cards.stack.2-4.photo', // 2.6.3.1
                    'roles' => ['products', 'services', 'process', 'catalog'],
                ],
            ],
            'FurnitureCTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.6.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/contact' => [
            'ContactForm' => [
                1 => [
                    'morph' => 'plain : fields.stack.3-4.none.submit', // 2.7.1.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
            'Map' => [
                1 => [
                    'morph' => 'plain : media(map)', // 2.7.2.1
                    'roles' => ['banner', 'gallery', 'map', 'video'],
                ],
            ],
        ],
        '/actions' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.8.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'ActionsTimer' => [
                1 => [
                    'morph' => 'plain : list.row.=4.metric.timer', // 2.8.2.1
                    'roles' => ['stats', 'contacts', 'badges'],
                ],
            ],
            'ActionsCards' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo.filter', // 2.8.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'ActionsBanner' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.8.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'ActionsSteps' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 2.8.5.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'ActionsCTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.8.6.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/kitchens' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.9.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'KitchensGallery' => [
                1 => [
                    'morph' => 'plain : cards.rail.n.photo.carousel', // 2.9.2.1
                    'roles' => ['gallery', 'products', 'projects'],
                ],
            ],
            'ProductionCycle' => [
                1 => [
                    'morph' => 'plain : cards.stack.=4.photo', // 2.9.3.1
                    'roles' => ['products', 'services', 'process', 'catalog'],
                ],
            ],
            'KitchenStyles' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo', // 2.9.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'KitchensCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 2.9.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/wardrobes' => [
            'Hero' => [
                1 => [
                    'morph' => 'split(photo) : actions', // 2.10.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'WardrobesGallery' => [
                1 => [
                    'morph' => 'plain : cards.rail.n.photo.carousel', // 2.10.2.1
                    'roles' => ['gallery', 'products', 'projects'],
                ],
            ],
            'WardrobeFeatures' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.none/ord', // 2.10.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'WardrobeTypes' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo', // 2.10.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'WardrobesCTA' => [
                1 => [
                    'morph' => 'plain : actions', // 2.10.5.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/showrooms' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.11.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'ShowroomsMap' => [
                1 => [
                    'morph' => 'aside(map) : list.stack.n.none.filter', // 2.11.2.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'ShowroomsCTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.11.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/careers' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : actions + list.grid.2-4.metric', // 2.12.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner', 'stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'CareersPerks' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 2.12.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'CareersVacancies' => [
                1 => [
                    'morph' => 'plain : list.stack.n.none.accordion&filter', // 2.12.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'CareersForm' => [
                1 => [
                    'morph' => 'split : fields.stack.3-6.none.submit', // 2.12.4.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
        ],
        '/designers' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : actions + list.grid.2-4.metric', // 2.13.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner', 'stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'DesignersBenefits' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 2.13.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'DesignersTracks' => [
                1 => [
                    'morph' => 'plain : list.stack.2-4.none.tabs', // 2.13.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'DesignersSteps' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 2.13.4.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'DesignersForm' => [
                1 => [
                    'morph' => 'split : fields.stack.3-6.none.submit', // 2.13.5.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
        ],
        '/yandex-direct' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 2.14.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
            'USP' => [
                1 => [
                    'morph' => 'plain : list.stack.2-4.icon', // 2.14.2.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Advantages' => [
                1 => [
                    'morph' => 'split : list.grid.2-4.metric + list.stack.4-8.icon', // 2.14.3.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'Steps' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 2.14.4.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'SocialProof' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 2.14.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Offer' => [
                1 => [
                    'morph' => 'split : list.stack.3-6.icon', // 2.14.6.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'plain : actions', // 2.14.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '__global__' => [
            'Header' => [
                1 => [
                    'morph' => 'bar : list.row.n.none.accordion', // 2.М.1.1
                    'roles' => ['header', 'promo-bar'],
                ],
            ],
            'Footer' => [
                1 => [
                    'morph' => 'plain : list.grid.n.none', // 2.Ф.1.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
        ],
    ],
    // Promo-3
    3 => [
        '/' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions + list.row.3-6.metric', // 3.1.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner', 'stats', 'contacts', 'badges'],
                ],
            ],
            'Categories' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo', // 3.1.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Banner' => [
                1 => [
                    'morph' => 'split(photo) : list.stack.2-4.icon', // 3.1.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Collections' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.photo', // 3.1.4.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Advantages' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 3.1.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.1.6.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/about' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text + list.row.3-6.metric', // 3.2.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro', 'stats', 'contacts', 'badges'],
                ],
            ],
            'Mission' => [
                1 => [
                    'morph' => 'split : text', // 3.2.2.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'Values' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 3.2.3.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Timeline' => [
                1 => [
                    'morph' => 'plain : list.stack.3-6.none/ord', // 3.2.4.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Team' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.none', // 3.2.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Brands' => [
                1 => [
                    'morph' => 'plain : list.grid.4-8.none', // 3.2.6.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.2.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/brands' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 3.3.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'BrandsList' => [
                1 => [
                    'morph' => 'plain : cards.grid.n.none.filter', // 3.3.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.3.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/collections' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 3.4.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'CollectionsList' => [
                1 => [
                    'morph' => 'plain : cards.grid.n.photo.filter', // 3.4.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.4.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/contacts' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 3.5.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'Salons' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 3.5.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Courier' => [
                1 => [
                    'morph' => 'split : list.stack.2-4.icon', // 3.5.3.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Form' => [
                1 => [
                    'morph' => 'split : fields.stack.3-6.none.submit', // 3.5.4.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
        ],
        '/materials' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text + list.row.2-4.metric', // 3.6.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro', 'stats', 'contacts', 'badges'],
                ],
            ],
            'Categories' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.none', // 3.6.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'HowWeWork' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 3.6.3.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.6.4.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/services' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 3.7.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'ServicesGrid' => [
                1 => [
                    'morph' => 'plain : cards.grid.3-6.icon', // 3.7.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.7.3.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '/vacancies' => [
            'Hero' => [
                1 => [
                    'morph' => 'plain : text', // 3.8.1.1
                    'roles' => ['hero', 'about', 'intro', 'legal', 'quote', 'page-intro'],
                ],
            ],
            'VacanciesList' => [
                1 => [
                    'morph' => 'plain : list.stack.n.none.accordion', // 3.8.2.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'Form' => [
                1 => [
                    'morph' => 'split : fields.stack.3-6.none.submit', // 3.8.3.1
                    'roles' => ['contact', 'lead', 'application'],
                ],
            ],
        ],
        '/yandex-direct' => [
            'Hero' => [
                1 => [
                    'morph' => 'overlay(photo) : actions + list.row.2-4.icon', // 3.9.1.1
                    'roles' => ['hero', 'cta', 'offer', 'banner', 'stats', 'contacts', 'badges'],
                ],
            ],
            'USP' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.photo', // 3.9.2.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Advantages' => [
                1 => [
                    'morph' => 'split : list.grid.2-4.metric + list.stack.4-8.icon', // 3.9.3.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'Steps' => [
                1 => [
                    'morph' => 'plain : list.grid.2-4.none/ord', // 3.9.4.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
            'SocialProof' => [
                1 => [
                    'morph' => 'plain : cards.grid.2-4.icon', // 3.9.5.1
                    'roles' => ['benefits', 'services', 'features', 'steps', 'values', 'categories', 'team', 'products', 'plans', 'faq'],
                ],
            ],
            'Offer' => [
                1 => [
                    'morph' => 'split : list.stack.3-6.icon', // 3.9.6.1
                    'roles' => ['steps', 'faq', 'timeline', 'benefits', 'news', 'vacancies', 'specs', 'contacts', 'locations'],
                ],
            ],
            'CTA' => [
                1 => [
                    'morph' => 'overlay(photo) : actions', // 3.9.7.1
                    'roles' => ['hero', 'cta', 'offer', 'banner'],
                ],
            ],
        ],
        '__global__' => [
            'Header' => [
                1 => [
                    'morph' => 'bar : list.row.n.none', // 3.М.1.1
                    'roles' => ['header', 'promo-bar'],
                ],
            ],
            'Footer' => [
                1 => [
                    'morph' => 'plain : list.grid.n.none', // 3.Ф.1.1
                    'roles' => ['stats', 'steps', 'benefits', 'features', 'brands', 'footer', 'sitemap'],
                ],
            ],
        ],
    ],
];
