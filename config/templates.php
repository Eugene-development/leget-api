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
                ['type' => 'Hero',       'defaults' => ['label' => 'Белорусская фабрика мебели', 'title' => 'Мебель европейского качества по доступной цене', 'description' => 'Создаём кухонные гарнитуры, шкафы и гардеробы, которые сочетают безупречный дизайн, премиальные материалы и выверенную функциональность изделий', 'ctaPrimary' => 'Дизайнер на дом', 'ctaPrimaryLink' => '/contact', 'ctaSecondary' => 'Промокод на 10% скидку', 'ctaSecondaryLink' => '/actions']],
                ['type' => 'Styles',     'defaults' => ['label' => 'Коллекции', 'heading' => 'Найдите свой стиль', 'allLinkText' => 'Все стили', 'allLink' => '/styles']],
                ['type' => 'Advantages', 'defaults' => ['label' => 'Преимущества', 'heading' => 'Почему выбирают нас', 'description' => 'Мы объединяем многолетний опыт, передовые технологии и внимание к деталям для создания мебели, которая превосходит ожидания.']],
                ['type' => 'Details',    'defaults' => ['label' => 'Мебельная фабрика', 'heading' => 'Мебель для жизни', 'description' => 'Мебель — это сочетание безупречного дизайна, функциональности и долговечности. Мы используем только качественные материалы и фурнитуру от ведущих мировых производителей.']],
                ['type' => 'CTA',        'defaults' => ['label' => 'Начните сейчас', 'heading' => 'Создадим кухню вашей мечты', 'description' => 'Запишитесь на бесплатную консультацию. Наш дизайнер поможет подобрать идеальное решение для вашего пространства.', 'buttonText' => 'Бесплатная консультация', 'buttonLink' => '/contact']],
            ],
            '/about' => [
                ['type' => 'Hero',          'defaults' => ['label' => 'О фабрике', 'title' => 'От нашей фабрики для вашей семьи', 'description' => 'Наша фабрика располагает самой крупной сетью мебельных салонов. Предлагаем отличный сервис и доступные цены на мебель премиального качества.', 'ctaPrimary' => 'Найти ближайший салон', 'ctaPrimaryLink' => '/showrooms']],
                ['type' => 'LeaderSection', 'defaults' => ['quote' => 'Мы вкладываем весь свой опыт и душу в создание мебели', 'name' => 'Зуховицкий О.В.', 'role' => 'Руководитель фабрики ЗОВ', 'image' => 'https://storage.yandexcloud.net/zovtop/foto/zovdir.png']],
                ['type' => 'Mission',       'defaults' => ['label' => 'Наша миссия', 'heading' => 'Мы создаём мебель, которая дарит радость']],
                ['type' => 'Factory',       'defaults' => ['label' => 'Производство', 'heading' => 'Наша фабрика', 'description' => '25 000 м² современного производства, оснащённого передовым европейским оборудованием']],
                ['type' => 'Video',         'defaults' => ['src' => 'https://storage.yandexcloud.net/zovrus/zov.mp4']],
                ['type' => 'Principles',    'defaults' => ['label' => 'Наши ценности', 'heading' => 'Принципы компании']],
                ['type' => 'AboutCTA',      'defaults' => ['eyebrow' => 'Бесплатная услуга', 'heading' => 'Закажите дизайн-проект', 'description' => 'Запишитесь на бесплатную консультацию. Наш дизайнер поможет подобрать идеальное решение.', 'ctaText' => 'Заказать дизайн-проект', 'ctaLink' => '/contact']],
            ],
            '/news' => [
                ['type' => 'Hero',     'defaults' => ['label' => 'Мебельная фабрика', 'heading' => 'Новости', 'description' => 'События, обновления и достижения фабрики']],
                ['type' => 'NewsList', 'defaults' => []],
                ['type' => 'NewsCTA',  'defaults' => ['eyebrow' => 'Хотите узнавать первыми?', 'heading' => 'Посетите наши салоны', 'description' => 'Наши дизайнеры всегда в курсе последних новинок и акций.', 'ctaText' => 'Найти ближайший салон', 'ctaLink' => '/showrooms']],
            ],
            '/styles' => [
                ['type' => 'Hero',       'defaults' => ['label' => 'Коллекции', 'heading' => 'Стили кухонь', 'description' => 'Откройте для себя наше портфолио. От строгой классики до минимализма — найдите идеальное решение, отражающее ваш индивидуальный вкус.']],
                ['type' => 'StylesGrid', 'defaults' => []],
                ['type' => 'StylesCTA',  'defaults' => ['heading' => 'Поможем с выбором', 'description' => 'Запишитесь на встречу с нашим дизайнером. Мы подберем идеальный стиль, материалы и фурнитуру, учитывая архитектуру вашего пространства.', 'ctaText' => 'Записаться в салон', 'ctaLink' => '/contact']],
            ],
            '/facades' => [
                ['type' => 'Hero',           'defaults' => ['label' => 'Материалы', 'title' => 'Фасад это лицо вашей мебели', 'description' => 'Фасады задают характер вашего домашнего интерьера. От натурального массива до технологичного акрила: мы предлагаем материалы, которые сочетают в себе безупречную эстетику, функциональность и долговечность.']],
                ['type' => 'FacadesCatalog', 'defaults' => []],
                ['type' => 'FacadesCTA',     'defaults' => ['eyebrow' => 'Образцы', 'heading' => 'Посмотрите вживую', 'description' => 'Посетите салон фабрики, чтобы прикоснуться к образцам.', 'ctaText' => 'Запись в салон', 'ctaLink' => '/contact']],
            ],
            '/furniture' => [
                ['type' => 'Hero',           'defaults' => ['label' => 'Функциональность', 'title' => 'Безупречное движение', 'description' => 'Мебель премиум-класса требует фурнитуры соответствующего уровня. Мы предлагаем решения от ведущих европейских брендов, которые сделают каждое движение фасада или ящика плавным и комфортным.']],
                ['type' => 'FurnitureIntro', 'defaults' => ['eyebrow' => 'Качество в деталях', 'heading' => 'Надежность, которую вы чувствуете каждый день', 'description' => 'Фурнитура — это невидимое сердце любой мебели. От нее зависит, насколько плавно будут открываться дверцы, как тихо будут закрываться ящики и сколько лет мебель прослужит без единого скрипа.']],
                ['type' => 'BrandsSection',  'defaults' => []],
                ['type' => 'FurnitureCTA',   'defaults' => ['eyebrow' => 'Консультация', 'heading' => 'Поможем выбрать лучшую систему для вашей мебели', 'ctaText' => 'Записаться в салон', 'ctaLink' => '/contact']],
            ],
            '/contact' => [
                ['type' => 'ContactForm', 'defaults' => ['title' => 'Контакты', 'email' => 'info@example.com']],
                ['type' => 'Map',         'defaults' => ['address' => 'Москва, ул. Примерная, 1']],
            ],
            '/actions' => [
                ['type' => 'Hero',          'defaults' => ['label' => 'Специальные предложения', 'title' => 'Скидки & акции', 'description' => '', 'ctaText' => 'Расчёт проекта за час', 'ctaLink' => '/contact', 'discountBadge' => '−30%', 'statCount' => '6+', 'statDiscount' => '−30%']],
                ['type' => 'ActionsTimer',  'defaults' => ['badge' => 'Главные акции сезона', 'heading' => 'Сезонные акции', 'description' => 'Успейте оформить заказ до окончания акции', 'ctaText' => 'Консультация по акциям', 'ctaLink' => '/contact', 'deadline' => '2026-12-31T00:00:00']],
                ['type' => 'ActionsCards',  'defaults' => ['label' => 'Текущие предложения', 'heading' => 'Все акции', 'consultLink' => '/contact']],
                ['type' => 'ActionsBanner', 'defaults' => ['eyebrow' => 'Специальное предложение', 'title' => 'Кухня мечты −30%', 'description' => '', 'ctaText' => 'Консультация по акции', 'ctaLink' => '/contact']],
                ['type' => 'ActionsSteps',  'defaults' => ['label' => 'Просто', 'heading' => 'Как получить скидку', 'description' => 'Воспользоваться акцией легко — всего 3 шага до вашей новой мебели по выгодной цене']],
                ['type' => 'ActionsCTA',    'defaults' => ['eyebrow' => 'Не упустите момент', 'title' => 'Запишитесь на бесплатную консультацию', 'description' => 'Наш дизайнер поможет выбрать подходящую акцию, разработает проект и рассчитает точную стоимость с учётом скидок', 'ctaText' => 'Записаться на консультацию', 'ctaLink' => '/contact', 'phone' => '']],
            ],
            '/kitchens' => [
                ['type' => 'Hero',            'defaults' => ['title' => 'Создание вашей идеальной кухни', 'description' => 'От детального проектирования до бережной сборки — каждый этап контролируется нашими специалистами', 'ctaText' => 'Спроектировать кухню', 'ctaLink' => '/contact']],
                ['type' => 'KitchensGallery', 'defaults' => ['label' => 'Галерея', 'heading' => 'Наши гарнитуры', 'description' => 'Ознакомьтесь с вариантами решений для вашей кухни.']],
                ['type' => 'ProductionCycle', 'defaults' => ['label' => 'Этапы', 'heading' => 'Производственный цикл', 'description' => 'Отточенный годами процесс создания премиальной мебели.']],
                ['type' => 'KitchenStyles',   'defaults' => ['heading' => 'Варианты стилистических решений', 'description' => 'Мы адаптируем индивидуальный проект под любой стиль.']],
                ['type' => 'KitchensCTA',     'defaults' => ['heading' => 'Хотите заказать кухню?', 'description' => 'Запишитесь в наши салоны для бесплатной консультации с дизайнером.', 'ctaText' => 'Записаться в салон', 'ctaLink' => '/contact']],
            ],
            '/wardrobes' => [
                ['type' => 'Hero',             'defaults' => ['label' => 'Системы хранения', 'title' => 'Идеальный порядок в каждой детали', 'description' => 'Мы создаем уникальные встроенные и корпусные шкафы, которые становятся органичным продолжением вашей квартиры.', 'ctaText' => 'Спроектировать шкаф', 'ctaLink' => '/contact']],
                ['type' => 'WardrobesGallery', 'defaults' => ['label' => 'Галерея', 'heading' => 'Наши шкафы и гардеробные']],
                ['type' => 'WardrobeFeatures', 'defaults' => ['heading' => 'Безупречное качество от замера до установки']],
                ['type' => 'WardrobeTypes',    'defaults' => ['label' => 'Варианты решений', 'heading' => 'Виды систем']],
                ['type' => 'WardrobesCTA',     'defaults' => ['heading' => 'Закажите расчет стоимости', 'ctaText' => 'Запись в салон', 'ctaLink' => '/contact']],
            ],
            '/showrooms' => [
                ['type' => 'Hero',         'defaults' => ['label' => 'Где купить', 'title' => 'Наши салоны', 'description' => 'Посетите один из наших фирменных салонов. Оцените качество материалов вживую.', 'ctaText' => 'Посмотреть карту', 'ctaLink' => '#network-section']],
                ['type' => 'ShowroomsMap', 'defaults' => []],
                ['type' => 'ShowroomsCTA', 'defaults' => ['heading' => 'Нет времени на поездку в салон?', 'description' => 'Закажите выезд дизайнера на дом абсолютно бесплатно.', 'ctaText' => 'Вызвать дизайнера', 'ctaLink' => '/contact']],
            ],
            '/careers' => [
                ['type' => 'Hero',             'defaults' => ['label' => 'Карьера', 'title' => 'Строй карьеру вместе с нами', 'description' => 'Фабрика — это более 700 специалистов, которые создают премиальную мебель. Присоединяйтесь к команде профессионалов.']],
                ['type' => 'CareersPerks',     'defaults' => ['label' => 'Почему мы', 'heading' => 'Условия работы']],
                ['type' => 'CareersVacancies', 'defaults' => ['label' => 'Открытые позиции', 'heading' => 'Вакансии']],
                ['type' => 'CareersForm',      'defaults' => ['label' => 'Открытое резюме', 'heading' => 'Отправьте ваше резюме', 'description' => 'Отправьте открытое резюме — мы сохраняем его в базе и свяжемся, когда появится подходящая позиция.', 'email' => 'info@zov.top', 'phone' => '+7 915 400-00-20']],
            ],
            '/designers' => [
                ['type' => 'Hero',              'defaults' => ['label' => 'Партнёрская программа', 'title' => 'Для дизайнеров и студий', 'description' => 'Станьте партнёром фабрики — и предлагайте клиентам премиальную мебель с эксклюзивными условиями.']],
                ['type' => 'DesignersBenefits', 'defaults' => ['label' => 'Что вы получаете', 'heading' => 'Преимущества партнёрства']],
                ['type' => 'DesignersTracks',   'defaults' => ['label' => 'Выберите формат', 'heading' => 'Программы сотрудничества']],
                ['type' => 'DesignersSteps',    'defaults' => ['label' => 'Просто и быстро', 'heading' => 'Как стать партнёром']],
                ['type' => 'DesignersForm',     'defaults' => ['label' => 'Начните сегодня', 'heading' => 'Оставьте заявку — мы позвоним первыми', 'description' => 'Расскажите о себе, и мы предложим наиболее подходящие условия сотрудничества.', 'email' => 'info@zov.top', 'phone' => '+7 915 400-00-20']],
            ],
        ],
    ],

];
