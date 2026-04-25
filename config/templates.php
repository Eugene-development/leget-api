<?php

/**
 * Template registry.
 *
 * Each entry describes a template available in the platform.
 * The key is the templateId stored in the licenses table.
 *
 * pages: map of page slug → ordered list of components.
 *   Each component has:
 *     - type:     component name (must match Svelte pageOverrides key)
 *     - defaults: default data seeded into page_components for new clients
 *
 * The 'defaults' are copied into each client's page_components row on page
 * creation. Clients can later edit their own copy independently.
 */
return [

    1 => [
        'name'  => 'Promo-1',
        'pages' => [
            '/' => [
                ['type' => 'HeroMain',   'defaults' => ['companyName' => 'Компания', 'title' => 'Мебель и Техника', 'description' => 'Мебель по вашим размерам с бесплатным проектом от дизайнера.', 'buttonText' => 'Бесплатный дизайн-проект с расчётом стоимости', 'buttonHref' => '/contact']],
                ['type' => 'Message',    'defaults' => ['text' => 'Предлагаем мебель на заказ для вашего идеального интерьера с индивидуальным подбором размеров и дизайна.']],
                ['type' => 'PromoOffer', 'defaults' => ['badge' => 'Эксклюзивное предложение', 'title' => 'Специальное предложение', 'textPrimary' => 'Скидка 15% на мебель этой весной', 'textSecondary' => 'Воспользуйтесь уникальной возможностью приобрести качественную мебель по выгодной цене', 'primaryButton' => 'Получить предложение', 'secondaryButton' => 'Узнать больше', 'primaryHref' => '/contact', 'secondaryHref' => '/about']],
                ['type' => 'Equipment',  'defaults' => ['badge' => 'Дополнительно', 'title' => 'Комплектация проектов']],
                ['type' => 'Stage',      'defaults' => ['badge' => 'Это важно', 'title' => 'Наша работа', 'description' => 'Мы поддержим вас на всех этапах работы над мебельным проектом: от первой консультации до дня финальной сборки.']],
                ['type' => 'Incentives', 'defaults' => ['badge' => 'Выгода', 'title' => 'С нами выгодно', 'text' => '<p>Помогаем клиентам сделать правильный выбор фурнитуры, материалов и производителя мебели.</p>']],
                ['type' => 'Direction',  'defaults' => []],
                ['type' => 'Brands',     'defaults' => ['badge' => 'Материалы', 'title' => 'Бренды, говорящие о качестве', 'partnersLabel' => 'Наши партнёры-производители']],
                ['type' => 'Hero',       'defaults' => ['title' => 'Добро пожаловать', 'subtitle' => 'Мы делаем лучшую мебель']],
                ['type' => 'Features',   'defaults' => ['items' => []]],
                ['type' => 'Text',       'defaults' => ['content' => 'Расскажите о вашей компании']],
                ['type' => 'CTA',        'defaults' => ['title' => 'Свяжитесь с нами', 'buttonText' => 'Написать']],
            ],
            '/about' => [
                ['type' => 'Hero',       'defaults' => ['badge' => 'О компании', 'title' => 'О нас', 'lead' => '', 'text' => '', 'buttonText' => 'Связаться с нами', 'img1' => '', 'img2' => '', 'img3' => '', 'img4' => '']],
                ['type' => 'Statistics', 'defaults' => []],
                ['type' => 'Mission',    'defaults' => ['title' => 'Наша миссия', 'text1' => '', 'text2' => '', 'text3' => '', 'imageUrl' => '']],
                ['type' => 'Values',     'defaults' => ['title' => 'Наши ценности', 'subtitle' => 'Принципы, которыми мы руководствуемся в работе', 'items' => []]],
                ['type' => 'WhyUs',      'defaults' => ['title' => 'Почему выбирают нас', 'subtitle' => 'Преимущества работы с нами', 'items' => []]],
                ['type' => 'AboutCTA',   'defaults' => ['title' => 'Ждём вас в качестве клиента', 'subtitle' => 'Свяжитесь с нами, чтобы обсудить ваш проект', 'buttonText' => 'Получить консультацию', 'phone' => '']],
            ],
            '/contact' => [
                ['type' => 'ContactForm', 'defaults' => ['title' => 'Контакты', 'email' => 'info@example.com']],
            ],
            '/actions' => [
                ['type' => 'Hero',              'defaults' => ['eyebrow' => 'Выгодные предложения', 'title' => 'Акции, скидки и подарки', 'subtitle' => 'Получите актуальные акции наших партнёров и узнайте о подарках и скидках на проекты и услуги']],
                ['type' => 'ActionsCards',      'defaults' => []],
                ['type' => 'ActionsCardsExtra', 'defaults' => []],
                ['type' => 'ActionsBanner',     'defaults' => ['imageUrl' => '', 'imageAlt' => 'Акция']],
                ['type' => 'ActionsCTA',        'defaults' => ['title' => 'Хотите узнать больше об акциях?', 'subtitle' => 'Свяжитесь с нами и мы расскажем обо всех актуальных предложениях', 'buttonText' => 'Связаться с нами', 'phone' => '']],
            ],
            '/contacts' => [
                ['type' => 'ContactsHero',      'defaults' => ['eyebrow' => 'Связь с нами', 'title' => 'Контакты', 'subtitle' => 'Мы работаем с понедельника по субботу с 10:00 до 20:00. В иное время воспользуйтесь онлайн-чатом или почтой.']],
                ['type' => 'ContactChannels',   'defaults' => ['phone' => '', 'email' => '']],
                ['type' => 'ContactAddress',    'defaults' => ['eyebrow' => 'Давайте встретимся', 'title' => 'Личная консультация', 'description' => 'Для обсуждения деталей мы можем организовать с вами встречу в одном из салонов наших партнёров или на вашем объекте', 'buttonText' => 'Записаться на консультацию', 'addressTitle' => 'Адрес', 'addressText' => '', 'hoursTitle' => 'Часы работы', 'hoursText' => '', 'parkingTitle' => 'Парковка', 'parkingText' => '', 'mapImageUrl' => '']],
                ['type' => 'ContactMessengers', 'defaults' => ['eyebrow' => 'Социальные сети', 'title' => 'Мы в Телеграм', 'subtitle' => 'Напишите нам и мы ответим в течение нескольких минут', 'telegramUrl' => '']],
                ['type' => 'ContactCTA',        'defaults' => ['title' => 'Остались вопросы?', 'subtitle' => 'Свяжитесь с нами любым удобным способом — мы всегда рады помочь', 'phone' => '', 'telegramUrl' => '']],
            ],
            '/partnership' => [
                ['type' => 'PartnershipHero',    'defaults' => ['badge' => 'Партнёрская программа', 'title' => 'Растём вместе', 'text' => 'Приглашаем к сотрудничеству дизайнеров интерьеров, ремонтные бригады и продавцов мебели. Выгодные условия и прозрачная система вознаграждений.', 'buttonText' => 'Обсудить сотрудничество', 'quote' => 'Партнёрство открывает новые горизонты и возможности для совместного роста. Вместе мы достигнем большего.', 'quoteName' => '', 'quoteRole' => '']],
                ['type' => 'WhoWeInvite',        'defaults' => ['title' => 'Кого мы приглашаем', 'subtitle' => 'Партнёрство для профессионалов в сфере интерьера и ремонта', 'cards' => [], 'platformText' => 'Профессиональная платформа для автоматизации партнёрских продаж.', 'platformUrl' => '', 'platformButtonText' => 'Перейти на платформу']],
                ['type' => 'ForManufacturers',   'defaults' => ['badge' => 'Вы организация?', 'title' => 'Для производителей и поставщиков', 'text' => 'Мы готовы предложить уникальные возможности для совместного развития.', 'buttonText' => 'Оставить заявку', 'benefits' => [], 'stats' => []]],
                ['type' => 'Benefits',           'defaults' => ['title' => 'Преимущества партнёрства', 'subtitle' => 'Что вы получаете, работая с нами', 'items' => []]],
                ['type' => 'HowToStart',         'defaults' => ['title' => 'Как стать партнёром', 'subtitle' => 'Простой процесс от первого контакта до результата', 'steps' => []]],
                ['type' => 'PartnershipCTA',     'defaults' => ['title' => 'Присоединяйтесь к нам', 'subtitle' => 'Начните работать вместе с нами уже сегодня', 'buttonText' => 'Обсудить сотрудничество', 'phone' => '']],
            ],
            '/testimonials' => [
                ['type' => 'Hero',             'defaults' => ['eyebrow' => 'Отзывы', 'title' => 'Мы работаем ради таких отзывов клиентов о нашей работе']],
                ['type' => 'TestimonialsGrid', 'defaults' => ['featured' => [], 'reviews' => []]],
            ],
            '/installment' => [
                ['type' => 'InstallmentHero',         'defaults' => ['title' => 'Рассрочка без переплаты', 'subtitle' => 'Купите мебель и технику сейчас — платите частями до 12 месяцев. Быстрое одобрение, минимум документов.', 'buttonText' => 'Консультация по рассрочке']],
                ['type' => 'InstallmentPlans',        'defaults' => ['title' => 'Программы рассрочки', 'subtitle' => 'Выберите удобный срок и условия оплаты', 'plans' => []]],
                ['type' => 'InstallmentRequirements', 'defaults' => ['title' => 'Что нужно для оформления', 'subtitle' => 'Минимум документов — максимум удобства', 'items' => []]],
                ['type' => 'InstallmentSteps',        'defaults' => ['title' => 'Как оформить рассрочку', 'subtitle' => 'Простой процесс за 4 шага', 'steps' => []]],
                ['type' => 'InstallmentBanks',        'defaults' => ['title' => 'Банки-партнёры', 'subtitle' => 'Работаем с надёжными финансовыми организациями', 'banks' => []]],
                ['type' => 'InstallmentFAQ',          'defaults' => ['title' => 'Частые вопросы', 'items' => []]],
                ['type' => 'InstallmentCTA',          'defaults' => ['title' => 'Оформите рассрочку сегодня', 'subtitle' => 'Получите мебель и технику не откладывая покупку — платите комфортными частями', 'buttonText' => 'Консультация', 'phone' => '']],
            ],
            '/guarantees' => [
                ['type' => 'GuaranteesHero',  'defaults' => ['title' => 'Гарантия качества', 'subtitle' => 'Мы уверены в качестве продукции наших партнёров. Вся продукция имеет расширенную гарантию на материалы и работу мастеров.']],
                ['type' => 'GuaranteeTerms',  'defaults' => ['title' => 'Сроки гарантии', 'subtitle' => 'Официальная гарантия от производителей на все категории', 'items' => []]],
                ['type' => 'WhatsCovered',    'defaults' => ['title' => 'Что покрывает гарантия', 'description' => 'Наша гарантия распространяется на производственные дефекты материалов и качество сборки.', 'items' => [], 'imageUrl' => '']],
                ['type' => 'HowToApply',      'defaults' => ['title' => 'Как обратиться по гарантии', 'subtitle' => 'Простой процесс решения гарантийных вопросов', 'steps' => []]],
                ['type' => 'GuaranteesCTA',   'defaults' => ['title' => 'Гарантийный случай?', 'subtitle' => 'Заполните форму или позвоните нам — решим вопрос в кратчайшие сроки', 'buttonText' => 'Оставить заявку', 'phone' => '']],
            ],
        ],
    ],

    2 => [
        'name'  => 'Promo-2',
        'pages' => [
            '/' => [
                ['type' => 'Hero',         'defaults' => ['title' => 'Фабрика мебели', 'subtitle' => 'Качество и стиль']],
                ['type' => 'Gallery',      'defaults' => ['images' => []]],
                ['type' => 'Testimonials', 'defaults' => ['items' => []]],
                ['type' => 'Text',         'defaults' => ['content' => 'Описание компании']],
                ['type' => 'CTA',          'defaults' => ['title' => 'Заказать', 'buttonText' => 'Оставить заявку']],
            ],
            '/about' => [
                ['type' => 'Hero',          'defaults' => ['title' => 'О фабрике', 'subtitle' => 'Производство с душой']],
                ['type' => 'Text',          'defaults' => ['content' => 'Подробнее о производстве']],
                ['type' => 'LeaderSection', 'defaults' => ['name' => 'Иван Иванов', 'role' => 'Генеральный директор', 'bio' => '']],
                ['type' => 'Statistics',    'defaults' => []],
            ],
            '/contact' => [
                ['type' => 'ContactForm', 'defaults' => ['title' => 'Контакты', 'email' => 'info@example.com']],
                ['type' => 'Map',         'defaults' => ['address' => 'Москва, ул. Примерная, 1']],
            ],
        ],
    ],

];
